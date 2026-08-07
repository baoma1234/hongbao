import { getDeviceFp, getToken, goLoginIfUnauthorized } from './auth.js'
import { getApiBase, getImWsBase } from './config.js'

let socketTask = null
let socketOpen = false
let authed = false
let authMeta = {}
let reqSeq = 0
const pending = new Map()
const listeners = new Set()
let reconnectTimer = null
let reconnectAttempt = 0
let intentionalClose = false
/** 进行中的连接（含等 OPEN / 等 auth） */
let connectingPromise = null
/** 周期 ping，避免服务端 120s 空闲踢线 */
let pingTimer = null
const PING_INTERVAL_MS = 30000

function stopPingLoop() {
  if (pingTimer) {
    clearInterval(pingTimer)
    pingTimer = null
  }
}

function startPingLoop() {
  stopPingLoop()
  pingTimer = setInterval(() => {
    if (!getToken() || !isSocketSendable() || !authed) return
    try {
      socketTask.send({
        data: JSON.stringify({
          type: 'ping',
          data: {},
          req_id: nextReqId(),
        }),
        fail() {},
      })
    } catch (e) {}
  }, PING_INTERVAL_MS)
}

/** 与 888 对齐：这些写读优先走 /im-api，失败再回退 WS */
const HTTP_ROUTES = {
  'conversation.list': '/im/conversations',
  history: '/im/history',
  'redpacket.send': '/im/redpacket/send',
  'redpacket.grab': '/im/redpacket/grab',
  'redpacket.detail': '/im/redpacket/detail',
  'transfer.send': '/im/transfer/send',
}

function getImHttpBase() {
  const api = String(getApiBase() || '').replace(/\/$/, '')
  if (api) return api + '/im-api'
  // #ifdef H5
  if (typeof location !== 'undefined' && location.origin) {
    return String(location.origin).replace(/\/$/, '') + '/im-api'
  }
  // #endif
  return 'http://127.0.0.1:17273'
}

function sendViaHttp(type, data) {
  const path = HTTP_ROUTES[type]
  if (!path) return Promise.reject(new Error('no http route'))
  const token = getToken()
  if (!token) return Promise.reject(new Error('未登录'))
  const body = Object.assign({ token }, data || {})
  return new Promise((resolve, reject) => {
    uni.request({
      url: getImHttpBase() + path,
      method: 'POST',
      data: body,
      header: {
        'Content-Type': 'application/json',
        'X-Fans-Token': token,
      },
      timeout: 12000,
      success(res) {
        const json = (res && res.data) || null
        if (!json || typeof json !== 'object') {
          reject(new Error('HTTP empty'))
          return
        }
        const okStatus = res.statusCode >= 200 && res.statusCode < 300
        // 业务失败：HTTP 4xx 或 body.code===0（如 balance_not_enough_for_compensate）
        if (!okStatus || json.code === 0) {
          const errMsg = json.message || json.msg || json.error || 'HTTP ' + res.statusCode
          reject(new Error(String(errMsg)))
          return
        }
        const respType = json.ws_type || type
        let payload
        if (Object.prototype.hasOwnProperty.call(json, 'data') && json.data != null) {
          payload = json.data
        } else {
          payload = Object.assign({}, json)
          try {
            delete payload.code
            delete payload.ws_type
            delete payload.message
          } catch (e) {}
        }
        resolve({ type: respType, data: payload, via: 'http' })
      },
      fail(err) {
        // 部分环境把 HTTP 4xx 丢进 fail；尽量带上业务 message
        const raw = (err && (err.data || err.response || err)) || {}
        const body = raw.data || raw
        const biz =
          (body && typeof body === 'object' && (body.message || body.msg)) ||
          (err && err.message) ||
          (err && err.errMsg) ||
          'HTTP failed'
        reject(new Error(String(biz)))
      },
    })
  })
}

function isHttpNetworkError(err) {
  const hm = String((err && err.message) || '')
  if (!hm) return true
  if (hm === '未连接' || hm === '超时' || hm === 'HTTP empty' || hm === 'HTTP bad json') return true
  if (hm.indexOf('NetworkError') >= 0 || hm.indexOf('Load failed') >= 0) return true
  if (hm.indexOf('request:fail') >= 0 || hm.indexOf('Failed to fetch') >= 0) return true
  if (hm.indexOf('HTTP ') === 0) return true
  return false
}

function nextReqId() {
  reqSeq += 1
  return 'r' + reqSeq + '_' + Date.now().toString(36)
}

function emit(type, data) {
  listeners.forEach((fn) => {
    try {
      fn(type, data)
    } catch (e) {}
  })
}

export function onImEvent(fn) {
  listeners.add(fn)
  return () => listeners.delete(fn)
}

export function isImAuthed() {
  return !!authed && !!socketOpen && !!socketTask
}

export function getImStatus() {
  if (!socketTask) return 'disconnected'
  if (!socketOpen || !authed) return 'connecting'
  return 'online'
}

function connectUrl() {
  const base = getImWsBase()
  const token = getToken()
  if (!token) return base
  const fp = getDeviceFp()
  let q = 'token=' + encodeURIComponent(token)
  if (fp) q += '&device_fp=' + encodeURIComponent(fp)
  return base + (base.indexOf('?') >= 0 ? '&' : '?') + q
}

function isSocketSendable() {
  // 只信我们自己的 open 标记。uni H5 上 readyState 有时不可靠，硬查会误判。
  return !!(socketTask && socketOpen)
}

function flushPending(err) {
  pending.forEach((p) => {
    clearTimeout(p.timer)
    p.reject(err instanceof Error ? err : new Error(String(err || 'WS 已关闭')))
  })
  pending.clear()
}

/**
 * 发送；不依赖 success 回调（部分端不回调 success，只靠服务端 ack / fail）
 */
function doSend(packet, waitAck) {
  return new Promise((resolve, reject) => {
    if (!isSocketSendable()) {
      reject(new Error('WS 未连接'))
      return
    }
    const reqId = packet.req_id
    if (waitAck) {
      const timer = setTimeout(() => {
        pending.delete(reqId)
        reject(new Error('WS 超时: ' + packet.type))
      }, 12000)
      pending.set(reqId, { resolve, reject, timer })
    }
    try {
      socketTask.send({
        data: JSON.stringify(packet),
        fail(err) {
          if (waitAck && pending.has(reqId)) {
            const p = pending.get(reqId)
            clearTimeout(p.timer)
            pending.delete(reqId)
          }
          reject(new Error((err && err.errMsg) || '发送失败'))
        },
      })
      if (!waitAck) resolve(true)
    } catch (e) {
      if (waitAck && pending.has(reqId)) {
        const p = pending.get(reqId)
        clearTimeout(p.timer)
        pending.delete(reqId)
      }
      reject(e)
    }
  })
}

function handlePacket(raw) {
  let packet
  try {
    packet = typeof raw === 'string' ? JSON.parse(raw) : raw
  } catch (e) {
    return
  }
  if (!packet || !packet.type) return

  const reqId = packet.req_id
  if (reqId && pending.has(reqId)) {
    const p = pending.get(reqId)
    clearTimeout(p.timer)
    pending.delete(reqId)
    if (packet.type === 'error') p.reject(new Error((packet.data && packet.data.message) || 'error'))
    else p.resolve(packet)
  }

  if (packet.type === 'auth.ok') {
    authed = true
    authMeta = packet.data || {}
    reconnectAttempt = 0
    startPingLoop()
    emit('auth.ok', authMeta)
    return
  }
  if (packet.type === 'pong') {
    // 心跳应答，不向业务层广播
    return
  }
  if (packet.type === 'error') {
    emit('error', packet.data || {})
    return
  }
  emit(packet.type, packet.data || {})
}

function bindSocketHandlers(task, token) {
  task.onOpen(() => {
    if (socketTask !== task) return
    socketOpen = true
    try {
      task.send({
        data: JSON.stringify({
          type: 'auth',
          data: { token, device_fp: getDeviceFp() },
          req_id: nextReqId(),
        }),
        fail() {},
      })
    } catch (e) {}
  })
  task.onMessage((msg) => {
    if (socketTask !== task) return
    handlePacket(msg.data)
  })
  task.onError((err) => {
    if (socketTask !== task) return
    socketOpen = false
    authed = false
    stopPingLoop()
    emit('socket.error', err)
    scheduleReconnect()
  })
  task.onClose(() => {
    if (socketTask !== task) return
    socketOpen = false
    authed = false
    authMeta = {}
    socketTask = null
    stopPingLoop()
    emit('socket.close', {})
    if (!intentionalClose) scheduleReconnect()
  })
}

function waitEvent(eventType, timeoutMs) {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => {
      off()
      reject(new Error('WS 超时'))
    }, timeoutMs)
    const off = onImEvent((type) => {
      if (type === eventType) {
        clearTimeout(timer)
        off()
        resolve(true)
      }
    })
  })
}

function waitUntil(predicate, timeoutMs, stepMs = 50) {
  return new Promise((resolve, reject) => {
    const start = Date.now()
    const tick = () => {
      if (predicate()) {
        resolve(true)
        return
      }
      if (Date.now() - start >= timeoutMs) {
        reject(new Error('WS 超时'))
        return
      }
      setTimeout(tick, stepMs)
    }
    tick()
  })
}

export function imConnect() {
  const token = getToken()
  if (!token) {
    goLoginIfUnauthorized(401, '未登录')
    return Promise.reject(new Error('未登录'))
  }
  intentionalClose = false

  if (socketTask && socketOpen && authed) {
    return Promise.resolve(true)
  }
  if (connectingPromise) return connectingPromise

  connectingPromise = (async () => {
    // 已有连接在握手：等 OPEN，禁止 close 重建
    if (socketTask && !socketOpen) {
      await waitUntil(() => socketOpen && socketTask, 10000)
    } else if (!socketTask) {
      const task = uni.connectSocket({
        url: connectUrl(),
        complete() {},
      })
      socketTask = task
      socketOpen = false
      authed = false
      bindSocketHandlers(task, token)
      await waitUntil(() => socketOpen && socketTask, 10000)
    }

    if (!authed) {
      try {
        await waitEvent('auth.ok', 8000)
      } catch (e) {
        // query token 鉴权时可能不推 auth.ok：OPEN 即可宽松放行
        if (socketOpen && socketTask) {
          authed = true
          reconnectAttempt = 0
          startPingLoop()
        } else {
          throw new Error('WS 鉴权超时')
        }
      }
    } else {
      startPingLoop()
    }
    return true
  })()
    .catch((err) => {
      throw err
    })
    .finally(() => {
      connectingPromise = null
    })

  return connectingPromise
}

export async function ensureImReady() {
  if (!getToken()) throw new Error('未登录')
  await imConnect()
  if (!isSocketSendable()) throw new Error('WS 未连接')
  return true
}

export function imSend(type, data = {}, waitAck = false) {
  const payload = data || {}
  if (waitAck && HTTP_ROUTES[type]) {
    return sendViaHttp(type, payload).catch((httpErr) => {
      if (!isHttpNetworkError(httpErr)) throw httpErr
      return ensureImReady().then(() => {
        const packet = { type, data: payload, req_id: nextReqId() }
        return doSend(packet, true)
      })
    })
  }
  return ensureImReady().then(() => {
    const packet = { type, data: payload, req_id: nextReqId() }
    return doSend(packet, waitAck)
  })
}

function scheduleReconnect() {
  if (intentionalClose || reconnectTimer) return
  if (!getToken()) return
  reconnectAttempt = (reconnectAttempt | 0) + 1
  const n = Math.min(reconnectAttempt | 0, 6)
  // 2s → 4s → 8s → 16s → 30s…，避免断线风暴
  const delay = Math.min(30000, 2000 * Math.pow(2, Math.max(0, n - 1)))
  reconnectTimer = setTimeout(() => {
    reconnectTimer = null
    imConnect().catch(() => {})
  }, delay)
}

/** 手动/僵尸重连：清退避并强制重建 socket */
export function imForceReconnect() {
  if (!getToken()) return Promise.reject(new Error('未登录'))
  intentionalClose = false
  reconnectAttempt = 0
  if (reconnectTimer) {
    clearTimeout(reconnectTimer)
    reconnectTimer = null
  }
  connectingPromise = null
  stopPingLoop()
  if (socketTask) {
    const old = socketTask
    socketTask = null
    socketOpen = false
    authed = false
    try {
      intentionalClose = true
      old.close({})
    } catch (e) {}
    intentionalClose = false
  } else {
    socketOpen = false
    authed = false
  }
  return imConnect()
}

export function imDisconnect() {
  intentionalClose = true
  stopPingLoop()
  if (reconnectTimer) {
    clearTimeout(reconnectTimer)
    reconnectTimer = null
  }
  connectingPromise = null
  flushPending(new Error('WS 已关闭'))
  authed = false
  socketOpen = false
  if (socketTask) {
    const old = socketTask
    socketTask = null
    try {
      old.close({})
    } catch (e) {}
  }
}

export function getImAuthMeta() {
  return authMeta || {}
}

export function canCreateGroupFromAuth() {
  const m = authMeta || {}
  return !!m.can_create_group
}

export function createGroup(payload = {}) {
  return imSend(
    'group.create',
    {
      name: String(payload.name || '').trim(),
      member_ids: Array.isArray(payload.member_ids) ? payload.member_ids : [],
      privacy_mode: payload.privacy_mode || 'private',
      chat_mode: payload.chat_mode || 'chat',
      bind_owner_rebate: payload.bind_owner_rebate ? 1 : 0,
    },
    true
  )
}

export function listConversations(limit = 50) {
  return imSend('conversation.list', { limit }, true)
}

export function loadHistory(payload) {
  return imSend('history', payload || {}, true)
}

export function markConversationRead(conversationType, conversationId, lastReadMsgId = 0) {
  return imSend(
    'conversation.read',
    {
      conversation_type: conversationType | 0,
      conversation_id: String(conversationId || ''),
      last_read_msg_id: lastReadMsgId | 0,
    },
    true
  ).catch(() => null)
}

export function sendRedPacket(payload) {
  return imSend('redpacket.send', payload || {}, true)
}

/** 私聊转账：仅 to_user_id */
export function sendTransfer(payload) {
  return imSend('transfer.send', payload || {}, true)
}

export function grabRedPacket(packetId, extra = {}) {
  return imSend(
    'redpacket.grab',
    Object.assign({ packet_id: packetId | 0, device_fp: getDeviceFp() }, extra),
    true
  )
}

export function redPacketDetail(packetId) {
  return imSend('redpacket.detail', { packet_id: packetId | 0 }, true)
}

export function setPeerRemark(peerUserId, remark) {
  return imSend(
    'friend.set_remark',
    { peer_user_id: peerUserId | 0, remark: String(remark || '') },
    true
  )
}

export function friendLookup(payload = {}) {
  return imSend('friend.lookup', payload || {}, true)
}

export function friendRequest(payload = {}) {
  return imSend('friend.request', payload || {}, true)
}

export function friendRequests() {
  return imSend('friend.requests', {}, true)
}

export function friendAccept(requestId) {
  return imSend('friend.accept', { request_id: requestId | 0 }, true)
}

export function friendReject(requestId) {
  return imSend('friend.reject', { request_id: requestId | 0 }, true)
}

export function friendCancel(requestId) {
  return imSend('friend.cancel', { request_id: requestId | 0 }, true)
}

export function listMyGroups() {
  return imSend('group.list', {}, true)
}

export function joinGroup(groupId) {
  return imSend('group.join', { group_id: groupId | 0 }, true)
}

export function listFriends() {
  return imSend('friend.list', {}, true)
}

export function pinConversation(conversationType, conversationId, pinned = true) {
  return imSend(
    pinned ? 'conversation.pin' : 'conversation.unpin',
    {
      conversation_type: conversationType | 0,
      conversation_id: String(conversationId || ''),
    },
    true
  )
}

export function hideConversation(conversationType, conversationId, extra = {}) {
  return imSend(
    'conversation.hide',
    Object.assign(
      {
        conversation_type: conversationType | 0,
        conversation_id: String(conversationId || ''),
      },
      extra || {}
    ),
    true
  )
}

export function recallMessage(messageId) {
  return imSend('message.recall', { message_id: messageId | 0 }, true)
}

export function fetchGroupInfo(groupId) {
  return imSend('group.info', { group_id: groupId | 0 }, true)
}

export function fetchGroupMembers(groupId, keyword = '') {
  return imSend(
    'group.members',
    { group_id: groupId | 0, keyword: String(keyword || '') },
    true
  )
}

export function setGroupMuteAll(groupId, enabled) {
  return imSend(
    'group.mute_all',
    { group_id: groupId | 0, enabled: !!enabled },
    true
  )
}

export function muteGroupMember(groupId, userId, seconds) {
  return imSend(
    'group.mute',
    {
      group_id: groupId | 0,
      user_id: userId | 0,
      seconds: seconds | 0,
    },
    true
  )
}

export function kickGroupMember(groupId, userId) {
  return imSend(
    'group.kick',
    { group_id: groupId | 0, user_id: userId | 0 },
    true
  )
}

export function updateGroup(groupId, fields = {}) {
  return imSend(
    'group.update',
    Object.assign({ group_id: groupId | 0 }, fields || {}),
    true
  )
}

export function leaveGroup(groupId) {
  return imSend('group.leave', { group_id: groupId | 0 }, true)
}

export function setGroupForbid(groupId, modes = {}, hint) {
  const payload = {
    group_id: groupId | 0,
    forbid_modes: modes || {},
  }
  if (arguments.length >= 3) {
    payload.forbid_speak_hint = String(hint == null ? '' : hint)
  }
  return imSend('group.set_forbid', payload, true)
}

export function setGroupAdmin(groupId, userId, isAdmin) {
  return imSend(
    'group.set_admin',
    {
      group_id: groupId | 0,
      user_id: userId | 0,
      is_admin: isAdmin ? 1 : 0,
    },
    true
  )
}

export function addGroupMembers(groupId, userIds = []) {
  return imSend(
    'group.add_members',
    {
      group_id: groupId | 0,
      user_ids: Array.isArray(userIds) ? userIds.map((x) => x | 0).filter(Boolean) : [],
    },
    true
  )
}

export function groupCandidates(groupId) {
  return imSend('group.candidates', { group_id: groupId | 0 }, true)
}

function sendPingResume(reason) {
  if (!isSocketSendable()) return false
  try {
    socketTask.send({
      data: JSON.stringify({
        type: 'ping',
        data: { resume: 1, reason: String(reason || '') },
        req_id: nextReqId(),
      }),
      fail() {},
    })
    return true
  } catch (e) {
    return false
  }
}

let resumeBound = false
let lastResumeAt = 0

/**
 * 手机回前台：探测 WS，必要时强制重连，并广播 im.resume 让页面补历史
 */
export function resumeFromBackground(reason) {
  if (!getToken()) return
  const now = Date.now()
  if (lastResumeAt && now - lastResumeAt < 800) return
  lastResumeAt = now
  if (reconnectTimer) {
    clearTimeout(reconnectTimer)
    reconnectTimer = null
  }
  const alive = isSocketSendable() && authed
  if (!alive) {
    imForceReconnect()
      .then(() => emit('im.resume', { reason: String(reason || ''), reconnected: true }))
      .catch(() => emit('im.resume', { reason: String(reason || ''), reconnected: false }))
    return
  }
  if (!sendPingResume(reason)) {
    imForceReconnect()
      .then(() => emit('im.resume', { reason: String(reason || ''), reconnected: true }))
      .catch(() => emit('im.resume', { reason: String(reason || ''), reconnected: false }))
    return
  }
  emit('im.resume', { reason: String(reason || ''), reconnected: false })
}

export function bindForegroundResume() {
  if (resumeBound) return
  resumeBound = true
  const onShow = (ev) => {
    try {
      if (typeof document !== 'undefined' && document.hidden) return
    } catch (e) {}
    resumeFromBackground((ev && ev.type) || 'show')
  }
  // #ifdef H5
  try {
    if (typeof document !== 'undefined') {
      document.addEventListener('visibilitychange', () => {
        if (!document.hidden) onShow({ type: 'visibilitychange' })
      })
    }
  } catch (eV) {}
  try {
    if (typeof window !== 'undefined') {
      window.addEventListener('pageshow', (ev) => onShow(ev || { type: 'pageshow' }))
      window.addEventListener('focus', () => onShow({ type: 'focus' }))
      window.addEventListener('online', () => resumeFromBackground('online'))
    }
  } catch (eW) {}
  // #endif
}

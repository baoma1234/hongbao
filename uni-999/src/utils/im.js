import { getDeviceFp, getToken } from './auth.js'
import { getImWsBase } from './config.js'

let socketTask = null
let socketOpen = false
let authed = false
let authMeta = {}
let reqSeq = 0
const pending = new Map()
const listeners = new Set()
let reconnectTimer = null
let intentionalClose = false
/** 进行中的连接 Promise，避免并发 imConnect 反复 close/open */
let connectingPromise = null
/** 等待鉴权完成的 Promise */
let authWaiters = []

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

function rejectAuthWaiters(err) {
  const list = authWaiters.slice()
  authWaiters = []
  list.forEach((w) => {
    try {
      w.reject(err)
    } catch (e) {}
  })
}

function resolveAuthWaiters() {
  const list = authWaiters.slice()
  authWaiters = []
  list.forEach((w) => {
    try {
      w.resolve(true)
    } catch (e) {}
  })
}

function waitUntilAuthed(timeoutMs = 10000) {
  if (authed && socketOpen && socketTask) return Promise.resolve(true)
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => {
      authWaiters = authWaiters.filter((w) => w.resolve !== resolve)
      reject(new Error('WS 鉴权超时'))
    }, timeoutMs)
    authWaiters.push({
      resolve(v) {
        clearTimeout(timer)
        resolve(v)
      },
      reject(err) {
        clearTimeout(timer)
        reject(err)
      },
    })
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

function canSendNow() {
  if (!socketTask || !socketOpen) return false
  // uni SocketTask 部分端有 readyState：0 CONNECTING 1 OPEN 2 CLOSING 3 CLOSED
  const rs = socketTask.readyState
  if (rs == null) return true
  return rs === 1 || rs === 'OPEN'
}

function rawSend(packet) {
  return new Promise((resolve, reject) => {
    if (!canSendNow()) {
      reject(new Error('WS 未就绪'))
      return
    }
    try {
      socketTask.send({
        data: JSON.stringify(packet),
        success() {
          resolve(true)
        },
        fail(err) {
          reject(new Error((err && err.errMsg) || '发送失败'))
        },
      })
    } catch (e) {
      reject(e)
    }
  })
}

/**
 * 确保 socket OPEN；业务包默认还要等 auth.ok
 */
export async function ensureImReady(needAuth = true) {
  const token = getToken()
  if (!token) throw new Error('未登录')
  await imConnect()
  if (needAuth && !authed) {
    await waitUntilAuthed()
  }
  if (!canSendNow()) throw new Error('WS 未连接')
  return true
}

export function imSend(type, data = {}, waitAck = false) {
  const needAuth = type !== 'auth'
  return ensureImReady(needAuth).then(() => {
    return new Promise((resolve, reject) => {
      if (!canSendNow()) {
        reject(new Error('WS 未连接'))
        return
      }
      const reqId = nextReqId()
      const packet = { type, data: data || {}, req_id: reqId }
      if (waitAck) {
        const timer = setTimeout(() => {
          pending.delete(reqId)
          reject(new Error('WS 超时: ' + type))
        }, 12000)
        pending.set(reqId, { resolve, reject, timer })
      }
      rawSend(packet)
        .then(() => {
          if (!waitAck) resolve(true)
        })
        .catch((err) => {
          if (waitAck && pending.has(reqId)) {
            const p = pending.get(reqId)
            clearTimeout(p.timer)
            pending.delete(reqId)
          }
          reject(err)
        })
    })
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
    resolveAuthWaiters()
    emit('auth.ok', authMeta)
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
    // 服务端也可能用 query token 直接鉴权；再发一轮 auth 兼容
    rawSend({
      type: 'auth',
      data: { token, device_fp: getDeviceFp() },
      req_id: nextReqId(),
    }).catch(() => {})
  })
  task.onMessage((msg) => {
    if (socketTask !== task) return
    handlePacket(msg.data)
  })
  task.onError((err) => {
    if (socketTask !== task) return
    socketOpen = false
    authed = false
    emit('socket.error', err)
    rejectAuthWaiters(new Error((err && err.errMsg) || 'WS 连接失败'))
    scheduleReconnect()
  })
  task.onClose(() => {
    if (socketTask !== task) return
    socketOpen = false
    authed = false
    authMeta = {}
    socketTask = null
    emit('socket.close', {})
    rejectAuthWaiters(new Error('WS 已关闭'))
    if (!intentionalClose) scheduleReconnect()
  })
}

export function imConnect() {
  const token = getToken()
  if (!token) {
    return Promise.reject(new Error('未登录'))
  }
  intentionalClose = false

  // 已在线
  if (socketTask && socketOpen && authed) {
    return Promise.resolve(true)
  }
  // 已在连 / 已 OPEN 等鉴权：复用同一个 Promise
  if (connectingPromise) {
    return connectingPromise
  }
  // socket 已 OPEN 但还没 auth.ok：只等鉴权，不要重建连接
  if (socketTask && socketOpen) {
    connectingPromise = waitUntilAuthed(8000)
      .then(() => true)
      .catch(() => {
        // 宽松：部分环境 query 鉴权后可能不推 auth.ok
        if (socketTask && socketOpen) {
          authed = true
          resolveAuthWaiters()
          return true
        }
        throw new Error('WS 鉴权超时')
      })
      .finally(() => {
        connectingPromise = null
      })
    return connectingPromise
  }

  // 真正新建连接（若有旧 task 先丢掉）
  if (socketTask) {
    const old = socketTask
    socketTask = null
    socketOpen = false
    authed = false
    try {
      old.close({})
    } catch (e) {}
  }

  connectingPromise = new Promise((resolve, reject) => {
    let settled = false
    const finishOk = () => {
      if (settled) return
      settled = true
      resolve(true)
    }
    const finishErr = (err) => {
      if (settled) return
      settled = true
      reject(err instanceof Error ? err : new Error(String(err || 'WS 连接失败')))
    }

    const off = onImEvent((type) => {
      if (type === 'auth.ok') {
        off()
        finishOk()
      }
    })

    try {
      const url = connectUrl()
      const task = uni.connectSocket({
        url,
        complete() {},
      })
      socketTask = task
      socketOpen = false
      authed = false
      bindSocketHandlers(task, token)
    } catch (e) {
      off()
      finishErr(e)
      return
    }

    setTimeout(() => {
      if (settled) return
      off()
      // query 鉴权场景：已 OPEN 则宽松成功
      if (socketTask && socketOpen) {
        if (!authed) {
          authed = true
          resolveAuthWaiters()
        }
        finishOk()
      } else {
        finishErr(new Error('WS 鉴权超时'))
      }
    }, 8000)
  }).finally(() => {
    connectingPromise = null
  })

  return connectingPromise
}

function scheduleReconnect() {
  if (intentionalClose || reconnectTimer) return
  if (!getToken()) return
  reconnectTimer = setTimeout(() => {
    reconnectTimer = null
    imConnect().catch(() => {})
  }, 2500)
}

export function imDisconnect() {
  intentionalClose = true
  if (reconnectTimer) {
    clearTimeout(reconnectTimer)
    reconnectTimer = null
  }
  connectingPromise = null
  pending.forEach((p) => {
    clearTimeout(p.timer)
    p.reject(new Error('WS 已关闭'))
  })
  pending.clear()
  rejectAuthWaiters(new Error('WS 已关闭'))
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

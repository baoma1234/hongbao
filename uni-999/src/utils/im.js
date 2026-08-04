import { getDeviceFp, getToken } from './auth.js'
import { getImWsBase } from './config.js'

let socketTask = null
let authed = false
let reqSeq = 0
const pending = new Map()
const listeners = new Set()
let reconnectTimer = null
let intentionalClose = false

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
  return !!authed && !!socketTask
}

export function getImStatus() {
  if (!socketTask) return 'disconnected'
  if (!authed) return 'connecting'
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

export function imSend(type, data = {}, waitAck = false) {
  return new Promise((resolve, reject) => {
    if (!socketTask) {
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
    try {
      socketTask.send({
        data: JSON.stringify(packet),
        fail(err) {
          if (waitAck && pending.has(reqId)) {
            const p = pending.get(reqId)
            clearTimeout(p.timer)
            pending.delete(reqId)
            reject(new Error((err && err.errMsg) || '发送失败'))
          }
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
    emit('auth.ok', packet.data || {})
    return
  }
  if (packet.type === 'error') {
    emit('error', packet.data || {})
    return
  }
  emit(packet.type, packet.data || {})
}

export function imConnect() {
  const token = getToken()
  if (!token) {
    return Promise.reject(new Error('未登录'))
  }
  intentionalClose = false
  if (socketTask && authed) {
    return Promise.resolve(true)
  }
  if (socketTask) {
    try {
      socketTask.close({})
    } catch (e) {}
    socketTask = null
  }
  authed = false

  return new Promise((resolve, reject) => {
    const url = connectUrl()
    let settled = false
    const off = onImEvent((type) => {
      if (type === 'auth.ok' && !settled) {
        settled = true
        off()
        resolve(true)
      }
    })

    socketTask = uni.connectSocket({
      url,
      complete() {},
    })

    socketTask.onOpen(() => {
      // 服务端也可能用 query token 直接鉴权；再发一轮 auth 兼容
      imSend('auth', { token, device_fp: getDeviceFp() }).catch(() => {})
    })
    socketTask.onMessage((msg) => {
      handlePacket(msg.data)
    })
    socketTask.onError((err) => {
      emit('socket.error', err)
      if (!settled) {
        settled = true
        off()
        reject(new Error((err && err.errMsg) || 'WS 连接失败'))
      }
      scheduleReconnect()
    })
    socketTask.onClose(() => {
      authed = false
      socketTask = null
      emit('socket.close', {})
      if (!intentionalClose) scheduleReconnect()
    })

    setTimeout(() => {
      if (!settled) {
        settled = true
        off()
        // 有的环境 query 鉴权后不推 auth.ok 到业务层——若仍连接则宽松成功
        if (socketTask) resolve(true)
        else reject(new Error('WS 鉴权超时'))
      }
    }, 8000)
  })
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
  pending.forEach((p) => {
    clearTimeout(p.timer)
    p.reject(new Error('WS 已关闭'))
  })
  pending.clear()
  authed = false
  if (socketTask) {
    try {
      socketTask.close({})
    } catch (e) {}
    socketTask = null
  }
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

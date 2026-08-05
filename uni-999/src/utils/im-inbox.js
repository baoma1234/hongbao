/**
 * 全局 IM 收件箱：不依赖消息 Tab 是否可见。
 * - 未读角标 / 会话未读数
 * - 会话列表预览刷新事件
 */
import { convKey, msgExtra } from './chat.js'
import { getActiveChat } from './chat-route.js'
import { markConversationRead, onImEvent } from './im.js'
import { getChatUnreadTotal, setChatUnreadTotal } from './tab-badge.js'

let started = false
let off = null
let myUserId = 0
/** @type {Record<string, number>} */
const unreadMap = Object.create(null)

export function setInboxMyId(uid) {
  myUserId = uid | 0
}

export function getInboxUnreadMap() {
  return unreadMap
}

export function getInboxUnread(type, id) {
  return unreadMap[convKey(type, id)] | 0
}

export function clearInboxUnread(type, id) {
  const key = convKey(type, id)
  if (!(unreadMap[key] | 0)) return
  unreadMap[key] = 0
  recomputeBadge()
  emitUnread()
}

export function syncInboxFromServerList(rows) {
  const list = Array.isArray(rows) ? rows : []
  list.forEach((it) => {
    const type = it.conversation_type | 0
    let id = ''
    if (type === 2) id = String(it.group_id || it.conversation_id || '')
    else id = String(it.conversation_id || '')
    if (!id) return
    const key = convKey(type, id)
    const server = it.unread_count | 0
    unreadMap[key] = Math.max(unreadMap[key] | 0, server)
  })
  recomputeBadge()
  emitUnread()
}

function recomputeBadge() {
  let sum = 0
  Object.keys(unreadMap).forEach((k) => {
    sum += unreadMap[k] | 0
  })
  setChatUnreadTotal(sum)
}

function emitUnread() {
  try {
    uni.$emit && uni.$emit('fanshub-inbox-unread', Object.assign({}, unreadMap))
  } catch (e) {}
}

function emitIncoming(msg) {
  try {
    uni.$emit && uni.$emit('fanshub-inbox-msg', msg)
  } catch (e) {}
}

function msgConvType(msg) {
  let type = (msg && msg.conversation_type) | 0
  if (!type) {
    if (((msg && msg.group_id) | 0) > 0) type = 2
    else type = 1
  }
  return type
}

function msgConvId(msg) {
  const type = msgConvType(msg)
  if (type === 2) return String((msg && (msg.group_id || msg.conversation_id)) || '')
  return String((msg && msg.conversation_id) || '')
}

export function matchesActiveChat(msg) {
  const room = getActiveChat()
  if (!room || !msg) return false
  const type = msgConvType(msg)
  if (type !== (room.type | 0)) return false
  if (type === 2) {
    return (
      (msg.group_id | 0) === (room.group | 0) ||
      String(msg.conversation_id || '') === String(room.id || room.group || '')
    )
  }
  return (
    String(msg.conversation_id || '') === String(room.id || '') ||
    (msg.from_user_id | 0) === (room.peer | 0) ||
    (msg.to_user_id | 0) === (room.peer | 0)
  )
}

function shouldBumpUnread(msg) {
  if (!msg) return false
  const uid = myUserId | 0
  const fromSelf = uid > 0 && (msg.from_user_id | 0) === uid
  const ex = msgExtra(msg)
  if (fromSelf && !(ex.relay_auto | 0)) return false
  if (matchesActiveChat(msg)) return false
  return true
}

function bumpUnread(msg) {
  const type = msgConvType(msg)
  const id = msgConvId(msg)
  if (!id) return
  const key = convKey(type, id)
  unreadMap[key] = (unreadMap[key] | 0) + 1
  recomputeBadge()
  emitUnread()
}

function handleIncoming(msg) {
  if (!msg) return
  emitIncoming(msg)
  if (shouldBumpUnread(msg)) bumpUnread(msg)
  else if (matchesActiveChat(msg)) {
    // 正在看该会话：尽量立刻清服务端未读
    const type = msgConvType(msg)
    const id = msgConvId(msg)
    const lastId = (msg.msg_id || msg.id) | 0
    if (id) {
      clearInboxUnread(type, id)
      markConversationRead(type, id, lastId).catch(() => null)
    }
  }
}

export function startImInbox() {
  if (started) return
  started = true
  off = onImEvent((type, data) => {
    if (type === 'private.message' || type === 'group.message' || type === 'redpacket.relay_next') {
      const msg = (data && data.message) || data
      handleIncoming(msg)
    }
  })
}

export function stopImInbox() {
  if (off) {
    off()
    off = null
  }
  started = false
}

export function inboxUnreadTotal() {
  return getChatUnreadTotal()
}

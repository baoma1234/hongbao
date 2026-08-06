/**
 * 全局 IM 收件箱：不依赖消息 Tab 是否可见。
 * - 未读角标 / 会话未读数
 * - 会话列表预览刷新事件
 * - 刚已读保护：避免 conversation.list 用服务端旧未读把角标盖回来
 */
import { convKey, msgExtra } from './chat.js'
import { getActiveChat } from './chat-route.js'
import { markConversationRead, onImEvent } from './im.js'
import { getChatUnreadTotal, setChatUnreadTotal } from './tab-badge.js'
import { playIncomingMessageSound } from './notify-sound.js'

let started = false
let off = null
let myUserId = 0
/** @type {Record<string, number>} */
const unreadMap = Object.create(null)
/** @type {Record<string, { at: number, lastId: number }>} */
const recentlyRead = Object.create(null)
const RECENT_READ_MS = 600000

export function setInboxMyId(uid) {
  myUserId = uid | 0
}

export function getInboxUnreadMap() {
  return unreadMap
}

export function getInboxUnread(type, id) {
  const key = convKey(type, id)
  if (isRecentlyReadKey(key)) return 0
  return unreadMap[key] | 0
}

export function isConversationRecentlyRead(type, id) {
  return isRecentlyReadKey(convKey(type, id))
}

function isRecentlyReadKey(key) {
  const hit = recentlyRead[key]
  if (!hit) return false
  if (Date.now() - (hit.at | 0) > RECENT_READ_MS) {
    delete recentlyRead[key]
    return false
  }
  return true
}

/**
 * 本地立刻清未读，并记录“刚已读”，防止列表刷新把角标刷回来。
 */
export function noteConversationRead(type, id, lastMsgId = 0) {
  const tid = type | 0
  const cid = String(id || '').trim()
  if (!cid) return
  const keys = [convKey(tid, cid)]
  // 群：同时清 group_id / conversation_id 两种键，避免列表与聊天页键不一致
  if (tid === 2) {
    const n = cid | 0
    if (n > 0) keys.push(convKey(2, String(n)))
  }
  const lastId = Math.max(0, lastMsgId | 0)
  const now = Date.now()
  keys.forEach((key) => {
    if (!key || key.endsWith(':')) return
    recentlyRead[key] = { at: now, lastId }
    unreadMap[key] = 0
  })
  recomputeBadge()
  emitUnread()
}

export function clearInboxUnread(type, id) {
  noteConversationRead(type, id, 0)
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
    if (isRecentlyReadKey(key)) {
      // 服务端已确认 0 → 去掉保护；否则本地保持已读
      if (server <= 0) delete recentlyRead[key]
      unreadMap[key] = 0
      it.unread_count = 0
      return
    }
    unreadMap[key] = Math.max(unreadMap[key] | 0, server)
  })
  recomputeBadge()
  emitUnread()
}

function recomputeBadge() {
  let sum = 0
  Object.keys(unreadMap).forEach((k) => {
    if (isRecentlyReadKey(k)) return
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
  // 新消息进来：取消“刚已读”保护，正常累加
  delete recentlyRead[key]
  unreadMap[key] = (unreadMap[key] | 0) + 1
  recomputeBadge()
  emitUnread()
}

function handleIncoming(msg) {
  if (!msg) return
  emitIncoming(msg)
  // 自发普通消息不响；接龙续发 / 他人消息响
  try {
    const from = (msg.from_user_id | 0) || 0
    let ex = msg.extra || {}
    if (typeof ex === 'string') {
      try {
        ex = JSON.parse(ex) || {}
      } catch (e) {
        ex = {}
      }
    }
    const relayAuto = !!(ex.relay_auto | 0)
    if (!(from && myUserId && from === myUserId && !relayAuto)) {
      playIncomingMessageSound(msg)
    }
  } catch (e) {}
  if (shouldBumpUnread(msg)) bumpUnread(msg)
  else if (matchesActiveChat(msg)) {
    const type = msgConvType(msg)
    const id = msgConvId(msg)
    const lastId = (msg.id | 0) || (msg.msg_id | 0)
    if (id) {
      noteConversationRead(type, id, lastId)
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

/**
 * 在线本地「仿极光」推送（仅应用内顶部横幅）
 * - 不调用 plus.push，避免未勾选 Push 模块时弹出 HTML5+ Runtime 提示
 * - 离线系统推送走极光原生插件 luanqing-jgpush
 */
import { previewText } from './chat.js'
import { buildChatUrl, getActiveChat, getHashRoutePath } from './chat-route.js'
import { isGroupNotifyMuted } from './group-notify-mute.js'
import { isMsgMuted } from './app-prefs.js'
import { getSafeAreaInsets } from './safe-area.js'

/** @type {Array<(payload: object|null) => void>} */
const listeners = []
let lastShowAt = 0
let hideTimer = null

export function onLocalPush(fn) {
  if (typeof fn !== 'function') return () => {}
  listeners.push(fn)
  return () => {
    const i = listeners.indexOf(fn)
    if (i >= 0) listeners.splice(i, 1)
  }
}

function emit(payload) {
  listeners.slice().forEach((fn) => {
    try {
      fn(payload)
    } catch (e) {}
  })
}

function currentRoutePath() {
  // #ifdef H5
  const hashPath = getHashRoutePath()
  if (hashPath) return hashPath
  // #endif
  try {
    const pages = typeof getCurrentPages === 'function' ? getCurrentPages() : []
    const cur = pages && pages.length ? pages[pages.length - 1] : null
    if (!cur) return ''
    return String(cur.route || (cur.$page && cur.$page.fullPath) || '').replace(/^\//, '')
  } catch (e) {
    return ''
  }
}

/** 是否在红宝「会话列表」页（messages Tab 主页） */
export function isOnConversationListPage() {
  const path = currentRoutePath()
  if (!path) return false
  return (
    path === 'pages/messages/messages' ||
    path.indexOf('pages/messages/messages?') === 0
  )
}

function msgConvType(msg) {
  let type = (msg && msg.conversation_type) | 0
  if (!type) {
    if (((msg && msg.group_id) | 0) > 0) type = 2
    else type = 1
  }
  return type
}

function matchesActiveChat(msg) {
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

function pushTitle(msg) {
  const type = msgConvType(msg)
  if (type === 2) {
    const gName = String(
      (msg && (msg.group_name || msg.conversation_title || msg.title)) || ''
    ).trim()
    const nick = String((msg && (msg.nickname || msg.from_nickname)) || '').trim()
    if (gName && nick) return gName
    if (gName) return gName
    if (nick) return nick
    return '群消息'
  }
  const nick = String((msg && (msg.nickname || msg.from_nickname || msg.peer_nickname)) || '').trim()
  if (nick) return nick
  const uid = (msg && msg.from_user_id) | 0
  return uid ? 'ID' + uid : '新消息'
}

function pushBody(msg) {
  const type = msgConvType(msg)
  const text = previewText(msg)
  if (type === 2) {
    const nick = String((msg && (msg.nickname || msg.from_nickname)) || '').trim()
    if (nick && text && text.indexOf(nick) !== 0) return nick + ': ' + text
  }
  return text || '发来一条消息'
}

function buildChatPayload(msg) {
  const type = msgConvType(msg)
  if (type === 2) {
    const gid = (msg.group_id | 0) || (String(msg.conversation_id || '') | 0)
    const title = String(msg.group_name || msg.conversation_title || msg.title || '群聊')
    return {
      type: 2,
      id: String(gid),
      group: gid,
      peer: 0,
      title,
      nickname: '',
    }
  }
  const peer = (msg.from_user_id | 0) || 0
  const title = pushTitle(msg)
  return {
    type: 1,
    id: String(msg.conversation_id || ''),
    peer,
    group: 0,
    title,
    nickname: title,
  }
}

/**
 * @param {object} msg
 * @param {{ myUserId?: number }} [opts]
 */
export function maybeShowLocalPush(msg, opts = {}) {
  if (!msg) return false
  if (isMsgMuted()) return false

  const myUserId = (opts.myUserId | 0) || 0
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
  if (from && myUserId && from === myUserId && !relayAuto) return false

  const type = msgConvType(msg)
  const gid = type === 2 ? String(msg.group_id || msg.conversation_id || '') : ''
  if (type === 2 && gid && isGroupNotifyMuted(gid)) return false

  if (matchesActiveChat(msg)) return false
  if (isOnConversationListPage()) return false

  const now = Date.now()
  if (lastShowAt && now - lastShowAt < 800) return false
  lastShowAt = now

  const title = pushTitle(msg)
  const body = pushBody(msg)
  const chat = buildChatPayload(msg)
  const inset = getSafeAreaInsets()
  const payload = {
    title,
    body,
    chat,
    topPx: Math.max(8, (inset && inset.top) | 0) + 8,
    ts: now,
  }

  if (hideTimer) {
    clearTimeout(hideTimer)
    hideTimer = null
  }
  emit(payload)
  hideTimer = setTimeout(() => {
    hideTimer = null
    emit(null)
  }, 4500)

  return true
}

export function openLocalPushChat(chat) {
  const url = buildChatUrl(chat)
  if (!url) return
  try {
    uni.navigateTo({ url })
  } catch (e) {
    try {
      uni.reLaunch({ url })
    } catch (e2) {}
  }
}

export function dismissLocalPush() {
  if (hideTimer) {
    clearTimeout(hideTimer)
    hideTimer = null
  }
  emit(null)
}

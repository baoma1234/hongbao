/** 会话 / 消息展示辅助（对齐 888 preview） */

export function convKey(type, id) {
  return String(type | 0) + ':' + String(id || '')
}

export function msgType(m) {
  if (!m) return 1
  return (m.msg_type | 0) || (m.type | 0) || 1
}

export function msgExtra(m) {
  if (!m) return {}
  const e = m.extra
  if (!e) return {}
  if (typeof e === 'string') {
    try {
      return JSON.parse(e) || {}
    } catch (err) {
      return {}
    }
  }
  return e
}

export function packetTypeLabel(t) {
  const n = t | 0
  if (n === 2) return '拼手气'
  if (n === 3) return '埋雷'
  if (n === 4) return '随机'
  return '普通'
}

export function isRecalled(m) {
  return !!(m && (m.status | 0) === 2)
}

export function isSystemMsg(m) {
  if (!m) return false
  const mt = msgType(m)
  return mt === 3 || mt === 99
}

export function recallTip(m, myId, isPrivate) {
  const mine = (m && (m.from_user_id | 0)) === (myId | 0)
  if (isPrivate) return mine ? '你删除了一条消息' : '对方删除了一条消息'
  return mine ? '你撤回了一条消息' : '对方撤回了一条消息'
}

export function previewText(last) {
  if (!last) return '暂无消息'
  if (typeof last === 'string') return last
  if (isRecalled(last)) return '[已撤回]'
  const mt = msgType(last)
  const ex = msgExtra(last)
  if (mt === 2) {
    const bless = ex.blessing || '恭喜发财'
    return '[红包] ' + bless
  }
  if (mt === 4) {
    return '[图片]'
  }
  if (mt === 5) {
    return '[视频]'
  }
  if (mt === 6) {
    return '[表情]'
  }
  if (mt === 7) {
    return '[文件]'
  }
  if (mt === 8) {
    return last.content || '[转账]'
  }
  if (mt === 99 || (last.content && String(last.content).indexOf('[系统]') === 0)) {
    return last.content || '[系统消息]'
  }
  return last.content || last.text || '暂无消息'
}

export function formatConvTime(ts) {
  const t = Number(ts) || 0
  if (!t) return ''
  const d = new Date(t < 1e12 ? t * 1000 : t)
  const now = new Date()
  const pad = (n) => (n < 10 ? '0' + n : '' + n)
  const hm = pad(d.getHours()) + ':' + pad(d.getMinutes())
  if (
    d.getFullYear() === now.getFullYear() &&
    d.getMonth() === now.getMonth() &&
    d.getDate() === now.getDate()
  ) {
    return hm
  }
  return pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + hm
}

export function displayTitle(item) {
  if (!item) return '会话'
  if (item.remark) return String(item.remark)
  if (item.title) return String(item.title)
  if (item.peer_nickname) return String(item.peer_nickname)
  if ((item.conversation_type | 0) === 2) return '群 ' + (item.group_id || item.conversation_id || '')
  return '用户' + (item.peer_user_id || '')
}

export function resolveConvId(item) {
  const type = item.conversation_type | 0
  if (type === 2) return String(item.group_id || item.conversation_id || '')
  return String(item.conversation_id || '')
}

export function avatarLetter(title) {
  const s = String(title || '?').trim()
  return s ? s.charAt(0) : '?'
}

/**
 * 微信群同款：引用回复 / @成员 工具
 */
import { msgExtra, msgType, previewText } from './chat.js'

/** 从消息构建 reply_to 快照（发给服务端 + 本地预览） */
export function buildReplySnapshot(m) {
  if (!m) return null
  const id = (m.id | 0) || 0
  const mid = String(m.msg_id || '')
  if (!id && !mid) return null
  const nick =
    String(m.from_nickname || (m.from_user && m.from_user.nickname) || '').trim() ||
    ('用户' + ((m.from_user_id | 0) || ''))
  return {
    id,
    msg_id: mid,
    from_user_id: (m.from_user_id | 0) || 0,
    nickname: nick.slice(0, 64),
    content: String(previewText(m) || '').slice(0, 200),
    msg_type: msgType(m) || 1,
  }
}

export function msgReplyTo(m) {
  const ex = msgExtra(m)
  const rt = ex && ex.reply_to
  if (!rt || typeof rt !== 'object') return null
  if (!(rt.id | 0) && !String(rt.msg_id || '').trim()) return null
  return rt
}

export function msgAtUsers(m) {
  const ex = msgExtra(m)
  const list = ex && ex.at_users
  return Array.isArray(list) ? list : []
}

export function msgAtAll(m) {
  const ex = msgExtra(m)
  return !!(ex && ex.at_all)
}

/** 本条是否 @ 了我（含全体） */
export function messageMentionsMe(m, myUid) {
  const uid = myUid | 0
  if (!uid || !m) return false
  if (msgAtAll(m)) return true
  return msgAtUsers(m).some((u) => ((u && (u.user_id || u.id)) | 0) === uid)
}

/**
 * 检测输入框末尾是否正在输入 @xxx（用于弹出成员选择）
 * @returns {{ start:number, query:string }|null}
 */
export function detectAtTrigger(text) {
  const s = String(text || '')
  // 取最后一个 @ / ＠，且其后不含空格/换行
  const re = /(?:^|[\s\u3000])([@＠])([^\s@＠]*)$/
  const m = s.match(re)
  if (!m) {
    // 整段以 @ 开头
    const m2 = s.match(/^([@＠])([^\s@＠]*)$/)
    if (!m2) return null
    return { start: 0, query: String(m2[2] || '') }
  }
  const atToken = m[1]
  const query = String(m[2] || '')
  const start = s.length - atToken.length - query.length
  return { start, query }
}

/** 用选中的昵称替换触发段 */
export function applyAtPick(text, trigger, nickname) {
  const s = String(text || '')
  const nick = String(nickname || '').trim() || '用户'
  if (!trigger || trigger.start < 0) {
    return (s + (s && !/\s$/.test(s) ? ' ' : '') + '@' + nick + ' ').trimStart()
  }
  const head = s.slice(0, trigger.start)
  const tail = '' // 触发段在末尾
  const insert = '@' + nick + ' '
  return head + insert + tail
}

/**
 * 把正文拆成普通 / 提及片段，供气泡高亮
 * mentions: [{user_id, nickname}] + atAll
 */
export function splitMentionParts(text, mentions, atAll) {
  const raw = String(text || '')
  if (!raw) return [{ t: 'text', v: '' }]

  const tokens = []
  if (atAll) {
    tokens.push({ key: '@全体成员', me: true, all: true })
    tokens.push({ key: '@所有人', me: true, all: true })
  }
  ;(mentions || []).forEach((u) => {
    const nick = String((u && u.nickname) || '').trim()
    if (!nick) return
    tokens.push({
      key: '@' + nick,
      user_id: (u.user_id | 0) || (u.id | 0) || 0,
      me: !!u._me,
    })
  })
  // 按 key 长度降序，避免短昵称抢先匹配
  tokens.sort((a, b) => b.key.length - a.key.length)
  if (!tokens.length) {
    return [{ t: 'text', v: raw }]
  }

  const parts = []
  let i = 0
  while (i < raw.length) {
    let hit = null
    for (let t = 0; t < tokens.length; t++) {
      const tok = tokens[t]
      if (raw.substr(i, tok.key.length) === tok.key) {
        hit = tok
        break
      }
      // 全角 ＠
      const alt = '＠' + tok.key.slice(1)
      if (raw.substr(i, alt.length) === alt) {
        hit = Object.assign({}, tok, { key: alt })
        break
      }
    }
    if (hit) {
      parts.push({
        t: 'mention',
        v: hit.key,
        user_id: hit.user_id || 0,
        me: !!hit.me,
        all: !!hit.all,
      })
      i += hit.key.length
      continue
    }
    // 累积普通文本
    let j = i + 1
    while (j < raw.length) {
      let any = false
      for (let t = 0; t < tokens.length; t++) {
        const tok = tokens[t]
        if (
          raw.substr(j, tok.key.length) === tok.key ||
          raw.substr(j, tok.key.length) === '＠' + tok.key.slice(1)
        ) {
          any = true
          break
        }
      }
      if (any) break
      j++
    }
    parts.push({ t: 'text', v: raw.slice(i, j) })
    i = j
  }
  return parts.length ? parts : [{ t: 'text', v: raw }]
}

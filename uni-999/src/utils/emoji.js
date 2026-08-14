/** Emoji：优先加载 emoji-tree.json（分类），失败回退常用列表；面板用 Twemoji 图避免灰块/方框 */
import { assetBase } from './i18n.js'

export const COMMON_EMOJIS = [
  '😀', '😁', '😂', '🤣', '😃', '😄', '😅', '😆',
  '😉', '😊', '😋', '😎', '😍', '😘', '🥰', '😗',
  '😙', '😚', '🙂', '🤗', '🤩', '🤔', '🤨', '😐',
  '😑', '😶', '🙄', '😏', '😣', '😥', '😮', '🤐',
  '😯', '😪', '😫', '🥱', '😴', '😌', '😛', '😜',
  '😝', '🤤', '😒', '😓', '😔', '😕', '🙃', '🤑',
  '😲', '☹️', '🙁', '😖', '😞', '😟', '😤', '😢',
  '😭', '😦', '😧', '😨', '😩', '🤯', '😬', '😰',
  '😱', '🥵', '🥶', '😳', '🤪', '😵', '😡', '😠',
  '🤬', '😷', '🤒', '🤕', '🤢', '🤮', '🥴', '😇',
  '🥳', '🥺', '🤠', '🤡', '🤥', '🤫', '🤭', '🧐',
  '🤓', '😈', '👿', '👹', '👺', '💀', '👻', '👽',
  '🤖', '💩', '😺', '😸', '😹', '😻', '😼', '😽',
  '❤️', '🧡', '💛', '💚', '💙', '💜', '🖤', '🤍',
  '💔', '❣️', '💕', '💞', '💓', '💗', '💖', '💘',
  '💯', '💢', '💥', '💫', '💦', '🔥', '⭐', '🌟',
  '✨', '⚡', '☀️', '🌙', '🌈', '👋', '🤚', '🖐️',
  '✋', '🖖', '👌', '🤏', '✌️', '🤞', '🤟', '🤘',
  '🤙', '👈', '👉', '👆', '👇', '☝️', '👍', '👎',
  '✊', '👊', '🤛', '🤜', '👏', '🙌', '👐', '🤲',
  '🤝', '🙏', '💪', '🧧', '🎁', '🎈', '🎉', '🎊',
  '🏆', '🥇', '💰', '💵', '💴', '💶', '💷', '💸',
  '💳', '💎', '🔔',
]

/** Twemoji 72px CDN（系统字体缺字时面板仍显示彩色图） */
export const TWEMOJI_CDN =
  'https://cdnjs.cloudflare.com/ajax/libs/twemoji/14.0.2/72x72/'

let treeCache = null
let treePromise = null

const SKIN = { '1F3FB': 1, '1F3FC': 1, '1F3FD': 1, '1F3FE': 1, '1F3FF': 1 }
const IGNORE = { FE0E: 1, FE0F: 1, '20E3': 1 }

/**
 * Twemoji 文件名：小写 hex，用 - 连接；保留 FE0F / 200D（复合表情需要）
 */
export function emojiTwemojiKey(char) {
  const raw = String(char || '')
  if (!raw) return ''
  const cps = []
  for (const ch of Array.from(raw)) {
    const cp = ch.codePointAt(0)
    if (!cp && cp !== 0) continue
    if (cp === 0xfe0e) continue
    cps.push(cp.toString(16))
  }
  return cps.join('-')
}

export function emojiTwemojiUrl(char) {
  const key = emojiTwemojiKey(char)
  if (!key) return ''
  return TWEMOJI_CDN + key + '.png'
}

/** 过滤肤色 / ZWJ / Extended-A / 键帽数字 / 易豆腐块条目 */
function emojiCodesUnsupported(codes) {
  const parts = String(codes || '').split(/[^0-9A-Fa-f]+/).filter(Boolean)
  if (!parts.length) return true
  const core = []
  for (let i = 0; i < parts.length; i++) {
    const hex = parts[i].toUpperCase()
    if (hex === '200D') return true
    if (SKIN[hex]) return true
    if (IGNORE[hex]) continue
    const cp = parseInt(hex, 16)
    if (!Number.isFinite(cp)) continue
    // ASCII 键帽 / 数字 / 井号：系统常显示成灰框
    if (cp < 0xa0) return true
    // Symbols 杂项：易灰、易豆腐
    if (cp >= 0x2190 && cp <= 0x21ff) return true
    if (cp >= 0x2300 && cp <= 0x23ff) return true
    if (cp >= 0x25a0 && cp <= 0x25ff) return true
    if (cp >= 0x2600 && cp <= 0x26ff) {
      // 保留少量常见彩色符号（太阳/星等在 COMMON 里），树里杂项符号跳过
      const keep = {
        0x2600: 1,
        0x2601: 1,
        0x2614: 1,
        0x2615: 1,
        0x26a1: 1,
        0x26c4: 1,
        0x26c5: 1,
        0x26f2: 1,
        0x26f3: 1,
        0x26f5: 1,
        0x26fa: 1,
        0x26fd: 1,
        0x2668: 1,
        0x267b: 1,
        0x267f: 1,
        0x2693: 1,
        0x26a0: 1,
        0x26bd: 1,
        0x26be: 1,
        0x26f0: 1,
      }
      if (!keep[cp]) return true
    }
    if (cp >= 0x2700 && cp <= 0x27bf) {
      const keep = {
        0x2702: 1,
        0x2705: 1,
        0x2708: 1,
        0x2709: 1,
        0x270a: 1,
        0x270b: 1,
        0x270c: 1,
        0x270f: 1,
        0x2712: 1,
        0x2714: 1,
        0x2716: 1,
        0x271d: 1,
        0x2721: 1,
        0x2728: 1,
        0x2733: 1,
        0x2734: 1,
        0x2744: 1,
        0x2747: 1,
        0x274c: 1,
        0x274e: 1,
        0x2753: 1,
        0x2754: 1,
        0x2755: 1,
        0x2757: 1,
        0x2763: 1,
        0x2764: 1,
        0x2795: 1,
        0x2796: 1,
        0x2797: 1,
        0x27a1: 1,
        0x27b0: 1,
        0x27bf: 1,
      }
      if (!keep[cp]) return true
    }
    if (cp >= 0x1fa00 && cp <= 0x1faff) return true
    if (cp === 0x1f90c) return true
    core.push(hex)
  }
  if (core.length > 2) return true
  if (core.length === 2) {
    const a = parseInt(core[0], 16)
    const b = parseInt(core[1], 16)
    const isFlag = a >= 0x1f1e6 && a <= 0x1f1ff && b >= 0x1f1e6 && b <= 0x1f1ff
    // 国旗双字母：多数 Android WebView 显示为两字母灰框，整组跳过
    if (isFlag) return true
    return true
  }
  return false
}

function groupLabel(group, idx) {
  if (!group) return '分组' + (idx + 1)
  const i18n = group.name_i18n || {}
  return String(i18n.zh_CN || i18n.zh || group.name || group.label || group.id || ('分组' + (idx + 1)))
}

function isSkippedGroup(group) {
  const gid = String((group && (group.id || group.name)) || '').toLowerCase()
  const gzh = String((group && group.name_i18n && (group.name_i18n.zh_CN || group.name_i18n.zh)) || '')
  if (gid.indexOf('component') >= 0 || gzh.indexOf('成分') >= 0) return true
  // 符号 / 旗帜：系统字体大量豆腐块或灰框
  if (gid.indexOf('symbol') >= 0 || gzh.indexOf('符号') >= 0) return true
  if (gid.indexOf('flag') >= 0 || gzh.indexOf('旗帜') >= 0) return true
  return false
}

function flattenGroup(group) {
  const out = []
  if (!group || !Array.isArray(group.list)) return out
  group.list.forEach((sub) => {
    if (!sub) return
    if (Array.isArray(sub.list)) {
      sub.list.forEach((item) => {
        if (item && item.char && !emojiCodesUnsupported(item.codes)) {
          out.push(String(item.char))
        }
      })
    } else if (sub.char && !emojiCodesUnsupported(sub.codes)) {
      out.push(String(sub.char))
    }
  })
  return out
}

/**
 * @returns {Promise<{ groups: Array<{ id: string, name: string, chars: string[] }>, flat: string[] }>}
 */
export async function loadEmojiTree() {
  if (treeCache) return treeCache
  if (treePromise) return treePromise
  treePromise = new Promise((resolve) => {
    uni.request({
      url: assetBase() + 'static/data/emoji-tree.json',
      method: 'GET',
      success: (r) => {
        try {
          const tree = (r && r.data) || []
          if (!Array.isArray(tree) || !tree.length) throw new Error('empty')
          const groups = []
          const flat = []
          const seen = {}
          // 常用置顶：跨端最稳
          groups.push({ id: 'common', name: '常用', chars: COMMON_EMOJIS.slice() })
          COMMON_EMOJIS.forEach((c) => {
            seen[c] = 1
            flat.push(c)
          })
          tree.forEach((g, idx) => {
            if (isSkippedGroup(g)) return
            const chars = flattenGroup(g).filter((c) => !seen[c])
            if (!chars.length) return
            const name = groupLabel(g, idx)
            groups.push({ id: String((g && g.id) || idx), name, chars })
            chars.forEach((c) => {
              if (seen[c]) return
              seen[c] = 1
              flat.push(c)
            })
          })
          if (!groups.length) throw new Error('empty')
          treeCache = { groups, flat }
          resolve(treeCache)
        } catch (e) {
          treeCache = {
            groups: [{ id: 'common', name: '常用', chars: COMMON_EMOJIS.slice() }],
            flat: COMMON_EMOJIS.slice(),
          }
          resolve(treeCache)
        }
      },
      fail: () => {
        treeCache = {
          groups: [{ id: 'common', name: '常用', chars: COMMON_EMOJIS.slice() }],
          flat: COMMON_EMOJIS.slice(),
        }
        resolve(treeCache)
      },
    })
  })
  return treePromise
}

/** Emoji：优先加载 emoji-tree.json（分类），失败回退常用列表 */
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

let treeCache = null
let treePromise = null

const SKIN = { '1F3FB': 1, '1F3FC': 1, '1F3FD': 1, '1F3FE': 1, '1F3FF': 1 }
const IGNORE = { FE0E: 1, FE0F: 1, '20E3': 1 }

/** 过滤肤色 / ZWJ / Extended-A 等易乱码或刷屏条目 */
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
    if (!cp && cp !== 0) continue
    if (cp >= 0x1FA00 && cp <= 0x1FAFF) return true
    if (cp === 0x1F90C) return true
    core.push(hex)
  }
  if (core.length > 2) return true
  if (core.length === 2) {
    const a = parseInt(core[0], 16)
    const b = parseInt(core[1], 16)
    const isFlag = a >= 0x1F1E6 && a <= 0x1F1FF && b >= 0x1F1E6 && b <= 0x1F1FF
    if (!isFlag) return true
  }
  return false
}

function groupLabel(group, idx) {
  if (!group) return '分组' + (idx + 1)
  const i18n = group.name_i18n || {}
  return String(i18n.zh_CN || i18n.zh || group.name || group.label || group.id || ('分组' + (idx + 1)))
}

function isComponentGroup(group) {
  const gid = String((group && (group.id || group.name)) || '').toLowerCase()
  const gzh = String((group && group.name_i18n && (group.name_i18n.zh_CN || group.name_i18n.zh)) || '')
  return gid.indexOf('component') >= 0 || gzh.indexOf('成分') >= 0
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
          tree.forEach((g, idx) => {
            if (isComponentGroup(g)) return
            const chars = flattenGroup(g)
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

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

function flattenGroup(group) {
  const out = []
  if (!group || !Array.isArray(group.list)) return out
  group.list.forEach((sub) => {
    if (!sub) return
    if (Array.isArray(sub.list)) {
      sub.list.forEach((item) => {
        if (item && item.char) out.push(String(item.char))
      })
    } else if (sub.char) {
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
            const chars = flattenGroup(g)
            if (!chars.length) return
            const name = String((g && (g.name || g.label || g.id)) || '分组' + (idx + 1))
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

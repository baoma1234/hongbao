/**
 * 聊天图片复制 / 粘贴：红宝内已有 /uploads 图可复用路径，免二次上传
 */
import { msgExtra, publicUrl } from './chat.js'

export const CHAT_IMG_CLIP_MARKER = 'FANSHUB_IMG_V1'
const STORAGE_KEY = 'fanshub_copied_chat_images'

/** 从任意 URL / 路径提取 IM 可用的 /uploads/... 相对路径 */
export function toUploadsPath(raw) {
  const s = String(raw || '').trim()
  if (!s) return ''
  if (s.indexOf('/uploads/') === 0) {
    return s.split('?')[0].split('#')[0]
  }
  const idx = s.indexOf('/uploads/')
  if (idx >= 0) {
    try {
      const u = new URL(s)
      if (u.pathname && u.pathname.indexOf('/uploads/') === 0) {
        return u.pathname
      }
    } catch (e) {
      /* ignore */
    }
    return s.slice(idx).split('?')[0].split('#')[0]
  }
  return ''
}

/**
 * 从图片消息提取可复用条目
 * @returns {{ url: string, fullurl: string, preview: string }[]}
 */
export function extractReusableImagesFromMsg(m) {
  const ex = msgExtra(m) || {}
  const out = []
  const seen = {}
  const pushPair = (pathRaw, fullRaw) => {
    let path = toUploadsPath(pathRaw) || toUploadsPath(fullRaw)
    if (!path) return
    const full = String(fullRaw || '').trim() || publicUrl(path) || path
    if (seen[path]) return
    seen[path] = 1
    out.push({
      url: path,
      fullurl: full,
      preview: publicUrl(full) || publicUrl(path) || full || path,
    })
  }

  if (Array.isArray(ex.images) && ex.images.length) {
    for (let i = 0; i < ex.images.length && out.length < 5; i++) {
      const img = ex.images[i]
      if (typeof img === 'string') {
        pushPair(img, img)
      } else if (img && typeof img === 'object') {
        pushPair(img.url || '', img.fullurl || img.url || '')
      }
    }
  }
  if (!out.length && Array.isArray(ex.image_urls)) {
    const fulls = Array.isArray(ex.image_fullurls) ? ex.image_fullurls : []
    for (let i = 0; i < ex.image_urls.length && out.length < 5; i++) {
      pushPair(ex.image_urls[i], fulls[i] || '')
    }
  }
  if (!out.length) {
    pushPair(ex.url || '', ex.fullurl || ex.url || '')
  }
  return out
}

export function serializeChatImageClipboard(items) {
  const list = Array.isArray(items) ? items : []
  const lines = [CHAT_IMG_CLIP_MARKER]
  for (let i = 0; i < list.length; i++) {
    const it = list[i]
    if (!it || !it.url) continue
    lines.push(String(it.url) + '|' + String(it.fullurl || ''))
  }
  return lines.join('\n')
}

export function parseChatImageClipboard(text) {
  const raw = String(text || '').trim()
  if (!raw || raw.indexOf(CHAT_IMG_CLIP_MARKER) !== 0) return null
  const lines = raw.split(/\r?\n/)
  const out = []
  const seen = {}
  for (let i = 1; i < lines.length; i++) {
    const line = String(lines[i] || '').trim()
    if (!line) continue
    const pipe = line.indexOf('|')
    const pathRaw = pipe >= 0 ? line.slice(0, pipe) : line
    const fullRaw = pipe >= 0 ? line.slice(pipe + 1) : ''
    const path = toUploadsPath(pathRaw) || toUploadsPath(fullRaw)
    if (!path || seen[path]) continue
    seen[path] = 1
    const full = String(fullRaw || '').trim() || publicUrl(path) || path
    out.push({
      url: path,
      fullurl: full,
      preview: publicUrl(full) || publicUrl(path) || full || path,
    })
  }
  return out.length ? out : null
}

export function setCopiedChatImages(items) {
  const list = Array.isArray(items) ? items.filter((x) => x && x.url) : []
  try {
    uni.setStorageSync(STORAGE_KEY, JSON.stringify(list))
  } catch (e) {
    /* ignore */
  }
  return list
}

export function getCopiedChatImages() {
  try {
    const raw = uni.getStorageSync(STORAGE_KEY)
    if (!raw) return []
    const arr = typeof raw === 'string' ? JSON.parse(raw) : raw
    if (!Array.isArray(arr)) return []
    return arr.filter((x) => x && x.url)
  } catch (e) {
    return []
  }
}

/**
 * 写入系统剪贴板（标记文本），并缓存到本地，便于 App/H5 粘贴识别
 */
export function copyChatImagesToClipboard(items) {
  const list = setCopiedChatImages(items)
  if (!list.length) {
    return Promise.reject(new Error('没有可复制的图片'))
  }
  const payload = serializeChatImageClipboard(list)
  return new Promise((resolve, reject) => {
    uni.setClipboardData({
      data: payload,
      // #ifdef MP
      showToast: false,
      // #endif
      success: () => resolve(list),
      fail: (err) => {
        // 系统剪贴板失败仍保留本地缓存，输入框旁可粘贴
        resolve(list)
      },
    })
  })
}

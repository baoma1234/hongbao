/**
 * 聊天图片复制 / 粘贴：
 * - 红宝内 /uploads 图可复用路径，免二次上传
 * - 电脑端截图 / 资源管理器复制 / 网页复制：从 clipboardData 取图文件并上传
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
      const u = new URL(s, typeof location !== 'undefined' ? location.href : undefined)
      if (u.pathname && u.pathname.indexOf('/uploads/') === 0) {
        return u.pathname.split('?')[0]
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

function isImageFile(f) {
  if (!f) return false
  const t = String(f.type || '').toLowerCase()
  if (t.indexOf('image/') === 0) return true
  const n = String(f.name || '').toLowerCase()
  return /\.(png|jpe?g|gif|webp|bmp|heic|heif)$/i.test(n)
}

function pushUniqueFile(files, f, seen) {
  if (!f || !isImageFile(f)) return
  const key = (f.name || '') + '|' + (f.size || 0) + '|' + (f.lastModified || 0) + '|' + (f.type || '')
  if (seen[key]) return
  seen[key] = 1
  files.push(f)
}

/**
 * 从 DataTransfer / ClipboardData 收集图片 File（截图、资源管理器、网页拖放）
 * @param {DataTransfer|ClipboardEvent['clipboardData']|null} dt
 * @returns {File[]}
 */
export function collectImageFilesFromDataTransfer(dt) {
  const files = []
  const seen = {}
  if (!dt) return files
  try {
    const items = dt.items
    if (items && items.length) {
      for (let i = 0; i < items.length; i++) {
        const it = items[i]
        if (!it) continue
        if (it.kind === 'file' && String(it.type || '').indexOf('image/') === 0) {
          const f = typeof it.getAsFile === 'function' ? it.getAsFile() : null
          pushUniqueFile(files, f, seen)
        }
      }
    }
  } catch (e) {
    /* ignore */
  }
  try {
    const list = dt.files
    if (list && list.length) {
      for (let i = 0; i < list.length; i++) {
        pushUniqueFile(files, list[i], seen)
      }
    }
  } catch (e2) {
    /* ignore */
  }
  return files
}

/**
 * data URL → File（网页复制内嵌图）
 * @returns {File|null}
 */
export function dataUrlToImageFile(dataUrl, nameHint) {
  const raw = String(dataUrl || '')
  const m = /^data:(image\/[a-z0-9.+-]+);base64,([\s\S]+)$/i.exec(raw)
  if (!m) return null
  const mime = m[1].toLowerCase()
  const b64 = m[2].replace(/\s+/g, '')
  try {
    const bin = atob(b64)
    const len = bin.length
    const bytes = new Uint8Array(len)
    for (let i = 0; i < len; i++) bytes[i] = bin.charCodeAt(i)
    const ext = mime.split('/')[1] || 'png'
    const name = nameHint || 'paste.' + ext.replace('jpeg', 'jpg')
    return new File([bytes], name, { type: mime })
  } catch (e) {
    return null
  }
}

/**
 * 从 text/html 剪贴板解析：红宝复用路径 + data URL 文件
 * @returns {{ reuse: {url,fullurl,preview}[], files: File[] }}
 */
export function extractImagesFromClipboardHtml(html) {
  const reuse = []
  const files = []
  const seenPath = {}
  const raw = String(html || '')
  if (!raw || (raw.indexOf('<img') < 0 && raw.indexOf('data:image/') < 0 && raw.indexOf('/uploads/') < 0)) {
    return { reuse, files }
  }
  const srcRe = /(?:src|data-src)\s*=\s*(["'])(.*?)\1/gi
  let m
  let idx = 0
  while ((m = srcRe.exec(raw)) && idx < 8) {
    const src = String(m[2] || '')
      .trim()
      .replace(/&amp;/g, '&')
    if (!src) continue
    idx += 1
    const path = toUploadsPath(src)
    if (path) {
      if (seenPath[path]) continue
      seenPath[path] = 1
      const full = publicUrl(path) || path
      reuse.push({
        url: path,
        fullurl: full,
        preview: publicUrl(full) || publicUrl(path) || full,
      })
      continue
    }
    if (src.indexOf('data:image/') === 0) {
      const f = dataUrlToImageFile(src, 'paste-html-' + idx + '.png')
      if (f) files.push(f)
    }
  }
  // 无 img 标签时：裸 data URL
  if (!reuse.length && !files.length && raw.indexOf('data:image/') === 0) {
    const f = dataUrlToImageFile(raw.slice(0, 2e6), 'paste.png')
    if (f) files.push(f)
  }
  return { reuse, files }
}

/**
 * 解析粘贴纯文本：标记 / 单独一行的 /uploads 或图片 URL
 * @returns {{ reuse: object[], markerHit: boolean }}
 */
export function extractImagesFromClipboardText(text) {
  const raw = String(text || '').trim()
  const reuse = []
  if (!raw) return { reuse, markerHit: false }
  if (raw.indexOf(CHAT_IMG_CLIP_MARKER) === 0) {
    const parsed = parseChatImageClipboard(raw) || []
    return { reuse: parsed, markerHit: true }
  }
  // 整段就是一条 uploads / 图片链接
  const path = toUploadsPath(raw)
  if (path) {
    const full = publicUrl(path) || path
    reuse.push({ url: path, fullurl: full, preview: publicUrl(full) || full })
    return { reuse, markerHit: false }
  }
  if (/^https?:\/\/.+\.(png|jpe?g|gif|webp|bmp)(\?.*)?$/i.test(raw) && toUploadsPath(raw)) {
    const p2 = toUploadsPath(raw)
    const full = publicUrl(p2) || raw
    reuse.push({ url: p2, fullurl: full, preview: publicUrl(full) || full })
  }
  return { reuse, markerHit: false }
}

/**
 * 统一消化一次粘贴 / 拖放的 DataTransfer
 * @returns {{ reuse: object[], files: File[], consumedText: boolean }}
 */
export function digestClipboardPayload(dt) {
  const empty = { reuse: [], files: [], consumedText: false }
  if (!dt) return empty
  let plain = ''
  let html = ''
  try {
    plain = (dt.getData && dt.getData('text/plain')) || ''
  } catch (e) {
    plain = ''
  }
  try {
    html = (dt.getData && dt.getData('text/html')) || ''
  } catch (e2) {
    html = ''
  }

  const fromText = extractImagesFromClipboardText(plain)
  if (fromText.reuse.length) {
    return { reuse: fromText.reuse, files: [], consumedText: true }
  }

  const files = collectImageFilesFromDataTransfer(dt)
  const fromHtml = extractImagesFromClipboardHtml(html)
  const reuse = fromHtml.reuse.slice()
  // 截图/复制图时常同时带 image/* 与 html data URL，只取位图，避免同一张贴两次
  const allFiles = files.length ? files : fromHtml.files
  const seen = {}
  const uniqFiles = []
  for (let i = 0; i < allFiles.length; i++) {
    pushUniqueFile(uniqFiles, allFiles[i], seen)
  }
  return {
    reuse: files.length ? [] : reuse,
    files: uniqFiles,
    consumedText: fromText.markerHit || (!files.length && reuse.length > 0) || uniqFiles.length > 0,
  }
}

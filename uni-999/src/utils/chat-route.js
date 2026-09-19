/** 当前聊天室快照：刷新后若 hash 丢参可回退恢复 */
const KEY = 'fans_hub_999_active_chat'

export function saveActiveChat(payload) {
  if (!payload) return
  const data = {
    type: payload.type | 0,
    id: String(payload.id || payload.conversationId || ''),
    peer: payload.peer | 0,
    group: payload.group | 0,
    title: String(payload.title || ''),
    nickname: String(payload.nickname || ''),
    remark: String(payload.remark || ''),
    ts: Date.now(),
  }
  if (!data.id && !data.peer && !data.group) return
  try {
    uni.setStorageSync(KEY, JSON.stringify(data))
  } catch (e) {}
  // #ifdef H5
  try {
    if (typeof sessionStorage !== 'undefined') {
      sessionStorage.setItem(KEY, JSON.stringify(data))
    }
  } catch (e2) {}
  // #endif
}

export function clearActiveChat() {
  try {
    uni.removeStorageSync(KEY)
  } catch (e) {}
  // #ifdef H5
  try {
    if (typeof sessionStorage !== 'undefined') sessionStorage.removeItem(KEY)
  } catch (e2) {}
  // #endif
}

export function getActiveChat() {
  let raw = ''
  // #ifdef H5
  try {
    if (typeof sessionStorage !== 'undefined') raw = sessionStorage.getItem(KEY) || ''
  } catch (e) {}
  // #endif
  if (!raw) {
    try {
      raw = uni.getStorageSync(KEY) || ''
    } catch (e2) {
      raw = ''
    }
  }
  if (!raw) return null
  try {
    const data = typeof raw === 'string' ? JSON.parse(raw) : raw
    if (!data || (!(data.type | 0))) return null
    return data
  } catch (e) {
    return null
  }
}

export function buildChatUrl(data) {
  if (!data) return ''
  const type = data.type | 0
  const id = data.id || (type === 2 ? String(data.group || '') : '')
  const q = [
    'type=' + encodeURIComponent(type),
    'id=' + encodeURIComponent(id),
    'peer=' + encodeURIComponent(data.peer || 0),
    'group=' + encodeURIComponent(data.group || (type === 2 ? id : 0)),
    'title=' + encodeURIComponent(data.title || ''),
    'nickname=' + encodeURIComponent(data.nickname || ''),
    'remark=' + encodeURIComponent(data.remark || ''),
  ].join('&')
  return '/pages/chat/chat?' + q
}

/** 影音/频道群：APK 上易残留原生 video 层，进房用 redirectTo 避免叠栈 */
export const CHANNEL_VIDEO_GROUP_IDS = [70, 71, 72, 77]

export function isChannelVideoGroup(groupId) {
  const gid = groupId | 0
  return gid > 0 && CHANNEL_VIDEO_GROUP_IDS.indexOf(gid) >= 0
}

function currentRouteIsChat() {
  try {
    const pages = typeof getCurrentPages === 'function' ? getCurrentPages() : null
    const cur = pages && pages.length ? pages[pages.length - 1] : null
    const route = String((cur && (cur.route || cur.$page && cur.$page.fullPath)) || '')
    return route.indexOf('pages/chat/chat') >= 0
  } catch (e) {
    return false
  }
}

/**
 * 打开聊天页。
 * 注意：从 Tab 页（社群）redirectTo 会失败，必须 navigateTo；
 * 频道群在进房前先关掉栈里已有 chat，避免华为等机型残留原生/合成层黑影。
 * @param {string} url
 * @param {{ groupId?: number|string }=} opts
 */
export function openChatPage(url, opts) {
  const target = String(url || '').trim()
  if (!target) return
  const gid = (opts && opts.groupId) | 0
  const channel = isChannelVideoGroup(gid)
  const preferReplace = channel || currentRouteIsChat()

  const goNav = () => {
    uni.navigateTo({
      url: target,
      // #ifdef APP-PLUS
      animationType: channel ? 'none' : 'pop-in',
      animationDuration: channel ? 0 : 300,
      // #endif
      fail() {
        uni.reLaunch({ url: target })
      },
    })
  }
  const goReplace = () => {
    uni.redirectTo({
      url: target,
      fail() {
        goNav()
      },
    })
  }

  if (!preferReplace) {
    goNav()
    return
  }

  try {
    const pages = typeof getCurrentPages === 'function' ? getCurrentPages() : null
    const list = pages || []
    let chatIdx = -1
    for (let i = list.length - 1; i >= 0; i--) {
      const r = String((list[i] && list[i].route) || '')
      if (r.indexOf('pages/chat/chat') >= 0) {
        chatIdx = i
        break
      }
    }
    if (chatIdx >= 0) {
      const delta = list.length - 1 - chatIdx
      if (delta > 0) {
        // 栈上已有更早的 chat：先关掉再进新群（华为 WebView 残留层高发）
        uni.navigateBack({
          delta,
          complete() {
            setTimeout(goNav, channel ? 220 : 80)
          },
        })
        return
      }
      // 当前就是 chat 页：替换
      goReplace()
      return
    }
  } catch (e) {}

  // Tab 页上 redirectTo 不可用，直接 navigateTo
  goNav()
}

/**
 * App 端播放聊天视频：进独立播放页（不挂在聊天页，避免黑影；也不 openURL 跳浏览器）。
 * H5 返回 false，由调用方走页面内播放器。
 * @param {{ url: string, poster?: string }[]} sources
 * @param {number} current
 * @returns {boolean}
 */
export function openChatVideoPreview(sources, current) {
  // #ifndef APP-PLUS
  return false
  // #endif
  // #ifdef APP-PLUS
  const list = (sources || [])
    .map((s) => ({
      url: String((s && (s.url || s.src)) || '').trim(),
      poster: String((s && s.poster) || '').trim(),
    }))
    .filter((s) => !!s.url)
  if (!list.length) return false
  const idx = Math.max(0, Math.min(list.length - 1, current | 0))
  const item = list[idx]
  const q =
    'url=' +
    encodeURIComponent(item.url) +
    '&poster=' +
    encodeURIComponent(item.poster || '')
  try {
    uni.navigateTo({
      url: '/pages/chat/video-play?' + q,
      animationType: 'fade-in',
      animationDuration: 180,
      fail() {
        // 禁止 plus.runtime.openURL：三星等机会直接跳系统浏览器
        try {
          uni.showToast({ title: '无法打开播放器', icon: 'none' })
        } catch (e2) {}
      },
    })
    return true
  } catch (e) {
    return false
  }
  // #endif
}

/** H5：从 hash 解析当前页 path（不含 query） */
export function getHashRoutePath() {
  // #ifdef H5
  try {
    if (typeof location === 'undefined') return ''
    const hash = String(location.hash || '')
    // #/pages/chat/chat?x=1  or #/pages/chat/chat
    const m = hash.match(/^#\/?([^?]+)/)
    if (!m) return ''
    return String(m[1] || '').replace(/^\//, '')
  } catch (e) {
    return ''
  }
  // #endif
  return ''
}

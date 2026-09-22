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

/** App 视频播放页：用 storage 传长 OSS URL，避免 navigateTo query 被截断导致无法播放 */
export const CHAT_VIDEO_PLAY_STORAGE_KEY = 'fans_hub_chat_video_play'

export function isChannelVideoGroup(groupId) {
  const gid = groupId | 0
  return gid > 0 && CHANNEL_VIDEO_GROUP_IDS.indexOf(gid) >= 0
}

function isAndroidApp() {
  // #ifdef APP-PLUS
  try {
    const p = String((uni.getSystemInfoSync() || {}).platform || '').toLowerCase()
    return p === 'android'
  } catch (e) {
    return false
  }
  // #endif
  return false
}

function currentRouteIsChat() {
  try {
    const pages = typeof getCurrentPages === 'function' ? getCurrentPages() : null
    const cur = pages && pages.length ? pages[pages.length - 1] : null
    const route = String((cur && (cur.route || (cur.$page && cur.$page.fullPath))) || '')
    return route.indexOf('pages/chat/chat') >= 0
  } catch (e) {
    return false
  }
}

function topRouteIsVideoPlay() {
  try {
    const pages = typeof getCurrentPages === 'function' ? getCurrentPages() : null
    const cur = pages && pages.length ? pages[pages.length - 1] : null
    const route = String((cur && cur.route) || '')
    return route.indexOf('pages/chat/video-play') >= 0
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
  const android = isAndroidApp()
  // 安卓频道群：原生 video 层销毁更慢，切群前多等一会
  const afterPopMs = channel ? (android ? 650 : 220) : android ? 200 : 80

  const goNav = () => {
    try {
      uni.removeStorageSync(CHAT_VIDEO_PLAY_STORAGE_KEY)
    } catch (e0) {}
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
    try {
      uni.removeStorageSync(CHAT_VIDEO_PLAY_STORAGE_KEY)
    } catch (e0) {}
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
    let videoIdx = -1
    for (let i = list.length - 1; i >= 0; i--) {
      const r = String((list[i] && list[i].route) || '')
      if (videoIdx < 0 && r.indexOf('pages/chat/video-play') >= 0) {
        videoIdx = i
      }
      if (r.indexOf('pages/chat/chat') >= 0) {
        chatIdx = i
        break
      }
    }
    // 栈顶是播放页：先关掉，再进目标群（否则安卓残留层挡住点击/返回）
    if (topRouteIsVideoPlay() || videoIdx >= 0) {
      const delta = videoIdx >= 0 ? list.length - videoIdx : 1
      uni.navigateBack({
        delta: Math.max(1, delta),
        complete() {
          setTimeout(() => {
            if (preferReplace && currentRouteIsChat()) {
              goReplace()
            } else {
              goNav()
            }
          }, afterPopMs)
        },
      })
      return
    }
    if (chatIdx >= 0) {
      const delta = list.length - 1 - chatIdx
      if (delta > 0) {
        // 栈上已有更早的 chat：先关掉再进新群（华为 WebView 残留层高发）
        uni.navigateBack({
          delta,
          complete() {
            setTimeout(goNav, afterPopMs)
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
    .filter((s) => !!s.url && /^https?:\/\//i.test(s.url))
  if (!list.length) return false
  const idx = Math.max(0, Math.min(list.length - 1, current | 0))
  const item = list[idx]
  const token = String(Date.now()) + '_' + Math.random().toString(36).slice(2, 8)
  try {
    uni.setStorageSync(
      CHAT_VIDEO_PLAY_STORAGE_KEY,
      JSON.stringify({
        url: item.url,
        poster: /^https?:\/\//i.test(item.poster) ? item.poster : '',
        ts: Date.now(),
        token,
      })
    )
  } catch (e0) {
    try {
      uni.showToast({ title: '无法打开播放器', icon: 'none' })
    } catch (e1) {}
    return false
  }
  const playUrl = '/pages/chat/video-play?t=' + encodeURIComponent(token)
  const openFail = () => {
    try {
      uni.showToast({ title: '无法打开播放器', icon: 'none' })
    } catch (e2) {}
  }
  try {
    // 栈顶已是播放页（或残留）：redirect 强制重建，避免 70 播完后 71 打不开
    if (topRouteIsVideoPlay()) {
      uni.redirectTo({
        url: playUrl,
        fail() {
          uni.navigateTo({ url: playUrl, fail: openFail })
        },
      })
      return true
    }
    uni.navigateTo({
      url: playUrl,
      animationType: 'fade-in',
      animationDuration: 180,
      fail() {
        // 禁止 plus.runtime.openURL：三星等机会直接跳系统浏览器
        openFail()
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

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

/** 安卓频道切群锁：避免 70→71 叠栈 + 原生层未死就进新房 */
let androidChannelNavLock = false
/** 锁期间再次点开频道：记下目标，解锁后立刻进 */
let androidChannelNavPending = ''

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

function purgeVideoPlayStorage() {
  try {
    uni.removeStorageSync(CHAT_VIDEO_PLAY_STORAGE_KEY)
  } catch (e) {}
}

/** 栈里最早的 chat 或 video-play 下标（从底往上找） */
function findFirstChatOrVideoIndex(list) {
  for (let i = 0; i < list.length; i++) {
    const r = String((list[i] && list[i].route) || '')
    if (r.indexOf('pages/chat/video-play') >= 0 || r.indexOf('pages/chat/chat') >= 0) {
      return i
    }
  }
  return -1
}

function releaseAndroidChannelLock() {
  androidChannelNavLock = false
  const next = androidChannelNavPending
  androidChannelNavPending = ''
  if (next) {
    // 下一帧再开，避免与本次 unlock 竞态
    setTimeout(() => {
      openAndroidChannelChat(next)
    }, 80)
  }
}

/**
 * 安卓频道群专用：先杀掉栈上所有 chat/video-play（含自身），等原生层死透，再只进一次目标群。
 * 禁止在旧 chat 上再 navigateTo（叠栈 = 黑影吞点击/返回）。
 * 从社群 Tab 进房：无 chat 可杀时直接 navigateTo（redirectTo Tab 会失败）。
 */
function openAndroidChannelChat(target) {
  target = String(target || '').trim()
  if (!target) return
  if (androidChannelNavLock) {
    androidChannelNavPending = target
    return
  }
  androidChannelNavLock = true
  androidChannelNavPending = ''
  const afterKillMs = 1000
  const unlockMs = 400
  const unlock = () => {
    setTimeout(() => {
      releaseAndroidChannelLock()
    }, unlockMs)
  }

  purgeVideoPlayStorage()

  const enterOnce = () => {
    setTimeout(() => {
      try {
        const pages = typeof getCurrentPages === 'function' ? getCurrentPages() : null
        const list = pages || []
        const top = list.length ? String((list[list.length - 1] && list[list.length - 1].route) || '') : ''
        const onChatOrVideo =
          top.indexOf('pages/chat/chat') >= 0 || top.indexOf('pages/chat/video-play') >= 0
        // 顶层已是 chat/video：redirect 换房；顶层是社群 Tab：只能 navigateTo
        if (onChatOrVideo) {
          uni.redirectTo({
            url: target,
            fail() {
              uni.navigateTo({
                url: target,
                animationType: 'none',
                animationDuration: 0,
                fail() {
                  uni.reLaunch({ url: target, complete: unlock })
                },
                complete: unlock,
              })
            },
            complete: unlock,
          })
          return
        }
      } catch (e) {}
      uni.navigateTo({
        url: target,
        animationType: 'none',
        animationDuration: 0,
        fail() {
          uni.reLaunch({ url: target, complete: unlock })
        },
        complete: unlock,
      })
    }, afterKillMs)
  }

  try {
    const pages = typeof getCurrentPages === 'function' ? getCurrentPages() : null
    const list = pages || []
    const killFrom = findFirstChatOrVideoIndex(list)
    if (killFrom >= 0) {
      const delta = Math.max(1, list.length - killFrom)
      uni.navigateBack({
        delta,
        complete: enterOnce,
        fail() {
          // pop 失败：仍尝试进目标房
          enterOnce()
        },
      })
      return
    }
  } catch (e) {}

  // 已在社群等非 chat 页：稍等原生层空闲再进（短延迟即可）
  setTimeout(() => {
    uni.navigateTo({
      url: target,
      animationType: 'none',
      animationDuration: 0,
      fail() {
        uni.reLaunch({ url: target, complete: unlock })
      },
      complete: unlock,
    })
  }, 280)
}

/**
 * 打开聊天页。
 * 注意：从 Tab 页（社群）redirectTo 会失败，必须 navigateTo；
 * 安卓频道群走核切开房，禁止叠两个 chat。
 * @param {string} url
 * @param {{ groupId?: number|string }=} opts
 */
export function openChatPage(url, opts) {
  const target = String(url || '').trim()
  if (!target) return
  const gid = (opts && opts.groupId) | 0
  const channel = isChannelVideoGroup(gid)
  const android = isAndroidApp()

  // #ifdef APP-PLUS
  if (android && channel) {
    openAndroidChannelChat(target)
    return
  }
  // #endif

  const preferReplace = channel || currentRouteIsChat()
  const afterPopMs = channel ? 220 : 80

  const goNav = () => {
    purgeVideoPlayStorage()
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
    purgeVideoPlayStorage()
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
    if (topRouteIsVideoPlay() || videoIdx >= 0) {
      const killFrom = videoIdx >= 0 ? videoIdx : list.length - 1
      // iOS/非安卓：同样尽量把 chat 一并 pop，避免叠栈
      const chatUnder = chatIdx >= 0 && chatIdx < killFrom ? chatIdx : killFrom
      const delta = Math.max(1, list.length - chatUnder)
      uni.navigateBack({
        delta,
        complete() {
          setTimeout(() => {
            if (currentRouteIsChat()) goReplace()
            else goNav()
          }, afterPopMs)
        },
      })
      return
    }
    if (chatIdx >= 0) {
      const delta = list.length - 1 - chatIdx
      if (delta > 0) {
        // 非安卓保留旧逻辑；但进频道时也 pop 到 chat 再 replace
        uni.navigateBack({
          delta,
          complete() {
            setTimeout(() => {
              if (channel && currentRouteIsChat()) goReplace()
              else goNav()
            }, afterPopMs)
          },
        })
        return
      }
      goReplace()
      return
    }
  } catch (e) {}

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
  const openNav = () => {
    let hasVideoPage = topRouteIsVideoPlay()
    if (!hasVideoPage) {
      try {
        const pages = typeof getCurrentPages === 'function' ? getCurrentPages() : null
        const list = pages || []
        for (let i = 0; i < list.length; i++) {
          const r = String((list[i] && list[i].route) || '')
          if (r.indexOf('pages/chat/video-play') >= 0) {
            hasVideoPage = true
            break
          }
        }
      } catch (e3) {}
    }
    if (hasVideoPage) {
      // 已在播放页：替换，避免双 VideoView
      uni.redirectTo({
        url: playUrl,
        fail() {
          uni.reLaunch({ url: playUrl, fail: openFail })
        },
      })
      return
    }
    uni.navigateTo({
      url: playUrl,
      // #ifdef APP-PLUS
      animationType: isAndroidApp() ? 'none' : 'fade-in',
      animationDuration: isAndroidApp() ? 0 : 180,
      // #endif
      fail() {
        uni.redirectTo({ url: playUrl, fail: openFail })
      },
    })
  }
  // 切群锁未释放：等到解锁再开（勿 redirectTo 顶掉刚进的 chat71）
  if (isAndroidApp() && androidChannelNavLock) {
    let tries = 0
    const wait = () => {
      tries++
      if (!androidChannelNavLock || tries > 25) {
        openNav()
        return
      }
      setTimeout(wait, 120)
    }
    setTimeout(wait, 200)
  } else {
    openNav()
  }
  return true
  // #endif
}

/** H5：从 hash 解析当前页 path（不含 query） */
export function getHashRoutePath() {
  // #ifdef H5
  try {
    if (typeof location === 'undefined') return ''
    const hash = String(location.hash || '')
    const m = hash.match(/^#\/?([^?]+)/)
    if (!m) return ''
    return String(m[1] || '').replace(/^\//, '')
  } catch (e) {
    return ''
  }
  // #endif
  return ''
}

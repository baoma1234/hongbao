/**
 * App / H5 安全区与 Safari 视口修复。
 * - App：env(safe-area) 常为 0，用 statusBarHeight / safeAreaInsets（JS）
 * - H5 Safari：键盘收起后 inset / visualViewport 偶发残留，须封顶并复位 scroll
 */

function num(v, fallback = 0) {
  const n = Number(v)
  return Number.isFinite(n) && n >= 0 ? n : fallback
}

/** Home Indicator 通常 ≤34；超过此值多半是键盘污染或测量异常 */
const MAX_BOTTOM_INSET_PX = 40
const MAX_TOP_INSET_PX = 60

function capBottomInset(bottom) {
  const b = Math.max(0, num(bottom))
  if (b > MAX_BOTTOM_INSET_PX) return MAX_BOTTOM_INSET_PX
  return b
}

function capTopInset(top) {
  const t = Math.max(0, num(top))
  if (t > MAX_TOP_INSET_PX) return MAX_TOP_INSET_PX
  return t
}

/**
 * App 自定义顶栏避让状态栏。
 * 若 WebView 已整体下移（windowTop>0），只补差值，避免「空一大截」；
 * 若全屏沉浸（windowTop=0），垫 statusBarHeight，贴齐信号栏下方。
 */
export function getSafeAreaInsets() {
  let top = 0
  let bottom = 0
  let left = 0
  let right = 0
  try {
    const sys = uni.getSystemInfoSync() || {}
    const inset = sys.safeAreaInsets || {}
    let status = num(sys.statusBarHeight)
    const windowTop = num(sys.windowTop)

    // #ifdef APP-PLUS
    try {
      if (typeof plus !== 'undefined' && plus.navigator && plus.navigator.getStatusbarHeight) {
        const ph = num(plus.navigator.getStatusbarHeight())
        if (ph > 0) status = ph
      }
    } catch (e) {}
    // 已避让的部分不再重复垫；结果紧贴信号栏底边
    top = Math.max(0, status - windowTop)
    if (top < 1 && status < 1) top = 24
    // #endif

    // #ifndef APP-PLUS
    const envTop = num(inset.top)
    if (envTop > 0) top = envTop
    else top = Math.max(0, status - windowTop)
    // #endif

    bottom = num(inset.bottom)
    left = num(inset.left)
    right = num(inset.right)

    // H5：键盘收起后 Safari / uni 偶发把 bottom 报到很大；Home 条封顶
    // #ifdef H5
    bottom = capBottomInset(bottom)
    top = capTopInset(top)
    // #endif
  } catch (e) {
    // #ifdef APP-PLUS
    top = 24
    // #endif
  }
  return { top, bottom, left, right }
}

/** 与 TopBar / App.vue 一致：大厅同步加高（窄屏 60，否则 64） */
export function getTopBarContentHeight() {
  let bar = 64
  try {
    const sys = uni.getSystemInfoSync() || {}
    const w = Number(sys.windowWidth) || 0
    if (w > 0 && w <= 480) bar = 60
  } catch (e) {}
  return bar
}

/** 固定浮层应贴在 TopBar 底边：内容栏高 + 状态栏垫高 */
export function measureChatOverlayTop() {
  const { top } = getSafeAreaInsets()
  return getTopBarContentHeight() + Math.max(0, Number(top) || 0)
}

function setVarsOn(el, top, bottom, left, right, overlayTop) {
  if (!el || !el.style || !el.style.setProperty) return
  el.style.setProperty('--safe-area-inset-top', top + 'px')
  el.style.setProperty('--safe-area-inset-bottom', bottom + 'px')
  el.style.setProperty('--safe-area-inset-left', left + 'px')
  el.style.setProperty('--safe-area-inset-right', right + 'px')
  // 与 TopBar 同高：App 上 CSS env(safe-area) 常为 0，固定浮层用此变量避让
  el.style.setProperty('--chat-overlay-top', overlayTop + 'px')
  el.style.setProperty('--top-bar-offset', overlayTop + 'px')
}

export function applySafeAreaCssVars() {
  const { top, bottom, left, right } = getSafeAreaInsets()
  const overlayTop = measureChatOverlayTop()
  try {
    if (typeof document !== 'undefined') {
      setVarsOn(document.documentElement, top, bottom, left, right, overlayTop)
      setVarsOn(document.body, top, bottom, left, right, overlayTop)
      const nodes = document.querySelectorAll(
        'uni-page-body, uni-page, .uni-page-body, page, .chat-room-page, .bottom-action-bar'
      )
      for (let i = 0; i < nodes.length; i++) {
        setVarsOn(nodes[i], top, bottom, left, right, overlayTop)
      }
    }
  } catch (e) {}
  return { top, bottom, left, right, overlayTop }
}

/**
 * Safari / iOS H5：键盘收起后 fixed 底栏悬空、safe-area 残留。
 * 复位 scroll + 重写 CSS 变量；键盘打开时不改 bottom（由聊天页自己量高度）。
 */
export function resetSafariViewportAfterKeyboard() {
  try {
    if (typeof window === 'undefined') return
    const vv = window.visualViewport
    // 仍像键盘弹起：不强制清
    if (vv) {
      const covered = Math.max(0, window.innerHeight - vv.height - (vv.offsetTop || 0))
      if (covered > 80) return
    }
    window.scrollTo(0, 0)
    if (document.documentElement) document.documentElement.scrollTop = 0
    if (document.body) {
      document.body.scrollTop = 0
      // 清掉部分 WebView 在键盘期写的内联高度
      if (document.body.style.height === window.innerHeight + 'px') {
        document.body.style.height = ''
      }
      document.body.style.paddingBottom = ''
    }
    applySafeAreaCssVars()
  } catch (e) {}
}

let safariGuardInstalled = false
let safariVvTimer = null

/** H5 仅装一次：监听 visualViewport / pageshow，键盘收起后清鬼空白 */
export function installSafariViewportGuard() {
  // #ifndef H5
  return
  // #endif
  // #ifdef H5
  if (safariGuardInstalled) return
  if (typeof window === 'undefined') return
  safariGuardInstalled = true

  const onVv = () => {
    if (safariVvTimer) clearTimeout(safariVvTimer)
    safariVvTimer = setTimeout(() => {
      safariVvTimer = null
      try {
        const vv = window.visualViewport
        if (!vv) {
          resetSafariViewportAfterKeyboard()
          return
        }
        const covered = Math.max(0, window.innerHeight - vv.height - (vv.offsetTop || 0))
        if (covered <= 80) {
          resetSafariViewportAfterKeyboard()
        } else {
          // 键盘打开：仍刷新封顶后的 safe-area，避免 inset 被写成键盘高
          applySafeAreaCssVars()
        }
      } catch (e) {}
    }, 60)
  }

  try {
    if (window.visualViewport) {
      window.visualViewport.addEventListener('resize', onVv)
      window.visualViewport.addEventListener('scroll', onVv)
    }
  } catch (e) {}
  try {
    window.addEventListener('resize', onVv)
    window.addEventListener('orientationchange', onVv)
    window.addEventListener('pageshow', () => {
      setTimeout(resetSafariViewportAfterKeyboard, 50)
    })
    document.addEventListener('focusout', () => {
      // input 失焦 ≈ 键盘将收起
      setTimeout(resetSafariViewportAfterKeyboard, 80)
      setTimeout(resetSafariViewportAfterKeyboard, 320)
    })
  } catch (e2) {}
  // #endif
}

/**
 * App WebView 里 env(safe-area-inset-*) 常为 0，自定义顶栏会顶到信号栏。
 * 用 uni.getSystemInfoSync 写入 CSS 变量，样式用 var(--safe-area-inset-top, env(...))。
 */
function num(v, fallback = 0) {
  const n = Number(v)
  return Number.isFinite(n) && n >= 0 ? n : fallback
}

export function getSafeAreaInsets() {
  let top = 0
  let bottom = 0
  let left = 0
  let right = 0
  try {
    const sys = uni.getSystemInfoSync() || {}
    const inset = sys.safeAreaInsets || {}
    top = num(inset.top)
    bottom = num(inset.bottom)
    left = num(inset.left)
    right = num(inset.right)
    const status = num(sys.statusBarHeight)
    if (top < 1 && status > 0) top = status
    // #ifdef APP-PLUS
    // 自定义 navigationStyle 时内容区延伸到状态栏下，至少按状态栏高度垫开
    if (top < 1) top = status > 0 ? status : 24
    // #endif
  } catch (e) {
    // #ifdef APP-PLUS
    top = 24
    // #endif
  }
  return { top, bottom, left, right }
}

/** 写入 :root / page，供全局 CSS 使用 */
export function applySafeAreaCssVars() {
  const { top, bottom, left, right } = getSafeAreaInsets()
  try {
    if (typeof document !== 'undefined' && document.documentElement) {
      const root = document.documentElement
      root.style.setProperty('--safe-area-inset-top', top + 'px')
      root.style.setProperty('--safe-area-inset-bottom', bottom + 'px')
      root.style.setProperty('--safe-area-inset-left', left + 'px')
      root.style.setProperty('--safe-area-inset-right', right + 'px')
    }
  } catch (e) {}
  return { top, bottom, left, right }
}

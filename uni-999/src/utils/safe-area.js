/**
 * App 自定义顶栏避让状态栏。
 * 若 WebView 已整体下移（windowTop>0），只补差值，避免「空一大截」；
 * 若全屏沉浸（windowTop=0），垫 statusBarHeight，贴齐信号栏下方。
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
  } catch (e) {
    // #ifdef APP-PLUS
    top = 24
    // #endif
  }
  return { top, bottom, left, right }
}

function setVarsOn(el, top, bottom, left, right) {
  if (!el || !el.style || !el.style.setProperty) return
  el.style.setProperty('--safe-area-inset-top', top + 'px')
  el.style.setProperty('--safe-area-inset-bottom', bottom + 'px')
  el.style.setProperty('--safe-area-inset-left', left + 'px')
  el.style.setProperty('--safe-area-inset-right', right + 'px')
}

export function applySafeAreaCssVars() {
  const { top, bottom, left, right } = getSafeAreaInsets()
  try {
    if (typeof document !== 'undefined') {
      setVarsOn(document.documentElement, top, bottom, left, right)
      setVarsOn(document.body, top, bottom, left, right)
      const nodes = document.querySelectorAll(
        'uni-page-body, uni-page, .uni-page-body, page'
      )
      for (let i = 0; i < nodes.length; i++) {
        setVarsOn(nodes[i], top, bottom, left, right)
      }
    }
  } catch (e) {}
  return { top, bottom, left, right }
}

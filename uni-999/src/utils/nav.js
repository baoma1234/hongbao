/** 默认首页（Tab） */
export const HOME_TAB = '/pages/home/home'

const TAB_RE =
  /^\/pages\/(home\/home|community\/community|exchange\/exchange|messages\/messages|master\/master|fission\/detail|profile\/profile)/

function goFallback(url) {
  const target = String(url || HOME_TAB).trim() || HOME_TAB
  if (TAB_RE.test(target)) {
    uni.switchTab({
      url: target,
      fail() {
        uni.reLaunch({ url: target })
      },
    })
    return
  }
  uni.reLaunch({ url: target })
}

/**
 * 安全返回上一页。
 * 刷新后 H5 常只剩单页栈，navigateBack 会 history.back 原地刷新；
 * 栈深 ≤1 或 navigateBack 失败时跳转 fallback（默认首页）。
 */
export function safeNavigateBack(fallbackUrl) {
  const fallback = String(fallbackUrl || HOME_TAB).trim() || HOME_TAB
  let stackLen = 1
  try {
    const pages = typeof getCurrentPages === 'function' ? getCurrentPages() : null
    stackLen = (pages && pages.length) || 1
  } catch (e) {
    stackLen = 1
  }
  if (stackLen <= 1) {
    goFallback(fallback)
    return
  }
  uni.navigateBack({
    delta: 1,
    fail() {
      goFallback(fallback)
    },
  })
}

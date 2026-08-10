/**
 * App 状态栏：自定义顶栏多为浅色，必须用深色图标，否则时间/信号看不见。
 */
export function applyAppStatusBar() {
  // #ifdef APP-PLUS
  try {
    uni.setStatusBarStyle({ style: 'dark' })
  } catch (e) {}
  try {
    // eslint-disable-next-line no-undef
    if (typeof plus !== 'undefined' && plus.navigator) {
      plus.navigator.setFullscreen(false)
      if (plus.navigator.setStatusBarStyle) {
        plus.navigator.setStatusBarStyle('dark')
      }
      if (plus.navigator.setStatusBarBackground) {
        plus.navigator.setStatusBarBackground('#ffffff')
      }
    }
  } catch (e2) {}
  // #endif
}

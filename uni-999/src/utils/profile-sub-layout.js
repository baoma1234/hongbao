import { ref } from 'vue'
import { applySafeAreaCssVars, measureChatOverlayTop } from './safe-area.js'

/**
 * 我的/钱包二级页 QQ 白返回条：App 上 CSS var(--chat-overlay-top) 常失效，
 * 须内联 top 与 TopBar 底边对齐，避免被白顶栏压住。
 */
export function useProfileSubHdStyle() {
  const profileSubHdStyle = ref({})
  const profileSubPageStyle = ref({})

  function refreshProfileSubLayout() {
    const r = applySafeAreaCssVars()
    const overlayTop = (r && r.overlayTop) || measureChatOverlayTop()
    // 四端统一内联 top：H5/Safari 上 CSS var 偶发滞后；App 上 env(safe-area) 常为 0
    profileSubHdStyle.value = { top: overlayTop + 'px' }
    profileSubPageStyle.value = {
      '--chat-overlay-top': overlayTop + 'px',
    }
  }

  return { profileSubHdStyle, profileSubPageStyle, refreshProfileSubLayout }
}

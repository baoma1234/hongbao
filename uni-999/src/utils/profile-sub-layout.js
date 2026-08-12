import { ref } from 'vue'
import { applySafeAreaCssVars, measureChatOverlayTop } from './safe-area.js'

/**
 * 我的/钱包二级页红条 profile-sub-hd：App 上 CSS var(--chat-overlay-top) 常失效，
 * 须内联 top 与 TopBar 底边对齐，避免标题被白顶栏压住。
 */
export function useProfileSubHdStyle() {
  const profileSubHdStyle = ref({})
  const profileSubPageStyle = ref({})

  function refreshProfileSubLayout() {
    const r = applySafeAreaCssVars()
    const overlayTop = (r && r.overlayTop) || measureChatOverlayTop()
    // #ifdef APP-PLUS
    profileSubHdStyle.value = { top: overlayTop + 'px' }
    profileSubPageStyle.value = {
      '--chat-overlay-top': overlayTop + 'px',
    }
    // #endif
    // #ifndef APP-PLUS
    profileSubHdStyle.value = {}
    profileSubPageStyle.value = {}
    // #endif
  }

  return { profileSubHdStyle, profileSubPageStyle, refreshProfileSubLayout }
}

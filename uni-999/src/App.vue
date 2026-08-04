<script setup>
import { onLaunch, onShow } from '@dcloudio/uni-app'
import { getToken } from './utils/auth.js'
import {
  buildChatUrl,
  getActiveChat,
  getHashRoutePath,
} from './utils/chat-route.js'
import { initI18n } from './utils/i18n.js'
import { imConnect, imDisconnect } from './utils/im.js'
import { initSkin } from './utils/skin.js'
import './styles/hb.css'

/**
 * 已登录时不要盲目 reLaunch 大厅：
 * 刷新对话框 / 群设置等子页应留在当前 hash 路由。
 */
onLaunch(async () => {
  initSkin()
  try {
    await initI18n()
  } catch (e) {}

  const token = getToken()
  if (!token) return

  imConnect().catch(() => {})

  const path = getHashRoutePath()
  // 已在聊天室 / 群设置 / 钱包等：尊重 URL，不打断
  if (
    path.indexOf('pages/chat/') === 0 ||
    path.indexOf('pages/group/') === 0 ||
    path.indexOf('pages/wallet/') === 0 ||
    path.indexOf('pages/home/') === 0 ||
    path.indexOf('pages/messages/') === 0 ||
    path.indexOf('pages/exchange/') === 0 ||
    path.indexOf('pages/master/') === 0 ||
    path.indexOf('pages/profile/') === 0
  ) {
    return
  }

  // 落在登录页或空 hash：进大厅；若有未清的聊天快照则回对话框
  if (!path || path.indexOf('pages/login/') === 0) {
    const room = getActiveChat()
    const chatUrl = room ? buildChatUrl(room) : ''
    if (chatUrl) {
      uni.reLaunch({ url: chatUrl })
      return
    }
    uni.reLaunch({ url: '/pages/home/home' })
  }
})

onShow(() => {
  initSkin()
  try {
    uni.hideTabBar({ animation: false })
  } catch (e) {}
  if (getToken()) {
    imConnect().catch(() => {})
  } else {
    imDisconnect()
  }
})
</script>

<style>
page {
  background-color: var(--bg-main, #f4f6f9);
  color: var(--text-main, #1a212d);
  --top-bar-height: 48px;
}
</style>

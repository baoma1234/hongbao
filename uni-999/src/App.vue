<script setup>
import { onLaunch, onShow } from '@dcloudio/uni-app'
import { fetchConfig, getToken } from './utils/auth.js'
import {
  buildChatUrl,
  getActiveChat,
  getHashRoutePath,
} from './utils/chat-route.js'
import { bootstrapRuntimeConfig } from './utils/config.js'
import { initI18n } from './utils/i18n.js'
import { imConnect, imDisconnect, bindForegroundResume } from './utils/im.js'
import { startImInbox } from './utils/im-inbox.js'
import { applySafeAreaCssVars } from './utils/safe-area.js'
import { initSkin } from './utils/skin.js'
import './styles/hb.css'

async function refreshRemoteEndpoints() {
  try {
    const r = await bootstrapRuntimeConfig()
    if (r && r.changedWs && getToken()) {
      imDisconnect()
      imConnect().catch(() => {})
    }
  } catch (e) {}
}

/**
 * 已登录时不要盲目 reLaunch 大厅：
 * 刷新对话框 / 群设置等子页应留在当前 hash 路由。
 */
onLaunch(async () => {
  initSkin()
  // App 自定义顶栏：用 statusBarHeight 垫开信号栏（env(safe-area) 在安卓常为 0）
  applySafeAreaCssVars()
  // 先拉远端 apiUri / socketUri / imgUri（有缓存则先用缓存）
  await refreshRemoteEndpoints()
  // 尽早拿到 OSS upload_cdn，避免会话/社群头像先拼成本站 /uploads
  try {
    await fetchConfig()
  } catch (e) {}
  try {
    await initI18n()
  } catch (e) {}

  const token = getToken()
  if (!token) return

  startImInbox()
  bindForegroundResume()
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

  // 落在登录页 / 隐藏登录页或空 hash：进大厅；若有未清的聊天快照则回对话框
  if (!path || path.indexOf('pages/login/') === 0 || path.indexOf('gfhwgkdhf11131djfh/') === 0) {
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
  applySafeAreaCssVars()
  try {
    uni.hideTabBar({ animation: false })
  } catch (e) {}
  // 每次回到前台再拉一次远端配置
  refreshRemoteEndpoints()
  if (getToken()) {
    startImInbox()
    bindForegroundResume()
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
  --safe-area-inset-top: 0px;
  --safe-area-inset-bottom: 0px;
}
@media (max-width: 480px) {
  page {
    --top-bar-height: 44px;
  }
}
</style>

<template>
  <LocalPushBanner />
</template>

<script setup>
import { onLaunch, onShow } from '@dcloudio/uni-app'
import LocalPushBanner from './components/LocalPushBanner.vue'
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
import { applyAppStatusBar } from './utils/status-bar.js'
import { initSkin } from './utils/skin.js'
import { initOpenInstall } from './utils/openinstall.js'
import { initPushOnLaunch } from './utils/jpush.js'
import './styles/hb.css'
import './styles/app-back-fix.css'

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
  // App 携参安装/拉起；H5 落地页记录邀请指纹（需用户同意隐私后再 init 的上架包，可改到协议同意后）
  initOpenInstall()
  // 浅色顶栏 + 深色状态栏图标，避免时间/信号“消失”
  applyAppStatusBar()
  // App 自定义顶栏：用 statusBarHeight 垫开信号栏（env(safe-area) 在安卓常为 0）
  applySafeAreaCssVars()
  // 先拉远端 apiUri / socketUri / imgUri（有缓存则先用缓存）
  await refreshRemoteEndpoints()
  // 尽早拿到 OSS upload_cdn，避免会话/社群头像先拼成本站 /uploads
  try {
    await fetchConfig()
  } catch (e) {}
  // 语言：BOOT 即时可用；完整包后台拉取，不挡启动
  initI18n().catch(() => {})
  // 极光等推送：已装原生插件且用户未关推送时初始化
  try {
    initPushOnLaunch()
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
    path.indexOf('pages/fission/') === 0 ||
    path.indexOf('pages/master/') === 0 ||
    path.indexOf('pages/profile/') === 0
  ) {
    return
  }

  // 落在登录页 / 隐藏登录页或空 hash：进红宝；若有未清的聊天快照则回对话框
  if (!path || path.indexOf('pages/login/') === 0 || path.indexOf('gfhwgkdhf11131djfh/') === 0) {
    const room = getActiveChat()
    const chatUrl = room ? buildChatUrl(room) : ''
    if (chatUrl) {
      uni.reLaunch({ url: chatUrl })
      return
    }
    uni.reLaunch({ url: '/pages/messages/messages' })
  }
})

onShow(() => {
  initSkin()
  applyAppStatusBar()
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
}
@media (max-width: 480px) {
  page {
    --top-bar-height: 44px;
  }
}
</style>

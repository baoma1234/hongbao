<template>
  <view class="vp-page" :style="pageStyle">
    <!-- H5 / iOS：普通顶栏 -->
    <view v-if="!isAndroidApp" class="vp-bar" :style="barStyle">
      <view class="vp-back" hover-class="vp-back--on" @click="goBack">
        <text class="vp-back-char">‹</text>
      </view>
      <text class="vp-title" :style="titleStyle">视频</text>
      <view class="vp-close" hover-class="vp-back--on" @click="goBack">
        <text class="vp-close-text" :style="titleStyle">关闭</text>
      </view>
    </view>

    <view class="vp-body">
      <!-- 安卓：先空挂载再出 video，避免 70→71 原生层残留导致无法播 -->
      <video
        v-if="showPlayer && playSrc"
        :id="videoDomId"
        :key="videoDomId"
        class="vp-video"
        :src="playSrc"
        :poster="playPoster || undefined"
        controls
        autoplay
        :show-fullscreen-btn="false"
        show-center-play-btn
        object-fit="contain"
        playsinline
        @error="onVideoError"
        @fullscreenchange="onFullscreenChange"
      >
        <!-- #ifdef APP-PLUS -->
        <!-- 安卓顶栏必须 cover-view；内容区固定 44px + line-height 44，才能上下居中 -->
        <cover-view v-if="isAndroidApp" class="vp-cover-bar" :style="coverBarStyle">
          <cover-view class="vp-cover-row" :style="coverRowStyle">
            <cover-view class="vp-cover-back" @tap="goBack">
              <cover-view class="vp-cover-txt vp-cover-txt--back">‹</cover-view>
            </cover-view>
            <cover-view class="vp-cover-title">
              <cover-view class="vp-cover-txt vp-cover-txt--title">视频</cover-view>
            </cover-view>
            <cover-view class="vp-cover-close" @tap="goBack">
              <cover-view class="vp-cover-txt vp-cover-txt--close">关闭</cover-view>
            </cover-view>
          </cover-view>
        </cover-view>
        <!-- #endif -->
      </video>
      <view v-else class="vp-empty">
        <text>{{ emptyTip }}</text>
        <view class="vp-empty-close" @click="goBack">
          <text>关闭</text>
        </view>
      </view>
    </view>
  </view>
</template>

<script setup>
import { onLoad, onShow, onHide, onUnload, onBackPress } from '@dcloudio/uni-app'
import { ref } from 'vue'
import { getSafeAreaInsets, getTopBarContentHeight } from '../../utils/safe-area.js'
import { safeNavigateBack, HOME_TAB } from '../../utils/nav.js'
import { CHAT_VIDEO_PLAY_STORAGE_KEY } from '../../utils/chat-route.js'

/** 安卓 cover-view 内容行固定高度（与 line-height 一致才能垂直居中） */
const COVER_ROW_H = 44

const isAndroidApp = ref(false)
const showPlayer = ref(false)
const playSrc = ref('')
const playPoster = ref('')
const emptyTip = ref('加载中…')
const pageStyle = ref({})
const barStyle = ref({})
const titleStyle = ref({})
const coverBarStyle = ref({})
const coverRowStyle = ref({ height: COVER_ROW_H + 'px' })
const videoDomId = ref('chatVpVideo')

let pendingUrl = ''
let pendingPoster = ''
let tearingDown = false
let leaveDelayMs = 40
let mountTimer = null
let pageAlive = false
let statusTopPx = 0

function detectAndroid() {
  try {
    const p = String((uni.getSystemInfoSync() || {}).platform || '').toLowerCase()
    return p === 'android'
  } catch (e) {
    return false
  }
}

function refreshSafe() {
  const inset = getSafeAreaInsets() || {}
  statusTopPx = Math.max(0, inset.top | 0)
  const barH = Math.max(44, getTopBarContentHeight() | 0)
  if (isAndroidApp.value) {
    // 安卓：状态栏垫在 cover-bar 内，页面不再 paddingTop，避免双层空白
    pageStyle.value = { paddingTop: '0px' }
    coverBarStyle.value = {
      paddingTop: statusTopPx + 'px',
      height: statusTopPx + COVER_ROW_H + 'px',
    }
    coverRowStyle.value = {
      height: COVER_ROW_H + 'px',
    }
  } else {
    pageStyle.value = { paddingTop: statusTopPx + 'px' }
    barStyle.value = { height: barH + 'px' }
    titleStyle.value = { lineHeight: barH + 'px' }
  }
}

function clearStorage() {
  try {
    uni.removeStorageSync(CHAT_VIDEO_PLAY_STORAGE_KEY)
  } catch (e) {}
}

function clearMountTimer() {
  if (mountTimer) {
    clearTimeout(mountTimer)
    mountTimer = null
  }
}

function killPlayer() {
  clearMountTimer()
  // #ifdef APP-PLUS
  try {
    if (showPlayer.value && playSrc.value) {
      const ctx = uni.createVideoContext(videoDomId.value)
      if (ctx) {
        try {
          if (typeof ctx.pause === 'function') ctx.pause()
        } catch (e0) {}
        try {
          if (typeof ctx.stop === 'function') ctx.stop()
        } catch (e1) {}
      }
    }
  } catch (e) {}
  // #endif
  showPlayer.value = false
  playSrc.value = ''
  playPoster.value = ''
}

function exitFullscreen() {
  // #ifdef APP-PLUS
  try {
    const ctx = uni.createVideoContext(videoDomId.value)
    if (ctx && typeof ctx.exitFullScreen === 'function') ctx.exitFullScreen()
  } catch (e) {}
  // #endif
}

function onFullscreenChange(e) {
  const full = !!(e && e.detail && (e.detail.fullScreen || e.detail.fullscreen))
  if (full) exitFullscreen()
}

function goBack() {
  if (tearingDown) return
  tearingDown = true
  pageAlive = false
  exitFullscreen()
  killPlayer()
  clearStorage()
  setTimeout(() => {
    safeNavigateBack(HOME_TAB)
    setTimeout(() => {
      tearingDown = false
    }, 800)
  }, leaveDelayMs)
}

function onVideoError() {
  emptyTip.value = '视频加载失败'
  killPlayer()
  try {
    uni.showToast({ title: '视频加载失败', icon: 'none' })
  } catch (e) {}
}

function readPayload(q) {
  let url = ''
  let posterUrl = ''
  let token = ''
  try {
    const raw = uni.getStorageSync(CHAT_VIDEO_PLAY_STORAGE_KEY)
    const data = raw ? (typeof raw === 'string' ? JSON.parse(raw) : raw) : null
    if (data && typeof data === 'object') {
      url = String(data.url || '').trim()
      posterUrl = String(data.poster || '').trim()
      token = String(data.token || data.ts || '').trim()
    }
  } catch (e) {}
  if (!url && q && q.url) {
    try {
      url = decodeURIComponent(String(q.url || '').trim())
    } catch (e2) {
      url = String(q.url || '').trim()
    }
  }
  if (!posterUrl && q && q.poster) {
    try {
      posterUrl = decodeURIComponent(String(q.poster || '').trim())
    } catch (e3) {
      posterUrl = String(q.poster || '').trim()
    }
  }
  if (!token && q && q.t) token = String(q.t || '')
  if (url && !/^https?:\/\//i.test(url)) url = ''
  if (posterUrl && !/^https?:\/\//i.test(posterUrl)) posterUrl = ''
  return { url, posterUrl, token }
}

/** 卸掉旧实例 → 换 id → 延迟再挂载，专治安卓 70→71 黑屏 */
function scheduleMount() {
  clearMountTimer()
  killPlayer()
  if (!pendingUrl) {
    emptyTip.value = '视频地址无效'
    return
  }
  emptyTip.value = '加载中…'
  const token = String(Date.now()) + Math.random().toString(36).slice(2, 6)
  videoDomId.value = 'chatVpVideo_' + token
  const delay = isAndroidApp.value ? 360 : 0
  mountTimer = setTimeout(() => {
    mountTimer = null
    if (!pageAlive || tearingDown) return
    playPoster.value = pendingPoster
    playSrc.value = pendingUrl
    showPlayer.value = true
  }, delay)
}

function applyFromQuery(q) {
  const { url, posterUrl, token } = readPayload(q)
  pendingUrl = url
  pendingPoster = posterUrl
  if (token) {
    videoDomId.value = 'chatVpVideo_' + String(token).replace(/\W/g, '').slice(-12)
  }
  if (!pendingUrl) {
    emptyTip.value = '视频地址无效'
    try {
      uni.showToast({ title: '视频地址无效', icon: 'none' })
    } catch (e) {}
    return
  }
  scheduleMount()
}

onLoad((q) => {
  isAndroidApp.value = detectAndroid()
  leaveDelayMs = isAndroidApp.value ? 420 : 40
  tearingDown = false
  pageAlive = true
  refreshSafe()
  applyFromQuery(q)
})

onShow(() => {
  pageAlive = true
  // 从后台回前台或二次进入：若未在播且有地址，强制再挂一次
  if (isAndroidApp.value && pendingUrl && !showPlayer.value && !tearingDown) {
    scheduleMount()
  }
})

onHide(() => {
  pageAlive = false
  killPlayer()
})

onUnload(() => {
  pageAlive = false
  killPlayer()
  clearStorage()
  tearingDown = false
})

// #ifdef APP-PLUS
onBackPress((e) => {
  if (e && e.from === 'navigateBack') {
    killPlayer()
    return false
  }
  goBack()
  return true
})
// #endif
</script>

<style scoped>
.vp-page {
  display: flex;
  flex-direction: column;
  width: 100%;
  height: 100vh;
  height: 100dvh;
  background: #000;
  box-sizing: border-box;
  overflow: hidden;
  position: relative;
}
.vp-bar {
  position: relative;
  flex-shrink: 0;
  width: 100%;
  padding: 0 4px;
  background: #111;
  box-sizing: border-box;
  z-index: 20;
}
.vp-back,
.vp-close {
  position: absolute;
  top: 0;
  height: 100%;
  min-height: 44px;
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 2;
}
.vp-back {
  left: 0;
  width: 44px;
}
.vp-close {
  right: 0;
  min-width: 56px;
  padding: 0 10px;
}
.vp-back--on {
  opacity: 0.65;
}
.vp-back-char {
  color: #fff;
  font-size: 32px;
  line-height: 1;
  font-weight: 300;
}
.vp-close-text {
  color: #fff;
  font-size: 15px;
}
.vp-title {
  position: absolute;
  left: 56px;
  right: 56px;
  top: 0;
  bottom: 0;
  color: #fff;
  font-size: 17px;
  font-weight: 600;
  text-align: center;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  pointer-events: none;
}
.vp-body {
  flex: 1;
  min-height: 0;
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #000;
  z-index: 1;
}
.vp-video {
  width: 100%;
  height: 100%;
  background: #000;
}
.vp-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 16px;
  color: rgba(255, 255, 255, 0.7);
  font-size: 15px;
}
.vp-empty-close {
  padding: 8px 20px;
  border: 1px solid rgba(255, 255, 255, 0.45);
  border-radius: 6px;
  color: #fff;
}

/* —— 安卓 cover-view：不用 flex；行高=行高 44 才能垂直居中 —— */
.vp-cover-bar {
  position: absolute;
  left: 0;
  top: 0;
  width: 100%;
  background-color: #111111;
  box-sizing: border-box;
}
.vp-cover-row {
  position: relative;
  width: 100%;
}
.vp-cover-back {
  position: absolute;
  left: 0;
  top: 0;
  width: 48px;
  height: 44px;
}
.vp-cover-title {
  position: absolute;
  left: 56px;
  right: 64px;
  top: 0;
  height: 44px;
}
.vp-cover-close {
  position: absolute;
  right: 0;
  top: 0;
  width: 64px;
  height: 44px;
}
.vp-cover-txt {
  width: 100%;
  height: 44px;
  line-height: 44px;
  color: #ffffff;
  text-align: center;
  overflow: hidden;
}
.vp-cover-txt--back {
  font-size: 30px;
  font-weight: 300;
}
.vp-cover-txt--title {
  font-size: 17px;
  font-weight: 600;
}
.vp-cover-txt--close {
  font-size: 15px;
}
</style>

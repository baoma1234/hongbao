<template>
  <view class="vp-page" :style="pageStyle">
    <!--
      安卓：不要 webview 顶栏（会被原生 VideoView 盖住且会和 cover 关闭重复）。
      整页只留 video，顶栏用 cover-view 画在原生层上（‹ / 视频 / 关闭 各一个）。
      H5 / iOS：普通顶栏即可。
    -->
    <view v-if="!androidNativeBar" class="vp-bar" :style="barStyle">
      <view class="vp-back" hover-class="vp-back--on" @click="goBack">
        <text class="vp-back-char">‹</text>
      </view>
      <text class="vp-title" :style="titleStyle">视频</text>
      <view class="vp-close" hover-class="vp-back--on" @click="goBack">
        <text class="vp-close-text" :style="titleStyle">关闭</text>
      </view>
    </view>

    <view class="vp-body">
      <video
        v-if="src"
        :id="videoDomId"
        :key="videoDomId"
        class="vp-video"
        :src="src"
        :poster="poster || undefined"
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
        <cover-view v-if="androidNativeBar" class="vp-cover-bar" :style="coverBarStyle">
          <cover-view class="vp-cover-back" @tap="goBack">
            <cover-view class="vp-cover-back-char" :style="coverTitleStyle">‹</cover-view>
          </cover-view>
          <cover-view class="vp-cover-title" :style="coverTitleStyle">视频</cover-view>
          <cover-view class="vp-cover-close" @tap="goBack">
            <cover-view class="vp-cover-close-text" :style="coverTitleStyle">关闭</cover-view>
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
import { onLoad, onHide, onUnload, onBackPress } from '@dcloudio/uni-app'
import { ref } from 'vue'
import { getSafeAreaInsets, getTopBarContentHeight } from '../../utils/safe-area.js'
import { safeNavigateBack, HOME_TAB } from '../../utils/nav.js'
import { CHAT_VIDEO_PLAY_STORAGE_KEY } from '../../utils/chat-route.js'

const src = ref('')
const poster = ref('')
const emptyTip = ref('无法播放')
const pageStyle = ref({})
const barStyle = ref({})
const titleStyle = ref({})
const coverBarStyle = ref({})
const coverTitleStyle = ref({})
/** 安卓：顶栏交互全部走 cover-view */
const androidNativeBar = ref(false)
const videoDomId = ref('chatVpVideo')
let tearingDown = false
let leaveDelayMs = 40
let sessionToken = ''
let applyTimer = null
let barHPx = 60

function isAndroid() {
  try {
    const p = String((uni.getSystemInfoSync() || {}).platform || '').toLowerCase()
    return p === 'android'
  } catch (e) {
    return false
  }
}

function refreshSafe() {
  const inset = getSafeAreaInsets() || {}
  const top = Math.max(0, inset.top | 0)
  barHPx = Math.max(44, getTopBarContentHeight() | 0)
  pageStyle.value = {
    paddingTop: top + 'px',
  }
  barStyle.value = {
    height: barHPx + 'px',
  }
  // 标题/关闭行高与栏高一致，避免 44 vs 60/64 错位
  titleStyle.value = {
    lineHeight: barHPx + 'px',
  }
  coverBarStyle.value = {
    height: barHPx + 'px',
  }
  coverTitleStyle.value = {
    lineHeight: barHPx + 'px',
    height: barHPx + 'px',
  }
}

function clearStorage() {
  try {
    uni.removeStorageSync(CHAT_VIDEO_PLAY_STORAGE_KEY)
  } catch (e) {}
}

function destroyNativeVideo() {
  if (applyTimer) {
    clearTimeout(applyTimer)
    applyTimer = null
  }
  // #ifdef APP-PLUS
  try {
    const ctx = uni.createVideoContext(videoDomId.value)
    if (ctx) {
      try {
        if (typeof ctx.pause === 'function') ctx.pause()
      } catch (e0) {}
      try {
        if (typeof ctx.stop === 'function') ctx.stop()
      } catch (e1) {}
    }
  } catch (e) {}
  // #endif
  src.value = ''
  poster.value = ''
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
  exitFullscreen()
  destroyNativeVideo()
  clearStorage()
  setTimeout(() => {
    safeNavigateBack(HOME_TAB)
    setTimeout(() => {
      tearingDown = false
    }, 600)
  }, leaveDelayMs)
}

function onVideoError() {
  emptyTip.value = '视频加载失败'
  destroyNativeVideo()
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

function applyPayload(q) {
  const { url, posterUrl, token } = readPayload(q)
  sessionToken = token || String(Date.now())
  if (applyTimer) {
    clearTimeout(applyTimer)
    applyTimer = null
  }
  // 先卸掉旧原生实例，再换 id + 赋 src，避免 70→71 黑屏/无法播
  destroyNativeVideo()
  tearingDown = false
  videoDomId.value = 'chatVpVideo_' + sessionToken.replace(/\W/g, '').slice(-12)
  const delay = androidNativeBar.value ? 120 : 0
  applyTimer = setTimeout(() => {
    applyTimer = null
    if (tearingDown) return
    src.value = url
    poster.value = posterUrl
    if (!src.value) {
      emptyTip.value = '视频地址无效'
      try {
        uni.showToast({ title: '视频地址无效', icon: 'none' })
      } catch (e) {}
    }
  }, delay)
}

onLoad((q) => {
  refreshSafe()
  const android = isAndroid()
  androidNativeBar.value = android
  leaveDelayMs = android ? 280 : 40
  tearingDown = false
  applyPayload(q)
})

onHide(() => {
  // 离开页即拆原生层，避免叠到下一个群的 chat / video
  destroyNativeVideo()
})

onUnload(() => {
  destroyNativeVideo()
  clearStorage()
  tearingDown = false
})

// #ifdef APP-PLUS
onBackPress((e) => {
  if (e && e.from === 'navigateBack') {
    destroyNativeVideo()
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
/* 安卓 cover-view 顶栏：贴在 video 顶部，盖住原生层 */
.vp-cover-bar {
  position: absolute;
  left: 0;
  top: 0;
  width: 100%;
  background-color: #111111;
}
.vp-cover-back {
  position: absolute;
  left: 0;
  top: 0;
  width: 48px;
  height: 100%;
}
.vp-cover-back-char {
  width: 48px;
  color: #ffffff;
  font-size: 32px;
  font-weight: 300;
  text-align: center;
}
.vp-cover-title {
  position: absolute;
  left: 56px;
  right: 56px;
  top: 0;
  color: #ffffff;
  font-size: 17px;
  font-weight: 600;
  text-align: center;
}
.vp-cover-close {
  position: absolute;
  right: 0;
  top: 0;
  width: 64px;
  height: 100%;
}
.vp-cover-close-text {
  width: 64px;
  color: #ffffff;
  font-size: 15px;
  text-align: center;
}
</style>

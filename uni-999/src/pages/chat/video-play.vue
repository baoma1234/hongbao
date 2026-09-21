<template>
  <view class="vp-page" :style="pageStyle">
    <view class="vp-bar" :style="barStyle">
      <view class="vp-back" hover-class="vp-back--on" @click="goBack">
        <text class="vp-back-char">‹</text>
      </view>
      <text class="vp-title">视频</text>
      <view class="vp-back vp-back--spacer" aria-hidden="true" />
    </view>
    <view class="vp-body">
      <video
        v-if="src"
        id="chatVpVideo"
        class="vp-video"
        :src="src"
        :poster="poster || undefined"
        controls
        autoplay
        show-center-play-btn
        object-fit="contain"
        playsinline
        @error="onVideoError"
      />
      <view v-else class="vp-empty">
        <text>{{ emptyTip }}</text>
      </view>
    </view>
    <!-- 安卓：原生 video 会盖住普通 view，用 cover-view 保证返回可点 -->
    <!-- #ifdef APP-PLUS -->
    <cover-view v-if="androidCover" class="vp-cover-bar" :style="coverBarStyle">
      <cover-view class="vp-cover-back" @tap="goBack">
        <cover-view class="vp-cover-back-char">‹</cover-view>
      </cover-view>
      <cover-view class="vp-cover-title">视频</cover-view>
      <cover-view class="vp-cover-spacer" />
    </cover-view>
    <!-- #endif -->
  </view>
</template>

<script setup>
import { onLoad, onHide, onUnload, onBackPress } from '@dcloudio/uni-app'
import { ref } from 'vue'
import { getSafeAreaInsets, getTopBarContentHeight } from '../../utils/safe-area.js'
import { safeNavigateBack, HOME_TAB } from '../../utils/nav.js'
import { CHAT_VIDEO_PLAY_STORAGE_KEY } from '../../utils/chat-route.js'

const VIDEO_CTX_ID = 'chatVpVideo'
const src = ref('')
const poster = ref('')
const emptyTip = ref('无法播放')
const pageStyle = ref({})
const barStyle = ref({})
const coverBarStyle = ref({})
const androidCover = ref(false)
let tearingDown = false
let leaveDelayMs = 40

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
  const barH = Math.max(44, getTopBarContentHeight() | 0)
  pageStyle.value = {
    paddingTop: top + 'px',
  }
  barStyle.value = {
    height: barH + 'px',
  }
  coverBarStyle.value = {
    paddingTop: top + 'px',
    height: top + barH + 'px',
  }
}

function clearStorage() {
  try {
    uni.removeStorageSync(CHAT_VIDEO_PLAY_STORAGE_KEY)
  } catch (e) {}
}

function destroyNativeVideo() {
  // #ifdef APP-PLUS
  try {
    const ctx = uni.createVideoContext(VIDEO_CTX_ID)
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

function goBack() {
  if (tearingDown) return
  tearingDown = true
  destroyNativeVideo()
  clearStorage()
  setTimeout(() => {
    safeNavigateBack(HOME_TAB)
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
  try {
    const raw = uni.getStorageSync(CHAT_VIDEO_PLAY_STORAGE_KEY)
    const data = raw ? (typeof raw === 'string' ? JSON.parse(raw) : raw) : null
    if (data && typeof data === 'object') {
      url = String(data.url || '').trim()
      posterUrl = String(data.poster || '').trim()
    }
  } catch (e) {}
  // 兼容旧：query 传址（短 URL）；优先 storage，避免长 OSS 链被截断
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
  if (url && !/^https?:\/\//i.test(url)) url = ''
  if (posterUrl && !/^https?:\/\//i.test(posterUrl)) posterUrl = ''
  return { url, posterUrl }
}

onLoad((q) => {
  refreshSafe()
  const android = isAndroid()
  androidCover.value = android
  leaveDelayMs = android ? 160 : 40
  const { url, posterUrl } = readPayload(q)
  src.value = url
  poster.value = posterUrl
  if (!src.value) {
    emptyTip.value = '视频地址无效'
    uni.showToast({ title: '视频地址无效', icon: 'none' })
  }
})

onHide(() => {
  // 系统返回 / navigateBack 都会走这里：必须卸掉原生层，否则切群后点不动
  destroyNativeVideo()
})

onUnload(() => {
  destroyNativeVideo()
  clearStorage()
})

// #ifdef APP-PLUS
onBackPress(() => {
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
  display: flex;
  flex-direction: row;
  align-items: center;
  justify-content: space-between;
  flex-shrink: 0;
  padding: 0 4px;
  background: #111;
  box-sizing: border-box;
  z-index: 2;
}
.vp-back {
  width: 44px;
  height: 44px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.vp-back--on {
  opacity: 0.65;
}
.vp-back--spacer {
  pointer-events: none;
  opacity: 0;
}
.vp-back-char {
  color: #fff;
  font-size: 32px;
  line-height: 1;
  font-weight: 300;
}
.vp-title {
  color: #fff;
  font-size: 17px;
  font-weight: 600;
}
.vp-body {
  flex: 1;
  min-height: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #000;
}
.vp-video {
  width: 100%;
  height: 100%;
  background: #000;
}
.vp-empty {
  color: rgba(255, 255, 255, 0.7);
  font-size: 15px;
}
.vp-cover-bar {
  position: absolute;
  left: 0;
  right: 0;
  top: 0;
  z-index: 99;
  display: flex;
  flex-direction: row;
  align-items: flex-end;
  justify-content: space-between;
  background-color: rgba(17, 17, 17, 0.92);
  box-sizing: border-box;
}
.vp-cover-back {
  width: 44px;
  height: 44px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.vp-cover-back-char {
  color: #ffffff;
  font-size: 32px;
  line-height: 44px;
  text-align: center;
}
.vp-cover-title {
  flex: 1;
  color: #ffffff;
  font-size: 17px;
  font-weight: 600;
  line-height: 44px;
  text-align: center;
}
.vp-cover-spacer {
  width: 44px;
  height: 44px;
}
</style>

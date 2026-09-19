<template>
  <view class="chat-media-video-wrap" :class="'chat-hls-host-' + uid">
    <!-- #ifdef H5 -->
    <video
      :id="domId"
      class="chat-media-video"
      :src="h5NativeSrc"
      controls
      playsinline
      webkit-playsinline
      x5-playsinline
      preload="metadata"
      poster=""
      @play="onPlay"
      @playing="onPlay"
      @error="onVideoError"
    />
    <!-- #endif -->
    <!-- #ifndef H5 -->
    <video
      :id="domId"
      class="chat-media-video"
      :src="src"
      controls
      playsinline
      object-fit="contain"
      :poster="effectivePoster || ''"
      preload="metadata"
      @play="onPlay"
      @playing="onPlay"
      @error="onVideoError"
    />
    <!-- #endif -->
    <!-- 用 image 封面代替 video poster，避免 H5/Safari/App 滚动时封面脱层跟着飘 -->
    <view v-if="showPoster" class="chat-media-video-poster-layer" @click.stop="dismissPoster">
      <image class="chat-media-video-poster" :src="effectivePoster" mode="aspectFit" />
      <view class="chat-media-video-play">
        <text class="chat-media-video-play-ico">▶</text>
      </view>
    </view>
    <view v-if="errTip" class="chat-media-video-err" @click.stop="retry">
      <text>{{ errTip }}</text>
      <text class="chat-media-video-retry">点击重试</text>
    </view>
  </view>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { captureVideoFirstFrame, isHlsUrl } from '../utils/chat.js'

const props = defineProps({
  src: { type: String, default: '' },
  poster: { type: String, default: '' },
})

const uid = ref('v' + Date.now().toString(36) + Math.random().toString(36).slice(2, 7))
const domId = computed(() => 'chat-hls-' + uid.value)
const errTip = ref('')
const h5NativeSrc = ref('')
const started = ref(false)
const autoPoster = ref('')
let hlsInst = null
let destroyed = false
let capturing = false

const effectivePoster = computed(() => {
  const p = String(props.poster || '').trim()
  if (p) return p
  return String(autoPoster.value || '').trim()
})

const showPoster = computed(() => !!(effectivePoster.value && !started.value))

function canNativeHls(videoEl) {
  try {
    if (!videoEl || typeof videoEl.canPlayType !== 'function') return false
    return !!videoEl.canPlayType('application/vnd.apple.mpegurl')
  } catch (e) {
    return false
  }
}

function destroyHls() {
  try {
    if (hlsInst) {
      hlsInst.destroy()
      hlsInst = null
    }
  } catch (e) {}
}

function resolveVideoEl() {
  // #ifdef H5
  try {
    if (typeof document === 'undefined') return null
    const byId = document.getElementById(domId.value)
    if (byId && byId.tagName === 'VIDEO') return byId
    if (byId && byId.querySelector) {
      const inner = byId.querySelector('video')
      if (inner) return inner
    }
    const host = document.querySelector('.chat-hls-host-' + uid.value)
    if (host) {
      const v = host.querySelector('video')
      if (v) return v
    }
  } catch (e) {}
  // #endif
  return null
}

async function tryAutoPoster() {
  if (destroyed || capturing) return
  if (String(props.poster || '').trim()) return
  if (autoPoster.value) return
  const url = String(props.src || '').trim()
  if (!url || isHlsUrl(url)) return
  capturing = true
  try {
    // #ifdef H5
    const snapped = await captureVideoFirstFrame(url)
    if (!destroyed && snapped) autoPoster.value = snapped
    // #endif
  } catch (e) {
  } finally {
    capturing = false
  }
}

async function setupH5() {
  // #ifdef H5
  destroyHls()
  errTip.value = ''
  const url = String(props.src || '').trim()
  if (!url) {
    h5NativeSrc.value = ''
    return
  }
  await nextTick()
  await new Promise((r) => setTimeout(r, 40))
  if (destroyed) return
  const el = resolveVideoEl()
  if (!isHlsUrl(url)) {
    h5NativeSrc.value = url
    tryAutoPoster()
    return
  }
  // Safari / iOS：原生 HLS
  if (el && canNativeHls(el)) {
    h5NativeSrc.value = url
    try {
      el.src = url
    } catch (e) {}
    return
  }
  // Chrome / Android H5：hls.js
  h5NativeSrc.value = ''
  try {
    const mod = await import('hls.js')
    const Hls = mod.default || mod
    if (destroyed) return
    if (!Hls || !Hls.isSupported()) {
      h5NativeSrc.value = url
      errTip.value = ''
      return
    }
    const video = resolveVideoEl()
    if (!video) {
      errTip.value = '视频组件未就绪'
      return
    }
    hlsInst = new Hls({
      enableWorker: true,
      lowLatencyMode: false,
      maxBufferLength: 30,
    })
    hlsInst.loadSource(url)
    hlsInst.attachMedia(video)
    hlsInst.on(Hls.Events.ERROR, (_evt, data) => {
      if (!data || !data.fatal) return
      errTip.value = '视频加载失败'
      try {
        if (data.type === Hls.ErrorTypes.NETWORK_ERROR) hlsInst.startLoad()
        else if (data.type === Hls.ErrorTypes.MEDIA_ERROR) hlsInst.recoverMediaError()
      } catch (e2) {}
    })
  } catch (e) {
    h5NativeSrc.value = url
    errTip.value = '播放器加载失败'
  }
  // #endif
}

function onPlay() {
  started.value = true
}

function dismissPoster() {
  started.value = true
  nextTick(() => {
    // #ifdef H5
    try {
      const el = resolveVideoEl()
      if (el && typeof el.play === 'function') {
        const p = el.play()
        if (p && typeof p.catch === 'function') p.catch(() => {})
      }
    } catch (e) {}
    // #endif
  })
}

function onVideoError() {
  if (isHlsUrl(props.src) && !errTip.value) {
    errTip.value = '无法播放该视频'
  }
}

function retry() {
  errTip.value = ''
  started.value = false
  // #ifdef H5
  setupH5()
  // #endif
}

watch(
  () => props.src,
  () => {
    started.value = false
    autoPoster.value = ''
    // #ifdef H5
    setupH5()
    // #endif
  }
)

watch(
  () => props.poster,
  (v) => {
    if (String(v || '').trim()) {
      autoPoster.value = ''
    } else {
      tryAutoPoster()
    }
  }
)

onMounted(() => {
  // #ifdef H5
  setupH5()
  // #endif
})

onBeforeUnmount(() => {
  destroyed = true
  destroyHls()
  // App 原生 video 层：离开时 pause/stop，避免切群后黑影悬浮跟着滚
  // #ifndef H5
  try {
    const ctx = uni.createVideoContext(domId.value)
    if (ctx) {
      if (typeof ctx.pause === 'function') ctx.pause()
      if (typeof ctx.stop === 'function') ctx.stop()
    }
  } catch (e) {}
  // #endif
  // #ifdef H5
  try {
    const el = resolveVideoEl()
    if (el) {
      el.pause && el.pause()
      el.removeAttribute && el.removeAttribute('src')
      el.load && el.load()
    }
  } catch (e2) {}
  // #endif
})
</script>

<style scoped>
/* 必须 relative 文档流：absolute 包 video 会在 scroll-view 里脱层（H5/Safari/App） */
.chat-media-video-wrap {
  position: relative;
  display: block;
  width: 100%;
  height: 100%;
  min-height: 180px;
  max-width: none;
  overflow: hidden;
  background: #000;
  box-sizing: border-box;
}
.chat-media-video {
  position: relative;
  z-index: 0;
  display: block;
  width: 100%;
  height: 100%;
  min-height: 180px;
  max-width: none;
  max-height: none;
  border-radius: 0;
  background: #000;
  object-fit: contain;
  box-sizing: border-box;
  -webkit-backface-visibility: hidden;
  backface-visibility: hidden;
}
.chat-media-video-poster-layer {
  position: absolute;
  left: 0;
  top: 0;
  right: 0;
  bottom: 0;
  z-index: 2;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #000;
  overflow: hidden;
}
.chat-media-video-poster {
  position: absolute;
  left: 0;
  top: 0;
  width: 100%;
  height: 100%;
}
.chat-media-video-play {
  position: relative;
  z-index: 1;
  width: 52px;
  height: 52px;
  border-radius: 50%;
  background: rgba(0, 0, 0, 0.45);
  border: 2px solid rgba(255, 255, 255, 0.88);
  display: flex;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;
}
.chat-media-video-play-ico {
  color: #fff;
  font-size: 20px;
  line-height: 1;
  margin-left: 3px;
}
.chat-media-video-err {
  position: absolute;
  left: 0;
  top: 0;
  right: 0;
  bottom: 0;
  z-index: 3;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  background: rgba(0, 0, 0, 0.72);
  color: #fff;
  font-size: 12px;
  border-radius: 0;
  padding: 8px;
  text-align: center;
}
.chat-media-video-retry {
  color: #7ec8ff;
  font-size: 12px;
}
</style>

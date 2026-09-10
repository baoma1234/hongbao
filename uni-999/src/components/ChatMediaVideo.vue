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
      :poster="poster || ''"
      @error="onVideoError"
    />
    <!-- #endif -->
    <!-- #ifndef H5 -->
    <video
      class="chat-media-video"
      :src="src"
      controls
      playsinline
      object-fit="contain"
      :poster="poster || ''"
      @error="onVideoError"
    />
    <!-- #endif -->
    <view v-if="errTip" class="chat-media-video-err" @click.stop="retry">
      <text>{{ errTip }}</text>
      <text class="chat-media-video-retry">点击重试</text>
    </view>
  </view>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { isHlsUrl } from '../utils/chat.js'

const props = defineProps({
  src: { type: String, default: '' },
  poster: { type: String, default: '' },
})

const uid = ref('v' + Date.now().toString(36) + Math.random().toString(36).slice(2, 7))
const domId = computed(() => 'chat-hls-' + uid.value)
const errTip = ref('')
const h5NativeSrc = ref('')
let hlsInst = null
let destroyed = false

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
      // 兜底：仍写 src，部分 WebView 可能能播
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

function onVideoError() {
  if (isHlsUrl(props.src) && !errTip.value) {
    errTip.value = '无法播放该视频'
  }
}

function retry() {
  errTip.value = ''
  // #ifdef H5
  setupH5()
  // #endif
}

watch(
  () => props.src,
  () => {
    // #ifdef H5
    setupH5()
    // #endif
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
})
</script>

<style scoped>
.chat-media-video-wrap {
  position: relative;
  width: 100%;
  max-width: 240px;
}
.chat-media-video {
  width: 100%;
  max-width: 240px;
  max-height: 320px;
  border-radius: 8px;
  background: #000;
  display: block;
}
.chat-media-video-err {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  background: rgba(0, 0, 0, 0.72);
  color: #fff;
  font-size: 12px;
  border-radius: 8px;
  padding: 8px;
  text-align: center;
}
.chat-media-video-retry {
  color: #7ec8ff;
  font-size: 12px;
}
</style>

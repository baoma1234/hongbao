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
        class="vp-video"
        :src="src"
        :poster="poster || undefined"
        controls
        autoplay
        show-center-play-btn
        object-fit="contain"
        playsinline
      />
      <view v-else class="vp-empty">
        <text>无法播放</text>
      </view>
    </view>
  </view>
</template>

<script setup>
import { onLoad } from '@dcloudio/uni-app'
import { ref } from 'vue'
import { getSafeAreaInsets, getTopBarContentHeight } from '../../utils/safe-area.js'
import { safeNavigateBack, HOME_TAB } from '../../utils/nav.js'

const src = ref('')
const poster = ref('')
const pageStyle = ref({})
const barStyle = ref({})

function refreshSafe() {
  const inset = getSafeAreaInsets() || {}
  const top = Math.max(0, inset.top | 0)
  const barH = getTopBarContentHeight() | 0
  pageStyle.value = {
    paddingTop: top + 'px',
  }
  barStyle.value = {
    height: Math.max(44, barH || 44) + 'px',
  }
}

function goBack() {
  src.value = ''
  setTimeout(() => {
    safeNavigateBack(HOME_TAB)
  }, 40)
}

onLoad((q) => {
  refreshSafe()
  src.value = String((q && q.url) || '').trim()
  poster.value = String((q && q.poster) || '').trim()
  if (!src.value) {
    uni.showToast({ title: '视频地址无效', icon: 'none' })
  }
})
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
</style>

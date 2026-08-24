<template>
  <view
    v-if="visible"
    class="local-push-banner"
    :style="{ top: topPx + 'px' }"
    @click="onTap"
  >
    <view class="local-push-inner">
      <view class="local-push-mark">红宝</view>
      <view class="local-push-main">
        <text class="local-push-title">{{ title }}</text>
        <text class="local-push-body">{{ body }}</text>
      </view>
      <view class="local-push-close" @click.stop="onClose">×</view>
    </view>
  </view>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import {
  dismissLocalPush,
  onLocalPush,
  openLocalPushChat,
} from '../utils/local-push.js'

const visible = ref(false)
const title = ref('')
const body = ref('')
const topPx = ref(12)
let chat = null
let off = null

onMounted(() => {
  off = onLocalPush((payload) => {
    if (!payload) {
      visible.value = false
      chat = null
      return
    }
    title.value = String(payload.title || '新消息')
    body.value = String(payload.body || '')
    topPx.value = Math.max(8, payload.topPx | 0)
    chat = payload.chat || null
    visible.value = true
  })
})

onUnmounted(() => {
  if (off) off()
  off = null
})

function onTap() {
  const c = chat
  dismissLocalPush()
  if (c) openLocalPushChat(c)
}

function onClose() {
  dismissLocalPush()
}
</script>

<style scoped>
.local-push-banner {
  position: fixed;
  left: 10px;
  right: 10px;
  z-index: 10050;
  pointer-events: auto;
}
.local-push-inner {
  display: flex;
  flex-direction: row;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border-radius: 14px;
  background: rgba(28, 32, 40, 0.94);
  box-shadow: 0 8px 28px rgba(0, 0, 0, 0.28);
}
.local-push-mark {
  flex-shrink: 0;
  width: 36px;
  height: 36px;
  border-radius: 9px;
  background: #e63022;
  color: #fff;
  font-size: 12px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
}
.local-push-main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.local-push-title {
  color: #fff;
  font-size: 14px;
  font-weight: 600;
  line-height: 1.25;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.local-push-body {
  color: rgba(255, 255, 255, 0.82);
  font-size: 12px;
  line-height: 1.3;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.local-push-close {
  flex-shrink: 0;
  width: 28px;
  height: 28px;
  border-radius: 14px;
  color: rgba(255, 255, 255, 0.7);
  font-size: 20px;
  line-height: 28px;
  text-align: center;
}
</style>

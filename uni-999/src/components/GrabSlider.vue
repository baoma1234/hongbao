<template>
  <view class="mask" v-if="visible" @click="cancel">
    <view class="sheet" @click.stop>
      <view class="title">滑动验证</view>
      <text class="hint">{{ hint }}</text>
      <view
        class="track"
        :class="{ 'is-fail': failed, 'is-ok': passed }"
        id="grabSliderTrack"
      >
        <view class="fill" :style="{ width: dragX + 'px' }" />
        <view
          class="thumb"
          :class="{ 'is-dragging': dragging, 'is-ok': passed }"
          :style="{ transform: 'translateX(' + dragX + 'px)' }"
          @touchstart.stop.prevent="onTouchStart"
          @touchmove.stop.prevent="onTouchMove"
          @touchend.stop.prevent="onTouchEnd"
          @touchcancel.stop.prevent="onTouchEnd"
          @mousedown.stop.prevent="onMouseDown"
        >{{ passed ? '✓' : '›' }}</view>
        <text class="track-tip" v-if="!passed && dragX < 10">拖到最右侧</text>
      </view>
      <text class="status" :class="{ 'is-fail': failed, 'is-ok': passed }">{{ status }}</text>
      <view class="actions">
        <button class="btn ghost" size="mini" :disabled="locking" @click="reload">刷新</button>
        <button class="btn ghost" size="mini" :disabled="locking" @click="cancel">取消</button>
      </view>
    </view>
  </view>
</template>

<script setup>
import { getCurrentInstance, nextTick, ref } from 'vue'
import { apiRequest } from '../utils/auth.js'

const visible = ref(false)
const hint = ref('请按住滑块，拖动到最右侧')
const status = ref('')
const challenge = ref(null)
const dragX = ref(0)
const maxX = ref(200)
const passRatio = ref(0.82)
const dragging = ref(false)
const locking = ref(false)
const failed = ref(false)
const passed = ref(false)
const startTime = ref(0)
let startClientX = 0
let startDragX = 0
let resolveFn = null
let rejectFn = null
const instance = getCurrentInstance()

function measureMax() {
  return new Promise((resolve) => {
    nextTick(() => {
      try {
        const proxy = instance && (instance.proxy || instance)
        const q = uni.createSelectorQuery()
        if (proxy) q.in(proxy)
        q.select('#grabSliderTrack')
          .boundingClientRect((rect) => {
            const trackW = (rect && rect.width) || 280
            const thumb = 44
            resolve(Math.max(80, trackW - thumb))
          })
          .exec()
      } catch (e) {
        resolve(200)
      }
    })
  })
}

async function loadChallenge() {
  locking.value = false
  passed.value = false
  failed.value = false
  dragX.value = 0
  status.value = ''
  const data = await apiRequest('grabslider', 'GET')
  if (!data || data.enabled === false) {
    return null
  }
  challenge.value = data
  hint.value = data.hint || '请按住滑块，拖动到最右侧'
  passRatio.value = Number(data.pass_ratio) > 0 ? Number(data.pass_ratio) : 0.82
  maxX.value = await measureMax()
  return data
}

function finishReject(err) {
  const rej = rejectFn
  resolveFn = null
  rejectFn = null
  visible.value = false
  if (rej) rej(err || new Error('cancelled'))
}

function finishResolve(payload) {
  const res = resolveFn
  resolveFn = null
  rejectFn = null
  visible.value = false
  if (res) res(payload)
}

function cancel() {
  if (locking.value) return
  finishReject(new Error('grab cancelled'))
}

async function reload() {
  if (locking.value) return
  try {
    await loadChallenge()
  } catch (e) {
    status.value = (e && e.message) || '验证加载失败'
    failed.value = true
  }
}

/**
 * 打开抢包滑块；成功 resolve slider_* 字段，取消/失败 reject
 */
function challengeOpen() {
  return new Promise(async (resolve, reject) => {
    resolveFn = resolve
    rejectFn = reject
    visible.value = true
    try {
      const data = await loadChallenge()
      if (!data) {
        // 后端关闭滑块时直接放行空 payload
        finishResolve({})
      }
    } catch (e) {
      uni.showToast({ title: (e && e.message) || '验证加载失败', icon: 'none' })
      finishReject(e)
    }
  })
}

function pointerDown(clientX) {
  if (locking.value || passed.value) return
  dragging.value = true
  failed.value = false
  status.value = ''
  startTime.value = Date.now()
  startClientX = clientX
  startDragX = dragX.value
}

function pointerMove(clientX) {
  if (!dragging.value || locking.value) return
  const next = Math.max(0, Math.min(maxX.value, startDragX + (clientX - startClientX)))
  dragX.value = next
}

function failBack() {
  failed.value = true
  status.value = '请拖到最右侧'
  dragX.value = 0
  setTimeout(() => {
    failed.value = false
    if (!dragging.value) status.value = ''
  }, 420)
}

function succeed() {
  const ch = challenge.value
  if (!ch || !ch.token) {
    failBack()
    return
  }
  locking.value = true
  passed.value = true
  dragX.value = maxX.value
  status.value = '验证通过，继续抢包…'
  const payload = {
    slider_token: ch.token,
    slider_x: Math.round(maxX.value),
    slider_max: Math.round(maxX.value),
    slider_duration: Math.max(180, Date.now() - startTime.value),
  }
  setTimeout(() => finishResolve(payload), 220)
}

function pointerUp() {
  if (!dragging.value || locking.value) return
  dragging.value = false
  const passX = Math.floor(maxX.value * passRatio.value)
  if (dragX.value < passX) {
    failBack()
    return
  }
  succeed()
}

function onTouchStart(e) {
  const t = e.touches && e.touches[0]
  if (t) pointerDown(t.clientX)
}
function onTouchMove(e) {
  const t = e.touches && e.touches[0]
  if (t) pointerMove(t.clientX)
}
function onTouchEnd() {
  pointerUp()
}

function onMouseDown(e) {
  // #ifdef H5
  pointerDown(e.clientX)
  const move = (ev) => pointerMove(ev.clientX)
  const up = () => {
    document.removeEventListener('mousemove', move)
    document.removeEventListener('mouseup', up)
    pointerUp()
  }
  document.addEventListener('mousemove', move)
  document.addEventListener('mouseup', up)
  // #endif
}

defineExpose({ challenge: challengeOpen, open: challengeOpen })
</script>

<style scoped>
.mask {
  position: fixed;
  inset: 0;
  z-index: 1000;
  background: rgba(20, 10, 5, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 40rpx;
  box-sizing: border-box;
}
.sheet {
  width: 100%;
  max-width: 640rpx;
  background: #fff;
  border-radius: 24rpx;
  padding: 36rpx 32rpx 28rpx;
  box-shadow: 0 16rpx 48rpx rgba(40, 20, 10, 0.18);
}
.title {
  font-size: 34rpx;
  font-weight: 800;
  color: #2a1f18;
  text-align: center;
}
.hint {
  display: block;
  margin: 16rpx 0 28rpx;
  font-size: 24rpx;
  color: #9a8574;
  text-align: center;
}
.track {
  position: relative;
  height: 88rpx;
  border-radius: 44rpx;
  background: #f3ebe3;
  overflow: hidden;
  border: 2rpx solid #e8dcd0;
}
.track.is-fail {
  border-color: #e07070;
  background: #fff0f0;
}
.track.is-ok {
  border-color: #3cb371;
  background: #eefaf2;
}
.fill {
  position: absolute;
  left: 0;
  top: 0;
  bottom: 0;
  background: linear-gradient(90deg, #ffe8cc, #ffd4a8);
  border-radius: 44rpx 0 0 44rpx;
}
.thumb {
  position: absolute;
  left: 0;
  top: 0;
  width: 88rpx;
  height: 88rpx;
  border-radius: 50%;
  background: #c61114;
  color: #fff;
  font-size: 40rpx;
  font-weight: 800;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 4rpx 12rpx rgba(198, 17, 20, 0.35);
  z-index: 2;
  touch-action: none;
}
.thumb.is-ok {
  background: #2e9e5b;
}
.track-tip {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 24rpx;
  color: #b09a88;
  pointer-events: none;
  padding-left: 60rpx;
}
.status {
  display: block;
  min-height: 36rpx;
  margin-top: 16rpx;
  font-size: 24rpx;
  color: #9a8574;
  text-align: center;
}
.status.is-fail {
  color: #c61114;
}
.status.is-ok {
  color: #2e9e5b;
}
.actions {
  display: flex;
  justify-content: center;
  gap: 24rpx;
  margin-top: 20rpx;
}
.btn.ghost {
  background: transparent;
  color: #8a7a6e;
  border: none;
}
</style>

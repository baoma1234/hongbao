<template>
  <view v-if="visible" class="slider-modal" @click="onMask">
    <view class="slider-box" @click.stop>
      <view class="slider-box-hd">
        <text class="slider-box-title">{{ t('slider_modal_title') || '安全验证' }}</text>
        <text class="slider-box-refresh" @click="onRefresh">{{ t('slider_refresh_btn') || '重试' }}</text>
      </view>
      <view class="slider-box-hint">{{ hintText }}</view>
      <view
        class="slider-track"
        :class="{ 'is-ok': ok, 'is-fail': fail }"
        id="u999SliderTrack"
      >
        <view class="slider-track-fill" :style="{ width: fillW + 'px' }" />
        <view class="slider-track-hint" :style="{ opacity: dragX > 8 ? 0 : 1 }">
          {{ t('slider_track_hint') || '拖动滑块到右侧 →' }}
        </view>
        <view
          class="slider-thumb"
          :class="{ 'is-dragging': dragging, 'is-ok': ok, 'is-anim': anim }"
          :style="{ transform: 'translate3d(' + dragX + 'px,0,0)' }"
          @touchstart.prevent="onDown"
          @touchmove.prevent="onMove"
          @touchend.prevent="onUp"
          @mousedown.prevent="onMouseDown"
        >{{ ok ? '✓' : '›' }}</view>
      </view>
      <view class="slider-status" :class="{ 'is-ok': ok, 'is-fail': fail }">{{ status }}</view>
    </view>
  </view>
</template>

<script setup>
import { computed, nextTick, onUnmounted, ref, watch } from 'vue'
import { apiRequest } from '../utils/auth.js'
import { t } from '../utils/i18n.js'

const props = defineProps({
  show: { type: Boolean, default: false },
})
const emit = defineEmits(['success', 'cancel', 'update:show'])

const visible = ref(false)
const challenge = ref(null)
const dragX = ref(0)
const maxX = ref(0)
const dragging = ref(false)
const locking = ref(false)
const ok = ref(false)
const fail = ref(false)
const anim = ref(false)
const status = ref('')
const startTime = ref(0)
const startClientX = ref(0)
const startDragX = ref(0)
const fillW = computed(() => Math.max(0, dragX.value + 20))

const hintText = computed(() => {
  if (challenge.value && challenge.value.hint) return String(challenge.value.hint)
  return t('slider_modal_hint') || '请按住滑块，拖动到最右侧'
})

const PASS_RATIO = 0.82

function close(fromSuccess) {
  visible.value = false
  emit('update:show', false)
  if (!fromSuccess) emit('cancel')
  reset(false)
}

function onMask() {
  if (locking.value) return
  close(false)
}

function reset(animate) {
  dragging.value = false
  locking.value = false
  ok.value = false
  fail.value = false
  anim.value = !!animate
  status.value = ''
  dragX.value = 0
}

function measureMax() {
  // uni-app：用固定估算，与 888 320 宽弹窗接近
  maxX.value = 240
  // #ifdef H5
  try {
    const el = typeof document !== 'undefined' ? document.querySelector('.slider-track') : null
    const thumb = typeof document !== 'undefined' ? document.querySelector('.slider-thumb') : null
    if (el && thumb) {
      maxX.value = Math.max(0, el.clientWidth - thumb.offsetWidth - 4)
    }
  } catch (e) {}
  // #endif
}

async function loadChallenge() {
  reset(false)
  try {
    const data = await apiRequest('slidercaptcha', 'GET')
    if (!data || data.enabled === false) {
      emit('success', {})
      close(true)
      return
    }
    challenge.value = data
    visible.value = true
    emit('update:show', true)
    await nextTick()
    measureMax()
  } catch (e) {
    uni.showToast({ title: (e && e.message) || t('srv_slider_create_fail') || '滑块验证失败', icon: 'none' })
    emit('cancel')
    visible.value = false
    emit('update:show', false)
  }
}

function onRefresh() {
  if (locking.value) return
  loadChallenge()
}

function applyX(x, withAnim) {
  anim.value = !!withAnim
  dragX.value = Math.max(0, Math.min(maxX.value || 0, x))
}

function clientXOf(e) {
  if (e && e.touches && e.touches[0]) return e.touches[0].clientX
  if (e && e.changedTouches && e.changedTouches[0]) return e.changedTouches[0].clientX
  return e && e.clientX != null ? e.clientX : 0
}

function onDown(e) {
  if (locking.value) return
  measureMax()
  dragging.value = true
  startTime.value = Date.now()
  startClientX.value = clientXOf(e)
  startDragX.value = dragX.value
  ok.value = false
  fail.value = false
  status.value = ''
}

function onMove(e) {
  if (!dragging.value || locking.value) return
  const delta = clientXOf(e) - startClientX.value
  applyX(startDragX.value + delta, false)
}

function failBack() {
  fail.value = true
  status.value = t('alert_slider_fail') || '请拖到最右侧'
  applyX(0, true)
  setTimeout(() => {
    fail.value = false
    if (!dragging.value) status.value = ''
  }, 420)
}

function succeed() {
  const mx = maxX.value
  const ch = challenge.value
  if (!ch || !ch.token) {
    failBack()
    return
  }
  locking.value = true
  ok.value = true
  applyX(mx, true)
  status.value = t('slider_ok_sms') || '验证通过，正在发送验证码…'
  const payload = {
    slider_token: ch.token,
    slider_x: Math.round(mx),
    slider_max: Math.round(mx),
    slider_duration: Math.max(180, Date.now() - startTime.value),
  }
  setTimeout(() => {
    emit('success', payload)
    close(true)
  }, 220)
}

function onUp() {
  if (!dragging.value || locking.value) return
  dragging.value = false
  const passX = Math.floor((maxX.value || 0) * PASS_RATIO)
  if (dragX.value < passX) {
    failBack()
    return
  }
  succeed()
}

function onMouseDown(e) {
  onDown(e)
  // #ifdef H5
  const move = (ev) => onMove(ev)
  const up = () => {
    onUp()
    document.removeEventListener('mousemove', move)
    document.removeEventListener('mouseup', up)
  }
  document.addEventListener('mousemove', move)
  document.addEventListener('mouseup', up)
  // #endif
}

watch(
  () => props.show,
  (v) => {
    if (v) loadChallenge()
    else if (visible.value) close(false)
  }
)

onUnmounted(() => {
  // #ifdef H5
  // no-op
  // #endif
})

defineExpose({ open: loadChallenge, close: () => close(false) })
</script>

<style scoped>
.slider-modal {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.55);
  z-index: 9999;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  box-sizing: border-box;
}
.slider-box {
  background: #fff;
  border-radius: 14px;
  width: 100%;
  max-width: 320px;
  padding: 16px;
  box-shadow: 0 10px 40px rgba(0, 0, 0, 0.18);
  box-sizing: border-box;
}
.slider-box-hd {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}
.slider-box-title {
  font-size: 16px;
  font-weight: bold;
  color: #1a212d;
}
.slider-box-refresh {
  color: #0071ff;
  font-size: 12px;
  padding: 4px 6px;
}
.slider-box-hint {
  font-size: 12px;
  color: #657786;
  margin-bottom: 14px;
  text-align: center;
}
.slider-track {
  position: relative;
  height: 46px;
  background: #f4f6f9;
  border-radius: 23px;
  border: 1px solid #e1e8ed;
  overflow: hidden;
}
.slider-track.is-ok {
  border-color: rgba(0, 200, 83, 0.45);
  background: #f1fff6;
}
.slider-track-fill {
  position: absolute;
  left: 0;
  top: 0;
  height: 100%;
  background: linear-gradient(90deg, rgba(0, 113, 255, 0.1), rgba(0, 200, 83, 0.18));
  pointer-events: none;
}
.slider-track-hint {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  color: #657786;
  pointer-events: none;
}
.slider-thumb {
  position: absolute;
  left: 2px;
  top: 2px;
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background: linear-gradient(135deg, #0071ff 0%, #00c853 100%);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 2;
  box-shadow: 0 2px 10px rgba(0, 113, 255, 0.35);
  font-size: 20px;
  font-weight: 700;
}
.slider-thumb.is-ok {
  background: linear-gradient(135deg, #00c853 0%, #00a844 100%);
}
.slider-thumb.is-anim {
  transition: transform 0.28s cubic-bezier(0.2, 0.9, 0.2, 1);
}
.slider-status {
  min-height: 18px;
  margin-top: 10px;
  text-align: center;
  font-size: 12px;
  color: #657786;
}
.slider-status.is-ok {
  color: #00a844;
  font-weight: 600;
}
.slider-status.is-fail {
  color: #e53935;
}
</style>

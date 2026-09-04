<template>
  <view class="welcome-lottery-mask" :class="{ show: open }" data-chest-style="A">
    <view class="welcome-lottery-aura" aria-hidden="true" />
    <view class="welcome-lottery-panel">
      <view class="welcome-lottery-shine" aria-hidden="true" />
      <view class="welcome-lottery-eyebrow">{{ t('lottery_eyebrow') || '红宝 · VIP 入厅礼' }}</view>
      <view class="welcome-lottery-title">{{ t('lottery_title') || '黑金新手宝箱' }}</view>
      <view class="welcome-lottery-subtitle">{{ t('lottery_subtitle') || '轻触开启 · 锁定入场股份' }}</view>
      <view class="welcome-lottery-chest-wrap">
        <view class="wl-ring wl-ring-a" aria-hidden="true" />
        <view class="wl-ring wl-ring-b" aria-hidden="true" />
        <view
          class="welcome-lottery-chest"
          :class="{ shaking: shaking, opened: opened }"
          role="button"
          @click="onChestClick"
        >
          <view class="wl-chest-body">
            <view class="wl-chest-lid" />
            <view class="wl-chest-lock">◆</view>
            <view class="wl-chest-glow" />
          </view>
          <view class="wl-sparkles" aria-hidden="true">
            <view class="wl-spark" /><view class="wl-spark" /><view class="wl-spark" />
            <view class="wl-spark" /><view class="wl-spark" /><view class="wl-spark" />
          </view>
        </view>
        <view class="wl-burst" :class="{ show: burstShow }" aria-hidden="true" />
      </view>
      <view class="welcome-lottery-shares">{{ sharesText }}</view>
      <view class="welcome-lottery-price" :class="{ revealed: priceRevealed }">{{ priceText }}</view>
      <view class="welcome-lottery-hint" :class="{ pulse: hintPulse }">{{ hintText }}</view>
      <button
        type="button"
        class="welcome-lottery-close-btn"
        :disabled="!closeEnabled"
        @click="onClose"
      >
        {{ closeLabel }}
      </button>
    </view>
  </view>
</template>

<script setup>
import { computed, onUnmounted, ref } from 'vue'
import { t } from '../utils/i18n.js'
import '../styles/welcome-lottery.css'

const props = defineProps({
  sharePrice: { type: Number, default: 5 },
})
const emit = defineEmits(['done'])

const LOTTERY_SHARES = 5
const LOTTERY_DONE_KEY = 'fans_hub_lottery_done'
const LOTTERY_VAL_KEY = 'fans_hub_lottery_val'
const SHOW_KEY = 'fans_hub_show_lottery'

const open = ref(false)
const shaking = ref(false)
const opened = ref(false)
const burstShow = ref(false)
const priceRevealed = ref(false)
const hintPulse = ref(true)
const closeEnabled = ref(false)
const closeLabel = ref('')
const priceText = ref('￥--')
const hintText = ref('')
const lotteryOpening = ref(false)
const lotteryFinalAmount = ref(null)
let jumpTimer = null
let openTimer = null

const unitPrice = computed(() => {
  const n = Number(props.sharePrice)
  return !isNaN(n) && n > 0 ? n : 5
})

const sharesText = computed(() => {
  const s = LOTTERY_SHARES.toFixed(2)
  return t('lottery_shares_locked', { shares: s }) || `锁定 ${s} 份`
})

function storageGet(key) {
  try {
    if (typeof localStorage !== 'undefined') return localStorage.getItem(key)
  } catch (e) {}
  try {
    return uni.getStorageSync(key) || null
  } catch (e2) {
    return null
  }
}

function storageSet(key, val) {
  try {
    if (typeof localStorage !== 'undefined') localStorage.setItem(key, val)
  } catch (e) {}
  try {
    uni.setStorageSync(key, val)
  } catch (e2) {}
}

function sessionGet(key) {
  try {
    if (typeof sessionStorage !== 'undefined') return sessionStorage.getItem(key)
  } catch (e) {}
  try {
    return uni.getStorageSync(key) || null
  } catch (e2) {
    return null
  }
}

function sessionRemove(key) {
  try {
    if (typeof sessionStorage !== 'undefined') sessionStorage.removeItem(key)
  } catch (e) {}
  try {
    uni.removeStorageSync(key)
  } catch (e2) {}
}

function valuationForShares(shares) {
  const n = Number(shares)
  const s = !isNaN(n) && n > 0 ? n : LOTTERY_SHARES
  return Math.round(s * unitPrice.value * 100) / 100
}

function resetVisual() {
  shaking.value = false
  opened.value = false
  burstShow.value = false
  priceRevealed.value = false
  hintPulse.value = true
  priceText.value = '￥--'
  hintText.value = t('lottery_chest_hint') || '点击黑金宝箱 · 开启新手股份'
  closeEnabled.value = false
  closeLabel.value = t('lottery_close_wait') || '请先开启宝箱'
  lotteryOpening.value = false
  lotteryFinalAmount.value = null
  if (jumpTimer) {
    clearInterval(jumpTimer)
    jumpTimer = null
  }
}

function openModal() {
  if (storageGet(LOTTERY_DONE_KEY)) return
  resetVisual()
  open.value = true
}

function schedule() {
  if (storageGet(LOTTERY_DONE_KEY)) return
  if (sessionGet(SHOW_KEY) !== '1') return
  if (openTimer) clearTimeout(openTimer)
  openTimer = setTimeout(openModal, 1000)
}

function finish(finalAmount) {
  const amount =
    finalAmount !== undefined && finalAmount !== null
      ? finalAmount
      : valuationForShares(LOTTERY_SHARES)
  storageSet(LOTTERY_VAL_KEY, String(amount))
  storageSet(LOTTERY_DONE_KEY, '1')
  sessionRemove(SHOW_KEY)
  lotteryFinalAmount.value = null
  lotteryOpening.value = false
  open.value = false
  emit('done', { shares: LOTTERY_SHARES, amount })
}

function onClose() {
  if (!closeEnabled.value) return
  if (lotteryFinalAmount.value !== null) {
    finish(lotteryFinalAmount.value)
  } else {
    lotteryOpening.value = false
    lotteryFinalAmount.value = null
    open.value = false
  }
}

function onChestClick() {
  if (lotteryOpening.value || storageGet(LOTTERY_DONE_KEY)) return
  lotteryOpening.value = true
  const finalAmount = valuationForShares(LOTTERY_SHARES)
  const unit = unitPrice.value
  shaking.value = true
  opened.value = false
  priceRevealed.value = false
  burstShow.value = false
  hintPulse.value = false
  hintText.value = t('lottery_opening') || '开箱中…金光涌动'
  if (jumpTimer) clearInterval(jumpTimer)
  jumpTimer = setInterval(() => {
    const wobble = (Math.random() - 0.5) * Math.min(1.2, unit * 0.08)
    const jump = Math.max(unit, Math.round((finalAmount + wobble) * 100) / 100)
    priceText.value = '￥' + jump.toFixed(2)
  }, 70)
  setTimeout(() => {
    if (jumpTimer) {
      clearInterval(jumpTimer)
      jumpTimer = null
    }
    shaking.value = false
    opened.value = true
    burstShow.value = false
    setTimeout(() => {
      burstShow.value = true
    }, 20)
    priceText.value = '￥' + finalAmount.toFixed(2)
    priceRevealed.value = true
    const s = LOTTERY_SHARES.toFixed(2)
    hintText.value =
      t('lottery_result_shares', { shares: s }) || `已锁定 ${s} 份股份 · 可前往闪兑`
    lotteryFinalAmount.value = finalAmount
    lotteryOpening.value = false
    closeEnabled.value = true
    closeLabel.value = t('lottery_close_btn') || '收下入厅礼'
  }, 1100)
}

onUnmounted(() => {
  if (jumpTimer) clearInterval(jumpTimer)
  if (openTimer) clearTimeout(openTimer)
})

defineExpose({ schedule })
</script>

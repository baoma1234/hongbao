<template>
  <view
    class="chat-niuniu-card"
    :class="'phase-' + livePhase"
    @click="$emit('tap')"
    @longpress="$emit('longpress')"
  >
    <image class="nn-bg" src="/static/niuniu/bj.jpg" mode="aspectFill" lazy-load />
    <view class="nn-layer">
      <view class="nn-center">
        <text class="nn-l1">#{{ roundId || '--' }} 入场:{{ sharePrice }}</text>
        <text class="nn-l2">包数:{{ shareCount }} 官方手续费:{{ feePct }}%</text>
        <text class="nn-l3">奖池(扣除{{ feePct }}%后)</text>
        <text class="nn-l4">{{ distributableText }}</text>
      </view>
      <view class="nn-right">
        <view v-if="showCountdown" class="nn-countdown">
          <image class="nn-countdown-bg" src="/static/niuniu/countdown.png" mode="scaleToFill" />
          <text class="nn-countdown-time">{{ remainText }}</text>
        </view>
        <image class="nn-cta-btn" :src="btnSrc" mode="widthFix" />
      </view>
      <text class="nn-time">{{ timeText }}</text>
    </view>
  </view>
</template>

<script setup>
/**
 * 牛牛卡片倒计时局部化：1s tick 只重绘本组件，不 invalidate 整表消息列表。
 * APK / H5 Safari / 桌面网页共用同一逻辑。
 */
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { msgExtra } from '../utils/chat.js'

const props = defineProps({
  message: { type: Object, required: true },
  timeText: { type: String, default: '' },
})
const emit = defineEmits(['tap', 'longpress', 'expired'])

const nowSec = ref(Math.floor(Date.now() / 1000))
let tickTimer = null
let expiredEmittedFor = ''

function roundOf() {
  const ex = msgExtra(props.message)
  return (ex && ex.round) || {}
}

const roundId = computed(() => {
  const r = roundOf()
  const ex = msgExtra(props.message)
  return (r.id | 0) || (ex.round_id | 0) || 0
})

const rawPhase = computed(() => {
  const ex = msgExtra(props.message)
  return String((ex && ex.phase) || roundOf().card_phase || 'buying')
})

const livePhase = computed(() => {
  const raw = rawPhase.value
  if (raw === 'result' || raw === 'void' || raw === 'refund') return raw
  const r = roundOf()
  const now = nowSec.value
  const buyEnd = r.buy_end_at | 0
  const claimEnd = r.claim_end_at | 0
  if (raw === 'buying') {
    if (buyEnd > 0 && buyEnd <= now) {
      if (claimEnd > now) return 'claim'
      if (claimEnd > 0 && claimEnd <= now) return 'result'
      return 'result'
    }
    return 'buying'
  }
  if (raw === 'claim') {
    if (claimEnd > 0 && claimEnd <= now) return 'result'
    return 'claim'
  }
  return raw
})

const remainSec = computed(() => {
  const phase = livePhase.value
  if (phase !== 'buying' && phase !== 'claim') return 0
  const r = roundOf()
  const endAt = phase === 'claim' ? (r.claim_end_at | 0) : (r.buy_end_at | 0)
  if (endAt > 0) return Math.max(0, endAt - nowSec.value)
  const fallback = phase === 'claim' ? (r.remain_claim | 0) : (r.remain_buy | 0)
  return Math.max(0, fallback)
})

const showCountdown = computed(() => {
  const phase = livePhase.value
  return (phase === 'buying' || phase === 'claim') && remainSec.value > 0
})

const remainText = computed(() => {
  const sec = remainSec.value
  const mm = String(Math.floor(sec / 60)).padStart(2, '0')
  const ss = String(sec % 60).padStart(2, '0')
  return mm + ':' + ss
})

const sharePrice = computed(() => {
  const n = Number(roundOf().share_price != null ? roundOf().share_price : 100)
  return isNaN(n) ? 100 : Math.round(n)
})

const shareCount = computed(() => (roundOf().share_count | 0) || 0)

const feePct = computed(() => {
  const rate = Number(roundOf().fee_rate)
  if (!isNaN(rate) && rate > 0) return Math.round(rate * 1000) / 10
  return 3
})

const distributableText = computed(() => {
  const r = roundOf()
  let n = Number(r.distributable)
  if (isNaN(n) || n <= 0) {
    const pool = Number(r.pool_amount != null ? r.pool_amount : 0)
    const rate = Number(r.fee_rate)
    const fee = !isNaN(rate) && rate > 0 ? rate : 0.03
    n = isNaN(pool) ? 0 : pool * (1 - fee)
  }
  return (isNaN(n) ? 0 : n).toFixed(2)
})

const needsClaim = computed(() => {
  const phase = livePhase.value
  if (phase !== 'claim' && phase !== 'result') return false
  const r = roundOf()
  if ((r.my_share_count | 0) <= 0) return false
  return !r.my_claimed
})

const btnSrc = computed(() => {
  if (needsClaim.value) return '/static/niuniu/claim-hongbao.png'
  const phase = livePhase.value
  if (phase === 'buying') return '/static/niuniu/btn-buy.png'
  if (phase === 'claim') return '/static/niuniu/btn-claim.png'
  return '/static/niuniu/btn-detail.png'
})

function needsTick() {
  const raw = rawPhase.value
  if (raw !== 'buying' && raw !== 'claim') return false
  const r = roundOf()
  const endAt = raw === 'claim' ? (r.claim_end_at | 0) : (r.buy_end_at | 0)
  if (endAt > 0) return endAt > Math.floor(Date.now() / 1000)
  return true
}

function stopTick() {
  if (tickTimer) {
    clearInterval(tickTimer)
    tickTimer = null
  }
}

function startTick() {
  if (tickTimer || !needsTick()) return
  nowSec.value = Math.floor(Date.now() / 1000)
  tickTimer = setInterval(() => {
    nowSec.value = Math.floor(Date.now() / 1000)
    const r = roundOf()
    const raw = rawPhase.value
    const endAt = raw === 'claim' ? (r.claim_end_at | 0) : (r.buy_end_at | 0)
    if (endAt > 0 && endAt <= nowSec.value) {
      const key = String(roundId.value || 0) + ':' + raw + ':' + endAt
      if (expiredEmittedFor !== key) {
        expiredEmittedFor = key
        emit('expired', { round_id: roundId.value | 0, phase: raw })
      }
      if (!needsTick()) stopTick()
    }
  }, 1000)
}

watch(
  () => [rawPhase.value, roundOf().buy_end_at, roundOf().claim_end_at, roundId.value],
  () => {
    expiredEmittedFor = ''
    stopTick()
    startTick()
  }
)

onMounted(() => startTick())
onUnmounted(() => stopTick())
</script>

<style scoped>
/*
 * 红宝尾数牛牛气泡：叠在 bj.jpg 牛图空位上。
 * 宽度跟 .has-niuniu 列走；H5 / Safari / APK / IPA 共用。
 * 抽成子组件后样式必须写在本文件（父页 scoped 打不进子树）。
 */
.chat-niuniu-card {
  position: relative;
  width: 100%;
  max-width: 100%;
  min-width: 0;
  /* 1024×575 底图比例；无 aspect-ratio 时用 min-height 兜底 */
  aspect-ratio: 1024 / 575;
  min-height: 140px;
  border-radius: 12px;
  overflow: hidden;
  box-shadow: 0 8px 22px rgba(120, 10, 10, 0.35);
  background: #8b0d12;
  container-type: inline-size;
  container-name: nn-card;
  flex-shrink: 0;
  box-sizing: border-box;
}
.chat-niuniu-card .nn-bg {
  position: absolute;
  left: 0;
  right: 0;
  top: 0;
  bottom: 0;
  width: 100%;
  height: 100%;
  display: block;
  z-index: 0;
}
.chat-niuniu-card .nn-layer {
  position: absolute;
  left: 0;
  right: 0;
  top: 0;
  bottom: 0;
  z-index: 1;
  width: 100%;
  height: 100%;
  box-sizing: border-box;
  pointer-events: none;
}
/* 中间信息区：叠在牛右侧空位 */
.chat-niuniu-card .nn-center {
  position: absolute;
  left: 30%;
  top: 42%;
  width: 36%;
  max-width: 36%;
  z-index: 2;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  min-width: 0;
  box-sizing: border-box;
  text-align: center;
  color: #fff8e7;
}
.chat-niuniu-card .nn-l1,
.chat-niuniu-card .nn-l2,
.chat-niuniu-card .nn-l3 {
  width: 100%;
  display: block;
  text-align: center;
  font-weight: 700;
  color: rgba(255, 255, 255, 0.96);
  text-shadow: 0 1px 2px rgba(0, 0, 0, 0.4);
  white-space: nowrap;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  line-height: 1.2;
}
.chat-niuniu-card .nn-l1 {
  font-size: 11px;
}
.chat-niuniu-card .nn-l2 {
  font-size: 10px;
  opacity: 0.95;
}
.chat-niuniu-card .nn-l3 {
  font-size: 9px;
  opacity: 0.9;
  font-weight: 600;
}
.chat-niuniu-card .nn-l4 {
  width: 100%;
  display: block;
  text-align: center;
  font-size: 20px;
  font-weight: 900;
  line-height: 1.1;
  color: #ffe082;
  text-shadow: 0 1px 3px rgba(80, 0, 0, 0.5);
  white-space: nowrap;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  letter-spacing: 0.2px;
}
/* 支持 cqi 时按气泡宽度缩放字号（Safari / 新 WebView） */
@supports (width: 1cqi) {
  .chat-niuniu-card .nn-center {
    gap: clamp(1px, 0.7cqi, 3px);
  }
  .chat-niuniu-card .nn-l1 {
    font-size: clamp(8px, 3.1cqi, 11px);
  }
  .chat-niuniu-card .nn-l2 {
    font-size: clamp(8px, 2.9cqi, 10px);
  }
  .chat-niuniu-card .nn-l3 {
    font-size: clamp(7px, 2.7cqi, 9px);
  }
  .chat-niuniu-card .nn-l4 {
    font-size: clamp(15px, 6.2cqi, 22px);
  }
  .chat-niuniu-card .nn-right {
    gap: clamp(2px, 1.2cqi, 5px);
  }
  .chat-niuniu-card .nn-countdown-time {
    font-size: clamp(7px, 2.7cqi, 9px);
  }
  .chat-niuniu-card .nn-time {
    font-size: clamp(8px, 2.6cqi, 9px);
  }
}
/* 右侧：倒计时 + 操作按钮 */
.chat-niuniu-card .nn-right {
  position: absolute;
  top: 42%;
  right: 10%;
  width: 21%;
  z-index: 2;
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 3px;
  box-sizing: border-box;
  pointer-events: none;
}
.chat-niuniu-card .nn-countdown {
  position: relative;
  width: 100%;
  margin-left: auto;
  aspect-ratio: 282 / 73;
  min-height: 18px;
}
.chat-niuniu-card .nn-countdown-bg {
  position: absolute;
  left: 0;
  right: 0;
  top: 0;
  bottom: 0;
  width: 100%;
  height: 100%;
  display: block;
}
.chat-niuniu-card .nn-countdown-time {
  position: absolute;
  right: 7%;
  top: 50%;
  transform: translateY(-50%);
  width: 36%;
  text-align: center;
  font-size: 8px;
  font-weight: 900;
  color: #8a2a00;
  letter-spacing: 0.2px;
}
.chat-niuniu-card .nn-cta-btn {
  width: 100%;
  display: block;
}
/* 开奖后仅剩一个按钮时，整体下移对齐 */
.chat-niuniu-card.phase-result .nn-right,
.chat-niuniu-card.phase-void .nn-right,
.chat-niuniu-card.phase-refund .nn-right {
  top: 50%;
}
.chat-niuniu-card .nn-time {
  position: absolute;
  right: 6px;
  bottom: 3px;
  z-index: 2;
  font-size: 9px;
  color: rgba(255, 236, 200, 0.85);
  text-shadow: 0 1px 2px rgba(0, 0, 0, 0.45);
}
/* 窄气泡（约 iPhone SE / 小屏气泡列） */
@container nn-card (max-width: 300px) {
  .nn-center {
    left: 29%;
    top: 40%;
    width: 38%;
  }
  .nn-right {
    right: 10%;
    width: 22%;
    top: 40%;
    gap: 2px;
  }
  .phase-result .nn-right,
  .phase-void .nn-right,
  .phase-refund .nn-right {
    top: 48%;
  }
}
/* 无 container / 窄视口回退（旧 App WebView） */
@media (max-width: 360px) {
  .chat-niuniu-card .nn-center {
    left: 28%;
    width: 38%;
  }
  .chat-niuniu-card .nn-l1 {
    font-size: 10px;
  }
  .chat-niuniu-card .nn-l2,
  .chat-niuniu-card .nn-l3 {
    font-size: 9px;
  }
  .chat-niuniu-card .nn-l4 {
    font-size: 17px;
  }
  .chat-niuniu-card .nn-countdown-time {
    font-size: 8px;
  }
  .chat-niuniu-card .nn-right {
    right: 10%;
    width: 22%;
    gap: 2px;
  }
}
</style>

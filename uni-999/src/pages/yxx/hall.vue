<template>
  <view class="yxx-page">
    <TopBar />
    <scroll-view scroll-y class="yxx-scroll" :style="{ height: scrollH }">
      <view class="yxx-boards">
        <view class="yxx-board">
          <text class="yxx-board-k">本局投注池</text>
          <text class="yxx-board-v">{{ poolText }}</text>
        </view>
        <view class="yxx-board">
          <text class="yxx-board-k">入场模式</text>
          <text class="yxx-board-v sm">{{ stakeMin }} 积分场</text>
          <text class="yxx-board-s">最高 4 倍权重</text>
        </view>
        <view class="yxx-board gold">
          <text class="yxx-board-k">爆点累积池</text>
          <text class="yxx-board-v">{{ boomText }}</text>
        </view>
      </view>

      <view class="yxx-progress">
        <text class="yxx-progress-lab">当前有效局：{{ roundIndex }} / {{ cycleMax }}</text>
        <view class="yxx-bar">
          <view class="yxx-bar-fill" :style="{ width: progressPct + '%' }" />
        </view>
        <text v-if="inBoomZone" class="yxx-boom-tip">🔥 已进入爆点区间 ({{ boomFrom }}-{{ cycleMax }}局内必触发爆点)！</text>
      </view>

      <view class="yxx-marquee">
        <text class="yxx-marquee-txt">{{ marquee }}</text>
      </view>

      <view class="yxx-faces">
        <view
          v-for="f in faces"
          :key="f.id"
          class="yxx-face"
          :class="{ on: selectedFace === f.id }"
          @click="pickFace(f.id)"
        >
          <text class="yxx-face-emo">{{ f.emo }}</text>
          <text class="yxx-face-lab">{{ f.label }}</text>
        </view>
      </view>

      <view class="yxx-chips">
        <view
          v-for="c in chips"
          :key="c.stake"
          class="yxx-chip"
          :class="{ on: stake === c.stake }"
          @click="stake = c.stake"
        >
          <text>{{ c.stake }}</text>
          <text class="yxx-chip-w">{{ c.w }}倍</text>
        </view>
      </view>

      <view class="yxx-hint">每局限选 1 个图案 | 起注 {{ stakeMin }} 积分（最高可下 4 倍权重）</view>

      <view
        class="yxx-bet"
        :class="{ off: !canTapBet }"
        @click="onBet"
      >{{ betLabel }}</view>

      <view class="yxx-road">
        <text class="yxx-road-tit">历史开奖走势</text>
        <view v-if="!history.length" class="yxx-road-empty">内测预览：正式开奖后在此显示路单</view>
        <view v-else class="yxx-road-row">
          <text v-for="(h, i) in history" :key="i" class="yxx-road-dot">{{ faceEmo(h) }}</text>
        </view>
      </view>

      <view class="yxx-vip">
        <text class="yxx-vip-lock">🔒</text>
        <view class="yxx-vip-body">
          <text class="yxx-vip-t">[群主私域] 西贡豪华俱乐部</text>
          <text class="yxx-vip-s">底注 20-200 自定义 | 多选全包 | 独立群爆点池</text>
          <text class="yxx-vip-g">解锁门槛：本周大厅有效流水 5,000 积分</text>
        </view>
      </view>
    </scroll-view>
    <BottomTabBar active="yxx" />
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import BottomTabBar from '../../components/BottomTabBar.vue'
import { apiRequest, getToken } from '../../utils/auth.js'
import { applySafeAreaCssVars, getSafeAreaInsets } from '../../utils/safe-area.js'

const faces = [
  { id: 'fish', emo: '🐟', label: '鱼' },
  { id: 'shrimp', emo: '🦐', label: '虾' },
  { id: 'crab', emo: '🦀', label: '蟹' },
  { id: 'gourd', emo: '🎃', label: '葫芦' },
  { id: 'coin', emo: '💰', label: '金钱' },
  { id: 'deer', emo: '🦌', label: '鹿' },
]

const scrollH = ref('100vh')
const selectedFace = ref('')
const stakeMin = ref(50)
const stakeMax = ref(200)
const stake = ref(50)
const pool = ref(0)
const boomPool = ref(0)
const roundIndex = ref(0)
const cycleMax = ref(50)
const boomFrom = ref(30)
const inBoomZone = ref(false)
const marquee = ref(
  '🔥 爆点高能预警：每一轮 30-50 有效局之内必定触发一次爆点事件！触发后随机释放爆点池 50% 或 100% 奖金。利益分配：扣除 3% 服务费、5% 爆点池，剩余 92% 进入本局分配池。'
)
const history = ref([])
const preview = ref(true)

const chips = computed(() => {
  const min = Number(stakeMin.value) || 50
  return [1, 2, 3, 4].map((w) => ({ stake: min * w, w }))
})

const poolText = computed(() => formatMoney(pool.value))
const boomText = computed(() => formatMoney(boomPool.value))
const progressPct = computed(() => {
  const max = Math.max(1, Number(cycleMax.value) || 50)
  return Math.min(100, Math.round((Number(roundIndex.value) * 100) / max))
})
const canTapBet = computed(() => !!selectedFace.value)
const betLabel = computed(() => {
  if (!selectedFace.value) return '请先选择 1 个图案'
  if (!getToken()) return '登录后下注'
  if (preview.value) return '内测预览 · 暂未开放扣款'
  return '确认下注'
})

function formatMoney(v) {
  const n = Number(v)
  if (!isFinite(n)) return '0'
  return n.toFixed(0)
}

function faceEmo(id) {
  const f = faces.find((x) => x.id === id)
  return (f && f.emo) || id
}

function measureScroll() {
  try {
    applySafeAreaCssVars()
    const sys = uni.getSystemInfoSync()
    const inset = getSafeAreaInsets()
    const status = Number(inset.top || sys.statusBarHeight || 20)
    const topBar = 48
    const tab = 56 + Number(inset.bottom || 0)
    const h = (sys.windowHeight || 667) - status - topBar - tab
    scrollH.value = Math.max(280, h) + 'px'
  } catch (e) {
    scrollH.value = '70vh'
  }
}

function pickFace(id) {
  selectedFace.value = selectedFace.value === id ? '' : id
}

function onBet() {
  if (!selectedFace.value) {
    uni.showToast({ title: '请先选择 1 个图案', icon: 'none' })
    return
  }
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  uni.showToast({ title: '鱼虾蟹结算内测中，本局不扣款', icon: 'none' })
}

async function loadHall() {
  try {
    const data = await apiRequest('yxxhall', 'GET', {}, { skipAuthRedirect: true })
    if (!data) return
    stakeMin.value = Number(data.stake_min || 50)
    stakeMax.value = Number(data.stake_max || 200)
    cycleMax.value = Number(data.cycle_max || 50)
    boomFrom.value = Number(data.boom_from || 30)
    if (data.marquee) marquee.value = String(data.marquee)
    const r = data.round || {}
    pool.value = Number(r.pool || 0)
    boomPool.value = Number(r.boom_pool || 0)
    roundIndex.value = Number(r.round_index || 0)
    inBoomZone.value = !!r.in_boom_zone || roundIndex.value >= boomFrom.value
    history.value = Array.isArray(data.history) ? data.history : []
    preview.value = data.preview !== 0
    if (stake.value < stakeMin.value || stake.value > stakeMax.value) {
      stake.value = stakeMin.value
    }
  } catch (e) {}
}

onShow(() => {
  measureScroll()
  loadHall()
})
</script>

<style scoped>
.yxx-page {
  min-height: 100vh;
  background: radial-gradient(circle at 50% 0%, #3a1a12 0%, #120805 55%);
  color: #f6e6c8;
  max-width: 480px;
  margin: 0 auto;
  box-sizing: border-box;
  padding-bottom: calc(70px + var(--safe-area-inset-bottom, env(safe-area-inset-bottom, 0px)));
}
.yxx-scroll {
  box-sizing: border-box;
}
.yxx-boards {
  display: flex;
  gap: 8px;
  padding: 10px 12px 4px;
}
.yxx-board {
  flex: 1;
  background: rgba(255, 255, 255, 0.06);
  border: 1px solid rgba(246, 201, 120, 0.22);
  border-radius: 12px;
  padding: 8px 6px;
  text-align: center;
}
.yxx-board.gold {
  background: linear-gradient(180deg, rgba(180, 90, 20, 0.35), rgba(80, 20, 10, 0.4));
}
.yxx-board-k {
  display: block;
  font-size: 10px;
  color: #c9b48a;
}
.yxx-board-v {
  display: block;
  margin-top: 4px;
  font-size: 16px;
  font-weight: 900;
  color: #ffe29a;
}
.yxx-board-v.sm {
  font-size: 13px;
}
.yxx-board-s {
  display: block;
  font-size: 9px;
  color: #a89472;
  margin-top: 2px;
}
.yxx-progress {
  padding: 8px 16px 4px;
}
.yxx-progress-lab,
.yxx-boom-tip {
  display: block;
  font-size: 12px;
  font-weight: 700;
}
.yxx-boom-tip {
  color: #ffb347;
  margin-top: 6px;
}
.yxx-bar {
  height: 8px;
  margin-top: 6px;
  background: #1a0c08;
  border-radius: 6px;
  overflow: hidden;
}
.yxx-bar-fill {
  height: 100%;
  background: linear-gradient(90deg, #f2c14e, #e05a2b);
}
.yxx-marquee {
  margin: 8px 12px;
  padding: 8px 10px;
  border-radius: 8px;
  background: rgba(0, 0, 0, 0.35);
  overflow: hidden;
}
.yxx-marquee-txt {
  font-size: 11px;
  line-height: 1.45;
  color: #ffd9a0;
}
.yxx-faces {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  padding: 4px 12px 8px;
}
.yxx-face {
  width: calc(33.33% - 6px);
  box-sizing: border-box;
  background: rgba(255, 255, 255, 0.05);
  border: 1px solid rgba(255, 255, 255, 0.08);
  border-radius: 12px;
  padding: 12px 4px;
  text-align: center;
}
.yxx-face.on {
  border-color: #f2c14e;
  background: rgba(242, 193, 78, 0.16);
}
.yxx-face-emo {
  display: block;
  font-size: 28px;
}
.yxx-face-lab {
  display: block;
  margin-top: 4px;
  font-size: 13px;
  font-weight: 800;
}
.yxx-chips {
  display: flex;
  gap: 8px;
  padding: 4px 12px;
}
.yxx-chip {
  flex: 1;
  text-align: center;
  border-radius: 10px;
  padding: 8px 0;
  border: 1px solid rgba(255, 255, 255, 0.12);
  font-size: 13px;
  font-weight: 800;
}
.yxx-chip.on {
  border-color: #e05a2b;
  background: rgba(224, 90, 43, 0.25);
  color: #fff;
}
.yxx-chip-w {
  display: block;
  font-size: 10px;
  font-weight: 600;
  color: #c9b48a;
}
.yxx-hint {
  padding: 8px 16px 4px;
  font-size: 11px;
  color: #b9a384;
  text-align: center;
}
.yxx-bet {
  margin: 8px 16px 12px;
  height: 46px;
  line-height: 46px;
  text-align: center;
  border-radius: 23px;
  font-size: 16px;
  font-weight: 900;
  color: #3a1408;
  background: linear-gradient(90deg, #ffe29a, #e05a2b);
}
.yxx-bet.off {
  opacity: 0.55;
}
.yxx-road,
.yxx-vip {
  margin: 0 12px 12px;
  padding: 12px;
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.05);
}
.yxx-road-tit {
  font-size: 13px;
  font-weight: 800;
}
.yxx-road-empty {
  display: block;
  margin-top: 8px;
  font-size: 12px;
  color: #a89472;
}
.yxx-vip {
  display: flex;
  gap: 8px;
  align-items: flex-start;
  opacity: 0.85;
}
.yxx-vip-lock {
  font-size: 20px;
}
.yxx-vip-t {
  display: block;
  font-size: 13px;
  font-weight: 800;
}
.yxx-vip-s,
.yxx-vip-g {
  display: block;
  font-size: 11px;
  color: #b9a384;
  margin-top: 3px;
}
</style>

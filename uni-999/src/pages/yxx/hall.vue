<template>
  <view class="yxx-page" :style="pagePad">
    <view class="yxx-header">
      <view class="yxx-back" hover-class="yxx-hit" @click="goBack">
        <text class="yxx-back-char">‹</text>
      </view>
      <view class="yxx-titles">
        <text class="yxx-title">{{ t('yxx_title') }}</text>
        <text class="yxx-sub">{{ t('yxx_subtitle') }}</text>
      </view>
      <view class="yxx-rules-btn" hover-class="yxx-hit" @click="rulesOpen = true">{{ t('yxx_rules') }}</view>
    </view>

    <view class="yxx-stats">
      <view class="yxx-pill">
        <text class="yxx-pill-ico">⏱</text>
        <text>{{ timerText }}</text>
      </view>
      <view class="yxx-pill">
        <text class="yxx-pill-ico">👤</text>
        <text>{{ t('yxx_players', { n: playerCount }) }}</text>
      </view>
      <view class="yxx-pill gold">
        <text>{{ t('yxx_pool', { n: poolText }) }}</text>
      </view>
    </view>

    <scroll-view scroll-y class="yxx-scroll" :style="{ height: scrollH }">
      <view class="yxx-grid-wrap">
        <view class="yxx-grid">
          <view
            v-for="f in faces"
            :key="f.id"
            class="yxx-cell"
            :class="{ on: selectedFace === f.id, win: settleFace === f.id }"
            @click="pickFace(f.id)"
          >
            <view class="yxx-ring">
              <view class="yxx-ring-in">
                <text class="yxx-emo">{{ f.emo }}</text>
              </view>
            </view>
            <text class="yxx-lab">{{ f.label }}</text>
            <text v-if="myFace === f.id" class="yxx-chip">{{ t('yxx_staked', { n: myStake }) }}</text>
          </view>
        </view>
      </view>

      <view class="yxx-reveal" :class="{ open: phase === 'reveal' }">
        <view class="yxx-spark" v-if="phase === 'reveal'">
          <text v-for="n in 6" :key="n" class="yxx-coin" :class="'c' + n">●</text>
        </view>
        <view class="yxx-bowl" />
        <view class="yxx-dice-row">
          <view v-for="(d, i) in diceLabels" :key="i" class="yxx-die">
            <text>{{ d || '·' }}</text>
          </view>
        </view>
        <text class="yxx-reveal-cap">{{ revealHint }}</text>
      </view>

      <view class="yxx-feed">
        <text class="yxx-hist">{{ historyLine }}</text>
        <view class="yxx-feed-list">
          <view class="yxx-feed-col">
            <text v-for="(row, i) in feedLeft" :key="'l' + i" class="yxx-feed-item">{{ row }}</text>
          </view>
          <view class="yxx-feed-col">
            <text v-for="(row, i) in feedRight" :key="'r' + i" class="yxx-feed-item">{{ row }}</text>
          </view>
        </view>
      </view>
    </scroll-view>

    <view class="yxx-dock">
      <view class="yxx-chips">
        <view
          v-for="n in chipOpts"
          :key="n"
          class="yxx-qchip"
          :class="{ on: stake === n }"
          @click="stake = n"
        >{{ n }}</view>
      </view>
      <view class="yxx-dock-row">
        <view class="yxx-amt">
          <input
            class="yxx-input"
            type="number"
            :value="String(stake)"
            :placeholder="t('yxx_points_range', { min: stakeMin, max: stakeMax })"
            @input="onAmt"
          />
          <text class="yxx-amt-lab">{{ t('yxx_points') }}</text>
        </view>
        <view
          class="yxx-confirm"
          :class="{ off: phase !== 'betting' || betting }"
          hover-class="yxx-hit"
          @click="onBet"
        >{{ confirmText }}</view>
      </view>
    </view>

    <view v-if="rulesOpen" class="yxx-mask" @click="rulesOpen = false">
      <view class="yxx-sheet" @click.stop>
        <text class="yxx-sheet-t">{{ t('yxx_rules_title') }}</text>
        <scroll-view scroll-y class="yxx-sheet-body">
          <text class="yxx-sheet-p">{{ t('yxx_rules_p1', { min: stakeMin, max: stakeMax }) }}</text>
          <text class="yxx-sheet-p">{{ t('yxx_rules_p2') }}</text>
          <text class="yxx-sheet-p">{{ t('yxx_rules_p3') }}</text>
          <text class="yxx-sheet-p">{{ t('yxx_rules_p4') }}</text>
        </scroll-view>
        <view class="yxx-sheet-ok" @click="rulesOpen = false">{{ t('yxx_rules_ok') }}</view>
      </view>
    </view>
  </view>
</template>

<script setup>
import { computed, onUnmounted, ref } from 'vue'
import { onHide, onShow } from '@dcloudio/uni-app'
import { apiRequest, getToken } from '../../utils/auth.js'
import { localeState, t } from '../../utils/i18n.js'
import { applySafeAreaCssVars, getSafeAreaInsets } from '../../utils/safe-area.js'

const FACE_EMO = {
  gourd: '🎃',
  crab: '🦀',
  shrimp: '🦐',
  fish: '🐟',
  rooster: '🐓',
  tiger: '🐯',
}
const FACE_IDS = ['gourd', 'crab', 'shrimp', 'fish', 'rooster', 'tiger']
const locale = localeState()
const faces = computed(() => {
  void locale.value
  return FACE_IDS.map((id) => ({ id, emo: FACE_EMO[id], label: t('yxx_face_' + id) }))
})
const chipOpts = [50, 100, 150, 200]

const padTop = ref(20)
const padBottom = ref(8)
const scrollH = ref('60vh')
const selectedFace = ref('')
const stakeMin = ref(50)
const stakeMax = ref(200)
const stake = ref(50)
const pool = ref(24680)
const remainSec = ref(12)
const playerCount = ref(28)
const phase = ref('betting')
const settleFace = ref('')
const myFace = ref('')
const myStake = ref(0)
const rulesOpen = ref(false)
const betting = ref(false)
const diceShow = ref(['', '', ''])
const historyRows = ref([])
const feedRows = ref([])
let poll = null
let lastRound = -1

const pagePad = computed(() => ({
  paddingTop: padTop.value + 'px',
  paddingBottom: padBottom.value + 108 + 'px',
}))

const poolText = computed(() => {
  const n = Number(pool.value) || 0
  return n.toLocaleString('zh-CN')
})

const timerText = computed(() => {
  void locale.value
  const n = remainSec.value
  if (phase.value === 'locking') return t('yxx_timer_lock', { n })
  if (phase.value === 'reveal') return t('yxx_timer_reveal', { n })
  return t('yxx_timer_bet', { n })
})

const confirmText = computed(() => {
  void locale.value
  if (betting.value) return t('yxx_confirming')
  if (phase.value === 'locking') return t('yxx_sealed')
  if (phase.value === 'reveal') return t('yxx_drawing')
  return t('yxx_confirm')
})

function faceLabel(id) {
  void locale.value
  const k = String(id || '')
  if (!k) return ''
  return t('yxx_face_' + k) || k
}

const revealHint = computed(() => {
  void locale.value
  if (phase.value === 'locking') return t('yxx_hint_lock')
  if (phase.value === 'reveal') {
    const lab = faceLabel(settleFace.value)
    return lab ? t('yxx_hint_reveal', { face: lab }) : t('yxx_hint_lock')
  }
  if (selectedFace.value) return t('yxx_hint_pick', { face: faceLabel(selectedFace.value) })
  return t('yxx_hint_idle')
})

const historyLine = computed(() => {
  void locale.value
  const rows = historyRows.value.slice(0, 2)
  if (!rows.length) return t('yxx_hist_wait')
  return rows
    .map((r) => {
      const faces = (r.dice || []).map((id) => faceLabel(id)).filter(Boolean).join(' ')
      return t('yxx_hist_round', { faces })
    })
    .join('  |  ')
})

const feed = computed(() => {
  void locale.value
  return feedRows.value.map((row) => {
    if (typeof row === 'string') return row
    return t('yxx_feed_line', {
      nick: row.nick || '',
      face: faceLabel(row.face),
      stake: row.stake || 0,
    })
  })
})

const diceLabels = computed(() => {
  void locale.value
  return diceShow.value.map((id) => (id ? faceLabel(id) : ''))
})
const feedLeft = computed(() => feed.value.filter((_, i) => i % 2 === 0))
const feedRight = computed(() => feed.value.filter((_, i) => i % 2 === 1))

function measure() {
  try {
    applySafeAreaCssVars()
    const sys = uni.getSystemInfoSync() || {}
    const inset = getSafeAreaInsets()
    padTop.value = Math.max(12, Number(inset.top || sys.statusBarHeight || 20))
    padBottom.value = Math.max(8, Number(inset.bottom || 0))
    const header = 56
    const stats = 44
    const dock = 108 + padBottom.value
    const h = (sys.windowHeight || 667) - padTop.value - header - stats - dock
    scrollH.value = Math.max(240, h) + 'px'
  } catch (e) {
    scrollH.value = '58vh'
  }
}

function goBack() {
  uni.switchTab({
    url: '/pages/messages/messages',
    fail: () => uni.reLaunch({ url: '/pages/messages/messages' }),
  })
}

function pickFace(id) {
  if (phase.value !== 'betting') return
  selectedFace.value = selectedFace.value === id ? '' : id
}

function onAmt(e) {
  const n = parseInt(String((e && e.detail && e.detail.value) || ''), 10)
  if (!isFinite(n)) {
    stake.value = stakeMin.value
    return
  }
  stake.value = Math.min(stakeMax.value, Math.max(0, n))
}

function applyHall(data) {
  if (!data) return
  stakeMin.value = Number(data.stake_min || 50)
  stakeMax.value = Number(data.stake_max || 200)
  const r = data.round || {}
  if (r.pool != null) pool.value = Number(r.pool)
  if (r.remain_sec != null) remainSec.value = Number(r.remain_sec)
  if (r.player_count != null) playerCount.value = Number(r.player_count)
  phase.value = String(r.phase || 'betting')
  const idx = Number(r.round_index || 0)
  if (idx !== lastRound) {
    lastRound = idx
    myFace.value = ''
    myStake.value = 0
    settleFace.value = ''
  }
  if (Array.isArray(data.live_bets)) {
    feedRows.value = data.live_bets
  }
  if (Array.isArray(data.history)) {
    historyRows.value = data.history
  }
  const ids = Array.isArray(data.dice) ? data.dice : []
  if (phase.value === 'reveal' && ids.length === 3) {
    diceShow.value = ids.map((x) => String(x || ''))
  } else if (phase.value !== 'reveal') {
    diceShow.value = ['', '', '']
  }
  settleFace.value = phase.value === 'reveal' ? String(data.settle_face || '') : ''
  const mine = data.my_bet
  if (mine && mine.face) {
    myFace.value = String(mine.face)
    myStake.value = Number(mine.stake || 0)
    selectedFace.value = myFace.value
    if (myStake.value) stake.value = myStake.value
  }
  if (stake.value < stakeMin.value) stake.value = stakeMin.value
}

async function loadHall() {
  try {
    const data = await apiRequest('yxxhall', 'GET', {}, { skipAuthRedirect: true })
    applyHall(data)
  } catch (e) {}
}

async function onBet() {
  if (phase.value !== 'betting' || betting.value) return
  if (!selectedFace.value) {
    uni.showToast({ title: t('yxx_pick_face'), icon: 'none' })
    return
  }
  if (stake.value < stakeMin.value) {
    uni.showToast({ title: t('yxx_stake_min', { n: stakeMin.value }), icon: 'none' })
    return
  }
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  betting.value = true
  try {
    const data = await apiRequest('yxxbet', 'POST', {
      face: selectedFace.value,
      stake: stake.value,
    })
    applyHall(data)
    uni.showToast({ title: t('yxx_preview_ok'), icon: 'none' })
  } catch (e) {
    uni.showToast({ title: (e && e.message) || t('yxx_bet_fail'), icon: 'none' })
  } finally {
    betting.value = false
  }
}

function startPoll() {
  if (poll) clearInterval(poll)
  poll = setInterval(() => {
    loadHall()
  }, 1000)
}

function stopPoll() {
  if (poll) {
    clearInterval(poll)
    poll = null
  }
}

onShow(() => {
  measure()
  loadHall()
  startPoll()
})

onHide(() => {
  stopPoll()
})

onUnmounted(() => {
  stopPoll()
})
</script>

<style scoped>
.yxx-page {
  min-height: 100vh;
  min-height: -webkit-fill-available;
  background:
    radial-gradient(circle at 50% 18%, rgba(180, 40, 30, 0.55) 0%, transparent 42%),
    linear-gradient(180deg, #6b0d0d 0%, #3a0508 42%, #1a0204 100%);
  color: #fff7e6;
  box-sizing: border-box;
  max-width: 480px;
  margin: 0 auto;
}
.yxx-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 4px 12px 6px;
}
.yxx-back,
.yxx-rules-btn {
  width: 40px;
  height: 40px;
  border-radius: 20px;
  background: rgba(80, 10, 10, 0.55);
  border: 1px solid rgba(255, 210, 120, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.yxx-rules-btn {
  width: auto;
  padding: 0 10px;
  border-radius: 10px;
  font-size: 13px;
  font-weight: 800;
  color: #ffe29a;
}
.yxx-back-char {
  font-size: 32px;
  font-weight: 700;
  color: #ffe29a;
  line-height: 1;
  transform: translateY(-1px);
}
.yxx-hit {
  opacity: 0.82;
}
.yxx-titles {
  flex: 1;
  text-align: center;
}
.yxx-title {
  display: block;
  font-size: 28px;
  font-weight: 900;
  letter-spacing: 4px;
  color: #ffd56a;
  text-shadow: 0 2px 0 #7a3a00, 0 0 12px rgba(255, 200, 80, 0.45);
}
.yxx-sub {
  display: block;
  margin-top: 2px;
  font-size: 11px;
  color: rgba(255, 255, 255, 0.78);
  letter-spacing: 0.4px;
}
.yxx-stats {
  display: flex;
  gap: 6px;
  padding: 0 10px 8px;
}
.yxx-pill {
  flex: 1;
  min-width: 0;
  background: rgba(90, 8, 12, 0.72);
  border: 1px solid rgba(255, 180, 80, 0.28);
  border-radius: 999px;
  padding: 6px 6px;
  font-size: 10px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 3px;
  white-space: nowrap;
}
.yxx-pill.gold {
  flex: 1.35;
  background: linear-gradient(180deg, #f0c14b, #c48418);
  color: #4a2200;
  border-color: #ffe29a;
  font-size: 10px;
}
.yxx-pill-ico {
  font-size: 11px;
}
.yxx-scroll {
  box-sizing: border-box;
}
.yxx-grid-wrap {
  margin: 4px 12px 0;
  padding: 12px 8px 56px;
  border-radius: 16px;
  background: linear-gradient(180deg, rgba(90, 12, 16, 0.92), rgba(50, 6, 10, 0.92));
  border: 2px solid #e0b24a;
  box-shadow: inset 0 0 0 1px rgba(255, 220, 140, 0.35), 0 8px 24px rgba(0, 0, 0, 0.35);
}
.yxx-grid {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-around;
}
.yxx-cell {
  width: 33.33%;
  box-sizing: border-box;
  padding: 6px 4px 10px;
  text-align: center;
}
.yxx-ring {
  width: 74px;
  height: 74px;
  margin: 0 auto;
  border-radius: 50%;
  background: radial-gradient(circle at 35% 30%, #fff3c4, #d7a23a 42%, #8a4a10);
  border: 3px solid #f3d07a;
  box-shadow: 0 4px 10px rgba(0, 0, 0, 0.35);
  display: flex;
  align-items: center;
  justify-content: center;
}
.yxx-ring-in {
  width: 58px;
  height: 58px;
  border-radius: 50%;
  background: radial-gradient(circle at 40% 32%, #e23b32, #7a1014 72%);
  display: flex;
  align-items: center;
  justify-content: center;
}
.yxx-cell.on .yxx-ring,
.yxx-cell.win .yxx-ring {
  box-shadow: 0 0 0 3px #fff3c4, 0 0 16px rgba(255, 210, 80, 0.7);
}
.yxx-emo {
  font-size: 30px;
  line-height: 1;
}
.yxx-lab {
  display: block;
  margin-top: 6px;
  font-size: 13px;
  font-weight: 800;
  color: #ffe29a;
}
.yxx-chip {
  display: inline-block;
  margin-top: 4px;
  padding: 1px 6px;
  border-radius: 8px;
  font-size: 10px;
  color: #4a2200;
  background: #f0c14b;
}
.yxx-reveal {
  margin-top: -48px;
  align-items: center;
  display: flex;
  flex-direction: column;
  position: relative;
}
.yxx-bowl {
  width: 88px;
  height: 28px;
  border-radius: 44px 44px 10px 10px;
  background: linear-gradient(180deg, #f8e19a, #c48a22);
  box-shadow: 0 6px 12px rgba(0, 0, 0, 0.35);
  transform: rotate(0deg) translate(0, 36px);
  z-index: 2;
  transition: transform 0.45s ease;
}
.yxx-reveal.open .yxx-bowl {
  transform: rotate(-18deg) translate(-36px, -8px);
}
.yxx-dice-row {
  display: flex;
  gap: 10px;
  margin-top: -6px;
  padding: 10px 16px 8px;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(180, 30, 30, 0.55), transparent 70%);
}
.yxx-die {
  width: 48px;
  height: 48px;
  border-radius: 8px;
  background: #fff;
  color: #b01018;
  font-size: 13px;
  font-weight: 900;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 4px 8px rgba(0, 0, 0, 0.35);
}
.yxx-reveal-cap {
  margin-top: 4px;
  font-size: 11px;
  color: rgba(255, 230, 180, 0.75);
}
.yxx-spark {
  position: absolute;
  left: 0;
  right: 0;
  top: 8px;
  height: 80px;
  pointer-events: none;
}
.yxx-coin {
  position: absolute;
  left: 50%;
  color: #ffd56a;
  font-size: 10px;
  opacity: 0.85;
}
.yxx-coin.c1 { transform: translate(-48px, 8px); }
.yxx-coin.c2 { transform: translate(36px, 0); }
.yxx-coin.c3 { transform: translate(-20px, -12px); }
.yxx-coin.c4 { transform: translate(52px, 22px); }
.yxx-coin.c5 { transform: translate(-60px, 28px); }
.yxx-coin.c6 { transform: translate(8px, 18px); }
.yxx-feed {
  margin: 10px 12px 16px;
  border-radius: 12px;
  background: rgba(40, 8, 10, 0.72);
  border: 1px solid rgba(224, 178, 74, 0.28);
  overflow: hidden;
}
.yxx-hist {
  display: block;
  padding: 8px 10px;
  font-size: 11px;
  color: #ffe29a;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}
.yxx-feed-list {
  display: flex;
  gap: 8px;
  padding: 6px 10px 8px;
}
.yxx-feed-col {
  flex: 1;
  min-width: 0;
}
.yxx-feed-item {
  display: block;
  font-size: 11px;
  color: rgba(255, 247, 230, 0.88);
  line-height: 1.7;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}
.yxx-dock {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 20;
  max-width: 480px;
  margin: 0 auto;
  padding: 6px 12px;
  padding-bottom: calc(8px + var(--safe-area-inset-bottom, env(safe-area-inset-bottom, 0px)));
  background: linear-gradient(180deg, rgba(80, 10, 12, 0.92), #3a0608);
  box-sizing: border-box;
}
.yxx-chips {
  display: flex;
  gap: 8px;
  margin-bottom: 6px;
}
.yxx-qchip {
  flex: 1;
  height: 28px;
  line-height: 28px;
  text-align: center;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 800;
  color: #ffe29a;
  border: 1px solid rgba(240, 193, 75, 0.45);
  background: rgba(80, 12, 14, 0.8);
}
.yxx-qchip.on {
  color: #4a2200;
  background: linear-gradient(180deg, #f0c14b, #c48418);
}
.yxx-dock-row {
  display: flex;
  gap: 10px;
}
.yxx-amt {
  flex: 1;
  position: relative;
  height: 44px;
  border-radius: 10px;
  background: #fff;
  display: flex;
  align-items: center;
}
.yxx-input {
  flex: 1;
  height: 44px;
  padding: 0 52px 0 12px;
  font-size: 16px;
  font-weight: 800;
  color: #3a1408;
}
.yxx-amt-lab {
  position: absolute;
  right: 10px;
  font-size: 13px;
  color: #9a6a3a;
  font-weight: 700;
}
.yxx-confirm {
  width: 128px;
  height: 44px;
  line-height: 44px;
  text-align: center;
  border-radius: 10px;
  font-size: 16px;
  font-weight: 900;
  color: #fff8e6;
  background: linear-gradient(180deg, #e23b32, #a31218);
  border: 1px solid #f0c14b;
  box-shadow: 0 4px 10px rgba(120, 10, 10, 0.4);
}
.yxx-confirm.off {
  opacity: 0.55;
}
.yxx-mask {
  position: fixed;
  inset: 0;
  z-index: 40;
  background: rgba(0, 0, 0, 0.55);
  display: flex;
  align-items: flex-end;
  justify-content: center;
}
.yxx-sheet {
  width: 100%;
  max-width: 480px;
  background: #2a0a0c;
  border-radius: 16px 16px 0 0;
  padding: 16px 16px calc(16px + var(--safe-area-inset-bottom, env(safe-area-inset-bottom, 0px)));
  box-sizing: border-box;
}
.yxx-sheet-t {
  display: block;
  font-size: 16px;
  font-weight: 900;
  color: #ffd56a;
  margin-bottom: 8px;
}
.yxx-sheet-body {
  max-height: 42vh;
}
.yxx-sheet-p {
  display: block;
  font-size: 13px;
  line-height: 1.55;
  color: #f6e6c8;
  margin-bottom: 8px;
}
.yxx-sheet-ok {
  margin-top: 8px;
  height: 42px;
  line-height: 42px;
  text-align: center;
  border-radius: 10px;
  background: linear-gradient(180deg, #e23b32, #a31218);
  font-weight: 800;
}
</style>

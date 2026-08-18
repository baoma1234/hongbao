<template>
  <view class="yxx-page" :style="pagePad">
    <view class="yxx-header">
      <view class="yxx-h-side yxx-h-left">
        <view class="yxx-back" hover-class="yxx-hit" @click="goBack">
          <text class="yxx-back-char">‹</text>
        </view>
      </view>
      <view class="yxx-titles">
        <text class="yxx-title">{{ t('yxx_title') }}</text>
        <text class="yxx-sub">{{ t('yxx_subtitle') }}</text>
      </view>
      <view class="yxx-h-side yxx-h-right">
        <view class="yxx-rules-btn" hover-class="yxx-hit" @click="openVerify">{{ t('yxx_verify') }}</view>
        <view class="yxx-rules-btn" hover-class="yxx-hit" @click="rulesOpen = true">{{ t('yxx_rules') }}</view>
      </view>
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

    <view v-if="poolStatus && poolStatus !== 'normal'" class="yxx-status-banner">
      <text>{{ statusBannerText }}</text>
    </view>

    <view v-if="poolEnabled" class="yxx-pool-bar">
      <view class="yxx-pool-row">
        <text class="yxx-pool-txt">{{ poolGrossText }}</text>
        <text class="yxx-pool-txt dim">{{ poolReserveText }}</text>
      </view>
      <view class="yxx-pool-track">
        <view class="yxx-pool-fill" :style="{ width: rainProgress + '%' }" />
      </view>
      <text class="yxx-pool-prog">{{ rainProgText }}</text>
    </view>

    <scroll-view scroll-y class="yxx-scroll" :style="{ height: scrollH }">
      <view class="yxx-grid-wrap">
        <view class="yxx-stud s1" />
        <view class="yxx-stud s2" />
        <view class="yxx-stud s3" />
        <view class="yxx-stud s4" />
        <view class="yxx-grid">
          <view
            v-for="f in faces"
            :key="f.id"
            class="yxx-cell"
            :class="{ on: selectedFace === f.id, win: settleFace === f.id }"
            @click="pickFace(f.id)"
          >
            <view class="yxx-ring">
              <image class="yxx-face-img" :src="f.src" mode="aspectFill" />
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
        <image class="yxx-bowl-img" :src="bowlSrc" mode="aspectFit" />
        <view class="yxx-dice-row">
          <view v-for="(id, i) in diceShow" :key="i" class="yxx-die">
            <image v-if="id" class="yxx-die-img" :src="faceSrc(id)" mode="aspectFill" />
            <text class="yxx-die-lab">{{ diceLabels[i] || '·' }}</text>
          </view>
        </view>
        <text class="yxx-reveal-cap">{{ revealHint }}</text>
      </view>

      <view class="yxx-feed">
        <text class="yxx-hist" @click="openVerify">{{ historyLine }}</text>
        <view class="yxx-feed-list">
          <view class="yxx-feed-col">
            <view v-for="(row, i) in feedLeft" :key="'l' + i" class="yxx-feed-item">
              <text class="yxx-nick">{{ row.nick }}</text>
              <text class="yxx-feed-txt">{{ row.line }}</text>
            </view>
          </view>
          <view class="yxx-feed-col">
            <view v-for="(row, i) in feedRight" :key="'r' + i" class="yxx-feed-item">
              <text class="yxx-nick">{{ row.nick }}</text>
              <text class="yxx-feed-txt">{{ row.line }}</text>
            </view>
          </view>
        </view>
      </view>
    </scroll-view>

    <view class="yxx-dock" :style="dockStyle">
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
          :class="{ off: phase !== 'betting' || betting || poolStatus === 'locked' }"
          hover-class="yxx-hit"
          @click="onBet"
        >{{ confirmText }}</view>
      </view>
    </view>

    <view v-if="rulesOpen" class="yxx-mask" @click="rulesOpen = false">
      <view class="yxx-sheet" :style="sheetStyle" @click.stop>
        <text class="yxx-sheet-t">{{ t('yxx_rules_title') }}</text>
        <scroll-view scroll-y class="yxx-sheet-body">
          <text class="yxx-sheet-p">{{ t('yxx_rules_p1', { min: stakeMin, max: stakeMax }) }}</text>
          <text class="yxx-sheet-p">{{ t('yxx_rules_p2') }}</text>
          <text class="yxx-sheet-p">{{ t('yxx_rules_p3') }}</text>
          <text class="yxx-sheet-p">{{ t('yxx_rules_p4') }}</text>
          <text class="yxx-sheet-p">{{ t('yxx_rules_p5') }}</text>
        </scroll-view>
        <view class="yxx-sheet-ok" @click="rulesOpen = false">{{ t('yxx_rules_ok') }}</view>
      </view>
    </view>

    <view v-if="rainOpen" class="yxx-mask yxx-rain-mask" @click.stop>
      <view class="yxx-rain-fall">
        <text
          v-for="n in rainDrops"
          :key="'drop' + n"
          class="yxx-drop"
          :class="'d' + n"
        >🧧</text>
      </view>
      <view class="yxx-rain-card" @click.stop>
        <text class="yxx-rain-ico">🧧</text>
        <text class="yxx-rain-title">{{ t('yxx_rain_title') }}</text>
        <text class="yxx-rain-amt">+{{ rainAmount }}</text>
        <text class="yxx-rain-body">{{ rainBodyText }}</text>
        <view class="yxx-rain-btn" hover-class="yxx-hit" @click="ackRain">{{ t('yxx_rain_ok') }}</view>
      </view>
    </view>
  </view>
</template>

<script setup>
import { computed, onUnmounted, ref } from 'vue'
import { onHide, onResize, onShow } from '@dcloudio/uni-app'
import { apiRequest, getToken } from '../../utils/auth.js'
import { localeState, t } from '../../utils/i18n.js'
import { packagedStaticUrl } from '../../utils/config.js'
import { applySafeAreaCssVars, getSafeAreaInsets } from '../../utils/safe-area.js'

const FACE_IDS = ['gourd', 'crab', 'shrimp', 'fish', 'rooster', 'tiger']
function faceSrc(id) {
  return packagedStaticUrl('yxx/' + id + '.png')
}
const bowlSrc = packagedStaticUrl('yxx/bowl.png')
const locale = localeState()
const faces = computed(() => {
  void locale.value
  return FACE_IDS.map((id) => ({ id, src: faceSrc(id), label: t('yxx_face_' + id) }))
})
const chipOpts = [50, 100, 150, 200]
const rainDrops = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]

const padTop = ref(20)
const padBottom = ref(8)
const pageH = ref(667)
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
const realMoney = ref(false)
const myResult = ref('')
const myPayout = ref(0)
const poolEnabled = ref(false)
const grossPool = ref(0)
const baseReserve = ref(0)
const rainProgress = ref(0)
const rainOpen = ref(false)
const rainAmount = ref(0)
const rainRelease = ref(0)
const rainParticipants = ref(0)
const rainGrantId = ref(0)
const poolStatus = ref('normal')
let netPoll = null
let tickTimer = null
let lastRound = -1
let syncAt = 0
let syncRemain = 12
let hitZero = false
let netPollMs = 2500

const pagePad = computed(() => ({
  paddingTop: padTop.value + 'px',
  paddingBottom: padBottom.value + 108 + 'px',
  height: pageH.value + 'px',
}))

const dockStyle = computed(() => ({
  paddingBottom: Math.max(8, padBottom.value) + 'px',
  left: '50%',
  width: '100%',
  maxWidth: '480px',
  transform: 'translateX(-50%)',
}))

const sheetStyle = computed(() => ({
  paddingBottom: 16 + Math.max(8, padBottom.value) + 'px',
}))

const poolText = computed(() => {
  const n = Number(pool.value) || 0
  return n.toLocaleString('zh-CN')
})

const poolGrossText = computed(() => {
  void locale.value
  return t('yxx_pool_gross', { n: (Number(grossPool.value) || 0).toLocaleString('zh-CN') })
})

const poolReserveText = computed(() => {
  void locale.value
  return t('yxx_pool_reserve', { n: (Number(baseReserve.value) || 0).toLocaleString('zh-CN') })
})

const rainProgText = computed(() => {
  void locale.value
  return t('yxx_pool_rain_prog', { n: rainProgress.value })
})

const rainBodyText = computed(() => {
  void locale.value
  return t('yxx_rain_body', {
    amount: rainAmount.value,
    release: (Number(rainRelease.value) || 0).toLocaleString('zh-CN'),
    participants: rainParticipants.value,
  })
})

const statusBannerText = computed(() => {
  void locale.value
  if (poolStatus.value === 'locked') return t('yxx_status_locked')
  if (poolStatus.value === 'paused') return t('yxx_status_paused')
  if (poolStatus.value === 'degraded') return t('yxx_status_degraded')
  return ''
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
  if (poolStatus.value === 'locked') return t('yxx_err_locked')
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
    if (myResult.value === 'win' && myPayout.value > 0) {
      return t('yxx_hint_win', { n: myPayout.value })
    }
    if (myResult.value === 'lose') {
      return t('yxx_hint_lose')
    }
    const lab = faceLabel(settleFace.value)
    return lab ? t('yxx_hint_reveal', { face: lab }) : t('yxx_hint_lock')
  }
  if (selectedFace.value) {
    const key = realMoney.value ? 'yxx_hint_pick_real' : 'yxx_hint_pick'
    return t(key, { face: faceLabel(selectedFace.value) })
  }
  return t(realMoney.value ? 'yxx_hint_idle_real' : 'yxx_hint_idle')
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

const feedItems = computed(() => {
  void locale.value
  return feedRows.value.map((row) => {
    if (typeof row === 'string') {
      return { nick: '', line: row }
    }
    const face = faceLabel(row.face)
    return {
      nick: row.nick || '',
      line: t('yxx_feed_line', { nick: '·', face, stake: row.stake || 0 }).replace(/^·\s*/, ''),
    }
  })
})

const diceLabels = computed(() => {
  void locale.value
  return diceShow.value.map((id) => (id ? faceLabel(id) : ''))
})
const feedLeft = computed(() => feedItems.value.filter((_, i) => i % 2 === 0))
const feedRight = computed(() => feedItems.value.filter((_, i) => i % 2 === 1))

function measure() {
  try {
    applySafeAreaCssVars()
    const sys = uni.getSystemInfoSync() || {}
    const inset = getSafeAreaInsets()
    let bottom = Number(inset.bottom || 0)
    if (bottom < 1) {
      const sa = sys.safeArea
      if (sa && typeof sa.bottom === 'number' && sys.screenHeight) {
        bottom = Math.max(0, Number(sys.screenHeight) - Number(sa.bottom))
      }
    }
    padTop.value = Math.max(12, Number(inset.top || sys.statusBarHeight || 20))
    padBottom.value = Math.max(8, bottom)
    pageH.value = Math.max(480, Number(sys.windowHeight || 667))
    const header = 62
    const stats = 44
    const poolBar = poolEnabled.value ? 52 : 0
    const banner = poolStatus.value && poolStatus.value !== 'normal' ? 28 : 0
    const dock = 108 + padBottom.value
    const h = pageH.value - padTop.value - header - stats - poolBar - banner - dock
    scrollH.value = Math.max(220, h) + 'px'
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

function openVerify() {
  const rows = historyRows.value || []
  let idx = 0
  if (phase.value === 'reveal' && lastRound >= 0) {
    idx = lastRound
  } else if (rows.length && rows[0] && rows[0].round_index != null) {
    idx = Number(rows[0].round_index) || 0
  } else if (lastRound > 0) {
    idx = lastRound - 1
  }
  uni.navigateTo({
    url: '/pages/common/fair-verify?kind=yxx&round_index=' + encodeURIComponent(String(idx)),
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
  realMoney.value = !!(data.real_money || data.debit)
  stakeMin.value = Number(data.stake_min || 50)
  stakeMax.value = Number(data.stake_max || 200)
  const r = data.round || {}
  if (r.pool != null) pool.value = Number(r.pool)
  if (r.remain_sec != null) {
    remainSec.value = Number(r.remain_sec)
    syncRemain = remainSec.value
    syncAt = Date.now()
    hitZero = remainSec.value <= 0
  }
  if (r.player_count != null) playerCount.value = Number(r.player_count)
  phase.value = String(r.phase || 'betting')
  const idx = Number(r.round_index || 0)
  if (idx !== lastRound) {
    lastRound = idx
    myFace.value = ''
    myStake.value = 0
    myResult.value = ''
    myPayout.value = 0
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
    myResult.value = String(mine.result || '')
    myPayout.value = Number(mine.payout || 0)
  }
  if (stake.value < stakeMin.value) stake.value = stakeMin.value

  const pinfo = data.pool || {}
  poolEnabled.value = !!pinfo.pool_enabled
  grossPool.value = Number(pinfo.gross_pool || 0)
  baseReserve.value = Number(pinfo.base_reserve || 0)
  rainProgress.value = Math.min(100, Number(pinfo.rain_progress || 0))
  poolStatus.value = String(data.pool_status || pinfo.pool_status || 'normal')
  const nextPoll = Number(data.poll_ms || 0)
  if (nextPoll >= 800 && nextPoll !== netPollMs) {
    netPollMs = nextPoll
    startNetPoll()
  }
  measure()

  const popup = data.rain_popup
  if (popup && popup.amount > 0) {
    rainAmount.value = Number(popup.amount || 0)
    rainRelease.value = Number(popup.release || 0)
    rainParticipants.value = Number(popup.participants || 0)
    rainGrantId.value = Number(popup.grant_id || 0)
    rainOpen.value = true
  }
}

async function ackRain() {
  rainOpen.value = false
  try {
    await apiRequest('yxxrainack', 'POST', { grant_id: rainGrantId.value })
  } catch (e) {}
}

async function loadHall() {
  try {
    const data = await apiRequest('yxxhall', 'GET', {}, { skipAuthRedirect: true })
    applyHall(data)
  } catch (e) {}
}

async function onBet() {
  if (poolStatus.value === 'locked') {
    uni.showToast({ title: t('yxx_err_locked'), icon: 'none' })
    return
  }
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
    if (realMoney.value) {
      uni.showToast({ title: t('yxx_bet_ok', { n: stake.value }), icon: 'none' })
    } else {
      uni.showToast({ title: t('yxx_preview_ok'), icon: 'none' })
    }
  } catch (e) {
    uni.showToast({ title: (e && e.message) || t('yxx_bet_fail'), icon: 'none' })
  } finally {
    betting.value = false
  }
}

function tickLocal() {
  if (!syncAt) return
  const elapsed = Math.floor((Date.now() - syncAt) / 1000)
  const next = Math.max(0, syncRemain - elapsed)
  if (next !== remainSec.value) remainSec.value = next
  if (next <= 0 && !hitZero) {
    hitZero = true
    loadHall()
  }
}

function startNetPoll() {
  if (netPoll) clearInterval(netPoll)
  netPoll = setInterval(() => {
    loadHall()
  }, Math.max(800, netPollMs))
}

function startPoll() {
  stopPoll()
  tickTimer = setInterval(tickLocal, 250)
  startNetPoll()
}

function stopPoll() {
  if (netPoll) {
    clearInterval(netPoll)
    netPoll = null
  }
  if (tickTimer) {
    clearInterval(tickTimer)
    tickTimer = null
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

onResize(() => {
  measure()
})

onUnmounted(() => {
  stopPoll()
})
</script>

<style scoped>
.yxx-page {
  height: 100%;
  overflow: hidden;
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
.yxx-h-side {
  width: 31%;
  min-width: 72px;
  flex-shrink: 0;
  display: flex;
  align-items: center;
}
.yxx-h-left {
  justify-content: flex-start;
}
.yxx-h-right {
  justify-content: flex-end;
  gap: 4px;
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
  min-width: 36px;
  padding: 0 8px;
  border-radius: 10px;
  font-size: 12px;
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
  font-size: 24px;
  font-weight: 900;
  letter-spacing: 3px;
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
.yxx-status-banner {
  margin: 0 10px 8px;
  padding: 6px 10px;
  border-radius: 10px;
  background: rgba(180, 40, 20, 0.55);
  border: 1px solid rgba(255, 180, 80, 0.45);
  font-size: 11px;
  font-weight: 700;
  text-align: center;
  color: #ffe29a;
}
.yxx-pool-bar {
  margin: 0 10px 8px;
  padding: 8px 10px;
  border-radius: 12px;
  background: rgba(40, 6, 8, 0.75);
  border: 1px solid rgba(255, 190, 90, 0.35);
}
.yxx-pool-row {
  display: flex;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 6px;
}
.yxx-pool-txt {
  font-size: 10px;
  font-weight: 700;
  color: #ffe29a;
}
.yxx-pool-txt.dim {
  color: rgba(255, 255, 255, 0.65);
}
.yxx-pool-track {
  height: 6px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.12);
  overflow: hidden;
}
.yxx-pool-fill {
  height: 100%;
  border-radius: 999px;
  background: linear-gradient(90deg, #f0c14b, #ff6b3d);
  transition: width 0.4s ease;
}
.yxx-pool-prog {
  display: block;
  margin-top: 4px;
  font-size: 10px;
  color: rgba(255, 255, 255, 0.72);
  text-align: right;
}
.yxx-rain-card {
  position: relative;
  z-index: 2;
  width: 86%;
  max-width: 320px;
  margin: 0;
  padding: 24px 18px 18px;
  border-radius: 18px;
  background: linear-gradient(180deg, #8f1212, #4a0608);
  border: 2px solid #f0c14b;
  text-align: center;
  box-shadow: 0 16px 40px rgba(0, 0, 0, 0.45);
}
.yxx-rain-ico {
  font-size: 42px;
  line-height: 1.2;
}
.yxx-rain-title {
  display: block;
  margin-top: 6px;
  font-size: 20px;
  font-weight: 900;
  color: #ffd56a;
}
.yxx-rain-amt {
  display: block;
  margin: 10px 0;
  font-size: 36px;
  font-weight: 900;
  color: #fff3c4;
}
.yxx-rain-body {
  display: block;
  font-size: 12px;
  line-height: 1.5;
  color: rgba(255, 255, 255, 0.85);
  margin-bottom: 14px;
}
.yxx-rain-btn {
  display: inline-block;
  padding: 10px 28px;
  border-radius: 999px;
  background: linear-gradient(180deg, #f0c14b, #c48418);
  color: #4a2200;
  font-size: 15px;
  font-weight: 800;
}
.yxx-scroll {
  box-sizing: border-box;
}
.yxx-grid-wrap {
  margin: 4px 12px 0;
  padding: 12px 8px 52px;
  border-radius: 16px;
  position: relative;
  background: linear-gradient(180deg, rgba(90, 12, 16, 0.92), rgba(50, 6, 10, 0.92));
  border: 2px solid #e0b24a;
  box-shadow: inset 0 0 0 1px rgba(255, 220, 140, 0.35), 0 8px 24px rgba(0, 0, 0, 0.35);
}
.yxx-stud {
  position: absolute;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: radial-gradient(circle at 35% 30%, #fff3c4, #d7a23a 55%, #8a4a10);
  z-index: 2;
}
.yxx-stud.s1 { top: 8px; left: 8px; }
.yxx-stud.s2 { top: 8px; right: 8px; }
.yxx-stud.s3 { bottom: 8px; left: 8px; }
.yxx-stud.s4 { bottom: 8px; right: 8px; }
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
  width: 68px;
  height: 68px;
  margin: 0 auto;
  border-radius: 50%;
  overflow: hidden;
  border: 2px solid #f3d07a;
  box-shadow: 0 4px 10px rgba(0, 0, 0, 0.35), 0 0 0 1px rgba(255, 220, 140, 0.25);
  background: #5a1010;
}
.yxx-face-img {
  width: 68px;
  height: 68px;
}
.yxx-cell.on .yxx-ring,
.yxx-cell.win .yxx-ring {
  box-shadow: 0 0 0 3px #fff3c4, 0 0 16px rgba(255, 210, 80, 0.7);
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
.yxx-bowl-img {
  width: 108px;
  height: 72px;
  z-index: 2;
  transform: translate(0, 28px) rotate(0deg);
  transition: transform 0.45s ease;
}
.yxx-reveal.open .yxx-bowl-img {
  transform: translate(-28px, -10px) rotate(-22deg);
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
  width: 52px;
  height: 52px;
  border-radius: 8px;
  background: #fff;
  color: #b01018;
  overflow: hidden;
  position: relative;
  box-shadow: 0 4px 8px rgba(0, 0, 0, 0.35);
}
.yxx-die-img {
  width: 52px;
  height: 52px;
}
.yxx-die-lab {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  font-size: 9px;
  font-weight: 900;
  text-align: center;
  color: #fff8e6;
  background: rgba(140, 16, 20, 0.78);
  line-height: 14px;
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
  text-decoration: underline;
  text-underline-offset: 2px;
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
  display: flex;
  align-items: center;
  gap: 4px;
  min-width: 0;
  margin-bottom: 4px;
}
.yxx-nick {
  flex-shrink: 0;
  max-width: 42%;
  padding: 1px 5px;
  border-radius: 4px;
  font-size: 10px;
  font-weight: 800;
  color: #fff4d6;
  background: #c41a22;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}
.yxx-feed-txt {
  flex: 1;
  min-width: 0;
  font-size: 11px;
  color: rgba(255, 247, 230, 0.88);
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}
.yxx-dock {
  position: fixed;
  bottom: 0;
  z-index: 20;
  box-sizing: border-box;
  padding: 6px 12px;
  background: linear-gradient(180deg, rgba(80, 10, 12, 0.92), #3a0608);
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
  background: rgba(28, 8, 10, 0.92);
  border: 1px solid rgba(240, 193, 75, 0.35);
  display: flex;
  align-items: center;
}
.yxx-input {
  flex: 1;
  height: 44px;
  padding: 0 52px 0 12px;
  font-size: 16px;
  font-weight: 800;
  color: #ffe29a;
}
.yxx-amt-lab {
  position: absolute;
  right: 10px;
  font-size: 13px;
  color: #f0c14b;
  font-weight: 700;
}
.yxx-confirm {
  width: 128px;
  height: 44px;
  line-height: 44px;
  text-align: center;
  border-radius: 22px;
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
  left: 0;
  right: 0;
  top: 0;
  bottom: 0;
  z-index: 40;
  background: rgba(0, 0, 0, 0.55);
  display: flex;
  align-items: flex-end;
  justify-content: center;
}
.yxx-rain-mask {
  align-items: center;
  padding: 0 16px;
  box-sizing: border-box;
  overflow: hidden;
}
.yxx-rain-fall {
  position: absolute;
  left: 0;
  right: 0;
  top: 0;
  bottom: 0;
  overflow: hidden;
  pointer-events: none;
}
.yxx-drop {
  position: absolute;
  top: -8%;
  font-size: 22px;
  line-height: 1;
  opacity: 0.92;
  animation-name: yxx-fall;
  animation-timing-function: linear;
  animation-iteration-count: infinite;
}
.yxx-drop.d1 { left: 6%; animation-duration: 1.6s; animation-delay: 0s; }
.yxx-drop.d2 { left: 14%; animation-duration: 1.9s; animation-delay: 0.2s; font-size: 18px; }
.yxx-drop.d3 { left: 22%; animation-duration: 1.5s; animation-delay: 0.45s; }
.yxx-drop.d4 { left: 31%; animation-duration: 2.1s; animation-delay: 0.1s; font-size: 20px; }
.yxx-drop.d5 { left: 39%; animation-duration: 1.7s; animation-delay: 0.55s; }
.yxx-drop.d6 { left: 48%; animation-duration: 1.8s; animation-delay: 0.3s; font-size: 24px; }
.yxx-drop.d7 { left: 56%; animation-duration: 2s; animation-delay: 0.15s; }
.yxx-drop.d8 { left: 64%; animation-duration: 1.55s; animation-delay: 0.7s; font-size: 18px; }
.yxx-drop.d9 { left: 72%; animation-duration: 1.85s; animation-delay: 0.25s; }
.yxx-drop.d10 { left: 80%; animation-duration: 1.65s; animation-delay: 0.5s; }
.yxx-drop.d11 { left: 88%; animation-duration: 2.05s; animation-delay: 0.05s; font-size: 20px; }
.yxx-drop.d12 { left: 93%; animation-duration: 1.75s; animation-delay: 0.4s; }
@keyframes yxx-fall {
  0% { transform: translateY(0); opacity: 0; }
  12% { opacity: 0.95; }
  100% { transform: translateY(118%); opacity: 0.75; }
}
.yxx-sheet {
  width: 100%;
  max-width: 480px;
  background: #2a0a0c;
  border-radius: 16px 16px 0 0;
  padding: 16px;
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
<style>
page {
  height: 100%;
  overflow: hidden;
  background: #1a0204;
}
</style>

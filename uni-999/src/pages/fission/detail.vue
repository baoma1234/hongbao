<template>
  <view class="fx-page">
    <TopBar :title="tt('tab_bar_fission', '裂变红宝') || '裂变红宝'" />
    <scroll-view scroll-y class="fx-scroll" :style="{ height: scrollH }">
      <view v-if="loading" class="fx-loading">加载中…</view>
      <view v-else-if="!hasActivity" class="fx-empty">
        <text class="fx-empty-title">暂无裂变红宝活动</text>
        <text class="fx-empty-sub">活动开启后可在此参与</text>
      </view>
      <view v-else class="fx-body">
        <!-- 核心奖金区（与 2.html .main-card 一致） -->
        <view class="main-card">
          <view v-if="state === 'running' && remainText" class="timer-badge">{{ remainText }}</view>

          <view class="prize-title-wrapper" @click="openPoolRecords">
            <text class="prize-amount"><text class="prize-yen">￥</text>{{ poolText }}</text>
            <text class="prize-label">奖金池 · 点看领取记录</text>
          </view>

          <view class="progress-track">
            <view class="progress-fill" :style="{ width: Math.max(progressPct, 2) + '%' }" />
          </view>
          <text class="progress-text">当前 {{ globalQuals }} / {{ globalCap }} 份资格</text>

          <view v-if="priorClaimPending" class="fx-result ok">
            <text class="fx-result-title">有奖金待领取</text>
            <text class="fx-result-sub tip">还有 {{ unclaimedCount }} 份待拆，点「我的资格」领取</text>
          </view>
          <view v-else-if="state === 'running' && canClaim" class="fx-result ok">
            <text class="fx-result-title">资格已到账</text>
            <text class="fx-result-sub tip">可立即拆红包，无需等人数满 · 剩 {{ unclaimedCount }} 份</text>
          </view>
          <view v-else-if="state === 'success'" class="fx-result ok">
            <text class="fx-result-title">开奖成功</text>
            <text v-if="showWinAmount" class="fx-result-amt">你的中奖金额：¥{{ winText }}</text>
            <text v-else-if="unclaimedCount > 0" class="fx-result-sub tip">点「我的资格」拆开红包后查看金额</text>
            <text v-if="unclaimedCount > 0" class="fx-result-sub tip">还有 {{ unclaimedCount }} 份待拆，点「我的资格」领取</text>
            <text v-else class="fx-result-sub">上下级关系永久保留</text>
          </view>
          <view v-else-if="state === 'expired'" class="fx-result fail">
            <text class="fx-result-title">活动已结束</text>
            <text class="fx-result-sub">未集齐的剩余份额留在平台</text>
            <text v-if="unclaimedCount > 0" class="fx-result-sub tip">你还有 {{ unclaimedCount }} 份待拆，点「我的资格」领取</text>
            <text class="fx-result-sub">上下级邀请关系永久保留</text>
          </view>

          <view class="stats-grid">
            <view
              class="stat-box"
              :class="{ claimable: canClaim }"
              @click="onQualClick"
            >
              <view class="stat-dot tl" />
              <view class="stat-dot tr" />
              <view class="stat-curve" />
              <text class="stat-top">我的资格：{{ myQuals }} / {{ userCap }}</text>
              <text class="stat-bottom">{{ canClaim ? '点击领奖' : (myQuals + '/' + userCap) }}</text>
            </view>
            <view class="stat-box" @click="shareLink">
              <view class="stat-dot tl" />
              <view class="stat-dot tr" />
              <view class="stat-curve" />
              <text class="stat-top">直属下级：{{ subCount }}人</text>
              <text class="stat-bottom">分享</text>
            </view>
          </view>

          <view v-if="groupJoinUrl" class="invite-block">
            <text class="invite-label">官方群链接</text>
            <view class="invite-wrapper">
              <text class="invite-icon">👥</text>
              <text class="invite-text">打开自动进群</text>
              <button type="button" class="copy-btn" @click="copyGroupLink">复制</button>
              <button type="button" class="copy-btn" style="margin-left:6px" @click="openGroupJoin">进群</button>
            </view>
          </view>

          <view v-if="state === 'running' || inviteLink" class="invite-block">
            <text class="invite-label">邀请链接</text>
            <view class="invite-wrapper">
              <text class="invite-icon">📍</text>
              <text class="invite-text">我的专属邀请链接</text>
              <button type="button" class="copy-btn" @click="copyLink">复制链接</button>
            </view>
          </view>

          <view v-if="state === 'running'" class="fx-join-wrap">
            <text class="fx-join-tip">邀请新人注册得资格后，可立即拆红包，无需等人数满</text>
          </view>
        </view>

        <!-- 规则区 -->
        <view class="rules-card">
          <view class="rules-title">
            <view class="rules-line left" />
            <text class="rules-title-text">活动规则</text>
            <view class="rules-line right" />
          </view>
          <view class="rules-list">
            <text v-for="(r, i) in displayRules" :key="i" class="rules-item">
              {{ i + 1 }}. {{ r }}
            </text>
          </view>
        </view>

        <view class="footer-notice">
          <view class="check-icon">✓</view>
          <text class="footer-notice-text">红包不承诺必得，邀请有效新用户才可累计资格</text>
        </view>
      </view>
    </scroll-view>
    <BottomTabBar active="fission" />

    <!-- 开奖后逐份拆红包：设计稿 1:1 + 拆开动画（H5 / Safari / APK / IPA） -->
    <view
      v-if="claimOpen"
      class="fx-claim-mask"
      :style="claimMaskStyle"
      @touchmove.stop.prevent
      @click="closeClaim"
    >
      <view class="fx-claim-stage" @click.stop>
        <!-- 未拆开：设计稿红包 + 金币可点拆开 -->
        <view v-if="!claimOpened" class="fx-claim-closed" :class="{ 'is-opening': claimOpening }">
          <image
            class="fx-claim-art"
            src="/static/fission/claim-hongbao.png"
            mode="widthFix"
          />
          <view
            class="fx-claim-seal"
            :class="{ spin: claimOpening }"
            @click="doClaim"
          />
          <view class="fx-claim-foot">
            <text class="fx-claim-cta">{{ claimOpening ? '拆开中…' : '点击金币拆开红包' }}</text>
            <text class="fx-claim-remain">还剩 {{ claimRemainHint }} 份可领</text>
          </view>
        </view>
        <!-- 已拆开：金额浮层 -->
        <view v-else class="fx-claim-opened">
          <image
            class="fx-claim-art is-open"
            src="/static/fission/claim-hongbao.png"
            mode="widthFix"
          />
          <view class="fx-claim-result">
            <text class="fx-claim-got">恭喜获得</text>
            <text class="fx-claim-amt">¥{{ claimAmtText }}</text>
            <text class="fx-claim-sub">已入账红宝</text>
            <button
              v-if="unclaimedCount > 0"
              type="button"
              class="fx-claim-btn"
              :disabled="claiming"
              @click="prepareNextClaim"
            >
              继续拆下一份（剩 {{ unclaimedCount }}）
            </button>
            <button v-else type="button" class="fx-claim-btn ghost" @click="closeClaim">完成</button>
          </view>
        </view>
        <view class="fx-claim-x" @click="closeClaim">×</view>
      </view>
    </view>
  </view>
</template>

<script setup>
import { computed, ref, onUnmounted } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import BottomTabBar from '../../components/BottomTabBar.vue'
import { apiRequest, getToken } from '../../utils/auth.js'
import { applySafeAreaCssVars, getSafeAreaInsets, getTopBarContentHeight } from '../../utils/safe-area.js'
import { copyText } from '../../utils/master.js'
import { savePendingGroupJoin, tryConsumeGroupJoin } from '../../utils/group-invite.js'
import { tt } from '../../utils/i18n.js'

const loading = ref(true)
const detail = ref(null)
const scrollH = ref('100vh')
const remainSec = ref(0)
const claimOpen = ref(false)
const claimOpened = ref(false)
const claimOpening = ref(false)
const claiming = ref(false)
const claimAmt = ref(0)
const claimSafeTop = ref(0)
const claimSafeBottom = ref(0)
let tickTimer = null
let claimAnimTimer = null

const hasActivity = computed(() => !!(detail.value && detail.value.has_activity))
const state = computed(() => (detail.value && detail.value.state) || 'none')
const act = computed(() => (detail.value && detail.value.activity) || {})
const me = computed(() => (detail.value && detail.value.me) || {})
const groupInfo = computed(() => (detail.value && detail.value.group) || {})
const groupJoinUrl = computed(() => String(groupInfo.value.join_url || ''))
const groupJoinId = computed(() => (groupInfo.value.group_id | 0) || 0)
const groupJoinToken = computed(() => String(groupInfo.value.token || ''))

const poolText = computed(() => formatMoney(act.value.pool_amount))
const globalQuals = computed(() => Number(act.value.global_quals || 0))
const globalCap = computed(() => Number(act.value.global_cap || 100))
const progressPct = computed(() => Math.min(100, Number(act.value.progress_pct || 0)))
const myQuals = computed(() => Number(me.value.qual_count || 0))
const userCap = computed(() => Number(me.value.user_cap || 5))
const subCount = computed(() => Number(me.value.subordinate_count || 0))
const inviteLink = computed(() => String(me.value.invite_link || ''))
const winText = computed(() => formatMoney(me.value.win_amount))
const unclaimedCount = computed(() => Number(me.value.unclaimed_count || 0))
const claimedCount = computed(() => Number(me.value.claimed_count || 0))
const priorClaimPending = computed(() => !!me.value.prior_claim_pending)
/** 未拆开前不展示中奖金额；至少拆过一份且无待拆时才显示合计 */
const showWinAmount = computed(() => {
  if (unclaimedCount.value > 0) return false
  if (claimedCount.value <= 0 && myQuals.value <= 0) return false
  return Number(me.value.win_amount || 0) > 0 || claimedCount.value > 0
})
const canClaim = computed(() => !!me.value.can_claim || unclaimedCount.value > 0)
const claimAmtText = computed(() => formatMoney(claimAmt.value))
const claimRemainHint = computed(() => Math.max(1, unclaimedCount.value || 1))
const claimMaskStyle = computed(() => ({
  paddingTop: claimSafeTop.value + 'px',
  paddingBottom: claimSafeBottom.value + 'px',
}))

const displayRules = computed(() => [
  '活动开始后每邀 1 位新人注册：邀请人和被邀请人各得 1 份（每人上限' + userCap.value + '）',
  '活动开始前的老下级不计入',
  '获得资格后立即拆红包，无需等待人数满额',
  '点击奖金池可查看领取记录与余额',
])

const remainText = computed(() => {
  const s = Math.max(0, remainSec.value | 0)
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  const sec = s % 60
  const pad = (n) => (n < 10 ? '0' + n : '' + n)
  return pad(h) + ':' + pad(m) + ':' + pad(sec)
})

function formatMoney(v) {
  const n = Number(v)
  if (!isFinite(n)) return '0'
  return n.toFixed(2).replace(/\.00$/, '')
}

function measureScroll() {
  try {
    applySafeAreaCssVars()
    const sys = uni.getSystemInfoSync()
    const inset = getSafeAreaInsets()
    const status = Number(inset.top || sys.statusBarHeight || 20)
    const topBar = getTopBarContentHeight()
    const tab = 72 + Number(inset.bottom || 0)
    const h = (sys.windowHeight || 667) - status - topBar - tab
    scrollH.value = Math.max(280, h) + 'px'
    claimSafeTop.value = Math.max(0, Number(inset.top || sys.statusBarHeight || 0))
    claimSafeBottom.value = Math.max(0, Number(inset.bottom || 0))
  } catch (e) {
    scrollH.value = '70vh'
  }
}

/** 未登录点功能 → 登录页；登录后回到裂变页 */
function requireLogin() {
  if (getToken()) return true
  try {
    uni.setStorageSync('fanshub_login_return', '/pages/fission/detail')
  } catch (e) {}
  uni.reLaunch({ url: '/pages/login/login' })
  return false
}

function onQualClick() {
  if (!requireLogin()) return
  if (!canClaim.value) {
    if (myQuals.value > 0 && unclaimedCount.value <= 0) {
      uni.showToast({ title: '奖已领完', icon: 'none' })
    } else if (myQuals.value <= 0) {
      uni.showToast({ title: '邀请新人获得资格后可领取', icon: 'none' })
    }
    return
  }
  openClaim()
}

function openPoolRecords() {
  const aid = (act.value && act.value.id) | 0
  const q = aid > 0 ? '?activity_id=' + aid : ''
  uni.navigateTo({ url: '/pages/fission/claims' + q })
}

function copyGroupLink() {
  const link = groupJoinUrl.value
  if (!link) {
    uni.showToast({ title: '暂无群链接', icon: 'none' })
    return
  }
  copyText(link)
    .then(() => uni.showToast({ title: '群链接已复制', icon: 'none' }))
    .catch(() => {
      uni.setClipboardData({
        data: link,
        success: () => uni.showToast({ title: '群链接已复制', icon: 'none' }),
      })
    })
}

async function openGroupJoin() {
  if (!requireLogin()) return
  const gid = groupJoinId.value
  if (!gid) return
  savePendingGroupJoin(gid, groupJoinToken.value)
  await tryConsumeGroupJoin({ silent: false })
}

function openClaim() {
  measureScroll()
  claimOpened.value = false
  claimOpening.value = false
  claimAmt.value = 0
  claimOpen.value = true
}

function closeClaim() {
  if (claiming.value || claimOpening.value) return
  claimOpen.value = false
  claimOpened.value = false
  claimOpening.value = false
}

function prepareNextClaim() {
  if (claiming.value || claimOpening.value) return
  claimOpened.value = false
  claimOpening.value = false
  claimAmt.value = 0
}

function waitMs(ms) {
  return new Promise((resolve) => {
    claimAnimTimer = setTimeout(() => {
      claimAnimTimer = null
      resolve()
    }, ms)
  })
}

async function doClaim() {
  if (claiming.value || claimOpened.value || claimOpening.value) return
  if (!requireLogin()) return
  claiming.value = true
  claimOpening.value = true
  try {
    const [data] = await Promise.all([apiRequest('fissionclaim', 'POST', {}), waitMs(780)])
    claimAmt.value = Number((data && data.amount) || 0)
    claimOpening.value = false
    claimOpened.value = true
    if (data && data.detail) {
      detail.value = data.detail
    } else {
      await loadDetail()
    }
  } catch (e) {
    claimOpening.value = false
    uni.showToast({ title: (e && e.message) || '领取失败', icon: 'none' })
    if (String((e && e.message) || '').indexOf('没有可领取') >= 0) {
      claimOpen.value = false
      await loadDetail()
    }
  } finally {
    claiming.value = false
  }
}

function stopTick() {
  if (tickTimer) {
    clearInterval(tickTimer)
    tickTimer = null
  }
}

function startTick() {
  stopTick()
  if (state.value !== 'running') return
  tickTimer = setInterval(() => {
    if (remainSec.value > 0) remainSec.value -= 1
    else stopTick()
  }, 1000)
}

function applyDetailPayload(data) {
  detail.value = data || null
  remainSec.value = Number((data && data.activity && data.activity.remain_sec) || 0)
  startTick()
}

/** 旧接口仍需登录时：用可匿名的 fissionentry 拼出访客可见详情 */
function detailFromEntry(fe) {
  if (!fe || !fe.has_activity || !fe.activity) {
    return {
      has_activity: false,
      state: 'none',
      activity: null,
      me: null,
      server_time: (fe && fe.server_time) || Math.floor(Date.now() / 1000),
    }
  }
  const a = fe.activity
  const entryState = String(fe.entry_state || '')
  let state = 'none'
  if (entryState === 'active') state = 'running'
  else if (entryState === 'ended') {
    const st = Number(a.status || 0)
    state = st === 2 ? 'success' : 'expired'
  }
  const global = Number(a.global_quals || 0)
  const cap = Math.max(1, Number(a.global_cap || 100))
  const remain =
    (fe.popup && fe.popup.remain_sec != null)
      ? Number(fe.popup.remain_sec)
      : Math.max(0, Number(a.end_time || 0) - Math.floor(Date.now() / 1000))
  return {
    has_activity: true,
    state,
    activity: Object.assign({}, a, {
      progress_pct: Math.min(100, Math.round((global * 100) / cap)),
      remain_sec: remain,
      can_gain: state === 'running' && global < cap,
    }),
    me: {
      joined: false,
      qual_count: 0,
      user_cap: Number(a.user_cap || 5),
      subordinate_count: 0,
      win_amount: 0,
      unclaimed_count: 0,
      claimed_count: 0,
      can_claim: false,
      quals: [],
      invite_link: '',
      invite_code: '',
    },
    server_time: fe.server_time || Math.floor(Date.now() / 1000),
  }
}

async function loadDetail() {
  loading.value = true
  try {
    const data = await apiRequest('fissiondetail', 'GET', {}, { skipAuthRedirect: true })
    applyDetailPayload(data)
  } catch (e) {
    try {
      const fe = await apiRequest('fissionentry', 'GET', {}, { skipAuthRedirect: true })
      applyDetailPayload(detailFromEntry(fe))
    } catch (e2) {
      uni.showToast({ title: (e2 && e2.message) || (e && e.message) || '加载失败', icon: 'none' })
    }
  } finally {
    loading.value = false
  }
}

function copyLink() {
  if (!requireLogin()) return
  const link = inviteLink.value
  if (!link) {
    uni.showToast({ title: '暂无邀请链接', icon: 'none' })
    return
  }
  copyText(link)
    .then(() => uni.showToast({ title: '链接已复制', icon: 'none' }))
    .catch(() => {
      uni.setClipboardData({
        data: link,
        success: () => uni.showToast({ title: '链接已复制', icon: 'none' }),
      })
    })
}

async function shareLink() {
  if (!requireLogin()) return
  const link = inviteLink.value
  const text =
    '🧧全网裂变红宝，¥' +
    poolText.value +
    ' 奖金池，快来一起拆！\n' +
    (link || '')
  // #ifdef H5
  try {
    if (typeof navigator !== 'undefined' && navigator.share) {
      await navigator.share({ title: '裂变红宝', text })
      return
    }
  } catch (e) {}
  // #endif
  uni.setClipboardData({
    data: text,
    success: () => uni.showToast({ title: '已复制，可粘贴分享', icon: 'none' }),
  })
}

onShow(() => {
  measureScroll()
  loadDetail()
})

onUnmounted(() => {
  stopTick()
  if (claimAnimTimer) {
    clearTimeout(claimAnimTimer)
    claimAnimTimer = null
  }
})
</script>

<style scoped>
/* 从 Desktop/2.html 1:1 移植 */
.fx-page {
  min-height: 100vh;
  background: radial-gradient(circle at 50% 0%, #151d32 0%, #06080e 60%);
  color: #fff;
  box-sizing: border-box;
  max-width: 414px;
  margin: 0 auto;
  padding-bottom: calc(70px + var(--safe-area-inset-bottom, env(safe-area-inset-bottom, 0px)));
  -webkit-tap-highlight-color: transparent;
}

.fx-page-title {
  text-align: center;
  font-size: 16px;
  font-weight: 800;
  letter-spacing: 1px;
  color: #e2e4ed;
  padding: 8px 12px 4px;
}

.fx-scroll {
  box-sizing: border-box;
}
.fx-loading,
.fx-empty {
  padding: 80px 24px;
  text-align: center;
  color: #a4a8bd;
}
.fx-empty-title {
  display: block;
  font-size: 16px;
  color: #fff;
  margin-bottom: 8px;
}
.fx-empty-sub {
  display: block;
  font-size: 13px;
  color: #a4a8bd;
}
.fx-body {
  padding-bottom: 8px;
}

.main-card {
  background: linear-gradient(180deg, #1d2132 0%, #12141d 100%);
  border-radius: 18px;
  margin: 15px 20px;
  padding: 30px 20px 25px;
  position: relative;
  box-shadow: 0 15px 40px rgba(0, 0, 0, 0.8);
  border: 1px solid rgba(255, 255, 255, 0.08);
  border-top: 1px solid rgba(255, 255, 255, 0.15);
  box-sizing: border-box;
}

.timer-badge {
  position: absolute;
  top: 0;
  right: 0;
  background: linear-gradient(90deg, #ed3b33, #d1211b);
  color: #fff;
  padding: 5px 12px;
  font-size: 12px;
  font-weight: bold;
  font-family: 'DIN Alternate', monospace;
  border-radius: 0 16px 0 16px;
  box-shadow: -2px 2px 10px rgba(209, 33, 27, 0.4);
  z-index: 2;
}

.prize-title-wrapper {
  display: flex;
  flex-direction: column;
  align-items: center;
  margin-bottom: 22px;
  filter: drop-shadow(0px 4px 6px rgba(242, 154, 32, 0.2));
}
.prize-amount {
  background: linear-gradient(
    120deg,
    #f29a20 0%,
    #ffd466 30%,
    #ffffff 50%,
    #ffd466 70%,
    #f29a20 100%
  );
  background-size: 200% auto;
  -webkit-background-clip: text;
  background-clip: text;
  -webkit-text-fill-color: transparent;
  color: transparent;
  font-size: 64px;
  font-weight: 900;
  font-family: 'DIN Alternate', 'Impact', sans-serif;
  margin-right: 0;
  margin-bottom: 8px;
  line-height: 1;
  letter-spacing: -2px;
  animation: flowLight 3s linear infinite;
}
.prize-yen {
  font-size: 32px;
  font-weight: bold;
  margin-right: 2px;
  letter-spacing: 0;
}
.prize-label {
  display: block;
  background: linear-gradient(120deg, #ffe8ad 0%, #ffffff 50%, #f29a20 100%);
  background-size: 200% auto;
  -webkit-background-clip: text;
  background-clip: text;
  -webkit-text-fill-color: transparent;
  color: transparent;
  font-size: 24px;
  font-weight: 900;
  letter-spacing: 1.5px;
  animation: flowLight 3s linear infinite reverse;
}

@keyframes flowLight {
  0% {
    background-position: 200% center;
  }
  100% {
    background-position: 0% center;
  }
}

.progress-track {
  background: #090a0f;
  height: 10px;
  border-radius: 6px;
  margin-bottom: 10px;
  position: relative;
  box-shadow: inset 0 2px 6px rgba(0, 0, 0, 0.9);
  border: 1px solid rgba(255, 255, 255, 0.04);
  overflow: hidden;
}
.progress-fill {
  position: absolute;
  top: 0;
  left: 0;
  height: 100%;
  background: linear-gradient(90deg, #fcd88e 0%, #ff9c1a 100%);
  border-radius: 6px;
  box-shadow: 0 0 15px rgba(255, 156, 26, 0.5);
  transition: width 0.35s ease;
  min-width: 6px;
}
.progress-text {
  display: block;
  font-size: 13px;
  color: #a4a8bd;
  margin-bottom: 25px;
  font-weight: 500;
}

.fx-result {
  margin: -8px 0 18px;
  border-radius: 12px;
  padding: 12px;
  text-align: center;
}
.fx-result.ok {
  background: rgba(46, 160, 67, 0.18);
  border: 1px solid rgba(80, 200, 120, 0.4);
}
.fx-result.fail {
  background: rgba(180, 40, 40, 0.2);
  border: 1px solid rgba(255, 100, 100, 0.35);
}
.fx-result-title {
  display: block;
  font-size: 15px;
  font-weight: 800;
  margin-bottom: 4px;
  color: #fff;
}
.fx-result-amt {
  display: block;
  font-size: 18px;
  color: #e7ab44;
  font-weight: 800;
  margin-bottom: 4px;
}
.fx-result-sub {
  display: block;
  font-size: 12px;
  color: #a4a8bd;
  line-height: 1.5;
}
.fx-result-sub.tip {
  color: #ffd27a;
  font-weight: 700;
}

.stats-grid {
  display: flex;
  justify-content: space-between;
  gap: 15px;
  margin-bottom: 25px;
}
.stat-box {
  flex: 1;
  height: 90px;
  border-radius: 8px;
  position: relative;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  align-items: center;
  padding: 16px 0 14px;
  background: linear-gradient(145deg, #ff8441 0%, #f44425 40%, #da2314 100%);
  box-shadow:
    0 6px 15px rgba(0, 0, 0, 0.4),
    inset 0 1.5px 2px rgba(255, 255, 255, 0.6),
    inset 0 -2px 3px #ffda75;
  box-sizing: border-box;
}
.stat-dot {
  position: absolute;
  top: 8px;
  width: 4px;
  height: 4px;
  background: rgba(255, 255, 255, 0.3);
  transform: rotate(45deg);
}
.stat-dot.tl {
  left: 10px;
}
.stat-dot.tr {
  right: 10px;
}
.stat-curve {
  position: absolute;
  top: -24px;
  left: -10%;
  width: 120%;
  height: 70px;
  border-radius: 50%;
  border-bottom: 2.5px solid #ffeca8;
  filter: drop-shadow(0 3px 3px rgba(180, 0, 0, 0.8));
  -webkit-mask-image: linear-gradient(
    90deg,
    black 0%,
    black 42%,
    transparent 42%,
    transparent 58%,
    black 58%,
    black 100%
  );
  mask-image: linear-gradient(
    90deg,
    black 0%,
    black 42%,
    transparent 42%,
    transparent 58%,
    black 58%,
    black 100%
  );
  pointer-events: none;
}
.stat-top {
  font-size: 15px;
  color: #ffffff;
  font-weight: 800;
  z-index: 2;
  text-shadow: 0 1.5px 2px rgba(140, 10, 0, 0.8);
  position: relative;
}
.stat-bottom {
  font-size: 21px;
  font-weight: 900;
  color: #ffeba1;
  z-index: 2;
  text-shadow: 0 2px 3px rgba(150, 5, 0, 0.9);
  letter-spacing: 1px;
  position: relative;
}

.invite-label {
  display: block;
  font-size: 14px;
  color: #a4a8bd;
  margin-bottom: 12px;
}
.invite-wrapper {
  display: flex;
  align-items: center;
  background: #0a0c12;
  border-radius: 12px;
  padding: 8px 8px 8px 16px;
  box-shadow: inset 0 2px 8px rgba(0, 0, 0, 0.8);
  border: 1px solid rgba(255, 255, 255, 0.05);
  box-sizing: border-box;
}
.invite-icon {
  font-size: 16px;
  margin-right: 10px;
  flex: 0 0 auto;
}
.invite-text {
  flex: 1;
  font-size: 14px;
  color: #ced2e5;
  font-weight: 500;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}
.copy-btn {
  background: linear-gradient(90deg, #fde9aa 0%, #e7ab44 100%);
  color: #442700;
  border: none;
  border-radius: 8px;
  padding: 0 18px;
  height: 40px;
  line-height: 40px;
  font-size: 14px;
  font-weight: 800;
  box-shadow: 0 4px 15px rgba(231, 171, 68, 0.4);
  flex: 0 0 auto;
  margin: 0;
}
.copy-btn:active {
  transform: scale(0.95);
}

.fx-join-wrap {
  margin-top: 16px;
  text-align: center;
}
.fx-join-tip {
  display: block;
  font-size: 13px;
  line-height: 1.5;
  color: rgba(253, 233, 170, 0.85);
  padding: 0 8px;
}
.fx-btn-join {
  width: 100%;
  background: linear-gradient(90deg, #fde9aa 0%, #e7ab44 100%);
  color: #442700;
  font-size: 15px;
  font-weight: 800;
  border: none;
  border-radius: 12px;
  line-height: 46px;
  box-shadow: 0 4px 15px rgba(231, 171, 68, 0.35);
}

.rules-card {
  background: linear-gradient(180deg, #1f2233 0%, #12141d 100%);
  border-radius: 18px;
  margin: 0 20px;
  padding: 25px 20px 30px;
  border: 1px solid rgba(255, 255, 255, 0.08);
  border-top: 1px solid rgba(255, 255, 255, 0.15);
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.6);
  box-sizing: border-box;
}
.rules-title {
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 22px;
}
.rules-title-text {
  color: #e7ab44;
  font-size: 17px;
  font-weight: bold;
  letter-spacing: 1px;
}
.rules-line {
  width: 45px;
  height: 1px;
  opacity: 0.7;
  margin: 0 15px;
  flex: 0 0 auto;
}
.rules-line.left {
  background: linear-gradient(90deg, transparent, #e7ab44);
}
.rules-line.right {
  background: linear-gradient(270deg, transparent, #e7ab44);
}
.rules-list {
  display: flex;
  flex-direction: column;
}
.rules-item {
  color: #a4a8bd;
  font-size: 14px;
  line-height: 2.4;
  font-weight: 500;
}

.footer-notice {
  display: flex;
  justify-content: center;
  align-items: center;
  margin-top: 35px;
  padding: 0 20px 20px;
}
.check-icon {
  background: linear-gradient(135deg, #fde9aa, #e7ab44);
  color: #442700;
  width: 16px;
  height: 16px;
  border-radius: 4px;
  display: flex;
  justify-content: center;
  align-items: center;
  font-size: 12px;
  margin-right: 8px;
  font-weight: 900;
  flex: 0 0 auto;
  line-height: 16px;
  text-align: center;
}
.footer-notice-text {
  color: #c09e5c;
  font-size: 13px;
  font-weight: 500;
  line-height: 1.4;
}

.stat-box.claimable {
  box-shadow: 0 0 0 2px rgba(255, 220, 120, 0.55), 0 8px 18px rgba(180, 20, 0, 0.35);
  animation: fx-pulse 1.6s ease-in-out infinite;
}
@keyframes fx-pulse {
  0%,
  100% {
    transform: scale(1);
  }
  50% {
    transform: scale(1.02);
  }
}

.fx-claim-mask {
  position: fixed;
  left: 0;
  right: 0;
  top: 0;
  bottom: 0;
  z-index: 1200;
  background: rgba(0, 0, 0, 0.86);
  display: flex;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;
  /* #ifdef H5 */
  overscroll-behavior: none;
  /* #endif */
}
.fx-claim-stage {
  position: relative;
  width: min(92vw, 360px);
  max-width: 360px;
  box-sizing: border-box;
  text-align: center;
}
.fx-claim-closed,
.fx-claim-opened {
  position: relative;
  width: 100%;
}
.fx-claim-closed.is-opening .fx-claim-art {
  animation: fx-claim-shake 0.45s ease-in-out infinite;
}
.fx-claim-art {
  width: 100%;
  display: block;
  margin: 0 auto;
  filter: drop-shadow(0 18px 36px rgba(180, 20, 0, 0.45));
}
.fx-claim-art.is-open {
  opacity: 0.28;
  filter: blur(1px) brightness(0.55);
  transform: scale(0.96);
}
/* 金币热区：对准设计稿中央 ¥ 印章 */
.fx-claim-seal {
  position: absolute;
  left: 50%;
  top: 34%;
  width: 18vw;
  height: 18vw;
  max-width: 78px;
  max-height: 78px;
  min-width: 56px;
  min-height: 56px;
  transform: translate(-50%, -50%);
  border-radius: 50%;
  z-index: 3;
  box-shadow: 0 0 0 0 rgba(255, 214, 120, 0.55);
  animation: fx-seal-pulse 1.5s ease-in-out infinite;
}
.fx-claim-seal.spin {
  animation: fx-seal-spin 0.78s linear infinite;
  box-shadow: 0 0 22px 6px rgba(255, 210, 100, 0.65);
  background: radial-gradient(circle at 40% 35%, rgba(255, 236, 170, 0.35), rgba(212, 150, 40, 0.15) 55%, transparent 70%);
}
.fx-claim-foot {
  margin-top: 10px;
  padding: 0 8px 4px;
}
.fx-claim-cta {
  display: block;
  color: #ffe6a8;
  font-size: 16px;
  font-weight: 800;
  margin-bottom: 4px;
  text-shadow: 0 2px 8px rgba(0, 0, 0, 0.55);
}
.fx-claim-remain {
  display: block;
  color: #c9b08a;
  font-size: 13px;
}
.fx-claim-result {
  position: absolute;
  left: 50%;
  top: 42%;
  transform: translate(-50%, -50%);
  width: 78%;
  z-index: 4;
  animation: fx-result-pop 0.42s cubic-bezier(0.2, 0.9, 0.3, 1.2) both;
}
.fx-claim-got {
  display: block;
  color: #ffdca0;
  font-size: 15px;
  margin-bottom: 4px;
}
.fx-claim-amt {
  display: block;
  color: #fff6d0;
  font-size: 40px;
  font-weight: 900;
  margin: 4px 0 6px;
  letter-spacing: 1px;
  text-shadow: 0 4px 16px rgba(0, 0, 0, 0.55);
}
.fx-claim-sub {
  display: block;
  color: #b9a07a;
  font-size: 12px;
  margin-bottom: 16px;
}
.fx-claim-btn {
  width: 100%;
  height: 44px;
  line-height: 44px;
  border: none;
  border-radius: 22px;
  background: linear-gradient(135deg, #fde9aa, #e7ab44);
  color: #4a2200;
  font-size: 15px;
  font-weight: 800;
  margin-top: 4px;
}
.fx-claim-btn.ghost {
  background: transparent;
  color: #ffdca0;
  border: 1px solid rgba(255, 220, 160, 0.45);
}
.fx-claim-btn[disabled] {
  opacity: 0.6;
}
.fx-claim-x {
  position: absolute;
  top: -6px;
  right: 2px;
  width: 36px;
  height: 36px;
  line-height: 36px;
  text-align: center;
  color: #d7c4a0;
  font-size: 28px;
  z-index: 5;
}
@keyframes fx-seal-pulse {
  0%,
  100% {
    box-shadow: 0 0 0 0 rgba(255, 214, 120, 0.5);
    transform: translate(-50%, -50%) scale(1);
  }
  50% {
    box-shadow: 0 0 0 12px rgba(255, 214, 120, 0);
    transform: translate(-50%, -50%) scale(1.06);
  }
}
@keyframes fx-seal-spin {
  0% {
    transform: translate(-50%, -50%) rotate(0deg) scale(1);
  }
  50% {
    transform: translate(-50%, -50%) rotate(180deg) scale(1.1);
  }
  100% {
    transform: translate(-50%, -50%) rotate(360deg) scale(1);
  }
}
@keyframes fx-claim-shake {
  0%,
  100% {
    transform: translateX(0) rotate(0deg);
  }
  25% {
    transform: translateX(-3px) rotate(-0.6deg);
  }
  75% {
    transform: translateX(3px) rotate(0.6deg);
  }
}
@keyframes fx-result-pop {
  0% {
    opacity: 0;
    transform: translate(-50%, -40%) scale(0.7);
  }
  100% {
    opacity: 1;
    transform: translate(-50%, -50%) scale(1);
  }
}
.prize-title-wrapper {
  cursor: pointer;
}
</style>

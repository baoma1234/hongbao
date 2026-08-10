<template>
  <view class="fx-page">
    <TopBar />
    <scroll-view scroll-y class="fx-scroll" :style="{ height: scrollH }">
      <view class="fx-page-title">裂变红宝</view>
      <view v-if="loading" class="fx-loading">加载中…</view>
      <view v-else-if="!hasActivity" class="fx-empty">
        <text class="fx-empty-title">暂无裂变红宝活动</text>
        <text class="fx-empty-sub">活动开启后可在此参与</text>
      </view>
      <view v-else class="fx-body">
        <!-- 核心奖金区（与 2.html .main-card 一致） -->
        <view class="main-card">
          <view v-if="state === 'running' && remainText" class="timer-badge">{{ remainText }}</view>

          <view class="prize-title-wrapper">
            <text class="prize-amount"><text class="prize-yen">￥</text>{{ poolText }}</text>
            <text class="prize-label">奖金池</text>
          </view>

          <view class="progress-track">
            <view class="progress-fill" :style="{ width: Math.max(progressPct, 2) + '%' }" />
          </view>
          <text class="progress-text">当前 {{ globalQuals }} / {{ globalCap }} 份资格</text>

          <view v-if="state === 'success'" class="fx-result ok">
            <text class="fx-result-title">开奖成功</text>
            <text class="fx-result-amt">你的中奖金额：¥{{ winText }}</text>
            <text class="fx-result-sub">上下级关系永久保留</text>
          </view>
          <view v-else-if="state === 'expired'" class="fx-result fail">
            <text class="fx-result-title">活动已结束</text>
            <text class="fx-result-sub">未集齐 {{ globalCap }} 份资格，红宝池作废不予发放</text>
            <text class="fx-result-sub">上下级邀请关系永久保留</text>
          </view>

          <view class="stats-grid">
            <view class="stat-box">
              <view class="stat-dot tl" />
              <view class="stat-dot tr" />
              <view class="stat-curve" />
              <text class="stat-top">我的资格：{{ myQuals }} / {{ userCap }}</text>
              <text class="stat-bottom">{{ myQuals }}/{{ userCap }}</text>
            </view>
            <view class="stat-box" @click="shareLink">
              <view class="stat-dot tl" />
              <view class="stat-dot tr" />
              <view class="stat-curve" />
              <text class="stat-top">直属下级：{{ subCount }}人</text>
              <text class="stat-bottom">分享</text>
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

          <view v-if="state === 'running' && !joined" class="fx-join-wrap">
            <button
              type="button"
              class="fx-btn-join"
              :disabled="!canGain || joining"
              @click="onJoin"
            >
              {{ joining ? '参与中…' : '参与活动领取资格' }}
            </button>
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
  </view>
</template>

<script setup>
import { computed, ref, onUnmounted } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import BottomTabBar from '../../components/BottomTabBar.vue'
import { apiRequest, getToken } from '../../utils/auth.js'
import { applySafeAreaCssVars, getSafeAreaInsets } from '../../utils/safe-area.js'
import { copyText } from '../../utils/master.js'

const loading = ref(true)
const joining = ref(false)
const detail = ref(null)
const scrollH = ref('100vh')
const remainSec = ref(0)
let tickTimer = null

const hasActivity = computed(() => !!(detail.value && detail.value.has_activity))
const state = computed(() => (detail.value && detail.value.state) || 'none')
const act = computed(() => (detail.value && detail.value.activity) || {})
const me = computed(() => (detail.value && detail.value.me) || {})

const poolText = computed(() => formatMoney(act.value.pool_amount))
const globalQuals = computed(() => Number(act.value.global_quals || 0))
const globalCap = computed(() => Number(act.value.global_cap || 100))
const progressPct = computed(() => Math.min(100, Number(act.value.progress_pct || 0)))
const canGain = computed(() => !!act.value.can_gain)
const myQuals = computed(() => Number(me.value.qual_count || 0))
const userCap = computed(() => Number(me.value.user_cap || 5))
const subCount = computed(() => Number(me.value.subordinate_count || 0))
const joined = computed(() => !!me.value.joined)
const inviteLink = computed(() => String(me.value.invite_link || ''))
const winText = computed(() => formatMoney(me.value.win_amount))

const displayRules = computed(() => [
  '参与得1份资格，单人上限' + userCap.value + '份',
  '邀请有效新人，双方各得1份资格',
  '集齐' + globalCap.value + '份资格立即开奖',
  '72小时未集齐，红包池作废不予发放',
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
    const topBar = 48
    const tab = 56 + Number(inset.bottom || 0)
    const h = (sys.windowHeight || 667) - status - topBar - tab
    scrollH.value = Math.max(280, h) + 'px'
  } catch (e) {
    scrollH.value = '70vh'
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

async function loadDetail(autoJoin) {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  loading.value = true
  try {
    const data = await apiRequest('fissiondetail', 'GET', {})
    detail.value = data || null
    remainSec.value = Number((data && data.activity && data.activity.remain_sec) || 0)
    startTick()
    if (
      autoJoin &&
      data &&
      data.state === 'running' &&
      data.me &&
      !data.me.joined &&
      data.activity &&
      data.activity.can_gain
    ) {
      await onJoin(true)
    }
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '加载失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

async function onJoin(silent) {
  if (joining.value || joined.value || !canGain.value) return
  joining.value = true
  try {
    const data = await apiRequest('fissionjoin', 'POST', {})
    detail.value = data || detail.value
    remainSec.value = Number((data && data.activity && data.activity.remain_sec) || remainSec.value)
    if (!silent) uni.showToast({ title: '已获得1份资格', icon: 'none' })
  } catch (e) {
    if (!silent) uni.showToast({ title: (e && e.message) || '参与失败', icon: 'none' })
  } finally {
    joining.value = false
  }
}

function copyLink() {
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
  const link = inviteLink.value
  const text = '🧧全网裂变红宝，¥' + poolText.value + ' 奖金池，快来一起拆！\n' + (link || '')
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
  loadDetail(true)
})

onUnmounted(() => stopTick())
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
  align-items: baseline;
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
  margin-right: 12px;
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
</style>

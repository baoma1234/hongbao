<template>
  <view class="fx-page">
    <view class="fx-stars" aria-hidden="true" />
    <view class="fx-hd">
      <text class="fx-back" @click="goBack">‹</text>
      <text class="fx-title">裂变红宝</text>
      <text class="fx-more">···</text>
    </view>

    <scroll-view scroll-y class="fx-scroll" :style="{ height: scrollH }">
      <view v-if="loading" class="fx-loading">加载中…</view>
      <view v-else-if="!hasActivity" class="fx-empty">
        <text class="fx-empty-title">暂无裂变红宝活动</text>
        <text class="fx-empty-sub">活动开启后可在此参与</text>
      </view>
      <view v-else class="fx-body">
        <!-- 1. 奖金池主卡（设计稿独立上卡） -->
        <view class="fx-hero">
          <view v-if="state === 'running' && remainText" class="fx-countdown">{{ remainText }}</view>
          <view class="fx-pool-row">
            <text class="fx-yen">¥</text>
            <text class="fx-pool-num">{{ poolText }}</text>
            <text class="fx-pool-unit">奖金池</text>
          </view>
          <view class="fx-bar-wrap">
            <view class="fx-bar-fill" :style="{ width: Math.max(progressPct, 1.5) + '%' }" />
          </view>
          <text class="fx-bar-label">当前 {{ globalQuals }} / {{ globalCap }} 份资格</text>
        </view>

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

        <!-- 2. 红包双卡 -->
        <view class="fx-stats">
          <view class="fx-stat-card">
            <text class="fx-stat-top">我的资格: {{ myQuals }}/{{ userCap }}</text>
            <view class="fx-stat-flap" />
            <text class="fx-stat-bot">{{ myQuals }}/{{ userCap }}</text>
          </view>
          <view class="fx-stat-card" @click="shareLink">
            <text class="fx-stat-top">直属下级: {{ subCount }}人</text>
            <view class="fx-stat-flap" />
            <text class="fx-stat-bot">分享</text>
          </view>
        </view>

        <!-- 3. 邀请链接 -->
        <view v-if="state === 'running' || inviteLink" class="fx-invite">
          <text class="fx-section-title">邀请链接</text>
          <view class="fx-invite-row">
            <view class="fx-invite-box">
              <view class="fx-pin" />
              <text class="fx-invite-text">我的专属邀请链接</text>
            </view>
            <button type="button" class="fx-btn-gold" @click="copyLink">复制链接</button>
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

        <!-- 4. 活动规则：—— 活动规则 —— -->
        <view class="fx-rules">
          <view class="fx-rules-hd">
            <view class="fx-rules-line" />
            <text class="fx-rules-title">活动规则</text>
            <view class="fx-rules-line" />
          </view>
          <view v-for="(r, i) in displayRules" :key="i" class="fx-rule-line">
            {{ i + 1 }}. {{ r }}
          </view>
        </view>

        <!-- 5. 底部声明 -->
        <view class="fx-disclaimer">
          <text class="fx-check">☑</text>
          <text class="fx-disclaimer-text">红包不承诺必得，邀请有效新用户才可累计资格</text>
        </view>
      </view>
    </scroll-view>
  </view>
</template>

<script setup>
import { computed, ref, onUnmounted } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { apiRequest, getToken } from '../../utils/auth.js'
import { copyText } from '../../utils/master.js'
import { safeNavigateBack } from '../../utils/nav.js'

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

function goBack() {
  safeNavigateBack('/pages/profile/profile')
}

function measureScroll() {
  try {
    const sys = uni.getSystemInfoSync()
    const status = Number(sys.statusBarHeight || 20)
    const hd = status + 48
    const h = (sys.windowHeight || 667) - hd
    scrollH.value = Math.max(320, h) + 'px'
  } catch (e) {
    scrollH.value = '100vh'
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
  try {
    uni.setStorageSync('fission_popup_seen_session', '1')
  } catch (e) {}
})

onUnmounted(() => stopTick())
</script>

<style scoped>
.fx-page {
  position: relative;
  min-height: 100vh;
  background: #05070d;
  color: #e8eef8;
  box-sizing: border-box;
  overflow: hidden;
}
.fx-stars {
  pointer-events: none;
  position: absolute;
  inset: 0;
  z-index: 0;
  background:
    radial-gradient(1.5px 1.5px at 10% 16%, rgba(180, 210, 255, 0.5), transparent),
    radial-gradient(1.2px 1.2px at 26% 38%, rgba(160, 200, 255, 0.35), transparent),
    radial-gradient(1.8px 1.8px at 52% 10%, rgba(200, 220, 255, 0.45), transparent),
    radial-gradient(1px 1px at 70% 28%, rgba(150, 190, 255, 0.4), transparent),
    radial-gradient(1.4px 1.4px at 88% 14%, rgba(180, 210, 255, 0.38), transparent),
    radial-gradient(1.2px 1.2px at 92% 52%, rgba(160, 200, 255, 0.3), transparent),
    radial-gradient(1.6px 1.6px at 16% 72%, rgba(170, 200, 255, 0.3), transparent),
    radial-gradient(1px 1px at 48% 78%, rgba(190, 215, 255, 0.28), transparent),
    radial-gradient(ellipse at 50% -12%, rgba(30, 70, 150, 0.32) 0%, transparent 50%);
}
.fx-hd,
.fx-scroll {
  position: relative;
  z-index: 1;
}
.fx-hd {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-top: calc(env(safe-area-inset-top, 0px) + 12px);
  padding-bottom: 12px;
  padding-left: 6px;
  padding-right: 6px;
  min-height: 44px;
}
.fx-back {
  width: 44px;
  height: 44px;
  line-height: 42px;
  text-align: center;
  font-size: 32px;
  font-weight: 300;
  color: #fff;
  flex: 0 0 auto;
}
.fx-title {
  flex: 1;
  text-align: center;
  font-size: 17px;
  font-weight: 600;
  color: #fff;
  letter-spacing: 0.5px;
}
.fx-more {
  width: 44px;
  height: 44px;
  line-height: 36px;
  text-align: center;
  font-size: 22px;
  font-weight: 700;
  color: #fff;
  letter-spacing: 1px;
  flex: 0 0 auto;
}
.fx-loading,
.fx-empty {
  padding: 80px 24px;
  text-align: center;
  color: #9ab;
}
.fx-empty-title {
  display: block;
  font-size: 16px;
  color: #fff;
  margin-bottom: 8px;
}
.fx-body {
  padding: 4px 16px 40px;
}

/* —— 奖金池主卡 —— */
.fx-hero {
  position: relative;
  background: linear-gradient(165deg, #151d32 0%, #0d1424 100%);
  border: 1px solid rgba(140, 170, 220, 0.2);
  border-radius: 16px;
  padding: 28px 16px 20px;
  text-align: center;
  box-shadow: 0 10px 28px rgba(0, 0, 0, 0.35);
}
.fx-countdown {
  position: absolute;
  top: 12px;
  right: 12px;
  background: #e11d2e;
  color: #fff;
  font-size: 12px;
  font-weight: 800;
  padding: 5px 11px;
  border-radius: 8px;
  letter-spacing: 0.4px;
  line-height: 1.2;
}
.fx-pool-row {
  display: flex;
  align-items: baseline;
  justify-content: center;
  gap: 2px;
  margin: 8px 0 18px;
  padding-top: 6px;
}
.fx-yen {
  font-size: 26px;
  font-weight: 800;
  color: #f6c84a;
  text-shadow: 0 0 16px rgba(246, 200, 74, 0.3);
}
.fx-pool-num {
  font-size: 42px;
  font-weight: 900;
  color: #f6c84a;
  line-height: 1;
  letter-spacing: 0.5px;
  text-shadow: 0 0 16px rgba(246, 200, 74, 0.3);
}
.fx-pool-unit {
  font-size: 18px;
  font-weight: 800;
  color: #f6c84a;
  margin-left: 4px;
}
.fx-bar-wrap {
  height: 11px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.1);
  overflow: hidden;
  margin: 0 4px 12px;
}
.fx-bar-fill {
  height: 100%;
  border-radius: 999px;
  background: linear-gradient(90deg, #ff5a18 0%, #ff9a1a 50%, #f6c84a 100%);
  box-shadow: 0 0 8px rgba(255, 140, 40, 0.4);
  transition: width 0.35s ease;
  min-width: 6px;
}
.fx-bar-label {
  display: block;
  font-size: 13px;
  color: #e8eef8;
}

.fx-result {
  margin-top: 12px;
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
}
.fx-result-amt {
  display: block;
  font-size: 20px;
  color: #f6c84a;
  font-weight: 800;
  margin-bottom: 4px;
}
.fx-result-sub {
  display: block;
  font-size: 12px;
  color: #b8c4d6;
  line-height: 1.5;
}

/* —— 红包双卡 —— */
.fx-stats {
  display: flex;
  gap: 12px;
  margin-top: 14px;
}
.fx-stat-card {
  flex: 1;
  border-radius: 14px;
  overflow: hidden;
  background: linear-gradient(165deg, #ff7a2e 0%, #e82020 48%, #c41010 100%);
  box-shadow: 0 6px 16px rgba(160, 30, 20, 0.35);
  min-height: 96px;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 0;
}
.fx-stat-top {
  display: block;
  width: 100%;
  padding: 11px 8px 8px;
  font-size: 12px;
  font-weight: 700;
  color: #fff;
  text-align: center;
}
.fx-stat-flap {
  width: 100%;
  height: 14px;
  margin: 0;
  background:
    linear-gradient(135deg, transparent 49.5%, rgba(0, 0, 0, 0.18) 50%),
    linear-gradient(225deg, transparent 49.5%, rgba(0, 0, 0, 0.18) 50%);
  background-size: 50% 100%, 50% 100%;
  background-position: left top, right top;
  background-repeat: no-repeat;
  opacity: 0.9;
  position: relative;
}
.fx-stat-flap::before {
  content: '';
  position: absolute;
  left: 0;
  right: 0;
  top: 0;
  height: 1px;
  background: rgba(255, 255, 255, 0.28);
}
.fx-stat-bot {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  font-size: 24px;
  font-weight: 800;
  color: #fff;
  padding: 10px 8px 14px;
  letter-spacing: 0.5px;
}

/* —— 邀请链接 —— */
.fx-invite {
  margin-top: 18px;
}
.fx-section-title {
  display: block;
  font-size: 14px;
  font-weight: 700;
  color: #fff;
  margin-bottom: 10px;
}
.fx-invite-row {
  display: flex;
  gap: 8px;
  align-items: center;
}
.fx-invite-box {
  flex: 1;
  background: rgba(0, 0, 0, 0.45);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 999px;
  padding: 0 14px 0 12px;
  min-height: 44px;
  display: flex;
  align-items: center;
  gap: 8px;
  overflow: hidden;
}
.fx-pin {
  flex: 0 0 auto;
  width: 13px;
  height: 13px;
  border-radius: 50% 50% 50% 0;
  background: #cfd8e6;
  transform: rotate(-45deg);
  box-shadow: inset -3px -3px 0 0 #0a0f1a;
}
.fx-invite-text {
  flex: 1;
  font-size: 13px;
  color: #d7dfeb;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}
.fx-btn-gold {
  background: linear-gradient(180deg, #f8d76e 0%, #e8b428 100%);
  color: #2a1e00;
  font-weight: 800;
  font-size: 13px;
  border: none;
  border-radius: 999px;
  padding: 0 14px;
  line-height: 44px;
  height: 44px;
  white-space: nowrap;
  flex: 0 0 auto;
  box-shadow: 0 4px 10px rgba(232, 180, 40, 0.25);
}
.fx-join-wrap {
  margin-top: 14px;
}
.fx-btn-join {
  width: 100%;
  background: linear-gradient(180deg, #ffe08a, #f0b429);
  color: #3a2800;
  font-size: 15px;
  font-weight: 800;
  border: none;
  border-radius: 24px;
  line-height: 46px;
}

/* —— 活动规则 —— */
.fx-rules {
  margin-top: 20px;
  padding: 16px 14px 14px;
  border-radius: 14px;
  border: 1px solid rgba(255, 255, 255, 0.12);
  background: rgba(18, 24, 40, 0.72);
}
.fx-rules-hd {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  margin-bottom: 12px;
}
.fx-rules-line {
  width: 36px;
  height: 1px;
  background: linear-gradient(90deg, transparent, #f6c84a, transparent);
  opacity: 0.85;
}
.fx-rules-title {
  font-size: 14px;
  font-weight: 700;
  color: #fff;
  white-space: nowrap;
}
.fx-rule-line {
  display: block;
  font-size: 12px;
  color: #d0d8e6;
  line-height: 1.8;
  margin-bottom: 2px;
}

.fx-disclaimer {
  margin-top: 18px;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  gap: 6px;
  padding: 0 4px 28px;
}
.fx-check {
  color: #f6c84a;
  font-size: 13px;
  line-height: 1.45;
  flex: 0 0 auto;
}
.fx-disclaimer-text {
  font-size: 11px;
  color: #c4a86a;
  line-height: 1.5;
}
</style>

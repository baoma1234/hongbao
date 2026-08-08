<template>
  <view class="fx-page">
    <view class="fx-hd">
      <text class="fx-back" @click="goBack">‹</text>
      <text class="fx-title">裂变红宝</text>
      <text class="fx-hd-spacer" />
    </view>

    <scroll-view scroll-y class="fx-scroll" :style="{ height: scrollH }">
      <view v-if="loading" class="fx-loading">加载中…</view>
      <view v-else-if="!hasActivity" class="fx-empty">
        <text class="fx-empty-title">暂无裂变红宝活动</text>
        <text class="fx-empty-sub">活动开启后可在此参与</text>
      </view>
      <view v-else class="fx-body">
        <!-- 主奖金卡 -->
        <view class="fx-hero">
          <view v-if="state === 'running' && remainText" class="fx-countdown">{{ remainText }}</view>
          <view class="fx-pool-row">
            <text class="fx-yen">¥</text>
            <text class="fx-pool-num">{{ poolText }}</text>
            <text class="fx-pool-unit">奖金池</text>
          </view>
          <view class="fx-bar-wrap">
            <view class="fx-bar-fill" :style="{ width: progressPct + '%' }" />
          </view>
          <text class="fx-bar-label">当前 {{ globalQuals }} / {{ globalCap }} 份资格</text>
        </view>

        <!-- 成功 / 失败 -->
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

        <!-- 红包样式双卡 -->
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

        <!-- 邀请链接 -->
        <view v-if="state === 'running' || inviteLink" class="fx-invite">
          <text class="fx-section-title">邀请链接</text>
          <view class="fx-invite-row">
            <view class="fx-invite-box">
              <text class="fx-pin">📍</text>
              <text class="fx-invite-text">{{ inviteLink || '我的专属邀请链接' }}</text>
            </view>
            <button type="button" class="fx-btn-gold" @click="copyLink">复制链接</button>
          </view>
        </view>

        <!-- 未参与时补一次领取 -->
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

        <!-- 活动规则 -->
        <view class="fx-rules">
          <text class="fx-rules-title">活动规则</text>
          <view v-for="(r, i) in displayRules" :key="i" class="fx-rule-line">{{ i + 1 }}. {{ r }}</view>
        </view>

        <view class="fx-disclaimer">
          <text class="fx-check">☑</text>
          <text>红包不承诺必得，邀请有效新用户才可累计资格</text>
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
  min-height: 100vh;
  background:
    radial-gradient(ellipse at 50% -10%, rgba(40, 90, 180, 0.45) 0%, transparent 55%),
    radial-gradient(ellipse at 80% 20%, rgba(20, 50, 120, 0.35) 0%, transparent 40%),
    linear-gradient(180deg, #0a1630 0%, #070b14 45%, #05080f 100%);
  color: #e8eef8;
  box-sizing: border-box;
}
.fx-hd {
  display: flex;
  align-items: center;
  padding-top: calc(env(safe-area-inset-top, 0px) + 10px);
  padding-bottom: 10px;
  padding-left: 8px;
  padding-right: 8px;
  position: relative;
  z-index: 5;
}
.fx-back {
  width: 44px;
  height: 44px;
  line-height: 40px;
  text-align: center;
  font-size: 34px;
  font-weight: 300;
  color: #fff;
  flex: 0 0 auto;
}
.fx-title {
  flex: 1;
  text-align: center;
  font-size: 17px;
  font-weight: 700;
  color: #fff;
}
.fx-hd-spacer {
  width: 44px;
  flex: 0 0 auto;
}
.fx-scroll {
  box-sizing: border-box;
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
  padding: 8px 16px 36px;
}
.fx-hero {
  position: relative;
  background: rgba(12, 18, 36, 0.72);
  border: 1px solid rgba(255, 196, 67, 0.28);
  border-radius: 18px;
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
  padding: 4px 10px;
  border-radius: 8px;
  letter-spacing: 0.5px;
}
.fx-pool-row {
  display: flex;
  align-items: baseline;
  justify-content: center;
  gap: 4px;
  margin-bottom: 16px;
  padding-top: 8px;
}
.fx-yen {
  font-size: 22px;
  font-weight: 800;
  color: #f5c443;
}
.fx-pool-num {
  font-size: 40px;
  font-weight: 900;
  color: #f5c443;
  line-height: 1;
}
.fx-pool-unit {
  font-size: 18px;
  font-weight: 800;
  color: #f5c443;
  margin-left: 2px;
}
.fx-bar-wrap {
  height: 10px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.12);
  overflow: hidden;
  margin: 0 6px 10px;
}
.fx-bar-fill {
  height: 100%;
  border-radius: 999px;
  background: linear-gradient(90deg, #ff7a18, #f5c443);
  transition: width 0.35s ease;
}
.fx-bar-label {
  font-size: 13px;
  color: #d7dfeb;
}
.fx-result {
  margin-top: 14px;
  border-radius: 12px;
  padding: 14px;
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
  font-size: 16px;
  font-weight: 800;
  margin-bottom: 6px;
}
.fx-result-amt {
  display: block;
  font-size: 22px;
  color: #f5c443;
  font-weight: 800;
  margin-bottom: 6px;
}
.fx-result-sub {
  display: block;
  font-size: 12px;
  color: #b8c4d6;
  line-height: 1.5;
}
.fx-stats {
  display: flex;
  gap: 12px;
  margin-top: 14px;
}
.fx-stat-card {
  flex: 1;
  border-radius: 14px;
  overflow: hidden;
  background: linear-gradient(165deg, #ff6a2a 0%, #e11d2e 55%, #b71c1c 100%);
  box-shadow: 0 6px 16px rgba(180, 30, 30, 0.35);
  min-height: 92px;
  display: flex;
  flex-direction: column;
}
.fx-stat-top {
  display: block;
  padding: 10px 10px 8px;
  font-size: 12px;
  font-weight: 700;
  color: rgba(255, 255, 255, 0.95);
  text-align: center;
}
.fx-stat-flap {
  height: 1px;
  background: rgba(255, 255, 255, 0.28);
  box-shadow: 0 1px 0 rgba(0, 0, 0, 0.12);
}
.fx-stat-bot {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
  font-weight: 800;
  color: #fff;
  padding: 12px 8px;
}
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
  align-items: stretch;
}
.fx-invite-box {
  flex: 1;
  background: rgba(0, 0, 0, 0.4);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 999px;
  padding: 0 14px;
  min-height: 44px;
  display: flex;
  align-items: center;
  gap: 6px;
  overflow: hidden;
}
.fx-pin {
  flex: 0 0 auto;
  font-size: 14px;
}
.fx-invite-text {
  flex: 1;
  font-size: 12px;
  color: #aeb8c8;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}
.fx-btn-gold {
  background: linear-gradient(180deg, #f7d56a, #e0a820);
  color: #3a2800;
  font-weight: 800;
  font-size: 13px;
  border: none;
  border-radius: 999px;
  padding: 0 16px;
  line-height: 44px;
  white-space: nowrap;
  flex: 0 0 auto;
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
.fx-rules {
  position: relative;
  margin-top: 22px;
  padding: 22px 14px 14px;
  border-radius: 14px;
  border: 1px solid rgba(255, 255, 255, 0.22);
  background: rgba(255, 255, 255, 0.03);
}
.fx-rules-title {
  position: absolute;
  top: -10px;
  left: 50%;
  transform: translateX(-50%);
  padding: 0 12px;
  font-size: 14px;
  font-weight: 700;
  color: #fff;
  background: #0a1630;
  white-space: nowrap;
}
.fx-rule-line {
  display: block;
  font-size: 12px;
  color: #d0d8e6;
  line-height: 1.75;
  margin-bottom: 2px;
}
.fx-disclaimer {
  margin-top: 18px;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  gap: 6px;
  font-size: 11px;
  color: #8a95a8;
  line-height: 1.5;
  padding: 0 8px 24px;
}
.fx-check {
  color: #f5c443;
  font-size: 13px;
  line-height: 1.4;
}
</style>

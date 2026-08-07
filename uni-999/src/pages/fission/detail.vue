<template>
  <view class="fx-page">
    <TopBar :no-spacer="true" />
    <view class="fx-hd profile-sub-hd">
      <text class="profile-back-btn" @click="goBack">‹</text>
      <text class="profile-sub-title">{{ pageTitle }}</text>
      <text class="profile-sub-spacer" />
    </view>

    <scroll-view scroll-y class="fx-scroll" :style="{ height: scrollH }">
      <view v-if="loading" class="fx-loading">加载中…</view>
      <view v-else-if="!hasActivity" class="fx-empty">
        <text class="fx-empty-title">暂无裂变红包活动</text>
        <text class="fx-empty-sub">活动开启后可在此参与</text>
      </view>
      <view v-else class="fx-body">
        <view v-if="state === 'running' && remainText" class="fx-countdown">剩余 {{ remainText }}</view>

        <!-- 主奖金卡 -->
        <view class="fx-hero">
          <text class="fx-hero-brand">🧧 全网裂变红包</text>
          <text class="fx-hero-pool">¥{{ poolText }} 奖金池</text>
          <view class="fx-bar-wrap">
            <view class="fx-bar-fill" :style="{ width: progressPct + '%' }" />
          </view>
          <text class="fx-bar-label">当前 {{ globalQuals }} / {{ globalCap }} 份资格</text>
        </view>

        <!-- 成功 / 失败状态 -->
        <view v-if="state === 'success'" class="fx-result ok">
          <text class="fx-result-title">开奖成功</text>
          <text class="fx-result-amt">你的中奖金额：¥{{ winText }}</text>
          <text class="fx-result-sub">入口已置灰，上下级关系永久保留</text>
        </view>
        <view v-else-if="state === 'expired'" class="fx-result fail">
          <text class="fx-result-title">活动已结束</text>
          <text class="fx-result-sub">未集齐 {{ globalCap }} 份资格，红包池作废不予发放</text>
          <text class="fx-result-sub">上下级邀请关系永久保留</text>
        </view>

        <!-- 个人统计 -->
        <view class="fx-stats">
          <view class="fx-stat-card">
            <text class="fx-stat-label">我的资格</text>
            <text class="fx-stat-val">{{ myQuals }} / {{ userCap }}</text>
          </view>
          <view class="fx-stat-card">
            <text class="fx-stat-label">直属下级</text>
            <text class="fx-stat-val">{{ subCount }} 人</text>
          </view>
        </view>

        <!-- 邀请链接 -->
        <view v-if="state === 'running'" class="fx-invite">
          <text class="fx-section-title">邀请链接</text>
          <view class="fx-invite-row">
            <view class="fx-invite-box">
              <text class="fx-invite-text">{{ inviteLink || '我的专属邀请链接' }}</text>
            </view>
            <button type="button" class="fx-btn-gold" @click="copyLink">复制链接</button>
          </view>
          <button type="button" class="fx-btn-share" @click="shareLink">分享</button>
        </view>

        <!-- 参与按钮 -->
        <view v-if="state === 'running'" class="fx-join-wrap">
          <button
            type="button"
            class="fx-btn-join"
            :class="{ disabled: joined || !canGain || joining }"
            :disabled="joined || !canGain || joining"
            @click="onJoin"
          >
            {{ joinBtnText }}
          </button>
        </view>

        <!-- 风险提示 -->
        <view class="fx-risk">
          <text class="fx-risk-text">⚠️ 72小时未集齐{{ globalCap }}份资格，红包池作废不予发放，上下级关系永久保留</text>
        </view>

        <!-- 规则 -->
        <view class="fx-rules">
          <text class="fx-section-title">活动规则</text>
          <view v-for="(r, i) in rules" :key="i" class="fx-rule-line">{{ i + 1 }}、{{ r }}</view>
        </view>

        <view class="fx-disclaimer">
          <text>红包不承诺必得，邀请有效新用户才可累计资格</text>
        </view>
      </view>
    </scroll-view>
  </view>
</template>

<script setup>
import { computed, ref, onUnmounted } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import { apiRequest, getToken } from '../../utils/auth.js'
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
const rules = computed(() => (detail.value && detail.value.rules) || [])

const pageTitle = computed(() => act.value.title || '裂变红包详情')
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

const joinBtnText = computed(() => {
  if (joined.value) return '已参与 · 去邀请好友'
  if (!canGain.value) return '资格已满 · 等待开奖'
  return '参与活动领取资格'
})

const remainText = computed(() => {
  const s = Math.max(0, remainSec.value | 0)
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  const sec = s % 60
  const pad = (n) => (n < 10 ? '0' + n : '' + n)
  if (h >= 24) {
    const d = Math.floor(h / 24)
    const hh = h % 24
    return d + '天' + pad(hh) + ':' + pad(m) + ':' + pad(sec)
  }
  return pad(h) + ':' + pad(m) + ':' + pad(sec)
})

function formatMoney(v) {
  const n = Number(v)
  if (!isFinite(n)) return '0.00'
  return n.toFixed(2).replace(/\.00$/, '')
}

function goBack() {
  const pages = getCurrentPages()
  if (pages && pages.length > 1) {
    uni.navigateBack()
  } else {
    uni.switchTab({ url: '/pages/profile/profile' })
  }
}

function measureScroll() {
  try {
    const sys = uni.getSystemInfoSync()
    const h = (sys.windowHeight || 667) - 52
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
    if (autoJoin && data && data.state === 'running' && data.me && !data.me.joined && data.activity && data.activity.can_gain) {
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
    if (!silent) {
      uni.showToast({ title: '已获得1份资格', icon: 'none' })
    }
  } catch (e) {
    if (!silent) {
      uni.showToast({ title: (e && e.message) || '参与失败', icon: 'none' })
    }
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
  copyText(link).then(() => {
    uni.showToast({ title: '链接已复制', icon: 'none' })
  }).catch(() => {
    uni.setClipboardData({
      data: link,
      success: () => uni.showToast({ title: '链接已复制', icon: 'none' }),
    })
  })
}

async function shareLink() {
  const link = inviteLink.value
  const text = '🧧全网裂变红包，¥' + poolText.value + ' 奖金池，快来一起拆！\n' + (link || '')
  // #ifdef H5
  try {
    if (typeof navigator !== 'undefined' && navigator.share) {
      await navigator.share({ title: '裂变红包', text })
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
  background: radial-gradient(ellipse at 50% 0%, #1a3a6e 0%, #0b1224 45%, #070b14 100%);
  color: #e8eef8;
}
.fx-hd.profile-sub-hd {
  display: flex;
  align-items: center;
  padding: 8px 12px;
  position: relative;
  z-index: 2;
}
.profile-back-btn {
  width: 36px;
  font-size: 28px;
  line-height: 36px;
  color: #fff;
}
.profile-sub-title {
  flex: 1;
  text-align: center;
  font-size: 16px;
  font-weight: 700;
  color: #fff;
}
.profile-sub-spacer { width: 36px; }
.fx-scroll { box-sizing: border-box; }
.fx-loading, .fx-empty {
  padding: 80px 24px;
  text-align: center;
  color: #9ab;
}
.fx-empty-title { display: block; font-size: 16px; color: #fff; margin-bottom: 8px; }
.fx-body { padding: 12px 16px 40px; }
.fx-countdown {
  align-self: flex-end;
  margin-left: auto;
  display: inline-block;
  background: #e11d2e;
  color: #fff;
  font-size: 12px;
  font-weight: 700;
  padding: 4px 10px;
  border-radius: 999px;
  margin-bottom: 10px;
}
.fx-hero {
  background: linear-gradient(160deg, rgba(255, 90, 40, 0.25), rgba(20, 28, 48, 0.9));
  border: 1px solid rgba(255, 196, 67, 0.35);
  border-radius: 16px;
  padding: 20px 16px 18px;
  text-align: center;
  box-shadow: 0 8px 28px rgba(0, 0, 0, 0.35);
}
.fx-hero-brand {
  display: block;
  font-size: 14px;
  color: #ffd27a;
  margin-bottom: 8px;
}
.fx-hero-pool {
  display: block;
  font-size: 32px;
  font-weight: 800;
  color: #f5c443;
  letter-spacing: 0.5px;
  margin-bottom: 14px;
}
.fx-bar-wrap {
  height: 10px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.12);
  overflow: hidden;
  margin: 0 8px 8px;
}
.fx-bar-fill {
  height: 100%;
  border-radius: 999px;
  background: linear-gradient(90deg, #ff7a18, #f5c443);
  transition: width 0.35s ease;
}
.fx-bar-label {
  font-size: 12px;
  color: #c5d0e0;
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
  gap: 10px;
  margin-top: 14px;
}
.fx-stat-card {
  flex: 1;
  background: linear-gradient(135deg, #ff6a2a, #e11d2e);
  border-radius: 12px;
  padding: 14px 12px;
  text-align: center;
}
.fx-stat-label {
  display: block;
  font-size: 12px;
  color: rgba(255, 255, 255, 0.85);
  margin-bottom: 6px;
}
.fx-stat-val {
  display: block;
  font-size: 20px;
  font-weight: 800;
  color: #fff;
}
.fx-invite { margin-top: 18px; }
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
  background: rgba(0, 0, 0, 0.35);
  border: 1px solid rgba(255, 255, 255, 0.12);
  border-radius: 10px;
  padding: 10px 12px;
  min-height: 42px;
  display: flex;
  align-items: center;
}
.fx-invite-text {
  font-size: 12px;
  color: #aeb8c8;
  word-break: break-all;
  line-height: 1.4;
}
.fx-btn-gold {
  background: linear-gradient(180deg, #f7d56a, #e0a820);
  color: #3a2800;
  font-weight: 800;
  font-size: 13px;
  border: none;
  border-radius: 10px;
  padding: 0 14px;
  line-height: 42px;
  white-space: nowrap;
}
.fx-btn-share {
  margin-top: 10px;
  width: 100%;
  background: rgba(255, 255, 255, 0.08);
  color: #f5c443;
  border: 1px solid rgba(245, 196, 67, 0.4);
  border-radius: 10px;
  font-size: 14px;
  font-weight: 700;
  line-height: 42px;
}
.fx-join-wrap { margin-top: 16px; }
.fx-btn-join {
  width: 100%;
  background: linear-gradient(180deg, #ffe08a, #f0b429);
  color: #3a2800;
  font-size: 16px;
  font-weight: 800;
  border: none;
  border-radius: 24px;
  line-height: 48px;
}
.fx-btn-join.disabled {
  opacity: 0.55;
  filter: grayscale(0.3);
}
.fx-risk {
  margin-top: 18px;
  padding: 12px;
  border-radius: 10px;
  background: rgba(225, 29, 46, 0.15);
  border: 1px solid rgba(255, 80, 80, 0.45);
}
.fx-risk-text {
  font-size: 13px;
  font-weight: 700;
  color: #ff6b6b;
  line-height: 1.55;
}
.fx-rules {
  margin-top: 18px;
  padding: 14px;
  border-radius: 12px;
  border: 1px solid rgba(255, 255, 255, 0.12);
  background: rgba(255, 255, 255, 0.04);
}
.fx-rule-line {
  display: block;
  font-size: 12px;
  color: #c5d0e0;
  line-height: 1.7;
  margin-bottom: 4px;
}
.fx-disclaimer {
  margin-top: 16px;
  text-align: center;
  font-size: 11px;
  color: #7a8799;
  padding-bottom: 20px;
}
</style>

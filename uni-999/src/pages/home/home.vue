<template>
  <view class="page" :key="locale">
    <TopBar />
    <view class="inner">
      <!-- 大屏奖池 -->
      <view class="jackpot card">
        <text class="jp-lab">{{ t('jackpot_pool_label') || '平台累计创造价值' }}</text>
        <text class="jp-num">￥{{ jackpotText }}</text>
        <text class="jp-meta">{{ partnersText }}</text>
        <text class="jp-price">{{ priceText }}</text>
      </view>

      <!-- 资产摘要 -->
      <view class="assets card" v-if="profile">
        <view class="asset">
          <text class="a-lab">{{ t('asset_hongbao_label') || '红宝' }}</text>
          <text class="a-val">{{ hongbaoText }}</text>
        </view>
        <view class="divider" />
        <view class="asset">
          <text class="a-lab">{{ t('asset_shares_label') || '股份' }}</text>
          <text class="a-val">{{ rightsText }}</text>
        </view>
      </view>
      <view class="card muted" v-else>{{ t('loading_generic') }}</view>

      <!-- 快捷入口 -->
      <view class="quick">
        <view class="q-item" @click="goTab('/pages/exchange/exchange')">
          <text class="q-ico">⚡</text>
          <text class="q-t">{{ t('home_quick_exchange') || '去闪兑' }}</text>
          <text class="q-s">{{ t('home_quick_exchange_sub') || '股份秒变红宝' }}</text>
        </view>
        <view class="q-item" @click="goTab('/pages/messages/messages')">
          <text class="q-ico">💬</text>
          <text class="q-t">{{ t('home_quick_messages') || '红宝社区' }}</text>
          <text class="q-s">{{ t('home_quick_messages_sub') || '私聊 · 群聊 · 红包' }}</text>
        </view>
        <view class="q-item" @click="goTab('/pages/master/master')">
          <text class="q-ico">👑</text>
          <text class="q-t">{{ t('home_quick_master') || '团长大厅' }}</text>
          <text class="q-s">{{ t('home_quick_master_sub') || '天梯 + 星火暴击' }}</text>
        </view>
        <view class="q-item" @click="goTab('/pages/profile/profile')">
          <text class="q-ico">👤</text>
          <text class="q-t">{{ t('home_quick_profile') || '个人中心' }}</text>
          <text class="q-s">{{ t('home_quick_profile_sub') || '资料 · 钱包 · 退出' }}</text>
        </view>
      </view>

      <view class="foot">{{ t('footer_line1') || '红宝官方活跃粉丝福利大厅' }}</view>
    </view>
  </view>
</template>

<script setup>
import { computed, onUnmounted, ref } from 'vue'
import { onShow, onHide } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import { apiRequest, fetchProfile, getToken } from '../../utils/auth.js'
import { localeState, t } from '../../utils/i18n.js'
import { imConnect } from '../../utils/im.js'

const locale = localeState()
const profile = ref(null)
const jackpot = ref(null)
const config = ref(null)
let pollTimer = null

const hongbaoText = computed(() => {
  const p = profile.value || {}
  const n = p.hongbao != null ? p.hongbao : p.account?.hongbao
  return n != null ? Number(n).toFixed(2) : '-'
})
const rightsText = computed(() => {
  const p = profile.value || {}
  const n = p.rights != null ? p.rights : p.account?.rights
  return n != null ? Number(n).toFixed(2) : '-'
})
const jackpotText = computed(() => {
  const j = jackpot.value || {}
  const n = j.amount != null ? j.amount : j.jackpot
  return n != null ? Number(n).toFixed(2) : '0.00'
})
const partnersText = computed(() => {
  const j = jackpot.value || config.value || {}
  const count = j.partner_count != null ? j.partner_count : j.partners
  const up = j.partner_today_up != null ? j.partner_today_up : 0
  if (count == null) return ''
  return t('jackpot_partners', {
    partner_count: count,
    partner_today_up: up,
  })
})
const priceText = computed(() => {
  const j = jackpot.value || config.value || {}
  const price = j.current_share_price != null ? j.current_share_price : j.share_price
  const pct = j.price_up_pct != null ? j.price_up_pct : 0
  if (price == null) return ''
  return t('jackpot_price_line', {
    current_share_price: Number(price).toFixed(2),
    price_up_pct: pct,
  })
})

function goTab(url) {
  uni.switchTab({ url })
}

async function loadBootstrap() {
  try {
    const data = await apiRequest('bootstrap', 'GET', { include: 'home' })
    if (data) {
      if (data.profile) profile.value = data.profile
      if (data.config) config.value = data.config
      // market === jackpotPayload
      if (data.market) jackpot.value = data.market
      else if (data.jackpot) jackpot.value = data.jackpot
    }
  } catch (e) {
    // fallback profile only
  }
  if (!profile.value) {
    try {
      profile.value = await fetchProfile()
    } catch (e2) {
      uni.showToast({ title: e2.message || '资料失败', icon: 'none' })
    }
  }
  if (!jackpot.value) {
    await pollJackpot()
  }
}

async function pollJackpot() {
  try {
    const data = await apiRequest('jackpot', 'GET')
    if (data) jackpot.value = data
  } catch (e) {}
}

function startPoll() {
  stopPoll()
  pollTimer = setInterval(pollJackpot, 20000)
}

function stopPoll() {
  if (pollTimer) {
    clearInterval(pollTimer)
    pollTimer = null
  }
}

onShow(async () => {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  await loadBootstrap()
  imConnect().catch(() => {})
  startPoll()
})

onHide(() => stopPoll())
onUnmounted(() => stopPoll())
</script>

<style scoped>
.page {
  min-height: 100vh;
  background: var(--bg-main, #f6f1ea);
}
.inner { padding: 16rpx 24rpx 48rpx; }
.card {
  background: var(--bg-card, #fff);
  border-radius: 20rpx;
  padding: 28rpx 24rpx;
  margin-bottom: 20rpx;
  box-shadow: 0 6rpx 18rpx rgba(40, 20, 10, 0.05);
}
.card.muted {
  color: var(--text-muted, #9a8574);
  text-align: center;
}
.jackpot {
  background: linear-gradient(145deg, #1a212d 0%, #2d3748 55%, #1a365d 100%);
  color: #fff;
}
.jp-lab {
  display: block;
  font-size: 22rpx;
  opacity: 0.85;
  font-weight: 700;
}
.jp-num {
  display: block;
  margin-top: 12rpx;
  font-size: 52rpx;
  font-weight: 900;
  color: #ffd700;
  letter-spacing: 1rpx;
}
.jp-meta, .jp-price {
  display: block;
  margin-top: 10rpx;
  font-size: 22rpx;
  opacity: 0.88;
  line-height: 1.4;
}
.assets {
  display: flex;
  align-items: stretch;
}
.asset {
  flex: 1;
  text-align: center;
  padding: 8rpx 0;
}
.divider {
  width: 1px;
  background: rgba(40, 20, 10, 0.08);
  margin: 0 8rpx;
}
.a-lab {
  display: block;
  font-size: 22rpx;
  color: var(--text-muted, #9a8574);
  font-weight: 700;
}
.a-val {
  display: block;
  margin-top: 8rpx;
  font-size: 36rpx;
  font-weight: 800;
  color: var(--text-main, #1f1714);
}
.quick {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16rpx;
}
.q-item {
  background: var(--bg-card, #fff);
  border-radius: 18rpx;
  padding: 24rpx 20rpx;
  box-shadow: 0 4rpx 14rpx rgba(40, 20, 10, 0.04);
  border: 1.5px solid transparent;
  background-image:
    linear-gradient(var(--bg-card, #fff), var(--bg-card, #fff)),
    linear-gradient(145deg, #ffe9b0, #f0b04a, #e07a22);
  background-origin: border-box;
  background-clip: padding-box, border-box;
}
.q-ico { display: block; font-size: 36rpx; }
.q-t {
  display: block;
  margin-top: 10rpx;
  font-size: 28rpx;
  font-weight: 800;
  color: var(--text-main, #1f1714);
}
.q-s {
  display: block;
  margin-top: 6rpx;
  font-size: 22rpx;
  color: var(--text-muted, #9a8574);
}
.foot {
  margin-top: 28rpx;
  font-size: 20rpx;
  color: var(--text-muted, #9a8574);
  line-height: 1.5;
  text-align: center;
  opacity: 0.9;
}
</style>

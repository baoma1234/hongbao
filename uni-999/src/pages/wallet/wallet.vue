<template>
  <view class="page">
    <view class="hero">
      <text class="label">红宝余额</text>
      <text class="bal">{{ balanceText }}</text>
      <text class="sub">累计流水 {{ turnoverText }}</text>
      <text class="hint" v-if="turnHint">{{ turnHint }}</text>
    </view>

    <view class="grid">
      <view class="cell" @click="go('recharge')">
        <text class="t">充值</text>
        <text class="d">选通道入账</text>
      </view>
      <view class="cell" @click="go('withdraw')">
        <text class="t">提现</text>
        <text class="d">通道 / 线上合作</text>
      </view>
      <view class="cell" @click="go('ledger')">
        <text class="t">流水</text>
        <text class="d">资金明细</text>
      </view>
      <view class="cell" @click="go('payee')">
        <text class="t">收款地址</text>
        <text class="d">绑定钱包</text>
      </view>
    </view>

    <view class="tip" v-if="loading">加载中…</view>
    <view class="tip err" v-else-if="error">{{ error }}</view>
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { getToken } from '../../utils/auth.js'
import { loadWalletBootstrap, money, turnoverHint } from '../../utils/wallet.js'

const info = ref(null)
const loading = ref(false)
const error = ref('')

const balanceText = computed(() => {
  const i = info.value || {}
  const n = i.hongbao != null ? i.hongbao : i.balance
  return n != null ? money(n) : '—'
})
const turnoverText = computed(() => money((info.value && info.value.turnover) || 0))
const turnHint = computed(() => turnoverHint(info.value))

async function refresh(force = false) {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  loading.value = true
  error.value = ''
  try {
    const bundle = await loadWalletBootstrap(force)
    info.value = (bundle && bundle.info) || {}
  } catch (e) {
    error.value = (e && e.message) || '加载失败'
  } finally {
    loading.value = false
  }
}

function go(which) {
  uni.navigateTo({ url: '/pages/wallet/' + which })
}

onShow(() => {
  refresh(false)
})
</script>

<style scoped>
.page {
  padding: 32rpx;
  min-height: 100vh;
  background: linear-gradient(180deg, #f8ebe0 0%, #f6f1ea 40%);
}
.hero {
  background: linear-gradient(135deg, #c61114 0%, #9a0e10 100%);
  border-radius: 28rpx;
  padding: 40rpx 36rpx;
  color: #fff;
  margin-bottom: 28rpx;
}
.label {
  font-size: 24rpx;
  opacity: 0.85;
}
.bal {
  display: block;
  font-size: 64rpx;
  font-weight: 800;
  letter-spacing: 1rpx;
  margin: 8rpx 0 12rpx;
}
.sub {
  display: block;
  font-size: 24rpx;
  opacity: 0.9;
}
.hint {
  display: block;
  margin-top: 16rpx;
  font-size: 22rpx;
  opacity: 0.75;
  line-height: 1.4;
}
.grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20rpx;
}
.cell {
  background: #fff;
  border-radius: 20rpx;
  padding: 32rpx 28rpx;
}
.t {
  display: block;
  font-size: 32rpx;
  font-weight: 700;
  color: #2a1f18;
}
.d {
  display: block;
  margin-top: 8rpx;
  font-size: 22rpx;
  color: #9a8574;
}
.tip {
  margin-top: 28rpx;
  text-align: center;
  color: #9a8574;
  font-size: 24rpx;
}
.tip.err {
  color: #c61114;
}
</style>

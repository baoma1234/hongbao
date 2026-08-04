<template>
  <view class="hb-page">
    <view class="hero">
      <text class="title">VIP 闪兑大厅</text>
      <text class="sub">股份 ↔ 红宝 · 实时预估到账</text>
    </view>
    <view class="match-card">
      <view class="profile-meta-line">闪兑能力将从 `/888` 迁入。当前可先用「我的 → 钱包」管理充提。</view>
      <button class="btn-uid-submit" @click="goWallet">打开钱包</button>
    </view>
  </view>
</template>

<script setup>
import { onShow } from '@dcloudio/uni-app'
import { getToken } from '../../utils/auth.js'

onShow(() => {
  if (!getToken()) uni.reLaunch({ url: '/pages/login/login' })
})

function goWallet() {
  uni.navigateTo({ url: '/pages/wallet/wallet' })
}
</script>

<style scoped>
.hero { margin-bottom: 14px; }
.title { display: block; font-size: 22px; font-weight: 800; color: #1f1714; }
.sub { display: block; margin-top: 6px; font-size: 13px; color: #8a7a6e; }
</style>

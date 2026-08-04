<template>
  <view class="hb-page">
    <view class="hero">
      <text class="title">团长专属二期大厅</text>
      <text class="sub">荣誉天梯 · 星火暴击 · 战队催活</text>
    </view>
    <view class="match-card">
      <view class="profile-meta-line">团长模块将从 `/888` 迁入，敬请期待。</view>
      <button class="btn-uid-submit" @click="goHome">返回大厅</button>
    </view>
  </view>
</template>

<script setup>
import { onShow } from '@dcloudio/uni-app'
import { getToken } from '../../utils/auth.js'

onShow(() => {
  if (!getToken()) uni.reLaunch({ url: '/pages/login/login' })
})

function goHome() {
  uni.switchTab({ url: '/pages/home/home' })
}
</script>

<style scoped>
.hero { margin-bottom: 14px; }
.title { display: block; font-size: 22px; font-weight: 800; color: #1f1714; }
.sub { display: block; margin-top: 6px; font-size: 13px; color: #8a7a6e; }
</style>

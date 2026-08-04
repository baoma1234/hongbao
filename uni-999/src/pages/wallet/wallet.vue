<template>
  <view class="page">
    <view class="card">
      <view class="h">钱包</view>
      <view class="p">骨架页：后续迁入提现通道 / 线上合作 / 流水列表分页。</view>
      <view class="row" v-if="profile">
        <text>红宝余额</text>
        <text class="v">{{ hongbao }}</text>
      </view>
    </view>
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { fetchProfile, getToken } from '../../utils/auth.js'

const profile = ref(null)
const hongbao = computed(() => {
  const p = profile.value || {}
  const n = p.hongbao != null ? p.hongbao : p.account?.hongbao
  return n != null ? Number(n).toFixed(2) : '-'
})

onShow(async () => {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  try {
    profile.value = await fetchProfile()
  } catch (e) {}
})
</script>

<style scoped>
.page { padding: 32rpx; }
.card { background: #fff; border-radius: 20rpx; padding: 28rpx; }
.h { font-size: 34rpx; font-weight: 800; }
.p { margin: 12rpx 0 24rpx; color: #9a8574; font-size: 24rpx; }
.row { display: flex; justify-content: space-between; font-size: 28rpx; }
.v { font-weight: 800; color: #c61114; }
</style>

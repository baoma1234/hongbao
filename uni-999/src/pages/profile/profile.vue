<template>
  <view class="page">
    <view class="card">
      <view class="h">我的</view>
      <view class="row"><text>手机</text><text>{{ mobile }}</text></view>
      <view class="row"><text>会员ID</text><text>{{ userId }}</text></view>
      <button class="out" @click="onLogout">退出登录</button>
    </view>
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { fetchProfile, getToken, logoutLocal } from '../../utils/auth.js'
import { imDisconnect } from '../../utils/im.js'

const profile = ref(null)
const mobile = computed(() => (profile.value && (profile.value.mobile || profile.value.user?.mobile)) || '-')
const userId = computed(() => (profile.value && (profile.value.user_id || profile.value.id)) || '-')

onShow(async () => {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  try {
    profile.value = await fetchProfile()
  } catch (e) {}
})

function onLogout() {
  imDisconnect()
  logoutLocal()
  uni.reLaunch({ url: '/pages/login/login' })
}
</script>

<style scoped>
.page { padding: 32rpx; }
.card { background: #fff; border-radius: 20rpx; padding: 28rpx; }
.h { font-size: 34rpx; font-weight: 800; margin-bottom: 20rpx; }
.row { display: flex; justify-content: space-between; padding: 14rpx 0; font-size: 28rpx; }
.out { margin-top: 40rpx; background: #fff; color: #c61114; border: 1px solid #c61114; }
</style>

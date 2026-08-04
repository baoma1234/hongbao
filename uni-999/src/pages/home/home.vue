<template>
  <view class="page" :key="locale">
    <TopBar />
    <view class="inner">
      <view class="hero">
        <text class="title">{{ t('brand_name') }}{{ t('tab_bar_home') }}</text>
        <text class="desc">999 uni-app · {{ t('loading_generic') }}</text>
      </view>
      <view class="card" v-if="profile">
        <view class="row"><text class="k">昵称</text><text class="v">{{ nickname }}</text></view>
        <view class="row"><text class="k">会员ID</text><text class="v">{{ userId }}</text></view>
        <view class="row"><text class="k">{{ t('asset_hongbao_label') || '红宝' }}</text><text class="v">{{ hongbao }}</text></view>
        <view class="row"><text class="k">{{ t('asset_shares_label') || '股份' }}</text><text class="v">{{ rights }}</text></view>
      </view>
      <view class="card muted" v-else>{{ t('loading_generic') }}</view>
      <button class="btn" @click="goMessages">{{ t('home_quick_messages') || '进入消息' }}</button>
    </view>
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import { fetchProfile, getToken } from '../../utils/auth.js'
import { localeState, t } from '../../utils/i18n.js'
import { imConnect } from '../../utils/im.js'

const locale = localeState()
const profile = ref(null)

const nickname = computed(() => (profile.value && (profile.value.nickname || profile.value.user?.nickname)) || '-')
const userId = computed(() => (profile.value && (profile.value.user_id || profile.value.id)) || '-')
const hongbao = computed(() => {
  const p = profile.value || {}
  const n = p.hongbao != null ? p.hongbao : p.account?.hongbao
  return n != null ? Number(n).toFixed(2) : '-'
})
const rights = computed(() => {
  const p = profile.value || {}
  const n = p.rights != null ? p.rights : p.account?.rights
  return n != null ? Number(n).toFixed(2) : '-'
})

async function load() {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  try {
    profile.value = await fetchProfile()
  } catch (e) {
    uni.showToast({ title: e.message || '资料失败', icon: 'none' })
  }
  imConnect().catch(() => {})
}

function goMessages() {
  uni.switchTab({ url: '/pages/messages/messages' })
}

onShow(load)
</script>

<style scoped>
.page { min-height: 100vh; background: var(--bg-main, #f6f1ea); }
.inner { padding: 24rpx 32rpx 40rpx; }
.hero { margin-bottom: 24rpx; }
.title { display: block; font-size: 40rpx; font-weight: 800; color: var(--text-main, #3d2e22); }
.desc { display: block; margin-top: 8rpx; color: var(--text-muted, #9a8574); font-size: 24rpx; }
.card {
  background: var(--bg-card, #fff);
  border-radius: 20rpx;
  padding: 24rpx;
  margin-bottom: 24rpx;
}
.card.muted { color: var(--text-muted, #9a8574); }
.row { display: flex; justify-content: space-between; padding: 12rpx 0; font-size: 28rpx; }
.k { color: var(--text-muted, #9a8574); }
.v { color: var(--text-main, #3d2e22); font-weight: 700; }
.btn {
  background: var(--primary, #c61114);
  color: #fff;
  border-radius: 16rpx;
}
</style>

<template>
  <view class="page">
    <view class="nav">
      <view class="nav-ico" @click="goBack">
        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
          <path fill="currentColor" d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6 6 6z" />
        </svg>
      </view>
      <text class="nav-title">好友申请</text>
      <view class="nav-spacer" />
    </view>

    <view class="tabs">
      <view class="tab" :class="{ on: tab === 'incoming' }" @click="tab = 'incoming'">
        收到的
        <text v-if="pending > 0" class="badge">{{ pending > 99 ? '99+' : pending }}</text>
      </view>
      <view class="tab" :class="{ on: tab === 'outgoing' }" @click="tab = 'outgoing'">发出的</view>
    </view>

    <view class="empty" v-if="!rows.length && loaded">暂无申请</view>
    <view v-for="item in rows" :key="item.id" class="item">
      <view class="av">{{ letter(peerName(item)) }}</view>
      <view class="main">
        <text class="name">{{ peerName(item) }}</text>
        <text class="sub">{{ item.message || statusText(item.status) }}</text>
      </view>
      <view class="actions" v-if="tab === 'incoming' && item.status === 'pending'">
        <button size="mini" class="btn ghost" @click="act(item, 'reject')">拒绝</button>
        <button size="mini" class="btn ok" @click="act(item, 'accept')">通过</button>
      </view>
      <view class="actions" v-else-if="tab === 'outgoing' && item.status === 'pending'">
        <button size="mini" class="btn ghost" @click="act(item, 'cancel')">取消</button>
      </view>
      <text v-else class="st">{{ statusText(item.status) }}</text>
    </view>
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onLoad, onShow } from '@dcloudio/uni-app'
import { getToken } from '../../utils/auth.js'
import { avatarLetter } from '../../utils/chat.js'
import {
  friendAccept,
  friendCancel,
  friendReject,
  friendRequests,
  imConnect,
} from '../../utils/im.js'

const tab = ref('incoming')
const cache = ref({ incoming: [], outgoing: [], pending_count: 0 })
const loaded = ref(false)
const busyId = ref(0)

const pending = computed(() => cache.value.pending_count | 0)
const rows = computed(() => {
  return tab.value === 'outgoing' ? cache.value.outgoing || [] : cache.value.incoming || []
})

function letter(name) {
  return avatarLetter(name)
}

function peerName(item) {
  const peer = item.peer || item.from_user || {}
  return peer.nickname || ('ID' + (item.peer_user_id || item.from_user_id || ''))
}

function statusText(st) {
  if (st === 'accepted') return '已通过'
  if (st === 'rejected') return '已拒绝'
  if (st === 'cancelled') return '已取消'
  return '待处理'
}

function goBack() {
  uni.navigateBack({ fail: () => uni.switchTab({ url: '/pages/messages/messages' }) })
}

async function load() {
  try {
    await imConnect()
    const packet = await friendRequests()
    const data = (packet && packet.data) || {}
    cache.value = {
      incoming: data.incoming || [],
      outgoing: data.outgoing || [],
      pending_count: data.pending_count | 0,
    }
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '加载失败', icon: 'none' })
  } finally {
    loaded.value = true
  }
}

async function act(item, kind) {
  const id = item.id | 0
  if (!id || busyId.value) return
  busyId.value = id
  try {
    if (kind === 'accept') await friendAccept(id)
    else if (kind === 'cancel') {
      try {
        await friendCancel(id)
      } catch (e) {
        await friendReject(id)
      }
    } else await friendReject(id)
    uni.showToast({ title: kind === 'accept' ? '已通过' : '已处理', icon: 'none' })
    await load()
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '操作失败', icon: 'none' })
  } finally {
    busyId.value = 0
  }
}

onLoad((q) => {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  if (q && q.tab === 'outgoing') tab.value = 'outgoing'
})

onShow(() => {
  if (getToken()) load()
})
</script>

<style scoped>
.page { min-height: 100vh; background: #f6f1ea; }
.nav {
  display: flex; align-items: center; gap: 12rpx;
  padding: 18rpx 20rpx; background: linear-gradient(to right, #e63022, #c61114); color: #fff;
}
.nav-ico {
  width: 52rpx; height: 52rpx; border-radius: 12rpx;
  display: flex; align-items: center; justify-content: center;
}
.nav-title { flex: 1; font-size: 32rpx; font-weight: 800; }
.nav-spacer { width: 52rpx; }
.tabs {
  margin: 16rpx 24rpx; background: #fff; border-radius: 14rpx; padding: 6rpx;
  display: grid; grid-template-columns: 1fr 1fr; gap: 6rpx;
}
.tab {
  text-align: center; padding: 14rpx 0; border-radius: 10rpx; color: #8a7a6e; font-size: 26rpx;
  display: flex; align-items: center; justify-content: center; gap: 8rpx;
}
.tab.on { background: #fff1ef; color: #c61114; font-weight: 700; }
.badge {
  min-width: 28rpx; height: 28rpx; padding: 0 8rpx; border-radius: 999rpx;
  background: #c61114; color: #fff; font-size: 18rpx; line-height: 28rpx;
}
.empty { text-align: center; color: #9a8574; padding: 80rpx 24rpx; font-size: 26rpx; }
.item {
  margin: 0 24rpx 12rpx; background: #fff; border-radius: 14rpx; padding: 18rpx;
  display: flex; align-items: center; gap: 14rpx;
  box-shadow: 0 4rpx 12rpx rgba(40, 20, 10, 0.04);
}
.av {
  width: 72rpx; height: 72rpx; border-radius: 16rpx;
  background: linear-gradient(160deg, #fff5f3, #ffe3df); color: #c61114;
  font-weight: 800; display: flex; align-items: center; justify-content: center;
}
.main { flex: 1; min-width: 0; }
.name { display: block; font-size: 28rpx; font-weight: 800; color: #2a1f18; }
.sub { display: block; margin-top: 6rpx; font-size: 22rpx; color: #9a8574; }
.actions { display: flex; gap: 8rpx; }
.btn { margin: 0; }
.btn.ok { background: #c61114; color: #fff; }
.btn.ghost { background: #fff8f0; color: #8a4f1f; border: 1px solid #f0b04a; }
.st { font-size: 22rpx; color: #9a8574; flex-shrink: 0; }
</style>

<template>
  <view class="chat-shell chat-friend-page">
    <TopBar :no-spacer="true" />
    <view class="chat-hero-hd">
      <view class="chat-hero-back" @click="goBack">
        <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true">
          <path fill="currentColor" d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6 6 6 1.41-1.41z" />
        </svg>
      </view>
      <view class="chat-hero-title">好友申请</view>
      <view class="chat-hero-spacer" />
    </view>

    <view class="chat-sub-main">
      <view class="chat-friend-req-tabs" role="tablist">
        <view
          class="chat-friend-req-tab"
          :class="{ active: tab === 'incoming' }"
          @click="tab = 'incoming'"
        >
          收到的
          <text v-if="pending > 0" class="chat-friend-req-badge">{{ pending > 99 ? '99+' : pending }}</text>
        </view>
        <view
          class="chat-friend-req-tab"
          :class="{ active: tab === 'outgoing' }"
          @click="tab = 'outgoing'"
        >发出的</view>
      </view>

      <view class="chat-friend-req-list">
        <view v-if="!rows.length && loaded" class="chat-empty chat-empty-sm">暂无申请</view>
        <view v-for="item in rows" :key="item.id" class="chat-friend-req-item">
          <view class="chat-friend-req-avatar">{{ letter(peerName(item)) }}</view>
          <view class="chat-friend-req-body">
            <view class="chat-friend-req-name">{{ peerName(item) }}</view>
            <view class="chat-friend-req-sub">{{ item.message || statusText(item.status) }}</view>
          </view>
          <view class="chat-friend-req-actions" v-if="tab === 'incoming' && item.status === 'pending'">
            <view class="chat-friend-req-btn reject" @click="act(item, 'reject')">拒绝</view>
            <view class="chat-friend-req-btn accept" @click="act(item, 'accept')">通过</view>
          </view>
          <view class="chat-friend-req-actions" v-else-if="tab === 'outgoing' && item.status === 'pending'">
            <view class="chat-friend-req-btn reject" @click="act(item, 'cancel')">取消</view>
          </view>
          <view v-else class="chat-friend-req-status">{{ statusText(item.status) }}</view>
        </view>
      </view>
    </view>
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onLoad, onShow } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import { getToken } from '../../utils/auth.js'
import { avatarLetter } from '../../utils/chat.js'
import {
  friendAccept,
  friendCancel,
  friendReject,
  friendRequests,
  imConnect,
} from '../../utils/im.js'
import '../../styles/chat.bundle.css'
import '../../styles/chat-uni-adapter.css'
import '../../styles/friend-uni-adapter.css'

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

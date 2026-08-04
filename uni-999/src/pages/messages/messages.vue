<template>
  <view class="page">
    <view class="status" :class="status">
      <text>IM：{{ statusText }}</text>
      <button size="mini" class="mini" @click="reconnect">重连</button>
    </view>

    <view class="card" v-if="!list.length && loaded">
      <text class="empty">暂无会话（登录后应出现客服）</text>
    </view>

    <view
      v-for="item in list"
      :key="itemKey(item)"
      class="item"
      @click="openChat(item)"
    >
      <view class="title">{{ itemTitle(item) }}</view>
      <view class="sub">{{ itemPreview(item) }}</view>
    </view>
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onShow, onHide } from '@dcloudio/uni-app'
import { getToken } from '../../utils/auth.js'
import { getImStatus, imConnect, listConversations, onImEvent } from '../../utils/im.js'

const list = ref([])
const loaded = ref(false)
const status = ref('disconnected')
let off = null

const statusText = computed(() => {
  if (status.value === 'online') return '已连接'
  if (status.value === 'connecting') return '连接中…'
  return '未连接'
})

function refreshStatus() {
  status.value = getImStatus()
}

function itemKey(item) {
  return String(item.conversation_type) + ':' + String(item.conversation_id || item.group_id || item.peer_user_id)
}

function itemTitle(item) {
  return item.title || item.nickname || ('会话 ' + (item.peer_user_id || item.group_id || ''))
}

function itemPreview(item) {
  const last = item.last_message
  if (!last) return '暂无消息'
  if (typeof last === 'string') return last
  return last.content || last.text || JSON.stringify(last).slice(0, 40)
}

function openChat(item) {
  const q = [
    'type=' + encodeURIComponent(item.conversation_type || 1),
    'id=' + encodeURIComponent(item.conversation_id || ''),
    'peer=' + encodeURIComponent(item.peer_user_id || 0),
    'group=' + encodeURIComponent(item.group_id || 0),
    'title=' + encodeURIComponent(itemTitle(item)),
  ].join('&')
  uni.navigateTo({ url: '/pages/chat/chat?' + q })
}

async function loadList() {
  refreshStatus()
  try {
    await imConnect()
    refreshStatus()
    const packet = await listConversations(80)
    const data = (packet && packet.data) || packet || {}
    list.value = data.list || data.items || data.conversations || []
  } catch (e) {
    uni.showToast({ title: e.message || '拉取会话失败', icon: 'none' })
  } finally {
    loaded.value = true
    refreshStatus()
  }
}

async function reconnect() {
  try {
    await imConnect()
    await loadList()
    uni.showToast({ title: '已重连', icon: 'success' })
  } catch (e) {
    uni.showToast({ title: e.message || '重连失败', icon: 'none' })
  }
}

onShow(() => {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  off = onImEvent((type) => {
    if (type === 'auth.ok' || type === 'socket.close' || type === 'socket.error') refreshStatus()
    if (type === 'private.message' || type === 'group.message' || type === 'conversation.updated') {
      loadList()
    }
  })
  loadList()
})

onHide(() => {
  if (off) {
    off()
    off = null
  }
})
</script>

<style scoped>
.page { padding: 24rpx; }
.status {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 16rpx 20rpx;
  border-radius: 12rpx;
  margin-bottom: 20rpx;
  font-size: 26rpx;
  background: #eee;
}
.status.online { background: #e8f5e9; color: #2e7d32; }
.status.connecting { background: #fff8e1; color: #f57f17; }
.status.disconnected { background: #ffebee; color: #c62828; }
.mini { margin: 0; }
.card, .item {
  background: #fff;
  border-radius: 16rpx;
  padding: 24rpx;
  margin-bottom: 16rpx;
}
.empty { color: #9a8574; font-size: 26rpx; }
.title { font-size: 30rpx; font-weight: 700; color: #3d2e22; }
.sub { margin-top: 8rpx; font-size: 24rpx; color: #9a8574; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
</style>

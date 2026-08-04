<template>
  <view class="page">
    <TopBar />
    <view class="status" :class="status">
      <text>IM · {{ statusText }}</text>
      <button size="mini" class="mini" @click="reconnect">重连</button>
    </view>

    <view class="empty" v-if="!list.length && loaded">暂无会话（登录后通常会有客服）</view>

    <view
      v-for="item in list"
      :key="itemKey(item)"
      class="chat-conv-item"
      :class="{ 'is-pinned': !!item.pinned, 'is-admin': !!item.is_im_admin }"
      @click="openChat(item)"
      @longpress="onLongPress(item)"
    >
      <view class="chat-conv-avatar" :class="{ group: (item.conversation_type | 0) === 2, admin: !!item.is_im_admin }">
        <image v-if="item.avatar" class="av-img" :src="item.avatar" mode="aspectFill" />
        <text v-else>{{ avatarLetter(displayTitle(item)) }}</text>
      </view>
      <view class="chat-conv-main">
        <view class="chat-conv-top">
          <view class="chat-conv-title-wrap">
            <text v-if="item.pinned" class="chat-conv-pin">📌</text>
            <text class="chat-conv-title">{{ displayTitle(item) }}</text>
            <view v-if="item.is_im_admin" class="chat-conv-tag">
              <image class="tag-ico" :src="fxIcon" mode="aspectFit" />
              <text>客服</text>
            </view>
          </view>
          <text class="chat-conv-time">{{ itemTime(item) }}</text>
        </view>
        <view class="chat-conv-bottom">
          <text class="chat-conv-preview">{{ itemPreview(item) }}</text>
          <view v-if="unreadOf(item) > 0" class="chat-badge">
            {{ unreadOf(item) > 99 ? '99+' : unreadOf(item) }}
          </view>
        </view>
      </view>
    </view>
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onShow, onHide } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import { getToken } from '../../utils/auth.js'
import {
  avatarLetter,
  convKey,
  displayTitle,
  formatConvTime,
  previewText,
  resolveConvId,
} from '../../utils/chat.js'
import {
  getImStatus,
  hideConversation,
  imConnect,
  listConversations,
  onImEvent,
  pinConversation,
} from '../../utils/im.js'

const list = ref([])
const loaded = ref(false)
const status = ref('disconnected')
const localUnread = ref({})
const fxIcon = '/888/img/chat/fx.png'
let off = null
let loading = false

const statusText = computed(() => {
  if (status.value === 'online') return '已连接'
  if (status.value === 'connecting') return '连接中…'
  return '未连接'
})

function refreshStatus() {
  status.value = getImStatus()
}

function itemKey(item) {
  return convKey(item.conversation_type, resolveConvId(item))
}

function unreadOf(item) {
  const key = itemKey(item)
  const local = localUnread.value[key] | 0
  const server = item.unread_count | 0
  return Math.max(local, server)
}

function itemPreview(item) {
  const prev = previewText(item.last_message)
  if (prev && prev !== '暂无消息') return prev
  return item.is_im_admin ? '点击开始咨询' : '暂无消息'
}

function itemTime(item) {
  const last = item.last_message
  const ts = item.updatetime || (last && last.createtime) || 0
  return formatConvTime(ts)
}

function openChat(item) {
  const type = item.conversation_type | 0
  const id = resolveConvId(item)
  const key = convKey(type, id)
  localUnread.value = Object.assign({}, localUnread.value, { [key]: 0 })
  item.unread_count = 0
  const q = [
    'type=' + encodeURIComponent(type),
    'id=' + encodeURIComponent(id),
    'peer=' + encodeURIComponent(item.peer_user_id || 0),
    'group=' + encodeURIComponent(item.group_id || (type === 2 ? id : 0)),
    'title=' + encodeURIComponent(displayTitle(item)),
    'nickname=' + encodeURIComponent(item.peer_nickname || ''),
    'remark=' + encodeURIComponent(item.remark || ''),
  ].join('&')
  uni.navigateTo({ url: '/pages/chat/chat?' + q })
}

function bumpUnread(msg) {
  if (!msg) return
  const type = msg.conversation_type | 0
  let id = ''
  if (type === 2) id = String(msg.group_id || msg.conversation_id || '')
  else id = String(msg.conversation_id || '')
  if (!id) return
  const key = convKey(type, id)
  const cur = localUnread.value[key] | 0
  localUnread.value = Object.assign({}, localUnread.value, { [key]: cur + 1 })
}

function onLongPress(item) {
  const type = item.conversation_type | 0
  const id = resolveConvId(item)
  const pinned = !!item.pinned
  const items = [pinned ? '取消置顶' : '置顶会话', '删除会话']
  uni.showActionSheet({
    itemList: items,
    success: async (res) => {
      try {
        if (res.tapIndex === 0) {
          await pinConversation(type, id, !pinned)
          await loadList(true)
          uni.showToast({ title: pinned ? '已取消置顶' : '已置顶', icon: 'none' })
          return
        }
        if (res.tapIndex === 1) {
          uni.showModal({
            title: '删除会话',
            content: '从列表移除「' + displayTitle(item) + '」？聊天记录不会清空。',
            success: async (r) => {
              if (!r.confirm) return
              try {
                const extra = {}
                if (type === 2) extra.group_id = item.group_id || id
                else {
                  if (item.peer_user_id) extra.to_user_id = item.peer_user_id
                }
                await hideConversation(type, id, extra)
                list.value = list.value.filter((x) => itemKey(x) !== itemKey(item))
                uni.showToast({ title: '已删除', icon: 'none' })
              } catch (e) {
                uni.showToast({ title: (e && e.message) || '删除失败', icon: 'none' })
              }
            },
          })
        }
      } catch (e) {
        uni.showToast({ title: (e && e.message) || '操作失败', icon: 'none' })
      }
    },
  })
}

async function loadList(silent = false) {
  if (loading) return
  loading = true
  refreshStatus()
  try {
    await imConnect()
    refreshStatus()
    const packet = await listConversations(80)
    const data = (packet && packet.data) || packet || {}
    const rows = data.list || data.items || data.conversations || []
    // 置顶优先
    rows.sort((a, b) => {
      const ap = a.pinned ? 1 : 0
      const bp = b.pinned ? 1 : 0
      if (ap !== bp) return bp - ap
      return (b.updatetime | 0) - (a.updatetime | 0)
    })
    list.value = rows
    const next = Object.assign({}, localUnread.value)
    rows.forEach((it) => {
      const key = itemKey(it)
      const server = it.unread_count | 0
      next[key] = Math.max(next[key] | 0, server)
    })
    localUnread.value = next
  } catch (e) {
    if (!silent) uni.showToast({ title: e.message || '拉取会话失败', icon: 'none' })
  } finally {
    loaded.value = true
    loading = false
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
  if (off) off()
  off = onImEvent((type, data) => {
    if (type === 'auth.ok' || type === 'socket.close' || type === 'socket.error') refreshStatus()
    if (type === 'private.message' || type === 'group.message') {
      const msg = (data && data.message) || data
      bumpUnread(msg)
      loadList(true)
    }
    if (type === 'conversation.updated' || type === 'redpacket.update') {
      loadList(true)
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
.page {
  min-height: 100vh;
  background: var(--bg-main, #f6f1ea);
  padding: 0 0 40rpx;
}
.status {
  margin: 16rpx 24rpx;
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 14rpx 20rpx;
  border-radius: 12rpx;
  font-size: 24rpx;
  background: #eee;
}
.status.online { background: #e8f5e9; color: #2e7d32; }
.status.connecting { background: #fff8e1; color: #f57f17; }
.status.disconnected { background: #ffebee; color: #c62828; }
.mini { margin: 0; }
.empty {
  text-align: center;
  color: #9a8574;
  padding: 80rpx 24rpx;
  font-size: 26rpx;
}
.chat-conv-item {
  position: relative;
  display: flex;
  align-items: center;
  gap: 18rpx;
  padding: 20rpx 24rpx;
  background: #fff;
  transition: transform 0.2s ease, background 0.2s ease;
}
.chat-conv-item::after {
  content: '';
  position: absolute;
  left: 130rpx;
  right: 16rpx;
  bottom: 0;
  border-bottom: 1px solid rgba(40, 20, 10, 0.08);
}
.chat-conv-item:last-child::after { border-bottom: none; }
.chat-conv-item:active { background: #fff7f0; }
.chat-conv-item.is-pinned { background: rgba(245, 154, 35, 0.08); }
.chat-conv-avatar {
  width: 88rpx;
  height: 88rpx;
  border-radius: 18rpx;
  background: linear-gradient(160deg, #fff5f3, #ffe3df);
  color: #c61114;
  font-weight: 800;
  font-size: 34rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  overflow: hidden;
}
.chat-conv-avatar.group { background: linear-gradient(160deg, #fff8ee, #ffe8cc); color: #b8751a; }
.chat-conv-avatar.admin { background: linear-gradient(160deg, #e63022, #c61114); color: #fff; }
.av-img { width: 100%; height: 100%; }
.chat-conv-main { flex: 1; min-width: 0; }
.chat-conv-top, .chat-conv-bottom {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12rpx;
}
.chat-conv-bottom { margin-top: 10rpx; }
.chat-conv-title-wrap {
  display: flex;
  align-items: center;
  gap: 8rpx;
  min-width: 0;
  flex: 1;
}
.chat-conv-pin {
  font-size: 22rpx;
  opacity: 0.8;
}
.chat-conv-title {
  font-size: 30rpx;
  font-weight: 800;
  color: #2a1f18;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.chat-conv-tag {
  flex-shrink: 0;
  font-size: 18rpx;
  padding: 2rpx 10rpx 2rpx 8rpx;
  border-radius: 999rpx;
  background: #c61114;
  color: #fff;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  gap: 4rpx;
}
.tag-ico {
  width: 18rpx;
  height: 18rpx;
}
.chat-conv-time { font-size: 22rpx; color: #b8aaa0; flex-shrink: 0; }
.chat-conv-preview {
  flex: 1;
  min-width: 0;
  font-size: 24rpx;
  color: #9a8574;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.chat-conv-item.is-admin .chat-conv-preview { color: #e07a22; }
.chat-badge {
  flex-shrink: 0;
  min-width: 36rpx;
  height: 36rpx;
  padding: 0 10rpx;
  border-radius: 999rpx;
  background: #c61114;
  color: #fff;
  font-size: 20rpx;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
}
</style>

<template>
  <view class="page">
    <view class="nav">{{ title || '聊天' }}</view>
    <scroll-view scroll-y class="msgs" :scroll-into-view="scrollInto">
      <view v-for="m in messages" :id="'m' + m.msg_id" :key="m.msg_id || m.id" class="bubble" :class="{ mine: isMine(m) }">
        <text class="content">{{ m.content || m.text || '[消息]' }}</text>
      </view>
    </scroll-view>
    <view class="composer">
      <input class="input" v-model="text" confirm-type="send" @confirm="send" placeholder="输入消息" />
      <button class="send" size="mini" @click="send">发送</button>
    </view>
    <view class="tip">WS：{{ wsOk ? '在线' : '离线' }} · 骨架页，红包 UI 后续迁入</view>
  </view>
</template>

<script setup>
import { ref } from 'vue'
import { onLoad, onUnload } from '@dcloudio/uni-app'
import { fetchProfile, getToken } from '../../utils/auth.js'
import { getImStatus, imConnect, imSend, onImEvent } from '../../utils/im.js'

const title = ref('')
const text = ref('')
const messages = ref([])
const scrollInto = ref('')
const wsOk = ref(false)
const meta = ref({ type: 1, peer: 0, group: 0, conversationId: '' })
let myId = 0
let off = null

function isMine(m) {
  return (m.from_user_id | 0) === (myId | 0)
}

async function ensureUser() {
  try {
    const p = await fetchProfile()
    myId = (p && (p.user_id || p.id)) | 0
  } catch (e) {}
}

async function loadHistory() {
  const data = {
    conversation_type: meta.value.type | 0,
    limit: 40,
  }
  if (meta.value.type == 2) data.group_id = meta.value.group | 0
  else {
    data.to_user_id = meta.value.peer | 0
    if (meta.value.conversationId) data.conversation_id = meta.value.conversationId
  }
  const packet = await imSend('history', data, true)
  const body = (packet && packet.data) || {}
  const list = body.list || body.messages || []
  messages.value = list.slice().reverse()
  const last = messages.value[messages.value.length - 1]
  if (last) scrollInto.value = 'm' + (last.msg_id || last.id)
}

async function send() {
  const content = String(text.value || '').trim()
  if (!content) return
  try {
    if (meta.value.type == 2) {
      await imSend('group.send', { group_id: meta.value.group | 0, content }, true)
    } else {
      await imSend('private.send', { to_user_id: meta.value.peer | 0, content }, true)
    }
    text.value = ''
    await loadHistory()
  } catch (e) {
    uni.showToast({ title: e.message || '发送失败', icon: 'none' })
  }
}

onLoad(async (query) => {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  meta.value = {
    type: parseInt(query.type || '1', 10) || 1,
    peer: parseInt(query.peer || '0', 10) || 0,
    group: parseInt(query.group || '0', 10) || 0,
    conversationId: decodeURIComponent(query.id || ''),
  }
  title.value = decodeURIComponent(query.title || '聊天')
  await ensureUser()
  off = onImEvent((type, data) => {
    wsOk.value = getImStatus() === 'online'
    if (type === 'private.message' || type === 'group.message') {
      const msg = (data && data.message) || data
      if (msg) {
        messages.value.push(msg)
        scrollInto.value = 'm' + (msg.msg_id || msg.id || messages.value.length)
      }
    }
  })
  try {
    await imConnect()
    wsOk.value = true
    await loadHistory()
  } catch (e) {
    wsOk.value = false
    uni.showToast({ title: e.message || '连接失败', icon: 'none' })
  }
})

onUnload(() => {
  if (off) off()
})
</script>

<style scoped>
.page { display: flex; flex-direction: column; height: 100vh; background: #f6f1ea; }
.nav { padding: 20rpx 24rpx; font-weight: 800; background: #c61114; color: #fff; }
.msgs { flex: 1; padding: 20rpx; box-sizing: border-box; }
.bubble {
  max-width: 75%;
  margin: 12rpx 0;
  padding: 16rpx 20rpx;
  background: #fff;
  border-radius: 16rpx;
  font-size: 28rpx;
}
.bubble.mine { margin-left: auto; background: #ffe8e6; color: #c61114; }
.composer {
  display: flex;
  gap: 12rpx;
  padding: 16rpx;
  background: #fff;
  border-top: 1px solid #eee;
}
.input { flex: 1; height: 72rpx; background: #f7f2ec; border-radius: 12rpx; padding: 0 20rpx; }
.send { margin: 0; background: #c61114; color: #fff; }
.tip { font-size: 22rpx; color: #9a8574; padding: 8rpx 16rpx 20rpx; }
</style>

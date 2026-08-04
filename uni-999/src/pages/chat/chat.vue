<template>
  <view class="page">
    <view class="nav">
      <text class="nav-back" @click="goBack">‹</text>
      <text class="nav-title">{{ title }}</text>
      <text class="nav-more" @click="openMore">⋯</text>
    </view>

    <scroll-view
      scroll-y
      class="msgs"
      :scroll-into-view="scrollInto"
      scroll-with-animation
      @click="showEmoji = false"
    >
      <view
        v-for="m in messages"
        :id="'m' + msgId(m)"
        :key="msgId(m)"
        class="row"
        :class="{ mine: isMine(m), system: isSysRow(m) }"
      >
        <view v-if="isSysRow(m)" class="sys">{{ sysText(m) }}</view>
        <view
          v-else-if="isRp(m)"
          class="rp-card"
          :class="{ faded: rpFaded(m), grabbed: rpGrabbed(m) }"
          @click="onRpTap(m)"
          @longpress="onMsgLongPress(m)"
        >
          <view class="rp-ico">红</view>
          <view class="rp-main">
            <text class="rp-bless">{{ rpBlessing(m) }}</text>
            <text class="rp-sub">{{ rpSub(m) }}</text>
          </view>
        </view>
        <view v-else class="bubble" @longpress="onMsgLongPress(m)">
          <text class="content">{{ m.content || m.text || '[消息]' }}</text>
        </view>
      </view>
    </scroll-view>

    <view class="emoji-panel" v-if="showEmoji">
      <scroll-view scroll-y class="emoji-scroll">
        <view class="emoji-grid">
          <text
            v-for="(em, idx) in emojis"
            :key="idx"
            class="emoji-item"
            @click="insertEmoji(em)"
          >{{ em }}</text>
        </view>
      </scroll-view>
    </view>

    <view class="composer">
      <button class="tool" size="mini" @click="toggleEmoji">表情</button>
      <button class="tool" size="mini" @click="showRp = true; showEmoji = false">红包</button>
      <input
        class="input"
        v-model="text"
        confirm-type="send"
        @confirm="sendText"
        @focus="showEmoji = false"
        placeholder="输入消息"
      />
      <button class="send" size="mini" @click="sendText">发送</button>
    </view>

    <!-- 发包 -->
    <view class="mask" v-if="showRp" @click="showRp = false">
      <view class="sheet" @click.stop>
        <view class="sheet-title">发红包</view>
        <view class="field" v-if="!isPrivate">
          <text class="lab">类型</text>
          <view class="tabs">
            <text
              v-for="t in packetTypes"
              :key="t.v"
              class="tab"
              :class="{ on: rpForm.packet_type === t.v }"
              @click="rpForm.packet_type = t.v"
            >{{ t.n }}</text>
          </view>
        </view>
        <view class="field">
          <text class="lab">金额（红宝）</text>
          <input class="hb-input" type="digit" v-model="rpForm.total_amount" placeholder="金额" />
        </view>
        <view class="field" v-if="!isPrivate">
          <text class="lab">个数</text>
          <input class="hb-input" type="number" v-model="rpForm.total_count" placeholder="个数" />
        </view>
        <view class="field" v-if="!isPrivate && rpForm.packet_type === 3">
          <text class="lab">雷号 0-9</text>
          <input class="hb-input" type="number" v-model="rpForm.mine_digit" placeholder="0-9" />
        </view>
        <view class="field">
          <text class="lab">祝福语</text>
          <input class="hb-input" v-model="rpForm.blessing" placeholder="恭喜发财" />
        </view>
        <button class="btn-uid-submit" :disabled="rpSending" @click="sendRp">
          {{ rpSending ? '发送中…' : '塞进红包' }}
        </button>
        <button class="cancel" @click="showRp = false">取消</button>
      </view>
    </view>

    <!-- 红包详情 -->
    <view class="mask" v-if="detailVisible" @click="detailVisible = false">
      <view class="sheet detail" @click.stop>
        <view class="sheet-title">红包详情</view>
        <view class="detail-head" v-if="detail">
          <text class="d-bless">{{ (detail.packet && detail.packet.blessing) || '恭喜发财' }}</text>
          <text class="d-amt" v-if="myGrabAmount">抢到 {{ myGrabAmount }} 红宝</text>
          <text class="d-meta">
            {{ packetTypeLabel((detail.packet && detail.packet.packet_type) || 1) }}
            · {{ (detail.packet && detail.packet.total_amount) || '-' }} 红宝
            / {{ (detail.packet && detail.packet.total_count) || '-' }} 个
          </text>
        </view>
        <scroll-view scroll-y class="detail-list">
          <view v-for="r in detailRecords" :key="r.id || r.user_id" class="d-row">
            <text>{{ r.nickname || ('ID' + r.user_id) }}</text>
            <text class="d-right">{{ formatAmt(r.amount) }}</text>
          </view>
          <view v-if="!detailRecords.length" class="empty">暂无领取记录</view>
        </scroll-view>
        <button
          v-if="canGrabDetail"
          class="btn-uid-submit"
          :disabled="grabbing"
          @click="grabFromDetail"
        >
          {{ grabbing ? '领取中…' : '开红包' }}
        </button>
        <button class="cancel" @click="detailVisible = false">关闭</button>
      </view>
    </view>

    <!-- 私聊更多 -->
    <view class="mask" v-if="moreVisible" @click="moreVisible = false">
      <view class="sheet more" @click.stop>
        <view class="sheet-title">{{ peerNickname || title }}</view>
        <view class="more-sub">会员ID {{ meta.peer }}{{ remark ? ' · 备注 ' + remark : '' }}</view>
        <button class="btn-uid-submit" @click="editRemark">{{ remark ? '修改备注' : '设置备注' }}</button>
        <button class="cancel" @click="moreVisible = false">关闭</button>
      </view>
    </view>
  </view>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { onLoad, onUnload } from '@dcloudio/uni-app'
import { fetchProfile, getToken } from '../../utils/auth.js'
import {
  isRecalled,
  isSystemMsg,
  msgExtra,
  msgType,
  packetTypeLabel,
  recallTip,
} from '../../utils/chat.js'
import { COMMON_EMOJIS } from '../../utils/emoji.js'
import {
  grabRedPacket,
  imConnect,
  imSend,
  loadHistory,
  markConversationRead,
  onImEvent,
  recallMessage,
  redPacketDetail,
  sendRedPacket,
  setPeerRemark,
} from '../../utils/im.js'

const title = ref('聊天')
const peerNickname = ref('')
const remark = ref('')
const text = ref('')
const messages = ref([])
const scrollInto = ref('')
const meta = ref({ type: 1, peer: 0, group: 0, conversationId: '' })
const showRp = ref(false)
const showEmoji = ref(false)
const rpSending = ref(false)
const grabbing = ref(false)
const detailVisible = ref(false)
const detail = ref(null)
const moreVisible = ref(false)
const myGrabAmount = ref('')
const canGrabDetail = ref(false)
const emojis = COMMON_EMOJIS
let myId = 0
let off = null
let activePacketId = 0

const isPrivate = computed(() => (meta.value.type | 0) === 1)
const packetTypes = [
  { v: 2, n: '拼手气' },
  { v: 3, n: '埋雷' },
  { v: 1, n: '普通' },
]
const rpForm = reactive({
  packet_type: 2,
  total_amount: '',
  total_count: '5',
  mine_digit: '7',
  blessing: '恭喜发财',
})

const detailRecords = computed(() => {
  const d = detail.value
  if (!d) return []
  return d.records || d.list || []
})

function msgId(m) {
  return m.msg_id || m.id || m.createtime || Math.random()
}
function isMine(m) {
  return (m.from_user_id | 0) === (myId | 0)
}
function isRp(m) {
  return msgType(m) === 2
}
function isSysRow(m) {
  return isRecalled(m) || isSystemMsg(m)
}
function sysText(m) {
  if (isRecalled(m)) return recallTip(m, myId, isPrivate.value)
  return m.content || '[系统消息]'
}
function rpBlessing(m) {
  return msgExtra(m).blessing || '恭喜发财'
}
function rpSub(m) {
  const ex = msgExtra(m)
  const label = packetTypeLabel(ex.packet_type || 1)
  if (ex.cover_grabbed || ex.cover_faded) return label + ' · 已领过'
  if (ex.cover_expired) return label + ' · 已过期'
  return label + ' · 点击拆开'
}
function rpFaded(m) {
  const ex = msgExtra(m)
  return !!(ex.cover_faded || ex.cover_expired)
}
function rpGrabbed(m) {
  return !!msgExtra(m).cover_grabbed
}
function formatAmt(n) {
  const x = Number(n)
  if (!isFinite(x)) return '-'
  return x.toFixed(2)
}

function sameRoom(msg) {
  if (!msg) return false
  const type = msg.conversation_type | 0
  if (type !== (meta.value.type | 0)) return false
  if (type === 2) {
    return (msg.group_id | 0) === (meta.value.group | 0) ||
      String(msg.conversation_id || '') === String(meta.value.conversationId || meta.value.group)
  }
  return String(msg.conversation_id || '') === String(meta.value.conversationId) ||
    (msg.from_user_id | 0) === (meta.value.peer | 0) ||
    (msg.to_user_id | 0) === (meta.value.peer | 0)
}

function applyRecalled(msg) {
  if (!msg || !sameRoom(msg)) return
  const id = String(msg.id || msg.msg_id || '')
  if (!id) return
  const list = messages.value.slice()
  const idx = list.findIndex((x) => String(x.id || x.msg_id) === id)
  if (idx >= 0) {
    list[idx] = Object.assign({}, list[idx], msg, { status: 2 })
    messages.value = list
  }
}

function canRecallLocal(m) {
  if (!m || !isMine(m) || isRecalled(m) || isSystemMsg(m)) return false
  if (isPrivate.value) return true
  const ts = (m.createtime | 0) || 0
  const t = ts < 1e12 ? ts : Math.floor(ts / 1000)
  return t > 0 && Date.now() / 1000 - t <= 120
}

function onMsgLongPress(m) {
  if (!canRecallLocal(m)) return
  const label = isPrivate.value ? '删除消息' : '撤回消息'
  uni.showActionSheet({
    itemList: [label],
    success: async (res) => {
      if (res.tapIndex !== 0) return
      try {
        const packet = await recallMessage(m.id || m.msg_id)
        const body = (packet && packet.data) || {}
        const msg = body.message || Object.assign({}, m, { status: 2 })
        applyRecalled(msg)
        uni.showToast({ title: isPrivate.value ? '已删除' : '已撤回', icon: 'none' })
      } catch (e) {
        uni.showToast({ title: (e && e.message) || '操作失败', icon: 'none' })
      }
    },
  })
}

function toggleEmoji() {
  showEmoji.value = !showEmoji.value
}

function insertEmoji(em) {
  text.value = String(text.value || '') + em
}

function goBack() {
  uni.navigateBack({ fail: () => uni.switchTab({ url: '/pages/messages/messages' }) })
}

function openMore() {
  if (isPrivate.value) {
    moreVisible.value = true
    return
  }
  uni.navigateTo({
    url: '/pages/group/settings?group=' + encodeURIComponent(meta.value.group || meta.value.conversationId),
  })
}

async function ensureUser() {
  try {
    const p = await fetchProfile()
    myId = (p && (p.user_id || p.id)) | 0
  } catch (e) {}
}

async function markRead() {
  const list = messages.value
  if (!list.length) return
  const last = list[list.length - 1]
  const lastId = (last.msg_id || last.id) | 0
  const cid =
    meta.value.type === 2
      ? String(meta.value.group || meta.value.conversationId)
      : String(meta.value.conversationId)
  if (!cid) return
  await markConversationRead(meta.value.type, cid, lastId)
}

async function fetchHistory() {
  const data = {
    conversation_type: meta.value.type | 0,
    limit: 40,
  }
  if (meta.value.type == 2) data.group_id = meta.value.group | 0
  else {
    data.to_user_id = meta.value.peer | 0
    if (meta.value.conversationId) data.conversation_id = meta.value.conversationId
  }
  const packet = await loadHistory(data)
  const body = (packet && packet.data) || {}
  const list = body.list || body.messages || []
  messages.value = list.slice().reverse()
  const last = messages.value[messages.value.length - 1]
  if (last) scrollInto.value = 'm' + msgId(last)
  await markRead()
}

async function sendText() {
  const content = String(text.value || '').trim()
  if (!content) return
  try {
    if (meta.value.type == 2) {
      await imSend('group.send', { group_id: meta.value.group | 0, content }, true)
    } else {
      await imSend('private.send', { to_user_id: meta.value.peer | 0, content }, true)
    }
    text.value = ''
    showEmoji.value = false
    await fetchHistory()
  } catch (e) {
    uni.showToast({ title: e.message || '发送失败', icon: 'none' })
  }
}

async function sendRp() {
  const amount = Number(rpForm.total_amount)
  if (!(amount > 0)) {
    uni.showToast({ title: '请输入金额', icon: 'none' })
    return
  }
  const payload = {
    blessing: String(rpForm.blessing || '恭喜发财').trim() || '恭喜发财',
    total_amount: amount,
  }
  if (isPrivate.value) {
    payload.scope_type = 1
    payload.to_user_id = meta.value.peer | 0
    payload.packet_type = 1
    payload.total_count = 1
  } else {
    payload.scope_type = 2
    payload.group_id = meta.value.group | 0
    payload.packet_type = rpForm.packet_type | 0 || 2
    payload.total_count = Math.max(1, parseInt(rpForm.total_count, 10) || 1)
    if (payload.packet_type === 3) {
      const dig = parseInt(rpForm.mine_digit, 10)
      if (!(dig >= 0 && dig <= 9)) {
        uni.showToast({ title: '雷号需 0-9', icon: 'none' })
        return
      }
      payload.mine_digit = dig
      if ([5, 7, 9].indexOf(payload.total_count) < 0) {
        uni.showToast({ title: '埋雷个数请选 5/7/9', icon: 'none' })
        return
      }
    }
  }
  rpSending.value = true
  try {
    await sendRedPacket(payload)
    showRp.value = false
    uni.showToast({ title: '红包已发出', icon: 'none' })
    await fetchHistory()
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '发包失败', icon: 'none' })
  } finally {
    rpSending.value = false
  }
}

async function onRpTap(m) {
  const ex = msgExtra(m)
  const pid = (ex.packet_id || 0) | 0
  if (!pid) {
    uni.showToast({ title: '红包信息缺失', icon: 'none' })
    return
  }
  activePacketId = pid
  if (!ex.cover_grabbed && !ex.cover_expired && !isMine(m)) {
    await tryGrab(pid)
  }
  await openDetail(pid)
}

async function tryGrab(packetId) {
  grabbing.value = true
  try {
    const packet = await grabRedPacket(packetId)
    const data = (packet && packet.data) || packet || {}
    if (data.code === 'slider_required' || (packet && packet.type === 'redpacket.challenge')) {
      uni.showToast({ title: data.message || '需要滑动验证，请用 /888 完成', icon: 'none' })
      return null
    }
    if (data.amount != null) {
      myGrabAmount.value = formatAmt(data.amount)
      uni.showToast({ title: '抢到 ' + myGrabAmount.value, icon: 'none' })
    }
    return data
  } catch (e) {
    const msg = (e && e.message) || '领取失败'
    if (/already|已领|expired|过期|finished|抢完/i.test(msg)) {
      // open detail
    } else if (/slider/i.test(msg)) {
      uni.showToast({ title: '需要滑动验证', icon: 'none' })
    } else {
      uni.showToast({ title: msg, icon: 'none' })
    }
    return null
  } finally {
    grabbing.value = false
  }
}

async function openDetail(packetId) {
  try {
    const packet = await redPacketDetail(packetId)
    const data = (packet && packet.data) || packet || {}
    detail.value = data
    const p = data.packet || {}
    const remain = p.remain_count != null ? p.remain_count : p.remain
    canGrabDetail.value = !!(remain > 0 && !data.rp_detail_locked)
    const mine = (data.records || []).find((r) => (r.user_id | 0) === myId)
    if (mine && mine.amount != null) myGrabAmount.value = formatAmt(mine.amount)
    detailVisible.value = true
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '详情失败', icon: 'none' })
  }
}

async function grabFromDetail() {
  if (!activePacketId) return
  await tryGrab(activePacketId)
  await openDetail(activePacketId)
  await fetchHistory()
}

function editRemark() {
  moreVisible.value = false
  const cur = remark.value || ''
  // #ifdef H5
  if (typeof window !== 'undefined' && typeof window.prompt === 'function') {
    const nextRaw = window.prompt('设置备注（最多32字，留空清除）', cur)
    if (nextRaw === null) return
    saveRemark(String(nextRaw || '').trim().slice(0, 32))
    return
  }
  // #endif
  uni.showModal({
    title: remark.value ? '修改备注' : '设置备注',
    editable: true,
    placeholderText: '最多32字，留空清除',
    content: cur,
    success: (res) => {
      if (!res.confirm) return
      saveRemark(String(res.content || '').trim().slice(0, 32))
    },
  })
}

async function saveRemark(next) {
  try {
    const packet = await setPeerRemark(meta.value.peer, next)
    const data = (packet && packet.data) || packet || {}
    remark.value = String(data.remark || '')
    if (data.peer_nickname) peerNickname.value = String(data.peer_nickname)
    title.value = String(data.title || remark.value || peerNickname.value || title.value)
    uni.showToast({ title: remark.value ? '备注已保存' : '备注已清除', icon: 'none' })
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '备注失败', icon: 'none' })
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
  peerNickname.value = decodeURIComponent(query.nickname || '')
  remark.value = decodeURIComponent(query.remark || '')
  if (isPrivate.value) rpForm.packet_type = 1

  await ensureUser()
  off = onImEvent((type, data) => {
    if (type === 'private.message' || type === 'group.message') {
      const msg = (data && data.message) || data
      if (msg && sameRoom(msg)) {
        const id = msgId(msg)
        if (!messages.value.some((x) => String(msgId(x)) === String(id))) {
          messages.value.push(msg)
          scrollInto.value = 'm' + id
          markRead()
        }
      }
    }
    if (type === 'message.recalled') {
      const msg = (data && data.message) || data
      applyRecalled(msg)
    }
    if (type === 'redpacket.update') {
      fetchHistory().catch(() => {})
    }
  })
  try {
    await imConnect()
    await fetchHistory()
  } catch (e) {
    uni.showToast({ title: e.message || '连接失败', icon: 'none' })
  }
})

onUnload(() => {
  if (off) off()
})
</script>

<style scoped>
.page {
  display: flex;
  flex-direction: column;
  height: 100vh;
  background: #f6f1ea;
}
.nav {
  display: flex;
  align-items: center;
  gap: 12rpx;
  padding: 18rpx 20rpx;
  background: linear-gradient(to right, #e63022, #c61114);
  color: #fff;
}
.nav-back {
  font-size: 48rpx;
  font-weight: 300;
  line-height: 1;
  width: 48rpx;
}
.nav-title {
  flex: 1;
  font-size: 32rpx;
  font-weight: 800;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.nav-more {
  font-size: 40rpx;
  font-weight: 700;
  padding: 0 12rpx;
  line-height: 1;
}
.msgs { flex: 1; padding: 20rpx 24rpx; box-sizing: border-box; }
.row { display: flex; margin: 14rpx 0; }
.row.mine { justify-content: flex-end; }
.row.system { justify-content: center; }
.sys {
  max-width: 90%;
  font-size: 22rpx;
  color: #9a8574;
  background: rgba(255, 255, 255, 0.65);
  padding: 8rpx 18rpx;
  border-radius: 999rpx;
}
.bubble {
  max-width: 75%;
  padding: 16rpx 20rpx;
  background: #fff;
  border-radius: 16rpx;
  font-size: 28rpx;
  color: #2a1f18;
  box-shadow: 0 4rpx 12rpx rgba(40, 20, 10, 0.04);
}
.row.mine .bubble {
  background: #ffe8e6;
  color: #c61114;
}
.rp-card {
  display: flex;
  gap: 16rpx;
  width: 420rpx;
  padding: 20rpx;
  border-radius: 16rpx;
  background: linear-gradient(135deg, #e94b3c 0%, #c61114 58%, #a50f12 100%);
  color: #fff;
  box-shadow: 0 8rpx 18rpx rgba(198, 17, 20, 0.22);
}
.rp-card.faded, .rp-card.grabbed {
  opacity: 0.72;
  filter: grayscale(0.2);
}
.rp-ico {
  width: 64rpx;
  height: 64rpx;
  border-radius: 14rpx;
  background: rgba(255, 255, 255, 0.2);
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 800;
}
.rp-main { flex: 1; min-width: 0; }
.rp-bless { display: block; font-size: 28rpx; font-weight: 800; }
.rp-sub { display: block; margin-top: 6rpx; font-size: 22rpx; opacity: 0.9; }
.emoji-panel {
  height: 360rpx;
  background: #fffaf5;
  border-top: 1px solid #eee;
}
.emoji-scroll { height: 100%; }
.emoji-grid {
  display: flex;
  flex-wrap: wrap;
  padding: 12rpx 8rpx 24rpx;
}
.emoji-item {
  width: 12.5%;
  text-align: center;
  font-size: 40rpx;
  line-height: 72rpx;
}
.composer {
  display: flex;
  gap: 12rpx;
  padding: 16rpx;
  background: #fff;
  border-top: 1px solid #eee;
  align-items: center;
}
.tool {
  margin: 0;
  background: #fff8f0;
  color: #c45a1a;
  border: 1px solid #f0b04a;
}
.input {
  flex: 1;
  height: 72rpx;
  background: #f7f2ec;
  border-radius: 12rpx;
  padding: 0 20rpx;
}
.send { margin: 0; background: #c61114; color: #fff; }
.mask {
  position: fixed;
  inset: 0;
  z-index: 100;
  background: rgba(20, 12, 8, 0.45);
  display: flex;
  align-items: flex-end;
  justify-content: center;
}
.sheet {
  width: 100%;
  max-width: 720rpx;
  background: #fffaf5;
  border-radius: 24rpx 24rpx 0 0;
  padding: 28rpx 28rpx 40rpx;
  box-sizing: border-box;
}
.sheet.detail { max-height: 80vh; }
.sheet-title {
  font-size: 32rpx;
  font-weight: 800;
  text-align: center;
  margin-bottom: 20rpx;
}
.field { margin-bottom: 18rpx; }
.lab {
  display: block;
  font-size: 24rpx;
  color: #8a7a6e;
  margin-bottom: 8rpx;
  font-weight: 700;
}
.hb-input {
  width: 100%;
  box-sizing: border-box;
  background: #fff;
  border: 1.5px solid #f0b04a;
  border-radius: 12rpx;
  padding: 16rpx 20rpx;
  font-size: 28rpx;
}
.tabs { display: flex; gap: 12rpx; }
.tab {
  flex: 1;
  text-align: center;
  padding: 14rpx 0;
  border-radius: 12rpx;
  background: #fff8f0;
  border: 1.5px solid #f0b04a;
  color: #8a4f1f;
  font-size: 24rpx;
  font-weight: 700;
}
.tab.on {
  color: #c61114;
  border-color: #c61114;
  background: #fff;
}
.btn-uid-submit {
  width: 100%;
  margin-top: 8rpx;
  background: linear-gradient(#fff, #fff) padding-box,
    linear-gradient(145deg, #ffe9b0, #f0b04a, #e07a22) border-box;
  border: 1.5px solid transparent;
  color: #3d2e22;
  font-weight: 800;
  border-radius: 14rpx;
  padding: 18rpx;
}
.cancel {
  margin-top: 16rpx;
  background: #fff;
  color: #6a5648;
  border: 1px solid rgba(180, 140, 100, 0.35);
  border-radius: 12rpx;
}
.detail-head { text-align: center; margin-bottom: 16rpx; }
.d-bless { display: block; font-size: 30rpx; font-weight: 800; }
.d-amt { display: block; margin-top: 8rpx; color: #c61114; font-size: 36rpx; font-weight: 800; }
.d-meta { display: block; margin-top: 8rpx; color: #9a8574; font-size: 22rpx; }
.detail-list { max-height: 420rpx; margin-bottom: 12rpx; }
.d-row {
  display: flex;
  justify-content: space-between;
  padding: 16rpx 4rpx;
  border-bottom: 1px solid rgba(224, 122, 34, 0.12);
  font-size: 26rpx;
}
.d-right { font-weight: 700; color: #c61114; }
.empty { text-align: center; color: #9a8574; padding: 24rpx; font-size: 24rpx; }
.more-sub {
  text-align: center;
  color: #8a7a6e;
  font-size: 24rpx;
  margin-bottom: 20rpx;
}
</style>

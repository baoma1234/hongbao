<template>
  <view class="page">
    <TopBar />
    <view class="hero">
      <text class="hero-title">红宝社区</text>
      <view class="hero-actions">
        <view class="hero-icon-btn" @click="toggleSearch">
          <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
            <circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2" />
            <path d="M20 20l-3.5-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
          </svg>
        </view>
        <view class="plus-wrap">
          <view class="hero-icon-btn" @click="plusOpen = !plusOpen">
            <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
              <path fill="currentColor" d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" />
            </svg>
          </view>
          <view v-if="plusOpen" class="plus-menu">
            <view class="plus-item" @click="onPlusAction('scan')"><text class="plus-ico">📷</text><text>扫一扫</text></view>
            <view class="plus-item" @click="onPlusAction('friend')"><text class="plus-ico">👥</text><text>添加好友</text></view>
            <view class="plus-item" @click="onPlusAction('request')"><text class="plus-ico">📨</text><text>好友申请</text></view>
            <view class="plus-item" @click="onPlusAction('share')">
              <image class="plus-ico-img" :src="fxIcon" mode="aspectFit" />
              <text>分享推广</text>
            </view>
          </view>
        </view>
      </view>
    </view>

    <view class="status" :class="status">
      <text>IM · {{ statusText }}</text>
      <button size="mini" class="mini" @click="reconnect">重连</button>
    </view>

    <view class="search-row" v-if="searchOpen">
      <input class="search-input" v-model="keyword" placeholder="搜索会话 / 昵称 / 内容" />
      <button size="mini" class="search-cancel" @click="clearSearch">取消</button>
    </view>

    <view class="home-tabs">
      <view class="home-tab" :class="{ active: homeTab === 'chat' }" @click="switchHomeTab('chat')">聊天</view>
      <view class="home-tab" :class="{ active: homeTab === 'community' }" @click="switchHomeTab('community')">社群</view>
      <view class="home-tab" :class="{ active: homeTab === 'notice' }" @click="switchHomeTab('notice')">公告</view>
      <view class="home-tab" :class="{ active: homeTab === 'commission' }" @click="switchHomeTab('commission')">佣金</view>
    </view>

    <view v-if="homeTab === 'chat'">
      <view class="empty" v-if="!displayList.length && loaded">暂无会话（登录后通常会有客服）</view>
      <view
        v-for="item in displayList"
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

    <view v-else-if="homeTab === 'community'" class="panel card-panel">
      <view class="panel-title">社群推荐</view>
      <view v-for="g in communityRecs" :key="g.id || g.group_id" class="line">{{ g.name || g.title || ('群 ' + (g.group_id || g.id || '')) }}</view>
      <view v-if="!communityRecs.length" class="empty-sm">暂无推荐社群</view>
    </view>

    <view v-else-if="homeTab === 'notice'" class="panel card-panel">
      <view class="seg">
        <view class="seg-btn" :class="{ on: noticeCat==='latest' }" @click="setNoticeCat('latest')">最新发布</view>
        <view class="seg-btn" :class="{ on: noticeCat==='promote' }" @click="setNoticeCat('promote')">推广赚钱</view>
        <view class="seg-btn" :class="{ on: noticeCat==='ads' }" @click="setNoticeCat('ads')">广告发布</view>
        <view class="seg-btn" :class="{ on: noticeCat==='rules' }" @click="setNoticeCat('rules')">游戏规则</view>
      </view>
      <view v-for="n in notices" :key="n.id || n.createtime" class="line">
        <text class="line-title">{{ n.title || n.content || '公告' }}</text>
      </view>
      <view v-if="!notices.length" class="empty-sm">暂无公告</view>
    </view>

    <view v-else class="panel card-panel">
      <view class="commission-head">
        <view>
          <view class="muted">累计佣金</view>
          <view class="amt">¥ {{ money(commission.total_money) }}</view>
        </view>
        <button size="mini" class="mini" @click="goCommission">提现</button>
      </view>
      <view class="commission-stats">
        <view class="stat"><text class="muted">可提现</text><text>¥ {{ money(commission.withdrawable) }}</text></view>
        <view class="stat"><text class="muted">今日收益</text><text>¥ {{ money(commission.today_money) }}</text></view>
        <view class="stat"><text class="muted">红包返佣</text><text>¥ {{ money(commission.rebate_money) }}</text></view>
      </view>
      <view class="line" v-for="(row, idx) in commission.recent || []" :key="row.id || idx">
        <text>{{ row.title || row.scene_text || row.type_text || '结算记录' }}</text>
        <text class="amt-sm">{{ row.amount_text || row.amount || '-' }}</text>
      </view>
      <view v-if="!(commission.recent && commission.recent.length)" class="empty-sm">登录后查看佣金明细</view>
    </view>
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onShow, onHide } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import { apiRequest, getToken } from '../../utils/auth.js'
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
  imSend,
  listConversations,
  onImEvent,
  pinConversation,
} from '../../utils/im.js'

const list = ref([])
const loaded = ref(false)
const status = ref('disconnected')
const localUnread = ref({})
const fxIcon = '/888/img/chat/fx.png'
const homeTab = ref('chat')
const searchOpen = ref(false)
const keyword = ref('')
const plusOpen = ref(false)
const communityRecs = ref([])
const notices = ref([])
const noticeCat = ref('latest')
const commission = ref({})
let off = null
let loading = false

const displayList = computed(() => {
  const q = String(keyword.value || '').trim().toLowerCase()
  if (!q) return list.value
  return list.value.filter((it) => {
    const title = String(displayTitle(it) || '').toLowerCase()
    const nick = String(it.peer_nickname || '').toLowerCase()
    const prev = String(itemPreview(it) || '').toLowerCase()
    return title.indexOf(q) >= 0 || nick.indexOf(q) >= 0 || prev.indexOf(q) >= 0
  })
})

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

function toggleSearch() {
  searchOpen.value = !searchOpen.value
  plusOpen.value = false
}

function clearSearch() {
  keyword.value = ''
  searchOpen.value = false
}

function money(v) {
  const n = Number(v || 0)
  return isFinite(n) ? n.toFixed(2) : '0.00'
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

async function switchHomeTab(tab) {
  homeTab.value = tab
  plusOpen.value = false
  if (tab === 'community') {
    await loadCommunity()
  } else if (tab === 'notice') {
    await loadNotices()
  } else if (tab === 'commission') {
    await loadCommission()
  }
}

function setNoticeCat(cat) {
  noticeCat.value = cat
  loadNotices()
}

async function loadCommunity() {
  try {
    const rec = await apiRequest('communityrecommend', 'GET', {})
    const list = (rec && (rec.list || rec.rows || rec.items)) || rec || []
    communityRecs.value = Array.isArray(list) ? list : []
  } catch (e) {
    communityRecs.value = []
  }
}

async function loadNotices() {
  try {
    const data = await apiRequest('notices', 'GET', { page: 1, limit: 30, category: noticeCat.value })
    const list = (data && (data.list || data.rows || data.items)) || []
    notices.value = Array.isArray(list) ? list : []
  } catch (e) {
    notices.value = []
  }
}

async function loadCommission() {
  try {
    const data = await apiRequest('commission', 'GET', {})
    commission.value = data || {}
  } catch (e) {
    commission.value = {}
  }
}

async function onPlusAction(kind) {
  plusOpen.value = false
  if (kind === 'share') {
    uni.switchTab({ url: '/pages/master/master' })
    return
  }
  if (kind === 'scan') {
    uni.showToast({ title: '扫一扫即将接入', icon: 'none' })
    return
  }
  if (kind === 'friend') {
    try {
      await imSend('friend.list', {}, true)
    } catch (e) {}
    uni.showToast({ title: '添加好友入口即将接入', icon: 'none' })
    return
  }
  uni.showToast({ title: '功能即将接入', icon: 'none' })
}

function goCommission() {
  uni.switchTab({ url: '/pages/profile/profile' })
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
.hero {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 14rpx 24rpx 6rpx;
}
.hero-title {
  font-size: 34rpx;
  font-weight: 800;
  color: #2a1f18;
}
.hero-actions {
  display: flex;
  align-items: center;
  gap: 10rpx;
}
.hero-icon-btn {
  width: 56rpx;
  height: 56rpx;
  border-radius: 14rpx;
  background: #fff;
  color: #5b4335;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 3rpx 10rpx rgba(40, 20, 10, 0.08);
}
.plus-wrap { position: relative; }
.plus-menu {
  position: absolute;
  right: 0;
  top: 64rpx;
  width: 240rpx;
  border-radius: 14rpx;
  background: #fff;
  box-shadow: 0 8rpx 24rpx rgba(20, 10, 5, 0.18);
  z-index: 10;
  padding: 8rpx 0;
}
.plus-item {
  display: flex;
  align-items: center;
  gap: 10rpx;
  padding: 14rpx 16rpx;
  font-size: 24rpx;
  color: #2a1f18;
}
.plus-ico { width: 32rpx; text-align: center; }
.plus-ico-img { width: 26rpx; height: 26rpx; }
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
.search-row {
  margin: 10rpx 24rpx 0;
  display: flex;
  gap: 10rpx;
  align-items: center;
}
.search-input {
  flex: 1;
  background: #fff;
  border-radius: 12rpx;
  padding: 0 18rpx;
  height: 64rpx;
  font-size: 24rpx;
}
.search-cancel { margin: 0; }
.home-tabs {
  margin: 12rpx 24rpx;
  background: #fff;
  border-radius: 14rpx;
  padding: 6rpx;
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 6rpx;
}
.home-tab {
  text-align: center;
  font-size: 24rpx;
  color: #8a7a6e;
  border-radius: 10rpx;
  padding: 12rpx 0;
}
.home-tab.active {
  color: #c61114;
  background: #fff1ef;
  font-weight: 700;
}
.empty {
  text-align: center;
  color: #9a8574;
  padding: 80rpx 24rpx;
  font-size: 26rpx;
}
.panel.card-panel {
  margin: 0 24rpx;
  background: #fff;
  border-radius: 16rpx;
  padding: 20rpx;
}
.panel-title { font-size: 28rpx; font-weight: 800; color: #2a1f18; margin-bottom: 12rpx; }
.line {
  padding: 14rpx 4rpx;
  border-bottom: 1px solid rgba(40, 20, 10, 0.06);
  font-size: 24rpx;
  color: #4a3a30;
  display: flex;
  justify-content: space-between;
  gap: 12rpx;
}
.line:last-child { border-bottom: none; }
.line-title { display: block; line-height: 1.45; }
.empty-sm { color: #9a8574; font-size: 24rpx; text-align: center; padding: 24rpx 0; }
.seg {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 8rpx;
  margin-bottom: 10rpx;
}
.seg-btn {
  text-align: center;
  font-size: 22rpx;
  color: #8a7a6e;
  padding: 10rpx 0;
  border-radius: 10rpx;
  background: #faf6f1;
}
.seg-btn.on { color: #c61114; background: #fff1ef; }
.commission-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12rpx;
}
.muted { color: #9a8574; font-size: 22rpx; }
.amt { font-size: 36rpx; color: #c61114; font-weight: 800; margin-top: 4rpx; }
.commission-stats {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8rpx;
  margin-bottom: 8rpx;
}
.stat {
  background: #faf6f1;
  border-radius: 10rpx;
  padding: 10rpx;
  display: flex;
  flex-direction: column;
  gap: 6rpx;
  font-size: 22rpx;
}
.amt-sm { color: #c61114; font-weight: 700; }
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

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

    <view v-else-if="homeTab === 'community'" class="glass-panel">
      <view class="cg-seg">
        <view class="cg-seg-btn" :class="{ active: communitySub === 'official' }" @click="setCommunitySub('official')">官方社群</view>
        <view class="cg-seg-btn" :class="{ active: communitySub === 'mine' }" @click="setCommunitySub('mine')">我的社群</view>
        <view class="cg-seg-btn" :class="{ active: communitySub === 'friends' }" @click="setCommunitySub('friends')">好友动态</view>
      </view>

      <view v-if="communitySub === 'official'" class="cg-body">
        <view class="group-cards">
          <view
            v-for="(g, idx) in communityRecs"
            :key="g.id || g.group_id"
            class="group-card"
            @click="openGroup(g)"
          >
            <view v-if="!g.is_member && idx === 0" class="group-tag">新建社群</view>
            <view class="group-icon">
              <image v-if="g.avatar" class="group-av" :src="g.avatar" mode="aspectFill" />
              <text v-else>{{ groupEmoji(g.name) }}</text>
            </view>
            <text class="group-title">{{ g.name || ('#' + (g.id || g.group_id)) }}</text>
            <text class="group-sub">{{ groupMembersText(g) }}</text>
          </view>
        </view>
        <view v-if="!communityRecs.length" class="empty-glass">暂无推荐社群</view>
      </view>

      <view v-else-if="communitySub === 'mine'" class="cg-body">
        <view class="my-group create" @click="onCreateGroupHint">
          <view class="my-av plus">+</view>
          <view class="my-main">
            <text class="my-name">+ 创建我的专属保密对战群</text>
            <text class="my-sub">零门槛当群主，躺赚群内 1% 发包管理费津贴</text>
          </view>
        </view>
        <view v-for="g in myGroups" :key="g.id" class="my-group" @click="openGroup(g)">
          <view class="my-av">
            <image v-if="g.avatar" class="group-av" :src="g.avatar" mode="aspectFill" />
            <text v-else>{{ avatarLetter(g.name || '') }}</text>
          </view>
          <text class="my-name">{{ g.name || ('#' + g.id) }}</text>
          <text class="my-count">{{ (g.display_member_count || g.member_count || 0) }}<text class="my-count-sm">人</text></text>
        </view>
        <view v-if="!myGroups.length" class="empty-glass">暂无已加入社群</view>
      </view>

      <view v-else class="cg-body">
        <view v-for="f in friends" :key="f.peer_user_id || f.user_id" class="friend-row" @click="openFriendChat(f)">
          <view class="my-av">
            <image v-if="f.avatar" class="group-av" :src="f.avatar" mode="aspectFill" />
            <text v-else>{{ avatarLetter(f.remark || f.peer_nickname || f.nickname || '') }}</text>
          </view>
          <view class="friend-main">
            <text class="my-name">{{ f.remark || f.peer_nickname || f.nickname || ('ID' + (f.peer_user_id || f.user_id)) }}</text>
            <text class="my-sub">点击进入私聊</text>
          </view>
        </view>
        <view v-if="!friends.length" class="empty-glass">暂无好友，可从「+」添加</view>
      </view>
    </view>

    <view v-else-if="homeTab === 'notice'" class="glass-panel">
      <view class="cg-seg notice-seg">
        <view class="cg-seg-btn" :class="{ active: noticeCat==='latest' }" @click="setNoticeCat('latest')">最新发布</view>
        <view class="cg-seg-btn" :class="{ active: noticeCat==='promote' }" @click="setNoticeCat('promote')">推广赚钱</view>
        <view class="cg-seg-btn" :class="{ active: noticeCat==='ads' }" @click="setNoticeCat('ads')">广告发布</view>
        <view class="cg-seg-btn" :class="{ active: noticeCat==='rules' }" @click="setNoticeCat('rules')">游戏规则</view>
      </view>
      <view v-for="n in notices" :key="n.id || n.createtime" class="notice-card" @click="openNotice(n)">
        <text class="notice-title">{{ n.title || '公告' }}</text>
        <text class="notice-body">{{ noticeSnippet(n) }}</text>
        <text class="notice-time">{{ formatNoticeTime(n) }}</text>
      </view>
      <view v-if="!notices.length" class="empty-glass">暂无公告</view>
    </view>

    <view v-else class="glass-panel commission-panel">
      <view class="comm-hero">
        <view>
          <text class="muted">累计佣金</text>
          <view class="amt">¥ {{ money(commission.total_money) }}</view>
        </view>
        <button size="mini" class="comm-btn" @click="goCommission">提现</button>
      </view>
      <view class="commission-stats">
        <view class="stat glass-stat"><text class="muted">可提现</text><text class="stat-val">¥ {{ money(commission.withdrawable) }}</text></view>
        <view class="stat glass-stat"><text class="muted">今日收益</text><text class="stat-val">¥ {{ money(commission.today_money) }}</text></view>
        <view class="stat glass-stat"><text class="muted">红包返佣</text><text class="stat-val">¥ {{ money(commission.rebate_money) }}</text></view>
      </view>
      <view class="comm-sec-title">最近结算</view>
      <view class="comm-row" v-for="(row, idx) in commission.recent || []" :key="row.id || idx">
        <text class="comm-row-title">{{ row.title || row.scene_text || row.type_text || '结算记录' }}</text>
        <text class="amt-sm">{{ row.amount_text || formatAmt(row.amount) }}</text>
      </view>
      <view v-if="!(commission.recent && commission.recent.length)" class="empty-glass">登录后查看佣金明细</view>
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
  joinGroup,
  listFriends,
  listMyGroups,
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
const communitySub = ref('official')
const communityRecs = ref([])
const myGroups = ref([])
const friends = ref([])
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

function formatAmt(v) {
  if (v == null || v === '') return '-'
  const n = Number(v)
  if (!isFinite(n)) return String(v)
  return (n >= 0 ? '+' : '') + n.toFixed(2)
}

function groupEmoji(name) {
  const n = String(name || '')
  if (/红包|福利/.test(n)) return '🧧'
  if (/保密|私密|元宝|金/.test(n)) return '🪙'
  if (/礼|赠|礼物/.test(n)) return '🎁'
  if (/开放|红宝/.test(n)) return '👑'
  return '🧧'
}

function groupMembersText(g) {
  const n = (g && (g.member_count || g.display_member_count)) | 0
  return n > 0 ? n + '人' : ''
}

function noticeSnippet(n) {
  const t = String((n && (n.summary || n.content || n.body || '')) || '').replace(/<[^>]+>/g, '')
  return t.length > 80 ? t.slice(0, 80) + '…' : t
}

function formatNoticeTime(n) {
  const ts = (n && (n.createtime || n.updatetime || n.time)) | 0
  return formatConvTime(ts)
}

function openNotice(n) {
  const title = (n && n.title) || '公告'
  const body = String((n && (n.content || n.body || n.summary)) || '').replace(/<[^>]+>/g, '')
  uni.showModal({ title, content: body || '暂无详情', showCancel: false })
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

function setCommunitySub(sub) {
  communitySub.value = sub
  if (sub === 'mine' || sub === 'friends') loadCommunityExtra()
}

function setNoticeCat(cat) {
  noticeCat.value = cat
  loadNotices()
}

async function loadCommunity() {
  try {
    const rec = await apiRequest('communityrecommend', 'GET', {})
    const rows = (rec && (rec.list || rec.rows || rec.items)) || rec || []
    communityRecs.value = Array.isArray(rows) ? rows : []
  } catch (e) {
    communityRecs.value = []
  }
  await loadCommunityExtra()
  markMineInRecs()
}

async function loadCommunityExtra() {
  try {
    await imConnect()
    const mine = await listMyGroups()
    const md = (mine && mine.data) || {}
    myGroups.value = md.list || md.items || []
  } catch (e) {
    myGroups.value = []
  }
  try {
    const fr = await listFriends()
    const fd = (fr && fr.data) || {}
    friends.value = fd.list || fd.items || []
  } catch (e) {
    friends.value = []
  }
  markMineInRecs()
}

function markMineInRecs() {
  const mineIds = {}
  ;(myGroups.value || []).forEach((g) => {
    mineIds[g.id | 0] = true
  })
  communityRecs.value = (communityRecs.value || []).map((g) => {
    const id = g.id | 0 || g.group_id | 0
    return Object.assign({}, g, { is_member: !!mineIds[id] || !!g.is_member })
  })
}

async function openGroup(g) {
  const groupId = (g && (g.id || g.group_id)) | 0
  if (!groupId) return
  try {
    if (!g.is_member) {
      await joinGroup(groupId)
      uni.showToast({ title: '已加入社群', icon: 'none' })
      await loadCommunity()
    }
    uni.navigateTo({
      url:
        '/pages/chat/chat?type=2&id=' +
        encodeURIComponent(groupId) +
        '&group=' +
        encodeURIComponent(groupId) +
        '&title=' +
        encodeURIComponent(g.name || ('群' + groupId)),
    })
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '进入社群失败', icon: 'none' })
  }
}

function openFriendChat(f) {
  const peer = (f.peer_user_id || f.user_id) | 0
  const title = f.remark || f.peer_nickname || f.nickname || ('ID' + peer)
  const cid = f.conversation_id || ''
  uni.navigateTo({
    url:
      '/pages/chat/chat?type=1&id=' +
      encodeURIComponent(cid) +
      '&peer=' +
      encodeURIComponent(peer) +
      '&title=' +
      encodeURIComponent(title) +
      '&nickname=' +
      encodeURIComponent(f.peer_nickname || f.nickname || '') +
      '&remark=' +
      encodeURIComponent(f.remark || ''),
  })
}

function onCreateGroupHint() {
  uni.showToast({ title: '建群功能即将接入', icon: 'none' })
}

async function loadNotices() {
  try {
    const data = await apiRequest('notices', 'GET', { page: 1, limit: 30, category: noticeCat.value })
    const rows = (data && (data.list || data.rows || data.items)) || []
    notices.value = Array.isArray(rows) ? rows : []
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
    uni.navigateTo({ url: '/pages/friend/add' })
    return
  }
  if (kind === 'request') {
    uni.navigateTo({ url: '/pages/friend/requests' })
    return
  }
}

function goCommission() {
  uni.navigateTo({ url: '/pages/wallet/withdraw' })
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
.glass-panel {
  margin: 0 16rpx 20rpx;
  padding: 16rpx 14rpx 20rpx;
  border-radius: 18rpx;
  background:
    radial-gradient(120% 80% at 50% -10%, rgba(255, 236, 210, 0.95) 0%, transparent 55%),
    linear-gradient(180deg, #fbf4ea 0%, #f3e6d6 100%);
  min-height: 320rpx;
}
.cg-seg {
  display: flex;
  align-items: stretch;
  height: 72rpx;
  padding: 4rpx;
  box-sizing: border-box;
  border-radius: 999rpx;
  border: 1px solid #f3c9a0;
  background: linear-gradient(180deg, #fffdf9 0%, #fff8ef 100%);
  margin-bottom: 16rpx;
  overflow: hidden;
}
.cg-seg-btn {
  flex: 1;
  min-width: 0;
  text-align: center;
  font-size: 24rpx;
  font-weight: 700;
  color: #6b6b6b;
  border-radius: 999rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0 4rpx;
}
.cg-seg-btn.active {
  color: #fff;
  background: linear-gradient(90deg, #ffb347 0%, #ff7a2f 45%, #e83b1a 100%);
  box-shadow: 0 6rpx 16rpx rgba(232, 59, 26, 0.28);
}
.notice-seg .cg-seg-btn { font-size: 20rpx; }
.cg-body { min-height: 200rpx; }
.group-cards {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10rpx;
}
.group-card {
  position: relative;
  background: rgba(255, 255, 255, 0.62);
  border: 1px solid rgba(240, 176, 74, 0.35);
  border-radius: 16rpx;
  padding: 16rpx 8rpx 14rpx;
  text-align: center;
  box-shadow: 0 8rpx 18rpx rgba(176, 120, 50, 0.1);
}
.group-tag {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  font-size: 16rpx;
  color: #fff;
  background: linear-gradient(90deg, #ff7a2f, #e83b1a);
  border-radius: 16rpx 16rpx 0 0;
  padding: 2rpx 0;
}
.group-icon {
  width: 64rpx;
  height: 64rpx;
  margin: 18rpx auto 10rpx;
  border-radius: 16rpx;
  background: linear-gradient(160deg, #fff8ee, #ffe8cc);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 34rpx;
  overflow: hidden;
}
.group-av { width: 100%; height: 100%; }
.group-title {
  display: block;
  font-size: 20rpx;
  font-weight: 800;
  color: #3d2e22;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.group-sub {
  display: block;
  margin-top: 4rpx;
  font-size: 18rpx;
  color: #9a8574;
}
.my-group, .friend-row {
  display: flex;
  align-items: center;
  gap: 14rpx;
  background: rgba(255, 255, 255, 0.7);
  border: 1px solid rgba(240, 176, 74, 0.28);
  border-radius: 14rpx;
  padding: 16rpx;
  margin-bottom: 10rpx;
}
.my-group.create { border-style: dashed; }
.my-av {
  width: 72rpx;
  height: 72rpx;
  border-radius: 16rpx;
  background: linear-gradient(160deg, #fff5f3, #ffe3df);
  color: #c61114;
  font-weight: 800;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  overflow: hidden;
}
.my-av.plus {
  background: linear-gradient(160deg, #fff8ee, #ffe8cc);
  color: #e07a22;
  font-size: 40rpx;
}
.my-main { flex: 1; min-width: 0; }
.friend-main { flex: 1; min-width: 0; }
.my-name {
  flex: 1;
  min-width: 0;
  font-size: 26rpx;
  font-weight: 800;
  color: #2a1f18;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.my-sub {
  display: block;
  margin-top: 4rpx;
  font-size: 20rpx;
  color: #9a8574;
}
.my-count { font-size: 26rpx; color: #c61114; font-weight: 800; flex-shrink: 0; }
.my-count-sm { font-size: 18rpx; font-weight: 600; color: #9a8574; margin-left: 2rpx; }
.notice-card {
  background: rgba(255, 255, 255, 0.72);
  border: 1px solid rgba(240, 176, 74, 0.28);
  border-radius: 14rpx;
  padding: 18rpx;
  margin-bottom: 12rpx;
  box-shadow: 0 6rpx 14rpx rgba(176, 120, 50, 0.08);
}
.notice-title {
  display: block;
  font-size: 28rpx;
  font-weight: 800;
  color: #2a1f18;
}
.notice-body {
  display: block;
  margin-top: 8rpx;
  font-size: 22rpx;
  color: #6b5a4c;
  line-height: 1.45;
}
.notice-time {
  display: block;
  margin-top: 10rpx;
  font-size: 20rpx;
  color: #9a8574;
}
.commission-panel { padding-top: 20rpx; }
.comm-hero {
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: rgba(255, 255, 255, 0.72);
  border: 1px solid rgba(240, 176, 74, 0.3);
  border-radius: 16rpx;
  padding: 20rpx;
  margin-bottom: 12rpx;
}
.comm-btn {
  margin: 0;
  background: linear-gradient(90deg, #ffb347, #e83b1a);
  color: #fff;
  border: none;
  font-weight: 700;
}
.muted { color: #9a8574; font-size: 22rpx; }
.amt { font-size: 40rpx; color: #c61114; font-weight: 800; margin-top: 4rpx; }
.commission-stats {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8rpx;
  margin-bottom: 14rpx;
}
.stat {
  background: rgba(255, 255, 255, 0.7);
  border-radius: 12rpx;
  padding: 12rpx;
  display: flex;
  flex-direction: column;
  gap: 6rpx;
  font-size: 22rpx;
  border: 1px solid rgba(240, 176, 74, 0.22);
}
.stat-val { color: #3d2e22; font-weight: 700; }
.comm-sec-title {
  font-size: 24rpx;
  font-weight: 800;
  color: #3d2e22;
  margin: 8rpx 4rpx 10rpx;
}
.comm-row {
  display: flex;
  justify-content: space-between;
  gap: 12rpx;
  padding: 14rpx 12rpx;
  background: rgba(255, 255, 255, 0.55);
  border-radius: 12rpx;
  margin-bottom: 8rpx;
  font-size: 24rpx;
  color: #4a3a30;
}
.comm-row-title { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.amt-sm { color: #c61114; font-weight: 700; flex-shrink: 0; }
.empty-glass {
  text-align: center;
  color: #9a8574;
  font-size: 24rpx;
  padding: 48rpx 12rpx;
  background: rgba(255, 255, 255, 0.45);
  border-radius: 14rpx;
  border: 1px dashed rgba(240, 176, 74, 0.35);
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

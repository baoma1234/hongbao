<template>
  <view>
    <TopBar />
    <view id="tabMessages" class="tab-page active">
      <view class="chat-shell">
        <view class="chat-list-pane">
          <view class="chat-hero-hd chat-list-hero">
            <view class="chat-hero-title">红宝社区</view>
            <view class="chat-list-actions">
              <view class="chat-hero-icon-btn" @click="toggleSearch">
                <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
                  <circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2" />
                  <path d="M20 20l-3.5-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
              </view>
              <view class="chat-plus-menu-wrap">
                <view class="chat-hero-icon-btn" @click="plusOpen = !plusOpen">
                  <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
                    <path fill="currentColor" d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" />
                  </svg>
                </view>
                <view v-if="plusOpen" class="chat-plus-menu">
                  <view class="chat-plus-menu-item" @click="onPlusAction('scan')">
                    <image class="chat-plus-menu-ico-img" :src="icoScan" mode="aspectFit" />
                    <text>扫一扫</text>
                  </view>
                  <view class="chat-plus-menu-item" @click="onPlusAction('friend')">
                    <image class="chat-plus-menu-ico-img" :src="icoAddFriend" mode="aspectFit" />
                    <text>添加好友</text>
                  </view>
                  <view class="chat-plus-menu-item" @click="onPlusAction('request')">
                    <image class="chat-plus-menu-ico-img" :src="icoFriendReq" mode="aspectFit" />
                    <text>好友申请</text>
                    <text v-if="friendReqPending > 0" class="chat-friend-req-badge">{{ friendReqPending > 99 ? '99+' : friendReqPending }}</text>
                  </view>
                  <view class="chat-plus-menu-item" @click="onPlusAction('share')">
                    <image class="chat-plus-menu-ico-img" :src="fxIcon" mode="aspectFit" />
                    <text>分享推广</text>
                  </view>
                </view>
              </view>
            </view>
          </view>

          <view class="chat-list-main">
            <view class="chat-conn" :class="connClass">IM · {{ statusText }} · <text class="chat-reconnect" @click="reconnect">重连</text></view>

            <view class="chat-home-search-area">
              <view v-if="searchOpen" class="chat-home-search-row">
                <view class="chat-home-search-box">
                  <input class="chat-home-search-input" v-model="keyword" placeholder="搜索会话 / 昵称 / 内容" />
                </view>
                <view class="chat-home-search-cancel" @click="clearSearch">取消</view>
              </view>
              <view class="chat-home-tabs-row">
                <view class="chat-home-tab" :class="{ active: homeTab === 'chat' }" @click="switchHomeTab('chat')">聊天</view>
                <view class="chat-home-tab" :class="{ active: homeTab === 'community' }" @click="switchHomeTab('community')">社群</view>
                <view class="chat-home-tab" :class="{ active: homeTab === 'notice' }" @click="switchHomeTab('notice')">公告</view>
                <view class="chat-home-tab" :class="{ active: homeTab === 'commission' }" @click="switchHomeTab('commission')">佣金</view>
              </view>
            </view>

            <!-- 聊天 -->
            <view class="chat-home-panel" :class="{ 'is-hidden': homeTab !== 'chat' }">
              <view class="chat-conv-list">
                <view class="chat-empty" v-if="!displayList.length && loaded">暂无会话（登录后通常会有客服）</view>
                <view
                  v-for="item in displayList"
                  :key="itemKey(item)"
                  class="chat-conv-item"
                  :class="{ 'is-pinned': !!item.pinned, 'is-admin': !!item.is_im_admin }"
                  @click="openChat(item)"
                  @longpress="onLongPress(item)"
                >
                  <view class="chat-conv-avatar" :class="{ group: (item.conversation_type | 0) === 2, admin: !!item.is_im_admin }">
                    <image v-if="item.avatar" :src="item.avatar" mode="aspectFill" />
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
            </view>

            <!-- 社群 -->
            <view class="chat-home-panel chat-community-glass" :class="{ 'is-hidden': homeTab !== 'community' }">
              <view class="chat-community-seg">
                <view class="chat-community-seg-btn" :class="{ active: communitySub === 'official' }" @click="setCommunitySub('official')">官方社群</view>
                <view class="chat-community-seg-btn" :class="{ active: communitySub === 'mine' }" @click="setCommunitySub('mine')">我的群组</view>
                <view class="chat-community-seg-btn" :class="{ active: communitySub === 'friends' }" @click="setCommunitySub('friends')">好友列表</view>
              </view>

              <view v-if="communitySub === 'official'" class="chat-community-pane active">
                <view class="chat-community-hero-cards">
                  <view
                    v-for="(g, idx) in communityRecs"
                    :key="g.id || g.group_id"
                    class="chat-group-card"
                    @click="openGroup(g)"
                  >
                    <view v-if="!g.is_member && (idx === 0 || g.recommend_tag)" class="tag">{{ g.recommend_tag || '新建社群' }}</view>
                    <view class="icon-box">
                      <image v-if="g.avatar" :src="g.avatar" mode="aspectFill" />
                      <text v-else>{{ groupEmoji(g.name) }}</text>
                    </view>
                    <view class="title">{{ g.name || ('#' + (g.id || g.group_id)) }}</view>
                    <view class="subtitle">{{ groupMembersText(g) }}</view>
                  </view>
                  <view v-if="!communityRecs.length" class="chat-empty chat-empty-glass">暂无推荐社群</view>
                </view>
              </view>

              <view v-else-if="communitySub === 'mine'" class="chat-community-pane active">
                <view class="chat-community-glass-panel chat-community-pane-body">
                  <view class="chat-my-groups-list">
                    <view class="chat-my-group-item chat-my-group-create" @click="onCreateGroupHint">
                      <view class="chat-my-group-main">
                        <view class="chat-my-group-avatar chat-my-group-avatar-plus">+</view>
                        <view class="chat-my-group-create-text">
                          <text class="chat-my-group-name">+ 创建我的专属保密对战群</text>
                          <text class="chat-my-group-sub">零门槛当群主，躺赚群内 1% 发包管理费津贴</text>
                        </view>
                      </view>
                    </view>
                    <view v-for="g in myGroups" :key="g.id" class="chat-my-group-item" @click="openGroup(g)">
                      <view class="chat-my-group-main">
                        <view class="chat-my-group-avatar">
                          <image v-if="g.avatar" :src="g.avatar" mode="aspectFill" />
                          <text v-else>{{ avatarLetter(g.name || '') }}</text>
                        </view>
                        <text class="chat-my-group-name">{{ g.name || ('#' + g.id) }}</text>
                      </view>
                      <view class="chat-my-group-count">{{ g.display_member_count || g.member_count || 0 }}<text>人</text></view>
                    </view>
                    <view v-if="!myGroups.length" class="chat-empty chat-empty-glass">暂无已加入社群</view>
                  </view>
                </view>
              </view>

              <view v-else class="chat-community-pane active">
                <view class="chat-community-glass-panel chat-community-pane-body">
                  <view class="chat-friend-feed-list">
                    <view
                      v-for="f in friends"
                      :key="f.peer_user_id || f.user_id"
                      class="chat-feed-item"
                      :class="{ 'is-pinned-cs': !!(f.is_default_cs || f.pinned) }"
                      @click="openFriendChat(f)"
                    >
                      <view class="chat-feed-avatar">
                        <image v-if="f.avatar" :src="f.avatar" mode="aspectFill" />
                        <text v-else>{{ avatarLetter(friendName(f)) }}</text>
                        <view class="chat-feed-online-dot" :class="{ off: !f.online }" />
                      </view>
                      <view class="chat-feed-body">
                        <view class="chat-feed-text">
                          <text v-if="f.is_default_cs || f.pinned" class="chat-feed-pin">📌</text>
                          {{ friendName(f) }}
                        </view>
                        <view class="chat-feed-status" :class="{ on: !!f.online }">{{ f.online ? '刚刚在线' : '暂时离开' }}</view>
                      </view>
                    </view>
                    <view v-if="!friends.length" class="chat-empty chat-empty-glass">暂无好友</view>
                  </view>
                </view>
              </view>
            </view>

            <!-- 公告 -->
            <view class="chat-home-panel chat-notice-feed-panel" :class="{ 'is-hidden': homeTab !== 'notice' }">
              <view class="chat-community-seg chat-notice-seg">
                <view class="chat-community-seg-btn" :class="{ active: noticeCat === 'latest' }" @click="setNoticeCat('latest')">最新发布</view>
                <view class="chat-community-seg-btn" :class="{ active: noticeCat === 'promote' }" @click="setNoticeCat('promote')">推广赚钱</view>
                <view class="chat-community-seg-btn" :class="{ active: noticeCat === 'ads' }" @click="setNoticeCat('ads')">广告发布</view>
                <view class="chat-community-seg-btn" :class="{ active: noticeCat === 'rules' }" @click="setNoticeCat('rules')">游戏规则</view>
              </view>
              <view class="chat-notice-body-scroll">
                <view class="chat-notice-feed">
                  <view v-for="n in notices" :key="n.id || n.createtime" class="chat-notice-card" @click="openNotice(n)">
                    <view class="chat-notice-hd">
                      <image v-if="n.author_avatar" class="chat-notice-avatar" :src="n.author_avatar" mode="aspectFill" />
                      <view v-else class="chat-notice-avatar chat-notice-avatar-fallback">📢</view>
                      <view class="chat-notice-meta">
                        <view class="chat-notice-name-row">
                          <text class="chat-notice-name">{{ n.author_name || '红宝官方公告' }}</text>
                          <text class="chat-notice-day">{{ noticeRelativeDay(n) }}</text>
                          <text v-if="noticeCatLabel(n)" class="chat-notice-tag">【{{ noticeCatLabel(n) }}】</text>
                        </view>
                      </view>
                      <view class="chat-notice-time">{{ noticeClock(n) }}</view>
                    </view>
                    <view class="chat-notice-body">{{ n.content || n.summary || n.title || '' }}</view>
                  </view>
                  <view v-if="!notices.length" class="chat-empty chat-empty-glass">暂无公告</view>
                </view>
              </view>
            </view>

            <!-- 佣金 -->
            <view class="chat-home-panel chat-commission-panel" :class="{ 'is-hidden': homeTab !== 'commission' }">
              <view class="chat-commission-hero-card">
                <view class="chat-commission-hero-top">
                  <text class="chat-commission-hero-label">累计佣金</text>
                  <view class="chat-commission-withdraw-btn" @click="goCommission">提现</view>
                </view>
                <view class="chat-commission-hero-value">¥ {{ money(commission.total_money) }}</view>
                <view class="chat-commission-hero-stats">
                  <view class="chat-commission-stat">
                    <text class="chat-commission-stat-label">可提现</text>
                    <text class="chat-commission-stat-value">¥ {{ money(commission.withdrawable) }}</text>
                  </view>
                  <view class="chat-commission-stat-divider" />
                  <view class="chat-commission-stat">
                    <text class="chat-commission-stat-label">今日收益</text>
                    <text class="chat-commission-stat-value">¥ {{ money(commission.today_money) }}</text>
                  </view>
                  <view class="chat-commission-stat-divider" />
                  <view class="chat-commission-stat">
                    <text class="chat-commission-stat-label">红包返佣</text>
                    <text class="chat-commission-stat-value">¥ {{ money(commission.rebate_money) }}</text>
                  </view>
                </view>
              </view>

              <view class="chat-commission-nav-grid">
                <view class="chat-commission-nav-item" @click="goCommissionNav('promo')">
                  <view class="chat-commission-nav-ico">
                    <svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm4 18H6V4h7v5h5v11zm-3.5-5.5l-1.4 1.4L11 13.8l-2.1 2.1-1.4-1.4L9.6 12.4 7.5 10.3l1.4-1.4L11 11l2.1-2.1 1.4 1.4-2.1 2.1 2.1 2.1z"/></svg>
                  </view>
                  <text class="chat-commission-nav-label">推广结算 ›</text>
                </view>
                <view class="chat-commission-nav-item" @click="goCommissionNav('rebate')">
                  <view class="chat-commission-nav-ico">
                    <svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm1 15H9v-2h6v2zm0-4H9v-2h6v2zm-3-5V3.5L18.5 9H12z"/></svg>
                  </view>
                  <text class="chat-commission-nav-label">红包返佣 ›</text>
                </view>
                <view class="chat-commission-nav-item" @click="goCommissionNav('ledger')">
                  <view class="chat-commission-nav-ico">
                    <svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 14h-2v-2h2v2zm0-4h-2V7h2v5z"/></svg>
                  </view>
                  <text class="chat-commission-nav-label">收益明细 ›</text>
                </view>
                <view class="chat-commission-nav-item" @click="goCommissionNav('withdraw_list')">
                  <view class="chat-commission-nav-ico">
                    <svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 14l-5-5 1.41-1.41L11 13.17V7h2v6.17l2.59-2.58L17 12l-5 5z"/></svg>
                  </view>
                  <text class="chat-commission-nav-label">提现记录 ›</text>
                </view>
              </view>

              <view class="chat-commission-recent-card">
                <view class="chat-commission-recent-hd">最近结算</view>
                <view class="chat-commission-list">
                  <view class="chat-commission-row" v-for="(row, idx) in commission.recent || []" :key="row.id || idx">
                    <view class="chat-commission-row-main">
                      <view class="chat-commission-row-title">{{ row.title || row.scene_text || row.type_text || '结算记录' }}</view>
                      <view class="chat-commission-row-time">{{ formatNoticeTime(row) }}</view>
                    </view>
                    <view class="chat-commission-row-amt">{{ row.amount_text || formatAmt(row.amount) }}</view>
                  </view>
                  <view v-if="!(commission.recent && commission.recent.length)" class="chat-empty chat-empty-glass">登录后查看佣金明细</view>
                </view>
              </view>
            </view>
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
import '../../styles/chat.bundle.css'
import '../../styles/chat-uni-adapter.css'
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
  friendRequests,
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
const icoScan = '/888/img/chat/plus_scan.png'
const icoAddFriend = '/888/img/chat/plus_add_friend.png'
const icoFriendReq = '/888/img/chat/plus_friend_req.png'
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
const friendReqPending = ref(0)
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

const connClass = computed(() => {
  if (status.value === 'online') return 'ok'
  if (status.value === 'connecting') return ''
  return 'err'
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

function friendName(f) {
  return f.remark || f.peer_nickname || f.nickname || ('ID' + (f.peer_user_id || f.user_id || ''))
}

function noticeCatLabel(n) {
  const c = String((n && n.category) || noticeCat.value || '')
  if (c === 'promote') return '推广赚钱'
  if (c === 'ads') return '广告发布'
  if (c === 'rules') return '游戏规则'
  if (c === 'latest') return '最新发布'
  return n.category_label || ''
}

function noticeTs(n) {
  return (n && (n.publishtime || n.createtime || n.updatetime || n.time)) | 0
}

function noticeRelativeDay(n) {
  const ts = noticeTs(n)
  if (!ts) return ''
  const d = new Date(ts * (ts > 1e12 ? 1 : 1000))
  const now = new Date()
  const start = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  const day = new Date(d.getFullYear(), d.getMonth(), d.getDate())
  const diff = Math.round((start - day) / 86400000)
  if (diff === 0) return '今天'
  if (diff === 1) return '昨天'
  if (diff < 7) return diff + '天前'
  return formatConvTime(ts)
}

function noticeClock(n) {
  const ts = noticeTs(n)
  if (!ts) return ''
  const d = new Date(ts * (ts > 1e12 ? 1 : 1000))
  const hh = String(d.getHours()).padStart(2, '0')
  const mm = String(d.getMinutes()).padStart(2, '0')
  return hh + ':' + mm
}

function formatNoticeTime(n) {
  return formatConvTime(noticeTs(n))
}

function openNotice(n) {
  const title = (n && (n.author_name || n.title)) || '公告'
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
                else if (item.peer_user_id) extra.to_user_id = item.peer_user_id
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
  if (tab === 'community') await loadCommunity()
  else if (tab === 'notice') await loadNotices()
  else if (tab === 'commission') await loadCommission()
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
    const id = (g.id | 0) || (g.group_id | 0)
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
  const title = friendName(f)
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

async function loadFriendReqBadge() {
  try {
    await imConnect()
    const packet = await friendRequests()
    const data = (packet && packet.data) || {}
    friendReqPending.value = data.pending_count | 0
  } catch (e) {
    friendReqPending.value = 0
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
  }
}

function goCommission() {
  uni.navigateTo({ url: '/pages/wallet/withdraw' })
}

function goCommissionNav(kind) {
  if (kind === 'withdraw_list' || kind === 'ledger') {
    uni.navigateTo({ url: '/pages/wallet/ledger' })
    return
  }
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

function applyPageShell(on) {
  // #ifdef H5
  try {
    if (typeof document !== 'undefined') {
      document.body.classList.toggle('tab-messages', !!on)
      document.documentElement.style.setProperty('--top-bar-height', '44px')
    }
  } catch (e) {}
  // #endif
}

onShow(() => {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  applyPageShell(true)
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
    if (type === 'friend.request' || type === 'friend.accepted' || type === 'friend.rejected') {
      loadFriendReqBadge()
    }
  })
  loadList()
  loadFriendReqBadge()
})

onHide(() => {
  applyPageShell(false)
  if (off) {
    off()
    off = null
  }
})
</script>

<style scoped>
.chat-reconnect {
  color: var(--color-primary-start, #e63022);
  font-weight: 700;
  text-decoration: underline;
}
</style>

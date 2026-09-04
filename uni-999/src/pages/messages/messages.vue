<template>
  <view class="messages-page messages-page--qq-me">
    <TopBar title="消息" />
    <!-- 公共 TopBar 下方：头像 + 昵称 + ID + 加号 -->
    <view class="qq-msg-nav">
      <view class="qq-msg-nav-inner">
        <view class="qq-msg-me" hover-class="qq-msg-me--hit" @click="goMyProfile">
          <view class="qq-msg-avatar-wrap">
            <image class="qq-msg-avatar" :src="myAvatarSrc" mode="aspectFill" />
            <view class="qq-msg-online-dot" />
          </view>
          <view class="qq-msg-me-meta">
            <text class="qq-msg-nick">{{ myNickText }}</text>
            <text class="qq-msg-id">ID {{ myUidText }}</text>
          </view>
        </view>
        <view class="chat-plus-menu-wrap">
          <view class="qq-msg-plus" hover-class="qq-msg-plus--hit" @click.stop="plusOpen = !plusOpen">
            <text class="qq-msg-plus-char">+</text>
          </view>
          <view
            v-if="plusOpen"
            class="chat-plus-menu-mask"
            @click="plusOpen = false"
          />
          <view v-if="plusOpen" class="chat-plus-menu chat-plus-menu--qq" @click.stop>
            <view class="chat-plus-menu-item" @click="onPlusAction('search')">
              <text>搜索</text>
            </view>
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
            <view v-if="canCreateGroup" class="chat-plus-menu-item" @click="onPlusAction('createGroup')">
              <image class="chat-plus-menu-ico-img" :src="icoCreateGroup" mode="aspectFit" />
              <text>建群</text>
            </view>
          </view>
        </view>
      </view>
    </view>

    <!-- 搜索条：放在资料条下方，避免被会话列表盖住 -->
    <view v-if="searchOpen" class="qq-msg-search">
      <view class="qq-msg-search-box">
        <input
          class="qq-msg-search-input"
          v-model="keyword"
          focus
          placeholder="搜索会话 / 昵称 / 内容"
          confirm-type="search"
        />
      </view>
      <text class="qq-msg-search-cancel" @click="clearSearch">取消</text>
    </view>

    <view
      id="tabMessages"
      class="tab-page active msg-tab-root"
      :style="tabRootStyle"
    >
      <view class="chat-shell">
        <view class="chat-list-pane">
          <view class="chat-list-main">
            <!-- 聊天 -->
            <view
              id="chatHomePanelChat"
              class="chat-home-panel"
              :style="panelHostStyle"
            >
              <view class="chat-conv-ptr-host" :style="panelHostStyle">
                <view class="chat-conv-ptr" :class="{ refreshing: listRefreshing, ready: listRefreshing }">
                  <view class="chat-conv-ptr-inner">
                    <view class="chat-conv-ptr-spinner" />
                    <text class="chat-conv-ptr-text">{{ listRefreshing ? '刷新中…' : '下拉刷新' }}</text>
                  </view>
                </view>
                <scroll-view
                  scroll-y
                  class="chat-conv-scroll"
                  :style="panelScrollStyle"
                  :show-scrollbar="false"
                  :enable-flex="true"
                  :refresher-enabled="convRefresherEnabled"
                  :refresher-triggered="listRefreshing"
                  @refresherrefresh="onListRefresh"
                  @scroll="onConvScroll"
                >
                  <view class="chat-conv-list">
                    <view class="chat-empty" v-if="!displayList.length && loaded">暂无会话</view>
                    <view
                      v-for="item in displayList"
                      :key="itemKey(item)"
                      class="chat-conv-swipe"
                      :class="{
                        open: swipeOpenKey === itemKey(item),
                        'is-dragging': swipeDragKey === itemKey(item),
                      }"
                    >
                      <view class="chat-conv-swipe-actions">
                        <view
                          class="chat-conv-swipe-btn chat-conv-swipe-pin"
                          @click.stop="onSwipePin(item)"
                        >
                          <text class="chat-conv-swipe-lab">{{ item.pinned ? '取消置顶' : '置顶' }}</text>
                        </view>
                        <view
                          class="chat-conv-swipe-btn chat-conv-swipe-del"
                          @click.stop="onSwipeDelete(item)"
                        >
                          <text class="chat-conv-swipe-lab">删除</text>
                        </view>
                      </view>
                      <view
                        class="chat-conv-item"
                        :class="{ 'is-pinned': !!item.pinned, 'is-admin': !!item.is_im_admin }"
                        :style="swipeFrontStyle(item)"
                        @touchstart="onSwipeTouchStart($event, item)"
                        @touchmove="onSwipeTouchMove($event, item)"
                        @touchend="onSwipeTouchEnd($event, item)"
                        @touchcancel="onSwipeTouchEnd($event, item)"
                        @click="onConvClick(item)"
                      >
                        <view class="chat-avatar" :class="{ group: (item.conversation_type | 0) === 2, admin: !!item.is_im_admin }">
                          <image :src="avatarSrc(item.avatar_url || item.avatar)" mode="aspectFill" lazy-load />
                          <view v-if="unreadOf(item) > 0" class="chat-badge chat-badge--on-avatar">
                            {{ unreadOf(item) > 99 ? '99+' : unreadOf(item) }}
                          </view>
                        </view>
                        <view class="chat-conv-body">
                          <view class="chat-conv-title">
                            <view class="chat-conv-title-main">
                              <text class="chat-conv-name">{{ displayTitle(item) }}</text>
                              <text v-if="item.is_im_admin" class="chat-admin-tag">客服</text>
                            </view>
                            <text class="chat-conv-time">{{ itemTime(item) }}</text>
                          </view>
                          <view class="chat-conv-preview">{{ itemPreview(item) }}</view>
                        </view>
                      </view>
                    </view>
                    <view class="chat-list-scroll-pad" aria-hidden="true">
                      <text class="chat-list-scroll-pad-mark"> </text>
                    </view>
                  </view>
                </scroll-view>
              </view>
            </view>

          </view>
        </view>
      </view>
    </view>

    <!-- 创建新群聊（对齐 888 chatCreateGroupPane） -->
    <view
      class="chat-create-group-pane"
      :class="{ open: createGroupOpen, 'cg-app-fix': createGroupAppFix }"
      :aria-hidden="createGroupOpen ? 'false' : 'true'"
      :style="createGroupPaneStyle"
    >
      <view class="chat-cg-header">
        <view class="chat-cg-back" hover-class="chat-cg-back--hit" @click="closeCreateGroupPane">
          <text class="chat-cg-back-ico">‹</text>
          <text class="chat-cg-back-lab">返回</text>
        </view>
        <text class="chat-cg-title">创建新群聊</text>
        <view
          class="chat-cg-next-top"
          :class="{ 'is-disabled': createGroupSubmitting }"
          @click="submitCreateGroup"
        >下一步</view>
      </view>
      <view class="chat-cg-main">
        <view class="chat-cg-input-group">
          <view class="chat-cg-avatar" @click="cycleCreateGroupAvatar">{{ createGroupAvatar }}</view>
          <view class="chat-cg-input-box">
            <input
              class="chat-cg-name-input"
              type="text"
              maxlength="64"
              v-model="createGroupName"
              placeholder="请输入群名称"
              confirm-type="done"
              @confirm="submitCreateGroup"
            />
            <view
              class="chat-cg-next-inner"
              :class="{ 'is-disabled': createGroupSubmitting }"
              @click="submitCreateGroup"
            >下一步</view>
          </view>
        </view>

        <view class="chat-cg-section-title"><text>群类型</text></view>
        <view class="chat-cg-cards">
          <view
            class="chat-cg-card"
            :class="{ active: createGroupPrivacy === 'open' }"
            @click="createGroupPrivacy = 'open'"
          >
            <view class="chat-cg-card-header">
              <text class="chat-cg-radio" />
              <text>开放群</text>
            </view>
            <view class="chat-cg-card-body">
              <text class="chat-cg-dot" />
              <text>可查看成员资料，支持自由加好友</text>
            </view>
          </view>
          <view
            class="chat-cg-card"
            :class="{ active: createGroupPrivacy === 'private' }"
            @click="createGroupPrivacy = 'private'"
          >
            <view class="chat-cg-card-header">
              <text class="chat-cg-radio" />
              <text>隐私群</text>
            </view>
            <view class="chat-cg-card-body">
              <text class="chat-cg-dot" />
              <text>隐藏成员列表，陌生人不可互加</text>
            </view>
          </view>
        </view>

        <view class="chat-cg-section-title"><text>运行模式</text></view>
        <view class="chat-cg-cards">
          <view
            class="chat-cg-card"
            :class="{ 'active-light': createGroupMode === 'chat' }"
            @click="createGroupMode = 'chat'"
          >
            <view class="chat-cg-card-header">
              <text class="chat-cg-radio" />
              <text>聊天模式</text>
            </view>
            <view class="chat-cg-card-body">
              <text class="chat-cg-dot" />
              <text>自由聊天，可发普通/手气/埋雷红包</text>
            </view>
          </view>
          <view
            class="chat-cg-card"
            :class="{ active: createGroupMode === 'grab' }"
            @click="createGroupMode = 'grab'"
          >
            <view class="chat-cg-card-header">
              <text class="chat-cg-radio" />
              <text>红包对战模式</text>
            </view>
            <view class="chat-cg-card-body">
              <text class="chat-cg-dot" />
              <text>仅管理员可发红宝；发言限制请在群设置「禁止模式」配置</text>
            </view>
          </view>
        </view>

      </view>
    </view>


    <FriendScanSheet />

    <!-- 红宝页运营弹窗：仅海报图 + 下方关闭（H5 / Safari / APK / IPA） -->
    <view
      v-if="msgPopupOpen && msgPopup && msgPopupImage"
      class="msg-popup-mask"
      @click="dismissMsgPopup('dismiss_day')"
      @touchmove.stop.prevent="noopPopup"
    >
      <view class="msg-popup-wrap" @click.stop>
        <image
          class="msg-popup-img"
          :src="msgPopupImage"
          mode="widthFix"
          :show-menu-by-longpress="true"
          @click="clickMsgPopup"
        />
        <view
          class="msg-popup-close"
          hover-class="msg-popup-close--active"
          :hover-stay-time="80"
          @click="dismissMsgPopup('dismiss_day')"
        >×</view>
        <view
          v-if="msgPopup.show_mode !== 'once'"
          class="msg-popup-mute"
          hover-class="msg-popup-mute--active"
          :hover-stay-time="80"
          @click="dismissMsgPopup('dismiss_day')"
        >今日不再显示</view>
      </view>
    </view>

    <BottomTabBar active="messages" />
  </view>
</template>

<script setup>
import { computed, nextTick, ref } from 'vue'
import { onShow, onHide } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import BottomTabBar from '../../components/BottomTabBar.vue'
import FriendScanSheet from '../../components/FriendScanSheet.vue'
import '../../styles/chat-messages-list.css'
import '../../styles/chat-uni-adapter.css'
import '../../styles/chat-messages-parity.css'
import '../../styles/chat-qq-theme.css'
import { apiRequest, fetchProfile, getToken } from '../../utils/auth.js'
import { applySafeAreaCssVars, getSafeAreaInsets, getTopBarContentHeight } from '../../utils/safe-area.js'
import {
  avatarSrc,
  convKey,
  displayTitle,
  formatConvTime,
  previewText,
  publicUrl,
  resolveConvId,
} from '../../utils/chat.js'
import { packagedStaticUrl } from '../../utils/config.js'
import { openFriendScanSheet } from '../../utils/friend-scan.js'
import { saveActiveChat } from '../../utils/chat-route.js'
import { captureGroupJoinFromUrl, tryConsumeGroupJoin } from '../../utils/group-invite.js'
import {
  canCreateGroupFromAuth,
  createGroup,
  friendRequests,
  getImAuthMeta,
  getImStatus,
  hideConversation,
  imConnect,
  imForceReconnect,
  listMyGroups,
  listConversations,
  markConversationRead,
  onImEvent,
  pinConversation,
  resumeFromBackground,
  bindForegroundResume,
} from '../../utils/im.js'
import {
  getInboxUnread,
  getReadWatermark,
  isConversationRecentlyRead,
  noteConversationRead,
  setInboxMyId,
  startImInbox,
  syncInboxFromServerList,
} from '../../utils/im-inbox.js'
import { setChatUnreadTotal } from '../../utils/tab-badge.js'

const CREATE_GROUP_AVATARS = ['🐵', '🐼', '🦊', '🐯', '🦁', '🐶', '🐱', '🐰', '🐻', '🐨', '🐸', '🐷']
let chatSubpkgPrefetched = false

function prefetchChatSubpackages() {
  if (chatSubpkgPrefetched) return
  chatSubpkgPrefetched = true
  const urls = ['/pages/chat/chat', '/pages/friend/add', '/pages/friend/requests']
  urls.forEach((url) => {
    try {
      if (typeof uni.preloadPage === 'function') {
        uni.preloadPage({ url })
      }
    } catch (e) {}
  })
  // #ifdef H5
  // Vite 异步 chunk 预热（与 preloadPage 互补）
  try {
    import('../chat/chat.vue').catch(() => {})
  } catch (e) {}
  // #endif
}

const list = ref([])
const loaded = ref(false)
const status = ref('disconnected')
const localUnread = ref({})
const fxIcon = packagedStaticUrl('chat/fx.png')
const icoScan = packagedStaticUrl('chat/plus_scan.png')
const icoAddFriend = packagedStaticUrl('chat/plus_add_friend.png')
const icoFriendReq = packagedStaticUrl('chat/plus_friend_req.png')
const icoCreateGroup = packagedStaticUrl('chat/plus_create_group.png')
/** 红宝页运营弹窗队列 */
const msgPopupQueue = ref([])
const msgPopup = ref(null)
const msgPopupOpen = ref(false)
const msgPopupImage = computed(() => {
  const p = msgPopup.value
  const imgs = (p && p.images) || []
  const raw = imgs[0] || ''
  return raw ? publicUrl(raw) : ''
})
/** App WebView 常算不出 flex 高度：用 JS 量出面板/scroll 像素高 */
const panelScrollPx = ref(420)
const tabRootPx = ref(0)

/**
 * 仅 iPhone/iPad 的 H5 Safari（不含 APP-PLUS / 桌面网页）：
 * uni refresher + 页面滚动争抢时，会话列表下滑会被拽回顶部。
 */
function isIosSafariH5() {
  // #ifdef H5
  try {
    if (typeof navigator === 'undefined') return false
    const ua = String(navigator.userAgent || '')
    const iOS = /iPhone|iPad|iPod/i.test(ua)
    if (!iOS) return false
    // 排除 iOS 上的 Chrome/Firefox/Edge 外壳也可一并修，但 WebKit 同源；这里覆盖所有 iOS H5
    return true
  } catch (e) {
    return false
  }
  // #endif
  // #ifndef H5
  return false
  // #endif
}

/** APK / 桌面网页保留下拉刷新；iOS Safari H5 关掉，避免滚到下方又弹回顶部 */
const convRefresherEnabled = computed(() => !isIosSafariH5())

/** 会话列表当前 scrollTop（用于列表静默刷新后还原，避免 Safari 跳顶） */
let convScrollTop = 0
let convScrollRestoreTimer = null

function onConvScroll(e) {
  const d = (e && e.detail) || {}
  const top = Number(d.scrollTop)
  if (isFinite(top) && top >= 0) convScrollTop = top
}

function restoreConvScrollSoon() {
  if (!isIosSafariH5()) return
  if (convScrollTop <= 0) return
  const top = convScrollTop
  const apply = () => {
    try {
      if (typeof document === 'undefined') return
      const roots = document.querySelectorAll(
        '.msg-tab-root .chat-conv-scroll, #tabMessages .chat-conv-scroll, .chat-conv-scroll'
      )
      roots.forEach((node) => {
        const cands = [
          node,
          node.querySelector && node.querySelector('.uni-scroll-view'),
        ]
        cands.forEach((el) => {
          if (!el) return
          try {
            if (typeof el.scrollTop === 'number') el.scrollTop = top
          } catch (e0) {}
        })
      })
    } catch (e1) {}
  }
  nextTick(() => {
    apply()
    if (convScrollRestoreTimer) clearTimeout(convScrollRestoreTimer)
    convScrollRestoreTimer = setTimeout(apply, 40)
    setTimeout(apply, 120)
  })
}

const tabRootStyle = computed(() => {
  const h = Number(tabRootPx.value) || 0
  if (h < 200) return {}
  return { height: h + 'px', minHeight: h + 'px' }
})
const panelHostStyle = computed(() => {
  const h = Number(panelScrollPx.value) || 420
  return { height: h + 'px', minHeight: h + 'px', maxHeight: h + 'px', flex: 'none', overflow: 'hidden' }
})
const panelScrollStyle = computed(() => {
  const h = Number(panelScrollPx.value) || 420
  // 显式像素高：Safari 上 height:100% 常算不出，必须靠内联；勿被 CSS !important 盖掉
  return { height: h + 'px', minHeight: h + 'px', maxHeight: h + 'px', flex: 'none' }
})

/** QQ 资料条高度（在公共 TopBar 下方） */
const QQ_MSG_NAV_CONTENT = 56
const myProfile = ref(null)
const myNickText = computed(() => {
  const p = myProfile.value || {}
  const meta = getImAuthMeta() || {}
  return String(
    p.nickname || p.username || meta.nickname || meta.username || '会员'
  ).trim() || '会员'
})
const myUidText = computed(() => {
  const p = myProfile.value || {}
  const meta = getImAuthMeta() || {}
  const uid = p.user_id || p.id || meta.user_id || meta.uid || myIdText.value.replace(/\D+/g, '') || ''
  return String(uid || '--')
})
const myAvatarSrc = computed(() => {
  const p = myProfile.value || {}
  const meta = getImAuthMeta() || {}
  return avatarSrc(p.avatar_url || p.avatar || meta.avatar_url || meta.avatar || '')
})

function goMyProfile() {
  uni.navigateTo({
    url: '/pages/profile/info',
    fail: () => uni.switchTab({ url: '/pages/profile/profile' }),
  })
}

function measureMessagesLayout() {
  try {
    applySafeAreaCssVars()
    const sys = uni.getSystemInfoSync() || {}
    let winH = Number(sys.windowHeight || sys.screenHeight || 667)
    // #ifdef H5
    try {
      if (typeof window !== 'undefined') {
        const vh = window.innerHeight || 0
        const docH = (document.documentElement && document.documentElement.clientHeight) || 0
        const stable = Math.max(vh, docH, Number(sys.windowHeight) || 0)
        if (stable > 200) winH = stable
      }
    } catch (e0) {}
    // #endif
    const inset = getSafeAreaInsets()
    const status = Number(inset.top || 0)
    const topBar = getTopBarContentHeight()
    const tabBar = 72 + Number(inset.bottom || 0)
    const searchH = searchOpen.value ? 48 : 0
    // 扣公共顶栏 + 资料条 + 可选搜索条
    const shell = Math.max(280, winH - status - topBar - QQ_MSG_NAV_CONTENT - searchH - tabBar)
    tabRootPx.value = shell
    const chrome = 8
    let next = Math.max(220, shell - chrome)
    if (isIosSafariH5() && panelScrollPx.value > 0) {
      const shrink = panelScrollPx.value - next
      if (shrink > 0 && shrink < 120) next = panelScrollPx.value
    }
    panelScrollPx.value = next
  } catch (e) {
    tabRootPx.value = 0
    panelScrollPx.value = 420
  }
}

const searchOpen = ref(false)
const keyword = ref('')
const plusOpen = ref(false)
const canCreateGroup = ref(false)
const myIdText = ref('')
const myHongbao = ref(null)
const myHongbaoFrozen = ref(null)
const createGroupOpen = ref(false)
const createGroupName = ref('')
const createGroupPrivacy = ref('private')
const createGroupMode = ref('chat')
const createGroupAvatar = ref('🐵')
const createGroupSubmitting = ref(false)
const createGroupBindRebate = ref(false)
// #ifdef APP-PLUS
const createGroupAppFix = true
// #endif
// #ifndef APP-PLUS
const createGroupAppFix = false
// #endif
/** 建群全屏 QQ 顶栏：写入安全区顶距（H5 / Safari / APK / IPA） */
const createGroupPaneStyle = computed(() => {
  if (!createGroupOpen.value) return null
  applySafeAreaCssVars()
  const inset = getSafeAreaInsets()
  const pad = Math.max(0, Number(inset.top || 0)) + 6
  return {
    top: '0px',
    zIndex: 20100,
    '--cg-header-pad-top': pad + 'px',
  }
})
const myGroups = ref([])
const friendReqPending = ref(0)
const listRefreshing = ref(false)
const swipeOpenKey = ref('')
const swipeDragKey = ref('')
const swipeOffset = ref(0)
let swipeState = null
/** 置顶 + 删除两钮总宽 */
const SWIPE_ACTIONS_W = 128
let skipNextConvClick = false
let off = null
let loading = false
let pageAlive = false
let inboxListBound = false

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

function formatMoneyYuan(n) {
  const v = Number(n)
  if (!isFinite(v)) return '￥0.00'
  return '￥' + v.toFixed(2)
}

const myIdLine = computed(() => {
  const base = String(myIdText.value || '').trim()
  if (!base) return ''
  if (myHongbao.value == null || !isFinite(Number(myHongbao.value))) return base
  const bal = formatMoneyYuan(myHongbao.value)
  const fr = formatMoneyYuan(myHongbaoFrozen.value != null ? myHongbaoFrozen.value : 0)
  return base + ' · 红宝 ' + bal + ' · 冻结 ' + fr
})

let walletPollTimer = null
let walletPollBusy = false
function stopWalletPoll() {
  if (walletPollTimer) {
    clearInterval(walletPollTimer)
    walletPollTimer = null
  }
}
function startWalletPoll() {
  stopWalletPoll()
  loadMyIdLine()
  walletPollTimer = setInterval(() => {
    if (!pageAlive || walletPollBusy) return
    loadMyIdLine()
  }, 20000)
}

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
  const id = resolveConvId(item)
  if (!id) return 0
  if (isConversationRecentlyRead(item.conversation_type, id)) return 0
  const last = item.last_message
  const lastId = last ? (last.id | 0) || (last.msg_id | 0) : 0
  if (lastId > 0 && getReadWatermark(item.conversation_type, id) >= lastId) return 0
  const fromInbox = getInboxUnread(item.conversation_type, id)
  if (fromInbox <= 0) return 0
  return fromInbox
}

function toggleSearch() {
  searchOpen.value = !searchOpen.value
  plusOpen.value = false
  nextTick(() => measureMessagesLayout())
}

function clearSearch() {
  keyword.value = ''
  searchOpen.value = false
  nextTick(() => measureMessagesLayout())
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
  const last = item.last_message
  const lastId = last ? (last.id | 0) || (last.msg_id | 0) : 0
  const peer = item.peer_user_id | 0
  const group = (item.group_id || (type === 2 ? id : 0)) | 0
  const title = displayTitle(item)
  localUnread.value = Object.assign({}, localUnread.value, { [key]: 0 })
  item.unread_count = 0
  noteConversationRead(type, id, lastId)
  if (type === 2 && item.group_id) {
    noteConversationRead(2, String(item.group_id), lastId)
  }
  if (id && lastId > 0) markConversationRead(type, id, lastId).catch(() => null)
  // 进房前先挂上 activeChat，避免 navigate 空隙里延迟推送又把未读加回来
  saveActiveChat({
    type,
    id,
    peer,
    group,
    title,
    nickname: item.peer_nickname || '',
    remark: item.remark || '',
  })
  const q = [
    'type=' + encodeURIComponent(type),
    'id=' + encodeURIComponent(id),
    'peer=' + encodeURIComponent(peer),
    'group=' + encodeURIComponent(group),
    'title=' + encodeURIComponent(title),
    'nickname=' + encodeURIComponent(item.peer_nickname || ''),
    'remark=' + encodeURIComponent(item.remark || ''),
  ].join('&')
  uni.navigateTo({ url: '/pages/chat/chat?' + q })
}

function bumpUnread(msg) {
  // 未读与列表预览统一走 inbox → fanshub-inbox-msg，避免 WS 路径再 upsert 一次
  void msg
}

function upsertListFromMessage(msg) {
  if (!msg) return
  const type = (msg.conversation_type | 0) || ((msg.group_id | 0) > 0 ? 2 : 1)
  let id = ''
  if (type === 2) id = String(msg.group_id || msg.conversation_id || '')
  else id = String(msg.conversation_id || '')
  if (!id) return
  const key = convKey(type, id)
  const rows = list.value
  let foundIdx = -1
  for (let i = 0; i < rows.length; i++) {
    if (itemKey(rows[i]) === key) {
      foundIdx = i
      break
    }
  }
  // 代聊编辑/撤回/删除：只更新「最后一条」预览，避免把旧消息顶到列表前
  if (foundIdx >= 0) {
    const cur = rows[foundIdx]
    const last = cur && cur.last_message
    const lastId = last ? String(last.id || last.msg_id || '') : ''
    const mid = String(msg.id || msg.msg_id || '')
    const st = (msg.status | 0) || 1
    if (mid && lastId && mid === lastId) {
      rows[foundIdx] = Object.assign({}, cur, {
        last_message: Object.assign({}, last, msg, { status: st }),
        updatetime: (msg.createtime | 0) || (cur.updatetime | 0),
      })
      list.value = rows.slice()
      restoreConvScrollSoon()
      return
    }
    if (mid && lastId && mid !== lastId && (st === 2 || st === 3)) {
      return
    }
  }
  let found = null
  if (foundIdx < 0) {
    found = {
      conversation_type: type,
      conversation_id: id,
      group_id: type === 2 ? (msg.group_id | 0) : 0,
      peer_user_id: type === 1 ? ((msg.from_user_id | 0) === (myIdNum() | 0) ? msg.to_user_id : msg.from_user_id) : 0,
      title: type === 2 ? '群 ' + id : 'ID ' + (msg.from_user_id || ''),
      last_message: msg,
      updatetime: msg.createtime | 0,
      unread_count: localUnread.value[key] | 0,
      pinned: false,
    }
  } else {
    found = Object.assign({}, rows[foundIdx], {
      last_message: msg,
      updatetime: msg.createtime | 0,
      unread_count: localUnread.value[key] | 0,
    })
    rows.splice(foundIdx, 1)
  }
  // 免全表 sort：置顶区后插入，非置顶插到首个非置顶位置
  if (found.pinned) {
    rows.unshift(found)
  } else {
    let insertAt = 0
    while (insertAt < rows.length && rows[insertAt] && rows[insertAt].pinned) insertAt++
    rows.splice(insertAt, 0, found)
  }
  list.value = rows.slice()
  restoreConvScrollSoon()
}

function myIdNum() {
  const n = parseInt(String(myIdText.value || '').replace(/\D+/g, ''), 10)
  return n || 0
}

function closeAllSwipe(exceptKey) {
  if (!exceptKey) {
    swipeOpenKey.value = ''
    swipeDragKey.value = ''
    swipeOffset.value = 0
    return
  }
  if (swipeOpenKey.value && swipeOpenKey.value !== exceptKey) {
    swipeOpenKey.value = ''
  }
}

function swipeFrontStyle(item) {
  const key = itemKey(item)
  if (swipeDragKey.value === key) {
    const x = Number(swipeOffset.value) || 0
    return {
      transform: 'translateX(' + x + 'px)',
      transition: 'none',
    }
  }
  if (swipeOpenKey.value === key) {
    return {
      transform: 'translateX(-' + SWIPE_ACTIONS_W + 'px)',
      transition: 'transform 0.22s cubic-bezier(0.2, 0.9, 0.3, 1)',
    }
  }
  return {
    transform: 'translateX(0)',
    transition: 'transform 0.22s cubic-bezier(0.2, 0.9, 0.3, 1)',
  }
}

function touchPoint(ev) {
  const t = (ev && ev.touches && ev.touches[0]) || (ev && ev.changedTouches && ev.changedTouches[0])
  if (!t) return null
  return { x: t.clientX, y: t.clientY }
}

function onSwipeTouchStart(ev, item) {
  const p = touchPoint(ev)
  if (!p || !item) return
  const type = item.conversation_type | 0
  if (type !== 1 && type !== 2) {
    swipeState = null
    return
  }
  const key = itemKey(item)
  const baseX = swipeOpenKey.value === key ? -SWIPE_ACTIONS_W : 0
  swipeState = {
    key,
    item,
    startX: p.x,
    startY: p.y,
    baseX,
    moved: false,
    horizontal: false,
  }
}

function onSwipeTouchMove(ev, item) {
  if (!swipeState || !item || swipeState.key !== itemKey(item)) return
  const p = touchPoint(ev)
  if (!p) return
  const dx = p.x - swipeState.startX
  const dy = p.y - swipeState.startY
  if (!swipeState.horizontal) {
    if (Math.abs(dx) < 8 && Math.abs(dy) < 8) return
    if (Math.abs(dx) <= Math.abs(dy)) {
      swipeState = null
      return
    }
    swipeState.horizontal = true
    closeAllSwipe(swipeState.key)
    swipeDragKey.value = swipeState.key
  }
  swipeState.moved = true
  const nx = Math.max(-SWIPE_ACTIONS_W, Math.min(0, swipeState.baseX + dx))
  swipeOffset.value = nx
  if (nx <= -SWIPE_ACTIONS_W / 2) swipeOpenKey.value = swipeState.key
  else if (swipeOpenKey.value === swipeState.key) swipeOpenKey.value = ''
}

function onSwipeTouchEnd(ev, item) {
  if (!swipeState || !item || swipeState.key !== itemKey(item)) {
    swipeState = null
    return
  }
  const st = swipeState
  swipeState = null
  if (!st.horizontal) {
    swipeDragKey.value = ''
    return
  }
  const open = swipeOffset.value <= -SWIPE_ACTIONS_W * 0.4
  swipeDragKey.value = ''
  swipeOffset.value = 0
  swipeOpenKey.value = open ? st.key : ''
  if (st.moved) skipNextConvClick = true
}

function onConvClick(item) {
  if (skipNextConvClick) {
    skipNextConvClick = false
    return
  }
  const key = itemKey(item)
  if (swipeOpenKey.value && swipeOpenKey.value !== key) {
    closeAllSwipe()
    return
  }
  if (swipeOpenKey.value === key) {
    closeAllSwipe()
    return
  }
  openChat(item)
}

function confirmDeleteConv(item) {
  if (!item) return
  const type = item.conversation_type | 0
  const id = resolveConvId(item)
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
        closeAllSwipe()
        uni.showToast({ title: '已删除', icon: 'none' })
      } catch (e) {
        uni.showToast({ title: (e && e.message) || '删除失败', icon: 'none' })
      }
    },
  })
}

function onSwipeDelete(item) {
  closeAllSwipe()
  confirmDeleteConv(item)
}

async function onSwipePin(item) {
  if (!item) return
  const type = item.conversation_type | 0
  const id = resolveConvId(item)
  const pinned = !!item.pinned
  closeAllSwipe()
  try {
    await pinConversation(type, id, !pinned)
    const key = itemKey(item)
    const rows = list.value.slice()
    for (let i = 0; i < rows.length; i++) {
      if (itemKey(rows[i]) === key) {
        rows[i] = Object.assign({}, rows[i], { pinned: !pinned })
        break
      }
    }
    rows.sort((a, b) => {
      const ap = a.pinned ? 1 : 0
      const bp = b.pinned ? 1 : 0
      if (ap !== bp) return bp - ap
      return (b.updatetime | 0) - (a.updatetime | 0)
    })
    list.value = rows
    uni.showToast({ title: pinned ? '已取消置顶' : '已置顶', icon: 'none' })
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '操作失败', icon: 'none' })
  }
}

function noopPopup() {}

function showNextMsgPopup() {
  const q = msgPopupQueue.value.slice()
  // 仅展示带图海报；无图条目跳过
  while (q.length) {
    const head = q[0]
    const imgs = (head && head.images) || []
    const raw = imgs[0] || ''
    if (raw) break
    q.shift()
  }
  msgPopupQueue.value = q
  if (!q.length) {
    msgPopup.value = null
    msgPopupOpen.value = false
    return
  }
  msgPopup.value = q[0]
  msgPopupOpen.value = true
  const id = (q[0] && q[0].id) | 0
  if (id) {
    apiRequest('messagespopupack', 'POST', { popup_id: id, action: 'view' }).catch(() => {})
  }
}

async function loadMsgPopups() {
  try {
    const data = await apiRequest('messagespopups', 'GET', {})
    const list = (data && data.list) || []
    msgPopupQueue.value = Array.isArray(list) ? list.slice() : []
    showNextMsgPopup()
  } catch (e) {
    msgPopupQueue.value = []
    msgPopupOpen.value = false
  }
}

function shiftMsgPopup() {
  const q = msgPopupQueue.value.slice()
  if (q.length) q.shift()
  msgPopupQueue.value = q
  showNextMsgPopup()
}

async function dismissMsgPopup(action) {
  const p = msgPopup.value
  const id = (p && p.id) | 0
  msgPopupOpen.value = false
  if (id) {
    try {
      await apiRequest('messagespopupack', 'POST', {
        popup_id: id,
        action: action || 'dismiss_day',
      })
    } catch (e) {}
  }
  shiftMsgPopup()
}

async function clickMsgPopup() {
  const p = msgPopup.value
  if (!p) return
  const id = (p.id) | 0
  const jump = String(p.jump_type || 'none')
  const extra = String(p.jump_extra || '').trim()
  if (id) {
    apiRequest('messagespopupack', 'POST', { popup_id: id, action: 'click' }).catch(() => {})
  }
  msgPopupOpen.value = false
  shiftMsgPopup()

  if (jump === 'community') {
    uni.switchTab({
      url: '/pages/community/community',
      fail: () => uni.reLaunch({ url: '/pages/community/community' }),
    })
    return
  }
  if (jump === 'notice') {
    const cat = extra || 'latest'
    const allowed = ['latest', 'promote', 'ads', 'rules']
    const c = allowed.indexOf(cat) >= 0 ? cat : 'latest'
    const url = '/pages/notice/notice?cat=' + encodeURIComponent(c)
    uni.navigateTo({
      url,
      fail: () => uni.reLaunch({ url }),
    })
    return
  }
  if (jump === 'commission') {
    uni.navigateTo({
      url: '/pages/commission/commission',
      fail: () => uni.reLaunch({ url: '/pages/commission/commission' }),
    })
    return
  }
  if (jump === 'url' && extra) {
    if (/^https?:\/\//i.test(extra)) {
      // #ifdef H5
      try {
        window.open(extra, '_blank')
      } catch (e) {
        uni.navigateTo({ url: '/pages/common/webview?url=' + encodeURIComponent(extra) })
      }
      // #endif
      // #ifndef H5
      uni.navigateTo({
        url: '/pages/common/webview?url=' + encodeURIComponent(extra),
        fail: () => {
          // #ifdef APP-PLUS
          try {
            plus.runtime.openURL(extra)
          } catch (e2) {}
          // #endif
        },
      })
      // #endif
      return
    }
    if (extra.indexOf('/pages/') === 0) {
      uni.navigateTo({
        url: extra,
        fail: () => uni.switchTab({ url: extra, fail: () => {} }),
      })
    }
  }
}

function openCreateGroupPane(opts = {}) {
  plusOpen.value = false
  createGroupPrivacy.value = 'private'
  createGroupMode.value = 'chat'
  createGroupSubmitting.value = false
  createGroupBindRebate.value = !!opts.fromCreateCard
  createGroupName.value = opts.fromCreateCard ? '我的专属保密对战群' : ''
  if (!createGroupAvatar.value) createGroupAvatar.value = '🐵'
  createGroupOpen.value = true
}

function closeCreateGroupPane() {
  createGroupOpen.value = false
}

function cycleCreateGroupAvatar() {
  const cur = createGroupAvatar.value || '🐵'
  const idx = CREATE_GROUP_AVATARS.indexOf(cur)
  createGroupAvatar.value = CREATE_GROUP_AVATARS[(idx + 1 + CREATE_GROUP_AVATARS.length) % CREATE_GROUP_AVATARS.length]
}

async function submitCreateGroup() {
  if (createGroupSubmitting.value) return
  const name = String(createGroupName.value || '').trim()
  if (!name) {
    uni.showToast({ title: '请输入群名称', icon: 'none' })
    return
  }
  createGroupSubmitting.value = true
  try {
    await imConnect()
    const packet = await createGroup({
      name,
      member_ids: [],
      privacy_mode: createGroupPrivacy.value || 'private',
      chat_mode: createGroupMode.value || 'chat',
      bind_owner_rebate: createGroupBindRebate.value ? 1 : 0,
    })
    const g = (packet && packet.data && packet.data.group) || (packet && packet.group) || null
    if (!g || !(g.id | 0)) throw new Error('创建失败')
    closeCreateGroupPane()
    await loadList(true)
    await loadMyGroupsSafe()
    uni.showToast({ title: '群聊已创建', icon: 'none' })
    setTimeout(() => {
      uni.navigateTo({
        url:
          '/pages/chat/chat?type=2&id=' +
          encodeURIComponent(g.id) +
          '&group=' +
          encodeURIComponent(g.id) +
          '&title=' +
          encodeURIComponent(g.name || name || '群聊'),
      })
    }, 200)
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '创建失败', icon: 'none' })
  } finally {
    createGroupSubmitting.value = false
  }
}

function normalizeMyGroups(list) {
  return (list || []).map((g) =>
    Object.assign({}, g, {
      is_member: true,
      my_role: (g.my_role | 0) || (g.role | 0) || 0,
    })
  )
}

async function loadMyGroupsSafe() {
  try {
    const packet = await listMyGroups()
    const data = (packet && packet.data) || {}
    myGroups.value = normalizeMyGroups(data.list || data.groups || [])
  } catch (e) {}
}

async function refreshAuthFlags() {
  canCreateGroup.value = canCreateGroupFromAuth()
  const meta = getImAuthMeta() || {}
  const uid = meta.user_id || meta.uid || 0
  if (uid) {
    myIdText.value = '我的会员ID：' + uid
  }
  if (meta.nickname || meta.avatar_url || meta.avatar) {
    myProfile.value = Object.assign({}, myProfile.value || {}, {
      nickname: meta.nickname || (myProfile.value && myProfile.value.nickname),
      avatar_url: meta.avatar_url || meta.avatar || (myProfile.value && myProfile.value.avatar_url),
      user_id: uid || (myProfile.value && myProfile.value.user_id),
    })
  }
}

async function loadMyIdLine() {
  if (walletPollBusy) return
  walletPollBusy = true
  try {
    const p = await fetchProfile()
    const uid = (p && (p.user_id || p.id)) | 0
    if (uid) {
      myIdText.value = '我的会员ID：' + uid
      setInboxMyId(uid)
    }
    if (p) {
      myProfile.value = p
      const hb = p.hongbao != null ? p.hongbao : p.balance
      if (hb != null && isFinite(Number(hb))) {
        myHongbao.value = Number(hb)
      }
      if (p.hongbao_frozen != null && isFinite(Number(p.hongbao_frozen))) {
        myHongbaoFrozen.value = Math.max(0, Number(p.hongbao_frozen))
      } else if (myHongbaoFrozen.value == null) {
        myHongbaoFrozen.value = 0
      }
    }
  } catch (e) {
  } finally {
    walletPollBusy = false
  }
}

async function onPlusAction(kind) {
  plusOpen.value = false
  if (kind === 'search') {
    searchOpen.value = true
    nextTick(() => measureMessagesLayout())
    return
  }
  if (kind === 'share') {
    uni.navigateTo({ url: '/pages/messages/share-poster' })
    return
  }
  if (kind === 'scan') {
    const meta = getImAuthMeta() || {}
    const selfId = meta.user_id || meta.uid || ''
    openFriendScanSheet({
      selfUserId: selfId,
      onManual: () => uni.navigateTo({ url: '/pages/friend/add' }),
    })
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
  if (kind === 'createGroup') {
    openCreateGroupPane()
  }
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
    syncInboxFromServerList(rows)
    const next = Object.assign({}, localUnread.value)
    rows.forEach((it) => {
      const key = itemKey(it)
      const id = resolveConvId(it)
      const last = it.last_message
      const lastId = last ? (last.id | 0) || (last.msg_id | 0) : 0
      if (
        isConversationRecentlyRead(it.conversation_type, id) ||
        (lastId > 0 && getReadWatermark(it.conversation_type, id) >= lastId)
      ) {
        next[key] = 0
        it.unread_count = 0
        return
      }
      next[key] = getInboxUnread(it.conversation_type, id)
      it.unread_count = next[key]
    })
    localUnread.value = next
    let sum = 0
    rows.forEach((it) => {
      sum += unreadOf(it)
    })
    setChatUnreadTotal(sum)
    restoreConvScrollSoon()
  } catch (e) {
    if (!silent) uni.showToast({ title: e.message || '拉取会话失败', icon: 'none' })
  } finally {
    loaded.value = true
    loading = false
    refreshStatus()
  }
}

async function onListRefresh() {
  listRefreshing.value = true
  try {
    await loadList(true)
  } finally {
    listRefreshing.value = false
  }
}

async function reconnect() {
  try {
    await imForceReconnect()
    await loadList()
    uni.showToast({ title: '已重连', icon: 'success' })
  } catch (e) {
    uni.showToast({ title: e.message || '重连失败', icon: 'none' })
  }
}

function applyPageShell(on) {
  try {
    const h =
      typeof window !== 'undefined' && window.matchMedia && window.matchMedia('(max-width: 480px)').matches
        ? '44px'
        : '48px'
    // #ifdef H5
    if (typeof document !== 'undefined') {
      document.body.classList.toggle('tab-messages', !!on)
      document.documentElement.classList.toggle('tab-messages-ios-safari', !!(on && isIosSafariH5()))
      document.body.classList.toggle('tab-messages-ios-safari', !!(on && isIosSafariH5()))
      if (on) document.documentElement.style.setProperty('--top-bar-height', h)
      if (!on) {
        document.documentElement.style.removeProperty('overflow')
        document.documentElement.style.removeProperty('overscroll-behavior-y')
        document.body.style.removeProperty('overscroll-behavior-y')
      } else if (isIosSafariH5()) {
        document.documentElement.style.overflow = 'hidden'
        document.documentElement.style.overscrollBehaviorY = 'none'
        document.body.style.overscrollBehaviorY = 'none'
      }
    }
    // #endif
    // App：同样写入 CSS 变量（无 document.body 类，适配器已用 #tabMessages）
    // #ifdef APP-PLUS
    if (typeof document !== 'undefined' && document.documentElement && on) {
      document.documentElement.style.setProperty('--top-bar-height', h)
      if (document.body) document.body.classList.add('tab-messages')
    }
    // #endif
  } catch (e) {}
}

onShow(() => {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  pageAlive = true
  try {
    const pendingTab = String(uni.getStorageSync('fanshub_messages_home_tab') || '').trim()
    if (pendingTab) {
      uni.removeStorageSync('fanshub_messages_home_tab')
      if (pendingTab === 'community') {
        uni.switchTab({ url: '/pages/community/community' })
        return
      }
      if (pendingTab === 'notice') {
        uni.navigateTo({ url: '/pages/notice/notice' })
      } else if (pendingTab === 'commission') {
        uni.navigateTo({ url: '/pages/commission/commission' })
      }
    }
  } catch (e) {}
  captureGroupJoinFromUrl()
  void tryConsumeGroupJoin({ silent: false })
  prefetchChatSubpackages()
  measureMessagesLayout()
  // Safari / 旋转后 windowHeight 偶发滞后，下一帧再量一次
  setTimeout(() => {
    if (pageAlive) measureMessagesLayout()
  }, 50)
  applyPageShell(true)
  startImInbox()
  bindForegroundResume()
  resumeFromBackground('messages-onShow')
  loadMsgPopups()
  if (typeof off === 'function') off()
  off = onImEvent((type, data) => {
    if (type === 'im.resume') {
      if (pageAlive) loadList(true)
      return
    }
    if (type === 'auth.ok') {
      refreshAuthFlags()
      refreshStatus()
      const meta = getImAuthMeta() || {}
      const uid = (meta.user_id || meta.uid) | 0
      if (uid) setInboxMyId(uid)
    }
    if (type === 'socket.close' || type === 'socket.error') refreshStatus()
    if (type === 'private.message' || type === 'group.message' || type === 'redpacket.relay_next') {
      // 列表预览 / 未读只走 inbox（fanshub-inbox-msg），避免双重 upsert
      return
    }
    if (type === 'conversation.updated') {
      loadList(true)
    }
    if (type === 'redpacket.update') {
      // A2：抢包风暴不刷整表；预览由 inbox/msg 路径维护
      return
    }
    if (type === 'group.created' || type === 'group.kicked') {
      loadList(true)
      loadMyGroupsSafe()
    }
    if (type === 'friend.request' || type === 'friend.accepted' || type === 'friend.rejected' || type === 'friend.cancelled') {
      loadFriendReqBadge()
    }
  })
  // inbox 只绑一次：进聊天页 onHide 后仍更新会话预览
  if (!inboxListBound) {
    inboxListBound = true
    const onInboxUnread = (map) => {
      if (!map) return
      localUnread.value = Object.assign({}, localUnread.value, map)
    }
    const onInboxMsg = (msg) => {
      upsertListFromMessage(msg)
    }
    try {
      uni.$on && uni.$on('fanshub-inbox-unread', onInboxUnread)
      uni.$on && uni.$on('fanshub-inbox-msg', onInboxMsg)
    } catch (e) {}
  }
  loadMyIdLine()
  startWalletPoll()
  loadList().then(() => refreshAuthFlags())
  loadFriendReqBadge()
})

onHide(() => {
  pageAlive = false
  applyPageShell(false)
  stopWalletPoll()
  if (typeof off === 'function') {
    off()
    off = null
  }
  // 保留 inbox 监听与全局 im-inbox
})
</script>

<style scoped>
/* QQ 资料条：跟在公共 TopBar 下方（非 fixed） */
.qq-msg-nav {
  position: relative;
  z-index: 50;
  background: #dde2eb;
  box-sizing: border-box;
  width: 100%;
  flex-shrink: 0;
  overflow: visible;
}
.chat-plus-menu-wrap {
  position: relative;
  z-index: 60;
  overflow: visible;
}
.qq-msg-search {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 12px;
  background: #ffffff;
  border-bottom: 0.5px solid #e5e5e5;
  box-sizing: border-box;
  position: relative;
  z-index: 40;
  flex-shrink: 0;
}
.qq-msg-search-box {
  flex: 1;
  min-width: 0;
  height: 36px;
  display: flex;
  align-items: center;
  padding: 0 12px;
  background: #f5f5f5;
  border-radius: 6px;
  box-sizing: border-box;
}
.qq-msg-search-input {
  flex: 1;
  min-width: 0;
  height: 36px;
  font-size: 15px;
  color: #191919;
  background: transparent;
  border: none;
}
.qq-msg-search-cancel {
  flex-shrink: 0;
  font-size: 15px;
  color: #12b7f5;
  font-weight: 500;
  padding: 4px 2px;
}
.qq-msg-nav-inner {
  height: 56px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 12px 0 14px;
  box-sizing: border-box;
}
.qq-msg-me {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
  flex: 1 1 auto;
}
.qq-msg-me--hit {
  opacity: 0.88;
}
.qq-msg-avatar-wrap {
  position: relative;
  width: 42px;
  height: 42px;
  flex-shrink: 0;
}
.qq-msg-avatar {
  width: 42px;
  height: 42px;
  border-radius: 50%;
  display: block;
  background: #c8ced8;
}
.qq-msg-online-dot {
  position: absolute;
  right: 0;
  bottom: 0;
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: #34c759;
  border: 2px solid #dde2eb;
  box-sizing: border-box;
}
.qq-msg-me-meta {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  flex: 1 1 auto;
}
.qq-msg-nick {
  font-size: 17px;
  font-weight: 700;
  color: #1a1a1a;
  line-height: 1.2;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 58vw;
}
.qq-msg-id {
  font-size: 12px;
  font-weight: 400;
  color: #6b7280;
  line-height: 1.2;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.qq-msg-plus {
  position: relative;
  width: 36px;
  height: 36px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  border-radius: 50%;
  background: #ffffff;
  border: 0.5px solid rgba(0, 0, 0, 0.08);
  box-shadow:
    0 1px 2px rgba(0, 0, 0, 0.06),
    0 2px 6px rgba(0, 0, 0, 0.08);
  box-sizing: border-box;
  overflow: hidden;
}
.qq-msg-plus--hit {
  opacity: 1;
  background: #f3f4f6;
  box-shadow:
    0 0 0 0.5px rgba(0, 0, 0, 0.06) inset,
    0 1px 2px rgba(0, 0, 0, 0.05);
  transform: scale(0.94);
}
.qq-msg-plus-char {
  position: absolute;
  left: 50%;
  top: 50%;
  transform: translate(-50%, -50%);
  width: 36px;
  height: 36px;
  display: flex;
  align-items: center;
  justify-content: center;
  text-align: center;
  font-size: 28px;
  font-weight: 500;
  color: #191919;
  line-height: 36px;
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}
.messages-page--qq-me {
  background: #ffffff;
}

.chat-reconnect {
  color: var(--color-primary-start, #e63022);
  font-weight: 700;
  text-decoration: underline;
}
.chat-conv-ptr-host {
  flex: 1 1 0%;
  min-height: 120px;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  position: relative;
}
.chat-conv-scroll {
  /* 高度由内联 panelScrollStyle 像素给出（Safari 勿依赖 height:100%） */
  flex: none;
  min-height: 120px;
  width: 100%;
  box-sizing: border-box;
}
.chat-community-body-scroll,
.chat-official-scroll,
.chat-commission-body-scroll,
.chat-notice-scroll,
.chat-notice-body-scroll {
  flex: none;
  min-height: 120px;
  width: 100%;
  box-sizing: border-box;
}
/* 列表末项后留白，确保最后一条可滚出底栏遮挡（H5/Safari/APK/IPA） */
.chat-list-scroll-pad {
  display: block;
  width: 100%;
  height: 20px;
  min-height: 20px;
  max-height: 20px;
  flex-shrink: 0;
  overflow: hidden;
  pointer-events: none;
  box-sizing: border-box;
}
.chat-list-scroll-pad-mark {
  display: block;
  height: 20px;
  line-height: 20px;
  font-size: 20px;
  opacity: 0;
}
/* 会话列表：最后一条渲染完后再留 20px（padding + 实体 pad，双保险） */
.chat-conv-list {
  padding-bottom: 20px !important;
  box-sizing: border-box;
  overflow: visible !important;
  height: auto !important;
  min-height: 0 !important;
  flex: none !important;
}
/* 社群好友列表：同会话列表，完整可滚 + 末项后再留 20px */
.chat-friend-feed-list {
  padding-bottom: 20px !important;
  box-sizing: border-box;
}
.chat-official-list {
  display: flex;
  flex-direction: column;
  gap: 0;
  padding: 0 0 20px;
  box-sizing: border-box;
}
.chat-official-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 14px;
  border-radius: 0;
  background: #fff;
  box-shadow: none;
  border-bottom: 1px solid #e5e5e5;
}
/* 官方社群头像：复用会话列表 .chat-avatar.group（见 chat-uni-adapter） */
.chat-official-body {
  flex: 1 1 auto;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.chat-official-title {
  font-size: 15px;
  font-weight: 500;
  color: #191919;
  line-height: 1.25;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.chat-official-sub {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
}
.chat-official-online {
  font-size: 12px;
  color: #999;
}
.chat-official-tag {
  font-size: 11px;
  color: #12b7f5;
  background: rgba(18, 183, 245, 0.1);
  border-radius: 3px;
  padding: 1px 6px;
  font-weight: 400;
}
.chat-official-join {
  flex-shrink: 0;
  padding: 6px 12px;
  border-radius: 4px;
  border: none;
  color: #fff;
  font-size: 13px;
  font-weight: 500;
  background: #12b7f5;
}
.chat-official-end {
  text-align: center;
  color: #999;
  font-size: 12px;
  padding: 10px 0 8px;
  letter-spacing: 0.5px;
}
.chat-community-pane--official {
  display: flex !important;
  flex-direction: column !important;
  flex: 1 1 auto !important;
  min-height: 0 !important;
  overflow: hidden !important;
  box-sizing: border-box !important;
}
.chat-community-pane--feed {
  display: flex !important;
  flex-direction: column !important;
  flex: 1 1 0% !important;
  min-height: 0 !important;
  overflow: hidden !important;
  box-sizing: border-box !important;
}
.chat-community-pane--official .chat-community-body-scroll,
.chat-community-pane--feed .chat-community-body-scroll {
  flex: none !important;
  min-height: 0 !important;
}
/* 好友列表末项后 20px（与会话列表一致，App 勿依赖空 view） */
.chat-friend-feed-list,
.chat-my-groups-list {
  padding-bottom: 20px !important;
  box-sizing: border-box;
}
.chat-official-rules,
.chat-official-rules--dock {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
  position: relative;
  z-index: 20;
  margin: 4px 10px 6px;
  padding: 12px 12px;
  border-radius: 8px;
  background: #ffffff;
  border: 1px solid #e5e5e5;
  box-shadow: 0 -2px 12px rgba(0, 0, 0, 0.06);
  box-sizing: border-box;
}
.chat-official-rules--dock {
  /* fixed 压在底栏之上（BottomTabBar z-index:9000） */
  position: fixed !important;
  left: 0;
  right: 0;
  bottom: calc(72px + var(--safe-area-inset-bottom, env(safe-area-inset-bottom, 0px)));
  z-index: 9100 !important;
  margin: 0 10px 0;
  flex: none;
  max-width: 720px;
  margin-left: auto;
  margin-right: auto;
  width: calc(100% - 20px);
  box-sizing: border-box;
}
.chat-official-rules-ico {
  width: 42px;
  height: 42px;
  border-radius: 6px;
  background: #f5f5f5;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
  flex-shrink: 0;
  box-shadow: none;
}
.chat-official-rules-text {
  flex: 1 1 auto;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 3px;
}
.chat-official-rules-title {
  font-size: 14px;
  font-weight: 600;
  color: #191919;
  line-height: 1.3;
}
.chat-official-rules-desc {
  font-size: 12px;
  color: #999;
  line-height: 1.3;
}
.chat-official-rules-link {
  flex-shrink: 0;
  font-size: 12px;
  color: #12b7f5;
  font-weight: 500;
  white-space: nowrap;
}

.msg-popup-mask {
  position: fixed;
  left: 0;
  right: 0;
  top: 0;
  bottom: 0;
  z-index: 30000;
  background: rgba(0, 0, 0, 0.55);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px 18px;
  padding-top: calc(20px + var(--safe-area-inset-top, env(safe-area-inset-top, 0px)));
  padding-bottom: calc(24px + var(--safe-area-inset-bottom, env(safe-area-inset-bottom, 0px)));
  box-sizing: border-box;
}
.msg-popup-wrap {
  width: 100%;
  max-width: 360px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 14px;
  box-sizing: border-box;
  max-height: 100%;
}
.msg-popup-img {
  display: block;
  width: 85%;
  height: auto;
  max-height: min(61.2vh, 544px);
  border-radius: 12px;
  background: transparent;
}
.msg-popup-close {
  width: 40px;
  height: 40px;
  line-height: 38px;
  text-align: center;
  border-radius: 50%;
  font-size: 28px;
  font-weight: 300;
  color: #fff;
  background: rgba(255, 255, 255, 0.18);
  border: 1.5px solid rgba(255, 255, 255, 0.55);
  box-sizing: border-box;
  flex-shrink: 0;
}
.msg-popup-close--active {
  opacity: 0.75;
  background: rgba(255, 255, 255, 0.28);
}
.msg-popup-mute {
  margin-top: -6px;
  padding: 6px 12px 2px;
  font-size: 13px;
  font-weight: 600;
  color: rgba(255, 255, 255, 0.82);
  text-align: center;
  letter-spacing: 0.4px;
  flex-shrink: 0;
}
.msg-popup-mute--active {
  opacity: 0.7;
}
/* App：建群 QQ 全屏顶栏由 chat-qq-theme + --cg-header-pad-top 处理 */
/* #ifdef APP-PLUS */
.chat-create-group-pane.cg-app-fix {
  top: 0 !important;
}
/* #endif */
</style>

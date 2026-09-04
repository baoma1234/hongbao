<template>
  <view class="messages-page community-page" :class="{ 'community-page--official': communitySub === 'official' }">
    <TopBar title="社群" />
    <view
      id="tabCommunity"
      class="tab-page active msg-tab-root"
      :style="tabRootStyle"
    >
      <view class="chat-shell">
        <view class="chat-list-pane">
          <view class="chat-list-main">
            <view
              id="chatHomePanelCommunity"
              class="chat-home-panel chat-community-glass"
              :style="communityHostStyle"
            >
              <view class="chat-community-seg is-4">
                <view class="chat-community-seg-btn" :class="{ active: communitySub === 'official' }" @click="setCommunitySub('official')">官方社群</view>
                <view class="chat-community-seg-btn" :class="{ active: communitySub === 'mine' }" @click="setCommunitySub('mine')">我的群组</view>
                <view class="chat-community-seg-btn" :class="{ active: communitySub === 'created' }" @click="setCommunitySub('created')">我创建的</view>
                <view class="chat-community-seg-btn" :class="{ active: communitySub === 'friends' }" @click="setCommunitySub('friends')">好友列表</view>
              </view>

              <!-- 四端：同一时刻只挂载一个面板，避免点几次叠乱 -->
              <view
                v-if="communitySub === 'official'"
                key="pane-official"
                class="chat-community-pane active chat-community-pane--official"
              >
                <scroll-view
                  scroll-y
                  class="chat-community-body-scroll"
                  :style="officialScrollStyle"
                  :show-scrollbar="false"
                >
                  <view class="chat-official-list">
                    <view
                      v-for="(g, idx) in communityRecs"
                      :key="g.id || g.group_id"
                      class="chat-official-row"
                      @click="openGroup(g)"
                    >
                      <view class="chat-avatar group">
                        <image :src="avatarSrc(g.avatar_url || g.avatar)" mode="aspectFill" lazy-load />
                      </view>
                      <view class="chat-official-body">
                        <text class="chat-official-title">{{ g.name || ('#' + (g.id || g.group_id)) }}</text>
                        <view class="chat-official-sub">
                          <text class="chat-official-online">{{ groupMembersText(g) }}</text>
                          <text class="chat-official-tag">{{ officialGroupTag(g, idx) }}</text>
                        </view>
                      </view>
                      <view class="chat-official-join" @click.stop="openGroup(g)">立即进群</view>
                    </view>
                    <view v-if="!communityRecs.length" class="chat-empty chat-empty-glass">暂无推荐社群</view>
                    <view class="chat-list-scroll-pad" aria-hidden="true">
                      <text class="chat-list-scroll-pad-mark"> </text>
                    </view>
                  </view>
                </scroll-view>
              </view>

              <view
                v-else-if="communitySub === 'mine'"
                key="pane-mine"
                class="chat-community-pane active chat-community-pane--feed"
              >
                <scroll-view
                  scroll-y
                  class="chat-community-body-scroll"
                  :style="panelScrollStyle"
                  :show-scrollbar="false"
                  :enable-flex="true"
                >
                  <view class="chat-my-groups-list">
                    <view
                      v-if="canCreateGroup"
                      class="chat-my-group-item chat-my-group-create"
                      @click="openCreateGroupPane({ fromCreateCard: true })"
                    >
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
                          <image :src="avatarSrc(g.avatar_url || g.avatar)" mode="aspectFill" lazy-load />
                        </view>
                        <text class="chat-my-group-name">{{ g.name || ('#' + g.id) }}</text>
                      </view>
                      <view class="chat-my-group-count">{{ g.display_member_count || g.member_count || 0 }}<text>人</text></view>
                    </view>
                    <view v-if="!myGroups.length && communityExtraLoading" class="chat-empty chat-empty-glass">加载中…</view>
                    <view v-else-if="!myGroups.length" class="chat-empty chat-empty-glass">暂无已加入社群</view>
                    <view class="chat-list-scroll-pad" aria-hidden="true">
                      <text class="chat-list-scroll-pad-mark"> </text>
                    </view>
                  </view>
                </scroll-view>
              </view>

              <view
                v-else-if="communitySub === 'created'"
                key="pane-created"
                class="chat-community-pane active chat-community-pane--feed"
              >
                <scroll-view
                  scroll-y
                  class="chat-community-body-scroll"
                  :style="panelScrollStyle"
                  :show-scrollbar="false"
                  :enable-flex="true"
                >
                  <view class="chat-my-groups-list">
                    <view v-for="g in myCreatedGroups" :key="'c-' + g.id" class="chat-my-group-item" @click="openGroup(g)">
                      <view class="chat-my-group-main">
                        <view class="chat-my-group-avatar">
                          <image :src="avatarSrc(g.avatar_url || g.avatar)" mode="aspectFill" lazy-load />
                        </view>
                        <view class="chat-my-group-create-text">
                          <text class="chat-my-group-name">{{ g.name || ('#' + g.id) }}</text>
                          <text class="chat-my-group-sub">{{ (g.my_role | 0) >= 3 ? '群主' : '管理员' }}</text>
                        </view>
                      </view>
                      <view class="chat-my-group-count">{{ g.display_member_count || g.member_count || 0 }}<text>人</text></view>
                    </view>
                    <view v-if="!myCreatedGroups.length && communityExtraLoading" class="chat-empty chat-empty-glass">加载中…</view>
                    <view v-else-if="!myCreatedGroups.length" class="chat-empty chat-empty-glass">暂无我创建/管理的群</view>
                    <view class="chat-list-scroll-pad" aria-hidden="true">
                      <text class="chat-list-scroll-pad-mark"> </text>
                    </view>
                  </view>
                </scroll-view>
              </view>

              <view
                v-else
                key="pane-friends"
                class="chat-community-pane active chat-community-pane--feed"
              >
                <scroll-view
                  scroll-y
                  class="chat-community-body-scroll"
                  :style="panelScrollStyle"
                  :show-scrollbar="false"
                  :enable-flex="true"
                >
                  <view class="chat-friend-feed-list">
                    <view
                      v-for="f in friends"
                      :key="f.peer_user_id || f.user_id"
                      class="chat-feed-item"
                      :class="{ 'is-pinned-cs': !!(f.is_default_cs || f.pinned) }"
                      @click="openFriendChat(f)"
                    >
                      <view class="chat-avatar">
                        <image :src="avatarSrc(f.avatar_url || f.avatar)" mode="aspectFill" lazy-load />
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
                    <view v-if="!friends.length && communityExtraLoading" class="chat-empty chat-empty-glass">加载中…</view>
                    <view v-else-if="!friends.length" class="chat-empty chat-empty-glass">暂无好友</view>
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

    <view
      class="chat-create-group-pane"
      :class="{ open: createGroupOpen, 'cg-app-fix': createGroupAppFix }"
      :aria-hidden="createGroupOpen ? 'false' : 'true'"
      :style="createGroupPaneStyle"
    >
      <view class="chat-cg-header">
        <view class="chat-cg-back" @click="closeCreateGroupPane">
          <text class="chat-hero-back-char">‹</text>
        </view>
        <view class="chat-cg-title">创建新群聊</view>
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

    <view
      v-if="communitySub === 'official'"
      class="chat-official-rules chat-official-rules--dock"
      @click="openGameRulesFromCommunity"
    >
      <view class="chat-official-rules-ico">📜</view>
      <view class="chat-official-rules-text">
        <text class="chat-official-rules-title">🧧 红宝官方游戏规则</text>
        <text class="chat-official-rules-desc">新手通关玩法与佣金保障说明</text>
      </view>
      <text class="chat-official-rules-link">点开查看规则图 ›</text>
    </view>
    <BottomTabBar active="community" />
  </view>
</template>

<script setup>
import { computed, nextTick, ref } from 'vue'
import { onShow, onHide } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import BottomTabBar from '../../components/BottomTabBar.vue'
import '../../styles/chat-messages-list.css'
import '../../styles/chat-uni-adapter.css'
import '../../styles/chat-messages-parity.css'
import '../../styles/chat-qq-theme.css'
import { apiRequest, getToken } from '../../utils/auth.js'
import { applySafeAreaCssVars, getSafeAreaInsets, getTopBarContentHeight, measureChatOverlayTop } from '../../utils/safe-area.js'
import { avatarSrc } from '../../utils/chat.js'
import {
  canCreateGroupFromAuth,
  createGroup,
  getImAuthMeta,
  imConnect,
  joinGroup,
  listFriends,
  listMyGroups,
  onImEvent,
  resumeFromBackground,
  bindForegroundResume,
} from '../../utils/im.js'

const CREATE_GROUP_AVATARS = ['🐵', '🐼', '🦊', '🐯', '🦁', '🐶', '🐱', '🐰', '🐻', '🐨', '🐸', '🐷']

const panelScrollPx = ref(420)
const tabRootPx = ref(0)
const canCreateGroup = ref(false)
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
const createGroupPaneStyle = computed(() => {
  if (!createGroupOpen.value || !createGroupAppFix) return null
  applySafeAreaCssVars()
  const top = measureChatOverlayTop()
  return { '--cg-app-top': Math.max(44, top) + 'px' }
})

const communitySub = ref('official')
const communityRecs = ref([])
const myGroups = ref([])
const myCreatedGroups = computed(() =>
  (myGroups.value || []).filter((g) => ((g.my_role | 0) || (g.role | 0)) >= 2)
)
const friends = ref([])
const communityExtraLoading = ref(false)
const COMMUNITY_EXTRA_TTL_MS = 60000
let communityExtraAt = 0
let communityExtraOk = false
let communityExtraInflight = null
let pageAlive = false
let off = null

const OFFICIAL_RULES_DOCK_PX = 96

const tabRootStyle = computed(() => {
  const h = Number(tabRootPx.value) || 0
  if (h < 200) return {}
  return { height: h + 'px', minHeight: h + 'px' }
})
const communityHostStyle = computed(() => {
  let h = Number(panelScrollPx.value) || 420
  if (communitySub.value === 'official') {
    h = Math.max(200, h - OFFICIAL_RULES_DOCK_PX)
  }
  return { height: h + 'px', minHeight: h + 'px', maxHeight: h + 'px', flex: 'none', overflow: 'hidden' }
})
const panelScrollStyle = computed(() => {
  let h = Number(panelScrollPx.value) || 420
  h = Math.max(180, h - 52)
  if (communitySub.value === 'official') {
    h = Math.max(140, h - OFFICIAL_RULES_DOCK_PX)
  }
  return { height: h + 'px', minHeight: h + 'px', maxHeight: h + 'px', flex: 'none' }
})
const officialScrollStyle = computed(() => {
  let h = Number(panelScrollPx.value) || 420
  h = Math.max(140, h - 52 - OFFICIAL_RULES_DOCK_PX)
  return { height: h + 'px', minHeight: h + 'px', maxHeight: h + 'px', flex: 'none' }
})

function measureCommunityLayout() {
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
    const shell = Math.max(280, winH - status - topBar - tabBar)
    tabRootPx.value = shell
    const chrome = 52
    panelScrollPx.value = Math.max(220, shell - chrome)
  } catch (e) {
    tabRootPx.value = 0
    panelScrollPx.value = 420
  }
}

function groupMembersText(g) {
  const n = (g && (g.online_count || g.member_count || g.display_member_count)) | 0
  if (n <= 0) return '欢迎加入'
  return n.toLocaleString('en-US') + '人在线'
}

const OFFICIAL_TAGS = ['玩法火爆', '官方保障', '极速开奖', '大奖奖池', '官方保障']
function officialGroupTag(g, idx) {
  const t = String((g && (g.recommend_tag || g.tag || g.badge)) || '').trim()
  if (t) return t
  return OFFICIAL_TAGS[(idx | 0) % OFFICIAL_TAGS.length]
}

function openGameRulesFromCommunity() {
  uni.navigateTo({
    url: '/pages/notice/notice?cat=rules',
    fail: () => uni.reLaunch({ url: '/pages/notice/notice?cat=rules' }),
  })
}

function friendName(f) {
  return f.remark || f.peer_nickname || f.nickname || ('ID' + (f.peer_user_id || f.user_id || ''))
}

function setCommunitySub(sub) {
  const next = ['official', 'mine', 'created', 'friends'].indexOf(sub) >= 0 ? sub : 'official'
  if (communitySub.value === next) return
  communitySub.value = next
  if (next === 'mine' || next === 'created' || next === 'friends') {
    stopOfficialCommunityPoll()
    void loadCommunityExtra({ force: false })
  } else {
    startOfficialCommunityPoll()
  }
  nextTick(() => measureCommunityLayout())
}

let officialCommunityPoll = null
function stopOfficialCommunityPoll() {
  if (officialCommunityPoll) {
    clearInterval(officialCommunityPoll)
    officialCommunityPoll = null
  }
}
function startOfficialCommunityPoll() {
  stopOfficialCommunityPoll()
  if (communitySub.value !== 'official') return
  officialCommunityPoll = setInterval(() => {
    if (communitySub.value !== 'official') {
      stopOfficialCommunityPoll()
      return
    }
    loadCommunityQuiet()
  }, 20000)
}
async function loadCommunityQuiet() {
  try {
    const rec = await apiRequest('communityrecommend', 'GET', {})
    const rows = (rec && (rec.list || rec.rows || rec.items)) || rec || []
    if (!Array.isArray(rows)) return
    communityRecs.value = rows
    markMineInRecs()
  } catch (e) {}
}

async function loadCommunity() {
  try {
    const rec = await apiRequest('communityrecommend', 'GET', {})
    const rows = (rec && (rec.list || rec.rows || rec.items)) || rec || []
    communityRecs.value = Array.isArray(rows) ? rows : []
  } catch (e) {
    communityRecs.value = []
  }
  await loadCommunityExtra({ force: false })
  markMineInRecs()
  startOfficialCommunityPoll()
}

function normalizeMyGroups(list) {
  return (list || []).map((g) =>
    Object.assign({}, g, {
      is_member: true,
      my_role: (g.my_role | 0) || (g.role | 0) || 0,
    })
  )
}

async function loadCommunityExtra(opts) {
  const force = !!(opts && opts.force)
  const now = Date.now()
  if (!force && communityExtraOk && communityExtraAt > 0 && now - communityExtraAt < COMMUNITY_EXTRA_TTL_MS) {
    return
  }
  if (communityExtraInflight) return communityExtraInflight

  const showLoading = !communityExtraOk || !(myGroups.value || []).length
  if (showLoading) communityExtraLoading.value = true

  communityExtraInflight = (async () => {
    try {
      await imConnect()
      const [mineRes, frRes] = await Promise.all([
        listMyGroups().then((r) => ({ ok: true, r })).catch(() => ({ ok: false, r: null })),
        listFriends().then((r) => ({ ok: true, r })).catch(() => ({ ok: false, r: null })),
      ])
      if (mineRes.ok) {
        const md = (mineRes.r && mineRes.r.data) || {}
        myGroups.value = normalizeMyGroups(md.list || md.items || [])
      }
      if (frRes.ok) {
        const fd = (frRes.r && frRes.r.data) || {}
        friends.value = fd.list || fd.items || []
      }
      if (mineRes.ok || frRes.ok) {
        communityExtraAt = Date.now()
        communityExtraOk = true
      }
      if (communitySub.value === 'official') {
        markMineInRecs()
      }
    } finally {
      communityExtraLoading.value = false
      communityExtraInflight = null
    }
  })()
  return communityExtraInflight
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

function isMyGroupMember(groupId) {
  const gid = groupId | 0
  if (!gid) return false
  return (myGroups.value || []).some((x) => ((x.id || x.group_id) | 0) === gid)
}

async function openGroup(g) {
  const groupId = (g && (g.id || g.group_id)) | 0
  if (!groupId) return
  const alreadyMember = !!(g && g.is_member) || isMyGroupMember(groupId)
  try {
    if (!alreadyMember) {
      await joinGroup(groupId)
      uni.showToast({ title: '已加入社群', icon: 'none' })
      communityExtraOk = false
      communityExtraAt = 0
      await loadCommunityExtra({ force: true })
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

function openCreateGroupPane(opts = {}) {
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

async function loadMyGroupsSafe() {
  try {
    const packet = await listMyGroups()
    const data = (packet && packet.data) || {}
    myGroups.value = normalizeMyGroups(data.list || data.groups || [])
    communityExtraAt = Date.now()
    communityExtraOk = true
  } catch (e) {}
}

async function refreshAuthFlags() {
  canCreateGroup.value = canCreateGroupFromAuth()
}

onShow(() => {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  pageAlive = true
  measureCommunityLayout()
  setTimeout(() => {
    if (pageAlive) measureCommunityLayout()
  }, 50)
  bindForegroundResume()
  resumeFromBackground('community-onShow')
  if (typeof off === 'function') off()
  off = onImEvent((type) => {
    if (type === 'auth.ok') refreshAuthFlags()
    if (type === 'group.created' || type === 'group.kicked') {
      loadMyGroupsSafe()
    }
  })
  void loadCommunity().then(() => refreshAuthFlags())
})

onHide(() => {
  pageAlive = false
  stopOfficialCommunityPoll()
  if (typeof off === 'function') {
    off()
    off = null
  }
})
</script>

<style scoped>
.community-page,
.community-page.community-page--official {
  background: #ffffff !important;
}
.chat-community-body-scroll {
  flex: none;
  min-height: 120px;
  width: 100%;
  box-sizing: border-box;
  background: #ffffff;
}
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
.chat-official-list {
  display: flex;
  flex-direction: column;
  gap: 0;
  padding: 0 0 20px;
  box-sizing: border-box;
  background: transparent;
}
.chat-official-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 14px;
  border-radius: 0;
  background: #fff;
  box-shadow: none;
  border-bottom: 0.5px solid #e5e5e5;
}
.chat-friend-feed-list,
.chat-my-groups-list {
  padding-bottom: 20px !important;
  box-sizing: border-box;
  gap: 0 !important;
  background: transparent;
}
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
.chat-community-pane--official,
.chat-community-pane--feed {
  display: flex !important;
  flex-direction: column !important;
  flex: 1 1 auto !important;
  min-height: 0 !important;
  overflow: hidden !important;
  box-sizing: border-box !important;
}
.chat-community-pane--official,
.chat-community-pane--official .chat-community-body-scroll {
  background: #ffffff !important;
}
.chat-community-pane--official .chat-community-body-scroll,
.chat-community-pane--feed .chat-community-body-scroll {
  flex: none !important;
  min-height: 0 !important;
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
/* #ifdef APP-PLUS */
.chat-create-group-pane.cg-app-fix {
  top: var(--cg-app-top, 88px) !important;
}
.chat-create-group-pane.cg-app-fix .chat-cg-header {
  padding-top: 14px !important;
  padding-bottom: 18px !important;
}
.chat-create-group-pane.cg-app-fix .chat-cg-main {
  margin-top: 0 !important;
  padding-top: 18px !important;
}
/* #endif */
</style>

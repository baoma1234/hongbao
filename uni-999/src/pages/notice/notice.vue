<template>
  <view class="messages-page notice-page">
    <TopBar :title="tt('chat_tab_notice', '社区') || '社区'" />
    <view
      id="tabNotice"
      class="tab-page active msg-tab-root"
      :style="tabRootStyle"
    >
      <view class="chat-shell">
        <view class="chat-list-pane">
          <view class="chat-list-main">
            <view
              id="chatHomePanelNotice"
              class="chat-home-panel chat-notice-feed-panel"
              :style="panelHostStyle"
            >
              <view class="chat-community-seg chat-notice-seg is-4" id="chatNoticeCats" role="tablist">
                <view
                  class="chat-community-seg-btn"
                  :class="{ active: noticeCat === 'latest' }"
                  @click="setNoticeCat('latest')"
                >最新发布</view>
                <view
                  class="chat-community-seg-btn"
                  :class="{ active: noticeCat === 'promote' }"
                  @click="setNoticeCat('promote')"
                >推广赚钱</view>
                <view
                  class="chat-community-seg-btn"
                  :class="{ active: noticeCat === 'ads' }"
                  @click="setNoticeCat('ads')"
                >彩金白嫖</view>
                <view
                  class="chat-community-seg-btn is-long"
                  :class="{ active: noticeCat === 'rules' }"
                  @click="setNoticeCat('rules')"
                >红宝•海外圈内事</view>
              </view>
              <view class="chat-notice-toolbar-wrap">
                <view class="chat-notice-toolbar">
                  <view class="chat-notice-search">
                    <view
                      class="chat-notice-search-mod"
                      :class="{ open: searchModMenuOpen }"
                      @click.stop="toggleSearchModMenu"
                    >
                      <text class="chat-notice-search-mod-txt">{{ searchModShortLabel }}</text>
                      <text class="chat-notice-search-mod-caret">{{ searchModMenuOpen ? '▴' : '▾' }}</text>
                    </view>
                    <input
                      class="chat-notice-search-input"
                      type="search"
                      confirm-type="search"
                      :focus="searchInputFocus"
                      :value="noticeKeyword"
                      :placeholder="searchPlaceholder"
                      @focus="onSearchFocus"
                      @input="onNoticeKeywordInput"
                      @confirm="submitNoticeSearch"
                      @blur="onSearchBlur"
                    />
                  </view>
                  <view class="chat-notice-post-btn" @click="goNoticeCompose">
                    <text class="chat-notice-post-ico">✎</text>
                    <text>{{ tt('notice_post_btn', '发帖') }}</text>
                  </view>
                </view>
                <view
                  v-if="searchModMenuOpen || searchThemeMenuOpen"
                  class="chat-notice-search-mask"
                  @click="closeSearchMenus"
                />
                <view v-if="searchModMenuOpen" class="chat-notice-search-drop">
                  <view
                    v-for="m in searchModOptions"
                    :key="m.code"
                    class="chat-notice-search-drop-item"
                    :class="{ active: noticeCat === m.code }"
                    @click.stop="pickSearchMod(m.code)"
                  >
                    <text class="chat-notice-search-drop-title">{{ m.title }}</text>
                    <text v-if="noticeCat === m.code" class="chat-notice-search-drop-check">✓</text>
                  </view>
                </view>
                <view v-else-if="searchThemeMenuOpen" class="chat-notice-search-drop chat-notice-theme-drop">
                  <view class="chat-notice-search-drop-hd">
                    <text class="chat-notice-search-drop-hd-txt">选择「{{ searchModTitleLabel }}」主题</text>
                  </view>
                  <view
                    class="chat-notice-search-drop-item"
                    :class="{ active: !noticeThemeId }"
                    @click.stop="pickSearchTheme(null)"
                  >
                    <text class="chat-notice-search-drop-title">全部主题</text>
                    <text v-if="!noticeThemeId" class="chat-notice-search-drop-check">✓</text>
                  </view>
                  <view
                    v-for="t in searchThemes"
                    :key="t.id"
                    class="chat-notice-search-drop-item"
                    :class="{ active: noticeThemeId === (t.id | 0) }"
                    @click.stop="pickSearchTheme(t)"
                  >
                    <text class="chat-notice-search-drop-title">{{ t.title }}</text>
                    <text v-if="noticeThemeId === (t.id | 0)" class="chat-notice-search-drop-check">✓</text>
                  </view>
                  <view v-if="!searchThemes.length && !searchThemesLoading" class="chat-notice-search-drop-empty">
                    该模块暂无主题
                  </view>
                  <view v-if="searchThemesLoading" class="chat-notice-search-drop-empty">加载中…</view>
                </view>
              </view>
              <view class="chat-notice-pane" :style="noticePaneStyle">
                <scroll-view
                  class="chat-notice-body-scroll"
                  scroll-y
                  :style="noticeScrollStyle"
                  :show-scrollbar="false"
                >
                  <view
                    v-if="fissionNoticeVisible && (noticeCat === 'latest' || noticeCat === 'promote')"
                    class="chat-fission-card"
                  >
                    <view class="chat-fission-card-hd">
                      <text class="chat-fission-card-tag">官方活动</text>
                      <text class="chat-fission-card-time">{{ fissionNoticeTime }}</text>
                    </view>
                    <view class="chat-fission-envelope" @click="goFissionFromNotice">
                      <text class="chat-fission-title">裂变红宝</text>
                      <text class="chat-fission-pool">¥ {{ fissionNoticePool }} 奖金池</text>
                      <text class="chat-fission-progress">当前 {{ fissionNoticeQuals }} / {{ fissionNoticeCap }} 份资格</text>
                      <text class="chat-fission-remain">剩余 {{ fissionNoticeRemain }}</text>
                      <view class="chat-fission-cta" :class="{ disabled: fissionNoticeEnded }">
                        {{ fissionNoticeEnded ? '活动已结束' : '点击拆开红包' }}
                      </view>
                      <text class="chat-fission-risk">有资格即可拆包，无需等人数满</text>
                    </view>
                    <view class="chat-fission-card-ft" @click.stop="openFissionShare">
                      <view class="chat-notice-share-btn">分享</view>
                    </view>
                  </view>

                  <view
                    v-if="noticeCat === 'promote'"
                    class="chat-promote-earn-wrap"
                    id="chatPromoteEarnWrap"
                  >
                    <view class="chat-promote-earn-card">
                      <view class="chat-promote-earn-hd">
                        <text class="chat-promote-earn-title">{{ tt('promote_earn_title', '推广收益数据表') }}</text>
                        <text class="chat-promote-earn-live" @click="refreshPromoteEarnMock">{{ tt('promote_earn_live', '实时更新 ›') }}</text>
                      </view>
                      <view class="chat-promote-earn-table">
                        <view class="chat-promote-earn-thead">
                          <view class="chat-promote-earn-th"><text>{{ tt('promote_earn_col_uid', '用户ID') }}</text></view>
                          <view class="chat-promote-earn-th is-active">
                            <text class="pe-th-pill">{{ tt('promote_earn_col_type', '收益类型') }}</text>
                          </view>
                          <view class="chat-promote-earn-th"><text>{{ tt('promote_earn_col_detail', '广细记录') }}</text></view>
                          <view class="chat-promote-earn-th"><text>{{ tt('promote_earn_col_amount', '到手佣金') }}</text></view>
                        </view>
                        <view class="chat-promote-earn-viewport">
                          <view
                            class="chat-promote-earn-track"
                            :style="promoteEarnTrackStyle"
                          >
                            <view
                              v-for="(row, idx) in promoteEarnDisplayRows"
                              :key="'pe-' + idx"
                              class="chat-promote-earn-row"
                            >
                              <view class="chat-promote-earn-td"><text>{{ row.uidMasked }}</text></view>
                              <view class="chat-promote-earn-td"><text>{{ row.typeLabel }}</text></view>
                              <view class="chat-promote-earn-td is-detail"><text>{{ row.detailLabel }}</text></view>
                              <view class="chat-promote-earn-td is-amt"><text>{{ row.amountText }}</text></view>
                            </view>
                          </view>
                        </view>
                      </view>
                    </view>
                  </view>

                  <view class="chat-notice-feed" id="chatNoticeFeed">
                    <view
                      v-for="n in notices"
                      :key="n.id || n.publishtime || n.createtime"
                      class="chat-notice-card"
                      @click="openNoticeDetail(n)"
                    >
                      <view class="chat-notice-hd" @click.stop="openNoticeDetail(n)">
                        <image
                          class="chat-notice-avatar"
                          :src="avatarSrc(n.author_avatar || '')"
                          mode="aspectFill"
                        />
                        <view class="chat-notice-meta">
                          <view class="chat-notice-name-row">
                            <text class="chat-notice-name">{{ n.author_name || '红宝官方公告' }}</text>
                            <text class="chat-notice-day">{{ noticeRelativeDay(n) }}</text>
                            <text v-if="noticeTagLabel(n)" class="chat-notice-tag">【{{ noticeTagLabel(n) }}】</text>
                            <text class="chat-notice-views">{{ formatViews(n) }} 浏览</text>
                            <text v-if="n.status === 'pending'" class="chat-notice-status pending">待审核</text>
                            <text v-else-if="n.status === 'rejected'" class="chat-notice-status rejected">已拒绝</text>
                          </view>
                        </view>
                        <view class="chat-notice-time">{{ noticeClock(n) }}</view>
                      </view>
                      <LinkifiedText
                        class="chat-notice-body chat-notice-body-clamp"
                        :text="n.content || n.summary || n.title || ''"
                      />
                      <view v-if="noticeVideo(n)" class="chat-notice-media" @click.stop>
                        <video
                          class="chat-notice-video"
                          :src="noticeVideo(n)"
                          controls
                          object-fit="contain"
                        />
                      </view>
                      <view
                        v-if="noticeImages(n).length"
                        class="chat-notice-media"
                      >
                        <view
                          class="chat-notice-imgs"
                          :class="'imgs-' + Math.min(9, noticeImages(n).length)"
                        >
                          <view
                            v-for="(src, ii) in noticeImages(n).slice(0, 9)"
                            :key="ii"
                            class="chat-notice-img-wrap"
                            @click.stop="previewNoticeImages(n, ii)"
                          >
                            <image
                              class="chat-notice-img"
                              :src="avatarSrc(src)"
                              :mode="noticeImagesFull(n) ? 'widthFix' : 'aspectFill'"
                            />
                          </view>
                        </view>
                      </view>
                      <view v-if="noticeActionButtons(n).length" class="chat-notice-actions" @click.stop>
                        <view
                          v-for="(btn, bi) in noticeActionButtons(n)"
                          :key="bi"
                          class="chat-notice-action-btn"
                          :class="btn.cls"
                          @click="handleNoticeAction(btn.action, btn.url, btn.label)"
                        >{{ btn.label }}</view>
                      </view>
                      <view class="chat-notice-ft" @click.stop>
                        <view class="chat-notice-share-btn" @click="shareNoticeToCommunity(n)">分享到社群</view>
                      </view>
                    </view>
                    <view v-if="!notices.length" class="chat-empty chat-empty-glass">暂无公告</view>
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

    <view v-if="shareSheetOpen" class="chat-share-sheet-mask" @click="closeShareSheet">
      <view class="chat-share-sheet" @click.stop>
        <view class="chat-share-sheet-hd">
          <text class="chat-share-sheet-title">分享到</text>
          <text class="chat-share-sheet-close" @click="closeShareSheet">关闭</text>
        </view>
        <view class="chat-share-sheet-preview">{{ sharePreviewText }}</view>
        <scroll-view scroll-y class="chat-share-sheet-list">
          <view v-if="shareTargets.length" class="chat-share-sec-lab">好友</view>
          <view
            v-for="f in shareFriendTargets"
            :key="'f-' + shareFriendId(f)"
            class="chat-share-row"
            @click="sendShareToFriend(f)"
          >
            <image class="chat-share-av" :src="avatarSrc(f.avatar || f.peer_avatar || '')" mode="aspectFill" />
            <text class="chat-share-name">{{ friendName(f) }}</text>
            <text class="chat-share-go">发送</text>
          </view>
          <view v-if="shareGroupTargets.length" class="chat-share-sec-lab">可发言群聊</view>
          <view
            v-for="g in shareGroupTargets"
            :key="'g-' + ((g.id || g.group_id) | 0)"
            class="chat-share-row"
            @click="sendShareToGroup(g)"
          >
            <image class="chat-share-av" :src="avatarSrc(g.avatar_url || g.avatar || '')" mode="aspectFill" />
            <text class="chat-share-name">{{ g.name || ('群' + (g.id || g.group_id)) }}</text>
            <text class="chat-share-go">发送</text>
          </view>
          <view v-if="!shareTargets.length && !shareLoading" class="chat-empty">暂无可分享的好友或群</view>
          <view v-if="shareLoading" class="chat-empty">加载中…</view>
        </scroll-view>
      </view>
    </view>

    <BottomTabBar active="notice" />
  </view>
</template>

<script setup>
import { computed, nextTick, onUnmounted, ref, watch } from 'vue'
import { onLoad, onShow, onHide } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import BottomTabBar from '../../components/BottomTabBar.vue'
import LinkifiedText from '../../components/LinkifiedText.vue'
import '../../styles/chat-messages-list.css'
import '../../styles/chat-uni-adapter.css'
import '../../styles/chat-messages-parity.css'
import '../../styles/chat-qq-theme.css'
import { apiRequest, fetchConfig, getToken } from '../../utils/auth.js'
import { applySafeAreaCssVars, getSafeAreaInsets, getTopBarContentHeight } from '../../utils/safe-area.js'
import { avatarSrc, publicUrl } from '../../utils/chat.js'
import { tt } from '../../utils/i18n.js'
import {
  fetchGroupInfo,
  imConnect,
  imSend,
  listFriends,
  listMyGroups,
} from '../../utils/im.js'

const panelScrollPx = ref(420)
const tabRootPx = ref(0)
const notices = ref([])
const noticeCat = ref('latest')
const noticeKeyword = ref('')
let noticeSearchTimer = null
const searchModMenuOpen = ref(false)
const searchThemeMenuOpen = ref(false)
const searchInputFocus = ref(false)
const searchThemes = ref([])
const searchThemesLoading = ref(false)
const noticeThemeId = ref(0)
const noticeThemeTitle = ref('')
let searchThemesCat = ''
/** 选完主题后回填焦点时，跳过一次「弹出主题」 */
const searchThemeSkipOnce = ref(false)

const searchModOptions = [
  { code: 'latest', title: '最新发布', short: '最新' },
  { code: 'promote', title: '推广赚钱', short: '推广' },
  { code: 'ads', title: '彩金白嫖', short: '彩金' },
  { code: 'rules', title: '红宝•海外圈内事', short: '海外' },
]

const searchModShortLabel = computed(() => {
  const hit = searchModOptions.find((m) => m.code === noticeCat.value)
  return (hit && hit.short) || '最新'
})

const searchModTitleLabel = computed(() => {
  const hit = searchModOptions.find((m) => m.code === noticeCat.value)
  return (hit && hit.title) || '最新发布'
})

const searchPlaceholder = computed(() => {
  if (noticeThemeTitle.value) {
    return '在「' + noticeThemeTitle.value + '」中搜索…'
  }
  const hit = searchModOptions.find((m) => m.code === noticeCat.value)
  const name = (hit && hit.title) || '最新发布'
  return '在「' + name + '」中搜索…'
})
const chatFissionCardEnabled = ref(false)
const promoteEarnRows = ref([])
const promoteEarnOffset = ref(0)
let promoteEarnTimer = null
const fissionNotice = ref(null)
const fissionNoticeRemainSec = ref(0)
let fissionNoticeTick = null
let noticeViewsBumpTimer = null
let pageAlive = false

const myGroups = ref([])
const friends = ref([])

const shareSheetOpen = ref(false)
const shareLoading = ref(false)
const shareTextPayload = ref('')
const shareImagePayloads = ref([])
const shareFissionPayload = ref(null)
const shareFriendTargets = ref([])
const shareGroupTargets = ref([])
const shareBusy = ref(false)

const tabRootStyle = computed(() => {
  const h = Number(tabRootPx.value) || 0
  if (h < 200) return {}
  return { height: h + 'px', minHeight: h + 'px' }
})
const panelHostStyle = computed(() => {
  const h = Number(panelScrollPx.value) || 420
  return { height: h + 'px', minHeight: h + 'px', maxHeight: h + 'px', flex: 'none', overflow: 'hidden' }
})
const noticeScrollStyle = computed(() => {
  let h = Number(panelScrollPx.value) || 420
  // Seg(~48) + 搜索发帖栏(~52)
  h = Math.max(180, h - 100)
  return {
    height: h + 'px',
    minHeight: h + 'px',
    maxHeight: h + 'px',
    flex: 'none',
    width: '100%',
  }
})
const noticePaneStyle = computed(() => {
  const h = Number(panelScrollPx.value) || 420
  const inner = Math.max(180, h - 100)
  return {
    height: inner + 'px',
    minHeight: inner + 'px',
    maxHeight: inner + 'px',
    flex: 'none',
    overflow: 'hidden',
  }
})

function measureNoticeLayout() {
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
    // 底栏约 52 + padding；勿过大，否则列表底与 Tab 之间留灰缝
    const tabBar = 64 + Number(inset.bottom || 0)
    const shell = Math.max(280, winH - status - topBar - tabBar)
    tabRootPx.value = shell
    // panel 铺满 tabRoot，Seg 高度在 noticePane 内再扣
    panelScrollPx.value = Math.max(220, shell)
  } catch (e) {
    tabRootPx.value = 0
    panelScrollPx.value = 420
  }
}

function friendName(f) {
  return f.remark || f.peer_nickname || f.nickname || ('ID' + (f.peer_user_id || f.user_id || ''))
}

function noticeCatLabel(n) {
  const label = String((n && n.category_label) || '').trim()
  if (label) return label
  const c = String((n && n.category) || noticeCat.value || '')
  if (c === 'promote') return '推广赚钱'
  if (c === 'ads') return '彩金白嫖'
  if (c === 'rules') return '红宝•海外圈内事'
  if (c === 'latest') return '最新发布'
  return c
}

function noticeTagLabel(n) {
  const tag = String((n && (n.tag_label || n.theme_title)) || '').trim()
  if (tag) return tag
  return noticeCatLabel(n)
}

function formatViews(n) {
  const v = Number((n && n.views_count) || 0) || 0
  if (v >= 10000) {
    const w = Math.round(v / 1000) / 10
    return (Number.isInteger(w) ? String(w) : w.toFixed(1)) + '万'
  }
  return String(v)
}

function onNoticeKeywordInput(e) {
  const v = (e && e.detail && e.detail.value != null) ? e.detail.value : (e && e.target && e.target.value) || ''
  noticeKeyword.value = String(v)
  if (noticeSearchTimer) clearTimeout(noticeSearchTimer)
  noticeSearchTimer = setTimeout(() => {
    loadNotices()
  }, 380)
}

function submitNoticeSearch() {
  if (noticeSearchTimer) {
    clearTimeout(noticeSearchTimer)
    noticeSearchTimer = null
  }
  loadNotices()
}

function toggleSearchModMenu() {
  searchThemeMenuOpen.value = false
  searchModMenuOpen.value = !searchModMenuOpen.value
  if (searchModMenuOpen.value) {
    searchInputFocus.value = false
  }
}

function closeSearchMenus() {
  if (searchThemeMenuOpen.value) {
    searchThemeSkipOnce.value = true
  }
  searchModMenuOpen.value = false
  searchThemeMenuOpen.value = false
}

function closeSearchModMenu() {
  closeSearchMenus()
}

async function ensureSearchThemes(force) {
  const cat = String(noticeCat.value || 'latest')
  if (!force && searchThemesCat === cat && searchThemes.value.length) {
    return
  }
  searchThemesLoading.value = true
  searchThemesCat = cat
  try {
    const data = await apiRequest('noticethemes', 'GET', { category: cat })
    const list = (data && (data.list || data.rows)) || []
    searchThemes.value = Array.isArray(list) ? list : []
  } catch (e) {
    searchThemes.value = []
  } finally {
    searchThemesLoading.value = false
  }
}

async function openSearchThemeMenu() {
  searchModMenuOpen.value = false
  searchThemeMenuOpen.value = true
  searchInputFocus.value = false
  await ensureSearchThemes(false)
}

function onSearchFocus() {
  if (searchThemeSkipOnce.value) {
    searchThemeSkipOnce.value = false
    searchThemeMenuOpen.value = false
    return
  }
  // 点输入框：先弹出当前模块主题（海外→海外快讯/生活故事…）
  void openSearchThemeMenu()
}

function onSearchBlur() {
  // 延迟清 focus，避免点下拉时立刻失焦
  setTimeout(() => {
    searchInputFocus.value = false
  }, 120)
}

function pickSearchMod(code) {
  const next = String(code || 'latest')
  const allowed = searchModOptions.map((m) => m.code)
  const cat = allowed.indexOf(next) >= 0 ? next : 'latest'
  const changed = cat !== noticeCat.value
  noticeCat.value = cat
  searchModMenuOpen.value = false
  if (changed) {
    noticeThemeId.value = 0
    noticeThemeTitle.value = ''
    searchThemes.value = []
    searchThemesCat = ''
  }
  loadNotices()
  // 选完模块后立刻弹出该模块主题
  nextTick(() => {
    void openSearchThemeMenu()
  })
}

function pickSearchTheme(t) {
  if (!t) {
    noticeThemeId.value = 0
    noticeThemeTitle.value = ''
  } else {
    noticeThemeId.value = (t.id | 0) || 0
    noticeThemeTitle.value = String(t.title || '').trim()
  }
  searchThemeMenuOpen.value = false
  searchThemeSkipOnce.value = true
  loadNotices()
  searchInputFocus.value = false
  nextTick(() => {
    searchInputFocus.value = true
  })
}

function goNoticeCompose() {
  if (!getToken()) {
    uni.navigateTo({ url: '/pages/login/login' })
    return
  }
  let cat = String(noticeCat.value || 'ads')
  // 最新发布 / 推广赚钱 暂不开放用户发帖
  if (cat === 'latest' || cat === 'promote') {
    cat = 'ads'
  }
  uni.navigateTo({ url: '/pages/notice/compose?category=' + encodeURIComponent(cat) })
}

function openNoticeDetail(n) {
  const id = (n && n.id) | 0
  if (!id) return
  uni.navigateTo({ url: '/pages/notice/detail?id=' + id })
}

function noticeTs(n) {
  return (n && (n.publishtime || n.createtime || n.updatetime || n.time)) | 0
}

function noticeRelativeDay(n) {
  let ts = noticeTs(n)
  if (!ts) return ''
  if (ts > 1e12) ts = Math.floor(ts / 1000)
  const d = new Date(ts * 1000)
  const now = new Date()
  const startToday = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime()
  const startThat = new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()
  const diff = Math.round((startToday - startThat) / 86400000)
  if (diff === 0) return '今天'
  if (diff === 1) return '昨天'
  if (diff > 1 && diff < 7) return diff + '天前'
  return (d.getMonth() + 1) + '月' + d.getDate() + '日'
}

function noticeClock(n) {
  let ts = noticeTs(n)
  if (!ts) return ''
  if (ts > 1e12) ts = Math.floor(ts / 1000)
  const d = new Date(ts * 1000)
  const hh = String(d.getHours()).padStart(2, '0')
  const mm = String(d.getMinutes()).padStart(2, '0')
  return hh + ':' + mm
}

function noticeVideo(n) {
  return String((n && n.video) || '').trim()
}

function noticeImages(n) {
  const imgs = n && n.images
  if (!Array.isArray(imgs)) return []
  // 本站上传会把图存成 /uploads/*.js，按图片展示
  return imgs.filter(Boolean)
}

function noticeImagesFull(n) {
  // 多图一律九宫格合在一起；仅单图走大图模式
  return noticeImages(n).length === 1
}

function noticeActionButtons(n) {
  if (!n) return []
  const type = String(n.action_type || '')
  if (type === 'buttons' && Array.isArray(n.action_buttons) && n.action_buttons.length) {
    return n.action_buttons.map((btn) => ({
      label: String((btn && btn.label) || '').trim(),
      url: String((btn && btn.url) || ''),
      action: 'link',
      cls: 'soft',
    })).filter((b) => b.label)
  }
  const label = String(n.action_label || '').trim()
  if (!label) return []
  const isShare = type === 'share'
  return [{
    label,
    url: String(n.action_url || ''),
    action: isShare ? 'share' : 'link',
    cls: isShare ? 'primary' : 'wide-soft',
  }]
}

function previewNoticeImages(n, index) {
  const urls = noticeImages(n).map((u) => avatarSrc(u)).filter(Boolean)
  if (!urls.length) return
  uni.previewImage({ urls, current: urls[index | 0] || urls[0] })
}

function handleNoticeAction(action, url, label) {
  action = String(action || '')
  url = String(url || '').trim()
  label = String(label || '')
  if (action === 'share' || /邀请|推广|佣金|收益/.test(label)) {
    uni.navigateTo({ url: '/pages/commission/commission' })
    return
  }
  if (/裂变/.test(label) || /fission/i.test(url)) {
    uni.navigateTo({
      url: '/pages/fission/detail',
      fail: () => uni.reLaunch({ url: '/pages/fission/detail' }),
    })
    return
  }
  if (/红包|接力/.test(label)) {
    uni.switchTab({ url: '/pages/community/community' })
    return
  }
  if (!url) return
  if (/^https?:\/\//i.test(url)) {
    // #ifdef H5
    if (typeof window !== 'undefined') window.open(url, '_blank')
    // #endif
    // #ifndef H5
    uni.setClipboardData({
      data: url,
      success: () => uni.showToast({ title: '链接已复制', icon: 'none' }),
    })
    // #endif
    return
  }
  if (url.charAt(0) === '/') {
    uni.navigateTo({ url }).catch(() => {
      uni.switchTab({ url }).catch(() => {})
    })
  }
}

async function shareNoticeToCommunity(n) {
  const cat = noticeTagLabel(n)
  const text = String((n && (n.content || n.summary || n.title)) || '').trim()
  const shareText = (cat ? ('【' + cat + '】\n') : '') + (text || '')
  const images = noticeImages(n)
    .slice(0, 9)
    .map((src) => noticeImageMediaExtra(src))
    .filter(Boolean)
  await openShareSheet(shareText, images)
}

function noticeImageMediaExtra(src) {
  const raw = String(src || '').trim()
  if (!raw) return null
  let path = ''
  let full = ''
  if (/^https?:\/\//i.test(raw)) {
    full = raw.split('#')[0]
    const m = raw.match(/^https?:\/\/[^/?#]+(\/[^?#]*)/i)
    path = (m && m[1]) || ''
  } else if (raw.charAt(0) === '/') {
    path = raw.split('?')[0].split('#')[0]
    full = publicUrl(path) || avatarSrc(path) || path
  } else if (raw.indexOf('uploads/') === 0) {
    path = '/' + raw.replace(/^\/+/, '').split('?')[0].split('#')[0]
    full = publicUrl(path) || avatarSrc(path) || path
  } else {
    path = '/' + raw.replace(/^\/+/, '').split('?')[0].split('#')[0]
    full = publicUrl(path) || avatarSrc(path) || path
  }
  // 优先抽出 /uploads/ 相对路径（IM 入库用）；完整 https 作展示
  const up =
    (path && path.match(/(\/uploads\/[^?#]+)/i)) ||
    String(full || '').match(/(\/uploads\/[^?#]+)/i) ||
    String(raw || '').match(/(\/uploads\/[^?#]+)/i)
  if (up) {
    path = up[1].split('?')[0].split('#')[0]
  }
  const absOk = /^https?:\/\//i.test(full || raw)
  if (path.indexOf('/uploads/') !== 0 && !absOk) return null
  const checkPath = path.indexOf('/uploads/') === 0 ? path : (full || raw).split('?')[0]
  const ext = (String(checkPath).split('.').pop() || '').toLowerCase()
  // 本站上传偶发 .js 伪装图；外链需常见图片后缀
  if (path.indexOf('/uploads/') === 0) {
    if (ext && !['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'js'].includes(ext)) return null
  } else if (!['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'].includes(ext)) {
    return null
  }
  if (!full) full = publicUrl(path) || path
  // url：有 /uploads/ 用相对路径；否则直接用绝对链接（不重新上传）
  const sendUrl = path.indexOf('/uploads/') === 0 ? path : (full || raw)
  return { url: sendUrl, fullurl: full || sendUrl, name: '' }
}

const sharePreviewText = computed(() => {
  if (shareFissionPayload.value) {
    const p = shareFissionPayload.value
    return '裂变红宝卡片 · ¥' + (p.pool || '0') + ' · ' + (p.quals || 0) + '/' + (p.cap || 100)
  }
  const imgs = shareImagePayloads.value || []
  const s = String(shareTextPayload.value || '').trim()
  const imgPart = imgs.length ? imgs.length + ' 张图片' : ''
  const textPart = s ? (s.length > 60 ? s.slice(0, 60) + '…' : s) : ''
  if (imgPart && textPart) return imgPart + ' + ' + textPart
  return textPart || imgPart || '公告'
})
const shareTargets = computed(() => [
  ...(shareFriendTargets.value || []),
  ...(shareGroupTargets.value || []),
])

function shareFriendId(f) {
  return (f && (f.peer_user_id || f.user_id || f.id)) | 0
}

function closeShareSheet() {
  shareSheetOpen.value = false
  shareFissionPayload.value = null
  shareImagePayloads.value = []
  shareTextPayload.value = ''
}

function normalizeMyGroups(list) {
  return (list || []).map((g) =>
    Object.assign({}, g, {
      is_member: true,
      my_role: (g.my_role | 0) || (g.role | 0) || 0,
    })
  )
}

async function loadShareTargets() {
  shareSheetOpen.value = true
  shareLoading.value = true
  shareFriendTargets.value = []
  shareGroupTargets.value = []
  try {
    await imConnect()
    if (!friends.value.length || !myGroups.value.length) {
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
    }
    shareFriendTargets.value = (friends.value || []).filter((f) => shareFriendId(f) > 0)
    const groups = myGroups.value || []
    const speakable = []
    const slice = groups.slice(0, 24)
    await Promise.all(
      slice.map(async (g) => {
        const gid = (g.id || g.group_id) | 0
        if (!gid) return
        const role = (g.my_role | 0) || (g.role | 0) || 0
        if (role >= 2) {
          speakable.push(g)
          return
        }
        try {
          const info = await fetchGroupInfo(gid)
          const data = (info && info.data) || info || {}
          const pol = data.policy || {}
          if (data.can_speak === false) return
          if (pol.can_send_text === false) return
          speakable.push(g)
        } catch (e) {
          speakable.push(g)
        }
      })
    )
    shareGroupTargets.value = speakable
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '加载失败', icon: 'none' })
  } finally {
    shareLoading.value = false
  }
}

async function openShareSheet(text, images) {
  shareFissionPayload.value = null
  shareTextPayload.value = String(text || '').trim()
  shareImagePayloads.value = Array.isArray(images) ? images.filter(Boolean) : []
  if (!shareTextPayload.value && !shareImagePayloads.value.length) {
    uni.showToast({ title: '分享内容为空', icon: 'none' })
    return
  }
  await loadShareTargets()
}

async function openFissionShare() {
  const pool = fissionNoticePool.value
  const quals = fissionNoticeQuals.value
  const cap = fissionNoticeCap.value
  const ended = fissionNoticeEnded.value ? 1 : 0
  const act = (fissionNotice.value && fissionNotice.value.activity) || {}
  shareTextPayload.value = ''
  shareImagePayloads.value = []
  shareFissionPayload.value = {
    pool: String(pool),
    quals: quals | 0,
    cap: cap | 0,
    ended,
    activity_id: (act.id | 0) || 0,
  }
  await loadShareTargets()
}

async function sendNoticeSharePayload(sendFn) {
  const images = (shareImagePayloads.value || [])
    .map((ex) => {
      if (!ex) return null
      const url = String(ex.url || ex.fullurl || '').trim()
      if (!url) return null
      const full = String(ex.fullurl || ex.url || url).trim() || url
      return { url, fullurl: full, name: ex.name || '' }
    })
    .filter(Boolean)
    .slice(0, 9)
  const text = String(shareTextPayload.value || '').trim()

  // 图文合并为一条消息：直接复用帖子图片链接，不重新上传
  if (images.length) {
    const label = text || (images.length > 1 ? '[图片]x' + images.length : '[图片]')
    await sendFn({
      content: label,
      msg_type: 4,
      extra: {
        url: images[0].url,
        fullurl: images[0].fullurl,
        name: images[0].name || '',
        images,
        image_urls: images.map((x) => x.url),
        image_fullurls: images.map((x) => x.fullurl).filter(Boolean),
        count: images.length,
        ...(text ? { caption: text } : {}),
      },
    })
    return
  }
  if (text) {
    await sendFn({ content: text, msg_type: 1 })
  }
}

async function sendShareToFriend(f) {
  if (shareBusy.value) return
  const peer = shareFriendId(f)
  if (!peer) return
  shareBusy.value = true
  try {
    await imConnect()
    if (shareFissionPayload.value) {
      const p = shareFissionPayload.value
      await imSend(
        'private.send',
        {
          to_user_id: peer,
          content: '[裂变红宝]',
          msg_type: 11,
          extra: {
            fission: 1,
            pool: p.pool,
            quals: p.quals,
            cap: p.cap,
            ended: p.ended,
            activity_id: p.activity_id,
          },
        },
        true
      )
    } else {
      await sendNoticeSharePayload((body) =>
        imSend('private.send', Object.assign({ to_user_id: peer }, body), true)
      )
    }
    uni.showToast({ title: '已分享给好友', icon: 'success' })
    closeShareSheet()
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '发送失败', icon: 'none' })
  } finally {
    shareBusy.value = false
  }
}

async function sendShareToGroup(g) {
  if (shareBusy.value) return
  const gid = (g && (g.id || g.group_id)) | 0
  if (!gid) return
  shareBusy.value = true
  try {
    await imConnect()
    if (shareFissionPayload.value) {
      const p = shareFissionPayload.value
      await imSend(
        'group.send',
        {
          group_id: gid,
          content: '[裂变红宝]',
          msg_type: 11,
          extra: {
            fission: 1,
            pool: p.pool,
            quals: p.quals,
            cap: p.cap,
            ended: p.ended,
            activity_id: p.activity_id,
          },
        },
        true
      )
    } else {
      await sendNoticeSharePayload((body) =>
        imSend('group.send', Object.assign({ group_id: gid }, body), true)
      )
    }
    uni.showToast({ title: '已分享到群', icon: 'success' })
    closeShareSheet()
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '发送失败（可能禁言）', icon: 'none' })
  } finally {
    shareBusy.value = false
  }
}

const PROMOTE_EARN_VISIBLE = 8
const PROMOTE_EARN_ROW_H = 36

function promoteEarnMaskUid(uid) {
  uid = String(uid == null ? '' : uid).replace(/\D/g, '')
  if (uid.length <= 4) return '****'
  if (uid.length <= 6) return uid.slice(0, 1) + '****' + uid.slice(-1)
  const head = Math.floor((uid.length - 4) / 2)
  const tail = uid.length - 4 - head
  return uid.slice(0, head) + '****' + uid.slice(uid.length - tail)
}

function promoteEarnTypeLabel(key) {
  return key === 'group'
    ? tt('promote_earn_type_group', '红包返佣')
    : tt('promote_earn_type_share', '分享推广')
}

function promoteEarnDetailLabel(key, n) {
  if (key === 'promote_earn_detail_group_fee') return '红包抽成返佣'
  if (key === 'promote_earn_detail_groups_n') return '自建' + n + '群红包返利'
  if (key === 'promote_earn_detail_multi') return '多群互动返现'
  if (key === 'promote_earn_detail_exposure') return '推广曝光成交收益'
  return '分享链接引流' + n + '人'
}

function buildPromoteEarnMockRows(count) {
  // 滚动池至少覆盖「可见 8 行」的数倍，刷新时仍保证视口内始终 8 条
  count = Math.max(PROMOTE_EARN_VISIBLE * 2, Math.min(40, count || PROMOTE_EARN_VISIBLE * 3))
  const shareDetails = [
    'promote_earn_detail_share_n',
    'promote_earn_detail_multi',
    'promote_earn_detail_exposure',
  ]
  const rows = []
  for (let i = 0; i < count; i++) {
    const r = Math.random()
    const typeKey = r < 0.55 ? 'share' : 'group'
    const detailKey = typeKey === 'group'
      ? 'promote_earn_detail_group_fee'
      : shareDetails[Math.floor(Math.random() * shareDetails.length)]
    const n = 3 + Math.floor(Math.random() * 40)
    const uidNum = 10000000 + Math.floor(Math.random() * 90000000)
    let amt = 18 + Math.random() * 160 + (i % 7) * 3.17
    amt = Math.round(amt * 100) / 100
    rows.push({
      uidMasked: promoteEarnMaskUid(String(uidNum)),
      typeLabel: promoteEarnTypeLabel(typeKey),
      detailLabel: promoteEarnDetailLabel(detailKey, n),
      amountText: '¥' + amt.toFixed(2),
    })
  }
  return rows
}

const promoteEarnDisplayRows = computed(() => {
  const rows = promoteEarnRows.value || []
  if (!rows.length) return []
  return rows.concat(rows)
})

const promoteEarnTrackStyle = computed(() => ({
  transform: 'translateY(-' + (promoteEarnOffset.value | 0) + 'px)',
  transition: promoteEarnOffset.value ? 'transform 0.45s ease' : 'none',
}))

function stopPromoteEarnScroll() {
  if (promoteEarnTimer) {
    clearInterval(promoteEarnTimer)
    promoteEarnTimer = null
  }
  promoteEarnOffset.value = 0
}

function startPromoteEarnScroll() {
  stopPromoteEarnScroll()
  if (noticeCat.value !== 'promote') return
  if (!(promoteEarnRows.value && promoteEarnRows.value.length)) return
  const rowH = PROMOTE_EARN_ROW_H
  promoteEarnTimer = setInterval(() => {
    const half = (promoteEarnRows.value.length | 0) * rowH
    if (half < rowH * PROMOTE_EARN_VISIBLE) return
    promoteEarnOffset.value += rowH
    if (promoteEarnOffset.value >= half) {
      setTimeout(() => {
        promoteEarnOffset.value = 0
      }, 480)
    }
  }, 3000)
}

function syncPromoteEarnPanel() {
  if (noticeCat.value === 'promote') {
    if (!promoteEarnRows.value.length) {
      promoteEarnRows.value = buildPromoteEarnMockRows(PROMOTE_EARN_VISIBLE * 3)
    }
    startPromoteEarnScroll()
  } else {
    stopPromoteEarnScroll()
  }
}

function refreshPromoteEarnMock() {
  promoteEarnRows.value = buildPromoteEarnMockRows(PROMOTE_EARN_VISIBLE * 3)
  startPromoteEarnScroll()
  uni.showToast({ title: '已刷新收益数据', icon: 'none' })
}

watch(noticeCat, () => {
  syncPromoteEarnPanel()
})

function setNoticeCat(cat) {
  const allowed = ['latest', 'promote', 'ads', 'rules']
  const next = allowed.indexOf(cat) >= 0 ? cat : 'latest'
  noticeCat.value = next
  noticeThemeId.value = 0
  noticeThemeTitle.value = ''
  searchThemes.value = []
  searchThemesCat = ''
  searchModMenuOpen.value = false
  searchThemeMenuOpen.value = false
  syncPromoteEarnPanel()
  loadNotices()
}

async function loadChatFissionCardFlag() {
  try {
    const cfg = await fetchConfig()
    if (!cfg) return
    chatFissionCardEnabled.value = !!cfg.chat_fission_card_enabled
  } catch (e) {}
}

async function loadNotices() {
  try {
    const params = { page: 1, limit: 30, category: noticeCat.value }
    const kw = String(noticeKeyword.value || '').trim()
    if (kw) params.keyword = kw
    const tid = noticeThemeId.value | 0
    if (tid > 0) params.theme_id = tid
    const data = await apiRequest('notices', 'GET', params)
    const rows = (data && (data.list || data.rows || data.items)) || []
    notices.value = Array.isArray(rows) ? rows : []
  } catch (e) {
    notices.value = []
  }
  if (noticeCat.value === 'promote' || noticeCat.value === 'latest') {
    loadFissionNotice()
  }
}

async function tickNoticeViewsBump() {
  if (!pageAlive) return
  let bumped = false
  try {
    const data = await apiRequest('noticeviewsbump', 'POST', {})
    bumped = !!(data && data.bumped)
  } catch (e) {
    bumped = true
  }
  if (!bumped) return
  notices.value = (notices.value || []).map((n) => {
    if (!n || n.status === 'pending' || n.status === 'rejected') return n
    const add = 5 + Math.floor(Math.random() * 16)
    return Object.assign({}, n, { views_count: (Number(n.views_count) || 0) + add })
  })
}

function startNoticeViewsBump() {
  stopNoticeViewsBump()
  noticeViewsBumpTimer = setInterval(() => {
    void tickNoticeViewsBump()
  }, 60000)
}

function stopNoticeViewsBump() {
  if (noticeViewsBumpTimer) {
    clearInterval(noticeViewsBumpTimer)
    noticeViewsBumpTimer = null
  }
}

async function loadFissionNotice() {
  try {
    const data = await apiRequest('fissionentry', 'GET', {})
    fissionNotice.value = data || null
    const act = (data && data.activity) || {}
    const st = Number((data && data.server_time) || Math.floor(Date.now() / 1000))
    fissionNoticeRemainSec.value = Math.max(0, Number(act.end_time || 0) - st)
    if (fissionNoticeTick) clearInterval(fissionNoticeTick)
    if ((data && data.entry_state) === 'active' && fissionNoticeRemainSec.value > 0) {
      fissionNoticeTick = setInterval(() => {
        if (fissionNoticeRemainSec.value > 0) fissionNoticeRemainSec.value -= 1
        else clearInterval(fissionNoticeTick)
      }, 1000)
    }
  } catch (e) {
    fissionNotice.value = null
  }
}

const fissionNoticeVisible = computed(() => {
  if (!chatFissionCardEnabled.value) return false
  const f = fissionNotice.value
  return !!(f && f.has_activity)
})
const fissionNoticeEnded = computed(() => {
  const f = fissionNotice.value
  return !!(f && f.entry_state === 'ended')
})
const fissionNoticePool = computed(() => {
  const a = (fissionNotice.value && fissionNotice.value.activity) || {}
  return a.pool_amount != null ? a.pool_amount : 1000
})
const fissionNoticeQuals = computed(() => ((fissionNotice.value && fissionNotice.value.activity && fissionNotice.value.activity.global_quals) | 0))
const fissionNoticeCap = computed(() => ((fissionNotice.value && fissionNotice.value.activity && fissionNotice.value.activity.global_cap) || 100))
const fissionNoticeTime = computed(() => {
  const a = (fissionNotice.value && fissionNotice.value.activity) || {}
  const ts = Number(a.start_time || 0)
  if (!ts) return ''
  const d = new Date(ts * 1000)
  const pad = (n) => (n < 10 ? '0' + n : '' + n)
  return pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes())
})
const fissionNoticeRemain = computed(() => {
  const s = Math.max(0, fissionNoticeRemainSec.value | 0)
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  const sec = s % 60
  const pad = (n) => (n < 10 ? '0' + n : '' + n)
  return pad(h) + ':' + pad(m) + ':' + pad(sec)
})

function goFissionFromNotice() {
  uni.navigateTo({
    url: '/pages/fission/detail',
    fail: () => uni.reLaunch({ url: '/pages/fission/detail' }),
  })
}

onLoad((q) => {
  const cat = String((q && q.cat) || '').trim()
  const allowed = ['latest', 'promote', 'ads', 'rules']
  if (allowed.indexOf(cat) >= 0) noticeCat.value = cat
})

onShow(() => {
  try {
    const pending = String(uni.getStorageSync('fanshub_notice_cat') || '').trim()
    if (pending) {
      uni.removeStorageSync('fanshub_notice_cat')
      const allowed = ['latest', 'promote', 'ads', 'rules']
      if (allowed.indexOf(pending) >= 0) noticeCat.value = pending
    }
  } catch (e) {}
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  pageAlive = true
  measureNoticeLayout()
  setTimeout(() => {
    if (pageAlive) measureNoticeLayout()
  }, 50)
  void loadChatFissionCardFlag()
  syncPromoteEarnPanel()
  void loadNotices()
  startNoticeViewsBump()
  nextTick(() => measureNoticeLayout())
})

onHide(() => {
  pageAlive = false
  stopPromoteEarnScroll()
  stopNoticeViewsBump()
})

onUnmounted(() => {
  stopPromoteEarnScroll()
  stopNoticeViewsBump()
  if (fissionNoticeTick) clearInterval(fissionNoticeTick)
})
</script>

<style scoped>
/* 四分类：末项「红宝•海外圈内事」加宽可两行，五端不裁切 */
.chat-notice-seg {
  height: 44px;
  box-sizing: border-box;
  overflow: hidden;
}
.chat-notice-seg .chat-community-seg-btn {
  flex: 1 1 0;
  min-width: 0;
  padding: 0 3px;
  font-size: 12px;
  font-weight: 500;
  letter-spacing: 0;
  line-height: 1.2;
  white-space: normal;
  text-align: center;
  display: flex;
  align-items: center;
  justify-content: center;
  word-break: keep-all;
  overflow: hidden;
}
.chat-notice-seg .chat-community-seg-btn.is-long {
  flex: 1.65 1 0;
  font-size: 11px;
  line-height: 1.15;
}
.chat-notice-toolbar-wrap {
  position: relative;
  z-index: 20;
  flex-shrink: 0;
}
.chat-notice-search-mod {
  flex: 0 0 auto;
  max-width: 78px;
  height: 26px;
  padding: 0 6px 0 8px;
  margin-right: 2px;
  border-radius: 13px;
  background: #f0f0f0;
  display: flex;
  align-items: center;
  gap: 2px;
  box-sizing: border-box;
}
.chat-notice-search-mod.open {
  background: #e8f5ff;
}
.chat-notice-search-mod-txt {
  font-size: 12px;
  color: #333;
  line-height: 1;
  max-width: 48px;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}
.chat-notice-search-mod-caret {
  font-size: 10px;
  color: #888;
  line-height: 1;
}
.chat-notice-search-mask {
  position: fixed;
  left: 0;
  right: 0;
  top: 0;
  bottom: 0;
  z-index: 30;
  background: transparent;
}
.chat-notice-search-drop {
  position: absolute;
  left: 12px;
  right: 92px;
  top: 42px;
  z-index: 40;
  background: #fff;
  border-radius: 10px;
  border: 0.5px solid #e8e8e8;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
  overflow: hidden;
  box-sizing: border-box;
}
.chat-notice-search-drop-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  min-height: 42px;
  padding: 0 14px;
  border-bottom: 0.5px solid #f0f0f0;
  box-sizing: border-box;
}
.chat-notice-search-drop-item:last-child {
  border-bottom: none;
}
.chat-notice-search-drop-item.active {
  background: #f7fafc;
}
.chat-notice-search-drop-hd {
  padding: 10px 14px 6px;
  border-bottom: 0.5px solid #f0f0f0;
}
.chat-notice-search-drop-hd-txt {
  font-size: 12px;
  color: #888;
  line-height: 1.3;
}
.chat-notice-search-drop-empty {
  padding: 16px 14px;
  font-size: 13px;
  color: #999;
  text-align: center;
}
.chat-notice-theme-drop {
  max-height: 280px;
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
}
.chat-notice-search-drop-title {
  font-size: 14px;
  color: #222;
  line-height: 1.3;
}
.chat-notice-search-drop-item.active .chat-notice-search-drop-title {
  color: #12b7f5;
  font-weight: 600;
}
.chat-notice-search-drop-check {
  font-size: 14px;
  color: #12b7f5;
  font-weight: 700;
}
.chat-notice-body-scroll {
  flex: none;
  min-height: 120px;
  width: 100%;
  box-sizing: border-box;
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
</style>

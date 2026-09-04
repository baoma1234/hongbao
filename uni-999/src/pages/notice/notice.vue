<template>
  <view class="messages-page notice-page">
    <TopBar :title="tt('chat_tab_notice', '公告') || '公告'" />
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
              <view class="chat-community-seg chat-notice-seg" id="chatNoticeCats" role="tablist">
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
                >广告发布</view>
                <view
                  class="chat-community-seg-btn"
                  :class="{ active: noticeCat === 'rules' }"
                  @click="setNoticeCat('rules')"
                >游戏规则</view>
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
                    >
                      <view class="chat-notice-hd">
                        <image
                          class="chat-notice-avatar"
                          :src="avatarSrc(n.author_avatar || '')"
                          mode="aspectFill"
                        />
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
                      <view v-if="noticeVideo(n)" class="chat-notice-media">
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
                          :class="[
                            'imgs-' + Math.min(9, noticeImages(n).length),
                            { 'imgs-full': noticeImagesFull(n) },
                          ]"
                        >
                          <view
                            v-for="(src, ii) in noticeImages(n).slice(0, 9)"
                            :key="ii"
                            class="chat-notice-img-wrap"
                            @click="previewNoticeImages(n, ii)"
                          >
                            <image
                              class="chat-notice-img"
                              :src="avatarSrc(src)"
                              :mode="noticeImagesFull(n) ? 'widthFix' : 'aspectFill'"
                            />
                          </view>
                        </view>
                      </view>
                      <view v-if="noticeActionButtons(n).length" class="chat-notice-actions">
                        <view
                          v-for="(btn, bi) in noticeActionButtons(n)"
                          :key="bi"
                          class="chat-notice-action-btn"
                          :class="btn.cls"
                          @click="handleNoticeAction(btn.action, btn.url, btn.label)"
                        >{{ btn.label }}</view>
                      </view>
                      <view class="chat-notice-ft">
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

    <BottomTabBar active="home" />
  </view>
</template>

<script setup>
import { computed, nextTick, onUnmounted, ref, watch } from 'vue'
import { onLoad, onShow, onHide } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import BottomTabBar from '../../components/BottomTabBar.vue'
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
const chatFissionCardEnabled = ref(false)
const promoteEarnRows = ref([])
const promoteEarnOffset = ref(0)
let promoteEarnTimer = null
const fissionNotice = ref(null)
const fissionNoticeRemainSec = ref(0)
let fissionNoticeTick = null
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
  // 仅扣分类 Seg 高度，避免再额外 -72 把列表裁短露出灰底
  h = Math.max(180, h - 48)
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
  const inner = Math.max(180, h - 48)
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
  if (c === 'ads') return '广告发布'
  if (c === 'rules') return '游戏规则'
  if (c === 'latest') return '最新发布'
  return c
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
  return imgs.filter(Boolean)
}

function noticeImagesFull(n) {
  const c = String((n && n.category) || noticeCat.value || '')
  if (c === 'rules' || c.indexOf('规则') >= 0) return true
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
    uni.switchTab({ url: '/pages/fission/detail' })
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
  const cat = noticeCatLabel(n)
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
  let path = raw
  let full = ''
  if (/^https?:\/\//i.test(raw)) {
    full = raw
    const m = raw.match(/^https?:\/\/[^/?#]+(\/[^?#]*)/i)
    path = (m && m[1]) || raw
  } else if (raw.charAt(0) === '/') {
    path = raw
    full = publicUrl(raw) || avatarSrc(raw) || raw
  } else {
    path = '/' + raw.replace(/^\/+/, '')
    full = publicUrl(path) || avatarSrc(path) || path
  }
  const up = path.match(/(\/uploads\/[^?#]+)/i) || String(full || '').match(/(\/uploads\/[^?#]+)/i)
  if (up) path = up[1]
  if (path.indexOf('/uploads/') !== 0) return null
  path = path.split('?')[0].split('#')[0]
  const ext = (path.split('.').pop() || '').toLowerCase()
  if (!['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'].includes(ext)) return null
  if (!full) full = publicUrl(path) || path
  return { url: path, fullurl: full, name: '' }
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
  const images = shareImagePayloads.value || []
  for (let i = 0; i < images.length; i++) {
    const ex = images[i]
    if (!ex || !ex.url) continue
    await sendFn({
      content: '[图片]',
      msg_type: 4,
      extra: { url: ex.url, fullurl: ex.fullurl || ex.url, name: ex.name || '' },
    })
  }
  const text = String(shareTextPayload.value || '').trim()
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
  count = Math.max(12, Math.min(40, count || 24))
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
  const rowH = 36
  promoteEarnTimer = setInterval(() => {
    const half = (promoteEarnRows.value.length | 0) * rowH
    if (half < rowH) return
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
      promoteEarnRows.value = buildPromoteEarnMockRows(24)
    }
    startPromoteEarnScroll()
  } else {
    stopPromoteEarnScroll()
  }
}

function refreshPromoteEarnMock() {
  promoteEarnRows.value = buildPromoteEarnMockRows(24)
  startPromoteEarnScroll()
  uni.showToast({ title: '已刷新收益数据', icon: 'none' })
}

watch(noticeCat, () => {
  syncPromoteEarnPanel()
})

function setNoticeCat(cat) {
  const allowed = ['latest', 'promote', 'ads', 'rules']
  noticeCat.value = allowed.indexOf(cat) >= 0 ? cat : 'latest'
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
    const data = await apiRequest('notices', 'GET', { page: 1, limit: 30, category: noticeCat.value })
    const rows = (data && (data.list || data.rows || data.items)) || []
    notices.value = Array.isArray(rows) ? rows : []
  } catch (e) {
    notices.value = []
  }
  if (noticeCat.value === 'promote' || noticeCat.value === 'latest') {
    loadFissionNotice()
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
  uni.switchTab({ url: '/pages/fission/detail' })
}

onLoad((q) => {
  const cat = String((q && q.cat) || '').trim()
  const allowed = ['latest', 'promote', 'ads', 'rules']
  if (allowed.indexOf(cat) >= 0) noticeCat.value = cat
})

onShow(() => {
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
  nextTick(() => measureNoticeLayout())
})

onHide(() => {
  pageAlive = false
  stopPromoteEarnScroll()
})

onUnmounted(() => {
  stopPromoteEarnScroll()
  if (fissionNoticeTick) clearInterval(fissionNoticeTick)
})
</script>

<style scoped>
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

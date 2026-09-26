<template>
  <view class="game-lobby-page" :style="lobbyCssVars">
    <TopBar />
    <view class="game-lobby" :style="lobbyPageStyle">
      <view class="game-lobby-inner">
        <view v-if="lobbyBanners.length" class="game-lobby-banner">
          <swiper
            class="game-lobby-banner-swiper"
            circular
            autoplay
            :interval="4000"
            :duration="400"
            :indicator-dots="false"
          >
            <swiper-item
              v-for="(b, bi) in lobbyBanners"
              :key="b.id || bi"
              @click="onBannerTap(b)"
            >
              <image
                v-if="bannerSrc(b)"
                class="game-lobby-banner-img"
                :src="bannerSrc(b)"
                mode="aspectFill"
              />
            </swiper-item>
          </swiper>
          <view class="game-lobby-online-badge">
            <text class="game-lobby-online">
              {{ tt('lobby_online', '在线玩家') }}
              <text class="game-lobby-online-num">{{ onlineCountText }}</text>
              {{ tt('lobby_online_unit', '人') }}
            </text>
          </view>
        </view>

        <view class="game-lobby-ticker">
          <text class="game-lobby-ticker-ico">📢</text>
          <view class="game-lobby-ticker-track">
            <view class="game-lobby-ticker-text">{{ tickerText }}</view>
          </view>
        </view>
      </view>

      <!-- 跑马灯以下：QQ 灰底区（分类 / 热门游戏 / 邀请条） -->
      <view class="game-lobby-main">
        <view class="game-lobby-cats">
          <view class="game-lobby-cats-row">
            <view
              v-for="cat in lobbyCategories"
              :key="cat.id"
              class="game-lobby-cat"
              :class="{ on: activeCat === cat.id }"
              hover-class="game-lobby-hit"
              @click="onLobbyCat(cat)"
            >
              <view class="game-lobby-cat-tile">
                <image
                  v-if="catIconSrc(cat)"
                  class="game-lobby-cat-ico"
                  :src="catIconSrc(cat)"
                  mode="aspectFit"
                />
                <text v-else class="game-lobby-cat-ico-emoji">{{ cat.icon || '🎮' }}</text>
              </view>
            </view>
          </view>
        </view>

        <view class="game-lobby-section-hd">
          <text class="game-lobby-section-title">{{ lobbySectionTitle }}</text>
          <text class="game-lobby-section-more" @click="goTab('/pages/messages/messages')">
            {{ tt('lobby_all_games', '全部游戏') }} ›
          </text>
        </view>

        <view class="game-lobby-grid">
          <view
            v-for="game in visibleGames"
            :key="game.id"
            class="game-lobby-card"
            hover-class="game-lobby-hit"
            @click="onGameTap(game)"
          >
            <view class="game-lobby-card-media">
              <image
                v-if="gameCoverSrc(game)"
                class="game-lobby-card-img"
                :src="gameCoverSrc(game)"
                mode="aspectFill"
              />
            </view>
            <view
              v-if="game.badgeLabel || game.badge"
              class="game-lobby-badge"
              :class="game.badgeClass || game.badge || 'hot'"
            >{{ game.badgeLabel || String(game.badge).toUpperCase() }}</view>
            <view class="game-lobby-players">
              <text class="game-lobby-players-txt">
                <template v-if="game.comingSoon">
                  {{ tt('lobby_coming_soon', '敬请期待') }}
                </template>
                <template v-else>
                  <text class="game-lobby-players-num">{{ game.playersText }}</text>
                  {{ tt('lobby_playing', '人在玩') }}
                </template>
              </text>
            </view>
          </view>
        </view>

        <view
          v-if="inviteSrc"
          class="game-lobby-invite"
          hover-class="game-lobby-hit"
          role="button"
          @click.stop="onInviteTap"
          @tap.stop="onInviteTap"
        >
          <image
            class="game-lobby-invite-img"
            :src="inviteSrc"
            mode="widthFix"
            :draggable="false"
          />
        </view>
        <!-- 末项留白：App/Safari 底栏 + Home 指示条，避免邀请条被挡 -->
        <view class="game-lobby-scroll-pad" aria-hidden="true" />
      </view>
    </view>

    <!-- Safari/IPA 剪贴板受限时：可长按复制 -->
    <view v-if="inviteCopySheet" class="home-invite-copy-sheet" @click="closeInviteCopySheet">
      <view class="home-invite-copy-panel" @click.stop>
        <view class="home-invite-copy-title">邀请文案</view>
        <text class="home-invite-copy-text" selectable user-select>{{ inviteCopyCache || '…' }}</text>
        <view class="home-invite-copy-actions">
          <button type="button" class="home-invite-copy-btn" @click="retryInviteCopy">复制</button>
          <button type="button" class="home-invite-copy-close" @click="closeInviteCopySheet">关闭</button>
        </view>
      </view>
    </view>

    <!-- 真人视讯：先弹余额 sheet，确认后再进 live（样式对齐大厅白底弹层，四端安全区） -->
    <view
      class="og-mask"
      :class="{ 'is-open': ogSheetOpen }"
      :style="ogMaskStyle"
      @click="closeOgSheet"
      @touchmove.stop.prevent="noopTouch"
    >
      <view class="og-sheet" @click.stop>
        <view class="og-sheet-handle" aria-hidden="true" />
        <text class="og-sheet-title">{{ ogSheetTitle || 'OG 视讯' }}</text>
        <view class="og-sheet-bal-row">
          <view class="og-sheet-bal-card">
            <text class="og-sheet-bal-label">OG余额</text>
            <text class="og-sheet-bal-num">{{ ogBalText }}</text>
          </view>
          <view class="og-sheet-bal-card">
            <text class="og-sheet-bal-label">本站红宝</text>
            <text class="og-sheet-bal-num is-hot">{{ ogHbText }}</text>
          </view>
        </view>
        <view class="og-sheet-hint">
          <text class="og-sheet-hint-line">进入：全部红宝自动转入 OG 再开游戏</text>
          <text class="og-sheet-hint-line">提出：OG 余额全部提回红宝</text>
        </view>
        <view
          v-if="ogRebate.enabled"
          class="game-lobby-rebate og-sheet-rebate"
          :class="{
            'is-claimable': ogRebate.claimable,
            'is-claimed': ogRebate.claimed,
            'is-busy': ogRebateBusy,
          }"
          hover-class="game-lobby-hit"
          @click="onClaimOgRebate"
        >
          <view class="game-lobby-rebate-left">
            <text class="game-lobby-rebate-title">领取昨日的返水</text>
            <text class="game-lobby-rebate-sub">{{ ogRebateSubText }}</text>
          </view>
          <view class="game-lobby-rebate-btn">{{ ogRebateBtnText }}</view>
        </view>
        <button type="button" class="og-btn primary" :disabled="ogBusy" hover-class="og-btn-hit" @click="onOgEnterPrimary">
          {{ ogBusyLaunch ? '进入中…' : ('进入游戏' + (ogHbNum > 0 ? '（转入 ' + ogHbText + '）' : '')) }}
        </button>
        <button type="button" class="og-btn warn" :disabled="ogBusy" hover-class="og-btn-hit" @click="onOgWithdraw">
          {{ ogBusyWithdraw ? '提出中…' : ('提出全部' + (ogNum > 0 ? '（' + ogBalText + '）' : '')) }}
        </button>
        <button type="button" class="og-btn close" :disabled="ogBusy" hover-class="og-btn-hit" @click="closeOgSheet">
          关闭
        </button>
      </view>
    </view>

    <!-- VIP 密令弹层（挂在页外避免滚动裁剪） -->
    <view class="home-modal-root">
      <view class="modal-mask" :class="{ 'is-open': withdrawOpen }" @click="closeWithdrawModal">
        <view class="modal-box" @click.stop>
          <view class="modal-title">{{ withdrawTitle }}</view>
          <view class="modal-sub-label">{{ t('withdraw_amount_label') || '当前待领取福利总金额' }}</view>
          <view class="modal-money">￥{{ hongbaoText }}</view>
          <view class="modal-secret-label">
            {{ t('withdraw_secret_label') || '🔐 您的专属密令（请点击复制）' }}
          </view>
          <view class="secret-code-box" @click="copySecretCode">{{ secretCode || (t('withdraw_secret_loading') || 'FH-LOADING') }}</view>
          <view class="secret-timer">
            <text>{{ t('withdraw_secret_timer') || '⏳ 专属密令安全锁定剩余：' }}</text>
            <text>{{ secretCountdown }}</text>
          </view>
          <view class="cs-step-card">
            <view class="cs-step-line">
              <text class="step-num">1</text>
              <text class="cs-step-txt">{{ withdrawStepText(1) }}</text>
            </view>
            <view class="cs-step-line">
              <text class="step-num">2</text>
              <text class="cs-step-txt">{{ withdrawStepText(2) }}</text>
            </view>
            <view class="cs-step-line">
              <text class="step-num">3</text>
              <text class="cs-step-txt">{{ withdrawStepText(3) }}</text>
            </view>
          </view>
          <button type="button" class="modal-action-btn primary" @click="jumpToCustomerService">
            {{ t('withdraw_btn_cs') || '💬 一键复制密令 · 匹配专属客服' }}
          </button>
          <button type="button" class="modal-action-btn gold" v-if="appDownloadUrl" @click.stop="openAppDownload">
            {{ t('withdraw_btn_app') || '📥 下载官方红宝聊天App' }}
          </button>
          <button type="button" class="modal-close-btn" @click="closeWithdrawModal">
            {{ t('withdraw_btn_close') || '返回继续攒股份' }}
          </button>
        </view>
      </view>

      <!-- 裂变红宝弹窗：海报背景 + 文字信息 -->
      <view class="modal-mask fission-popup-mask" :class="{ 'is-open': fissionPopupOpen }" @click="dismissFissionPopup">
        <view class="fission-popup-wrap" @click.stop>
          <view class="fission-popup-card" @click="openFissionFromPopup">
            <image class="fission-popup-bg" src="/static/fission/popup-poster.png" mode="aspectFill" />
            <view class="fission-popup-body">
              <view class="fission-popup-pool-row">
                <text class="fission-popup-yen">¥</text>
                <text class="fission-popup-num">{{ fissionPopupPool }}</text>
                <text class="fission-popup-unit">奖金池</text>
              </view>
              <view class="fission-popup-cta">
                <text class="fission-popup-cta-txt">点击拆开红包</text>
              </view>
            </view>
          </view>
          <view class="fission-popup-close" @click="dismissFissionPopup">×</view>
        </view>
      </view>
    </view>

    <!-- 左右浮标：固定层，不随大厅滚动；× 关闭至下次登录 -->
    <view v-if="lobbyFloatsLeft.length" class="home-lobby-floats is-left">
      <view
        v-for="f in lobbyFloatsLeft"
        :key="'fl' + f.id"
        class="home-lobby-float"
        hover-class="home-lobby-float--active"
        @click="onFloatTap(f)"
      >
        <image class="home-lobby-float-img" :src="f.src" mode="aspectFit" />
        <view
          class="home-lobby-float-close"
          hover-class="home-lobby-float-close--active"
          @click.stop="onFloatDismiss(f)"
        >×</view>
      </view>
    </view>
    <view v-if="lobbyFloatsRight.length" class="home-lobby-floats is-right">
      <view
        v-for="f in lobbyFloatsRight"
        :key="'fr' + f.id"
        class="home-lobby-float"
        hover-class="home-lobby-float--active"
        @click="onFloatTap(f)"
      >
        <image class="home-lobby-float-img" :src="f.src" mode="aspectFit" />
        <view
          class="home-lobby-float-close"
          hover-class="home-lobby-float-close--active"
          @click.stop="onFloatDismiss(f)"
        >×</view>
      </view>
    </view>

    <WelcomeLottery ref="lotteryRef" :share-price="sharePrice" @done="onLotteryDone" />
    <BottomTabBar active="home" />
  </view>
</template>

<script setup>
import { computed, nextTick, onUnmounted, ref, watch } from 'vue'
import { onShow, onHide, onLoad } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import BottomTabBar from '../../components/BottomTabBar.vue'
import WelcomeLottery from '../../components/WelcomeLottery.vue'
import { apiRequest, fetchProfile, getToken, notifyProfileUpdated } from '../../utils/auth.js'
import { localeState, t, tt, applyServerCopy } from '../../utils/i18n.js'
import { imConnect } from '../../utils/im.js'
import { copyText, copyTextDeferred } from '../../utils/master.js'
import { openExternalHttpUrl } from '../../utils/wallet.js'
import { getUploadsBase, packagedStaticUrl } from '../../utils/config.js'
import { applySafeAreaCssVars, getSafeAreaInsets } from '../../utils/safe-area.js'
import {
  ogBalance,
  ogPlayer,
  ogRegister,
  ogWithdraw,
  ogRebateInfo,
  ogRebateClaim,
} from '../../utils/og.js'
import '../../styles/home-lobby.css'
import '../../styles/social-modals.css'
import '../../styles/home-uni-adapter.css'

const locale = localeState()
const profile = ref(null)
const jackpot = ref(null)
const config = ref(null)
const leaderboard = ref([])
const gameUid = ref('')
const uidSubmitting = ref(false)
const withdrawOpen = ref(false)
const secretCode = ref('')
const secretCountdown = ref('15:00')
const secretLockSeconds = ref(900)
const appDownloadUrl = ref('')
const mainStationUrl = ref('https://555.bio')
const lotteryRef = ref(null)
const shareSubmitting = ref(false)
const inviteCopyCache = ref('')
const inviteCopySheet = ref(false)
let inviteTapLock = false

const ogSheetOpen = ref(false)
const ogSheetTitle = ref('OG 视讯')
const ogPendingGameId = ref(0)
const ogBal = ref('0.00')
const ogHongbao = ref(0)
const ogBusyLaunch = ref(false)
const ogBusyWithdraw = ref(false)
const ogBusy = computed(() => ogBusyLaunch.value || ogBusyWithdraw.value)

const ogRebate = ref({
  enabled: false,
  claimable: false,
  claimed: false,
  rebate_amount: 0,
  bet_amount: 0,
  rate_percent: 1,
  biz_date: '',
})
const ogRebateBusy = ref(false)
const ogRebateSubText = computed(() => {
  const r = ogRebate.value || {}
  if (r.claimed) return '昨日返水已领取'
  const amt = Number(r.rebate_amount) || 0
  const bet = Number(r.bet_amount) || 0
  const pct = Number(r.rate_percent) || 0
  const fmt = (n) => (Math.max(0, Number(n) || 0)).toFixed(2)
  if (amt >= 0.01) {
    return '有效投注 ¥' + fmt(bet) + ' · ' + pct + '% = ¥' + fmt(amt)
  }
  return '昨日暂无有效投注返水'
})
const ogRebateBtnText = computed(() => {
  const r = ogRebate.value || {}
  if (ogRebateBusy.value) return '领取中…'
  if (r.claimed) return '已领取'
  const amt = Number(r.rebate_amount) || 0
  if (r.claimable && amt >= 0.01) return '领取 ¥' + amt.toFixed(2)
  return '暂无'
})

async function loadOgRebate() {
  if (!getToken()) {
    ogRebate.value = { enabled: false, claimable: false, claimed: false, rebate_amount: 0, bet_amount: 0, rate_percent: 1, biz_date: '' }
    return
  }
  try {
    const data = await ogRebateInfo()
    if (data && typeof data === 'object') {
      ogRebate.value = {
        enabled: !!data.enabled,
        claimable: !!data.claimable,
        claimed: !!data.claimed,
        rebate_amount: Number(data.rebate_amount) || 0,
        bet_amount: Number(data.bet_amount) || 0,
        rate_percent: Number(data.rate_percent) || 0,
        biz_date: String(data.biz_date || ''),
      }
    }
  } catch (e) {
    // 未登录/接口失败时隐藏条
    ogRebate.value = Object.assign({}, ogRebate.value, { enabled: false })
  }
}

async function onClaimOgRebate() {
  if (ogRebateBusy.value) return
  const r = ogRebate.value || {}
  if (!r.enabled) return
  if (r.claimed) {
    uni.showToast({ title: '昨日返水已领取', icon: 'none' })
    return
  }
  if (!r.claimable) {
    uni.showToast({ title: '昨日暂无返水可领', icon: 'none' })
    return
  }
  ogRebateBusy.value = true
  try {
    const data = await ogRebateClaim()
    const amt = Number(data && data.rebate_amount) || Number(r.rebate_amount) || 0
    ogRebate.value = Object.assign({}, ogRebate.value, {
      claimed: true,
      claimable: false,
      rebate_amount: amt,
      bet_amount: Number(data && data.bet_amount) || r.bet_amount,
    })
    uni.showToast({ title: '已领取 ¥' + amt.toFixed(2), icon: 'none' })
    try {
      notifyProfileUpdated()
    } catch (eN) {}
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '领取失败', icon: 'none' })
    loadOgRebate().catch(() => {})
  } finally {
    ogRebateBusy.value = false
  }
}

function noopTouch() {}

function ogRound2(v) {
  const n = Number(v)
  if (!Number.isFinite(n) || n <= 0) return 0
  return Math.floor(n * 100 + 1e-8) / 100
}
function ogMoney2(v) {
  return ogRound2(v).toFixed(2)
}
const ogBalText = computed(() => ogMoney2(ogBal.value))
const ogHbText = computed(() => ogMoney2(ogHongbao.value))
const ogNum = computed(() => ogRound2(ogBal.value))
const ogHbNum = computed(() => ogRound2(ogHongbao.value))

const fissionEntry = ref(null)
const fissionPopupOpen = ref(false)
const fissionPopupRemainSec = ref(0)
/** 底栏 Home 指示条（App 上 env(safe-area) 常为 0，靠 JS 测量） */
const lobbySafeBottom = ref(0)
let fissionPopupTick = null
let pollTimer = null
let pollLocalTimer = null
let lbTimer = null
let secretTimer = null
let secretRequestId = ''
let tickerTimer = null
let onlinePollTimer = null
let onlineJitterTimer = null
/** 在线人数相对基数的氛围浮动（每分钟 ±10～30；展示硬夹在 16000～20000） */
const onlineCountJitter = ref(0)

const TAB_BAR_CONTENT_PX = 64

function measureLobbySafeBottom() {
  try {
    applySafeAreaCssVars()
    let bottom = Math.max(0, Number(getSafeAreaInsets().bottom) || 0)
    // #ifdef APP-PLUS
    try {
      const sys = uni.getSystemInfoSync() || {}
      const sa = sys.safeArea || null
      const sh = Number(sys.screenHeight) || 0
      if (bottom < 1 && sa && sh > 0) {
        const gap = Math.max(0, sh - Number(sa.bottom || 0))
        if (gap > 0 && gap < 80) bottom = gap
      }
      if (bottom < 1) {
        const inset = sys.safeAreaInsets || {}
        bottom = Math.max(0, Number(inset.bottom) || 0)
      }
    } catch (e0) {}
    // #endif
    lobbySafeBottom.value = bottom
  } catch (e) {
    lobbySafeBottom.value = 0
  }
}

const lobbyPageStyle = computed(() => {
  const pad = TAB_BAR_CONTENT_PX + Math.max(0, Number(lobbySafeBottom.value) || 0) + 16
  return {
    paddingBottom: pad + 'px',
  }
})

/** 浮标等固定层也要用底栏安全距，变量挂在 page 根上 */
const lobbyCssVars = computed(() => {
  const pad = TAB_BAR_CONTENT_PX + Math.max(0, Number(lobbySafeBottom.value) || 0) + 16
  return {
    '--lobby-safe-bottom': (Number(lobbySafeBottom.value) || 0) + 'px',
    '--lobby-tab-pad': pad + 'px',
  }
})

/** App 上 env(safe-area) 常为 0，用已测的 lobbySafeBottom 垫底 */
const ogMaskStyle = computed(() => {
  const safe = Math.max(0, Number(lobbySafeBottom.value) || 0)
  return {
    paddingBottom: 12 + safe + 'px',
    '--og-sheet-safe': safe + 'px',
  }
})

const activeCat = ref('hot')
const lobbyLiveTestToken = ref('')
const lobbyLiveEnabled = ref(false)
const onlineCountLive = ref(0)
/** 服务端官方在线合计（与七群分摊同源，每分钟游走） */
const lobbyOnlineTotal = ref(0)
/** 官方推荐群（与社群页 communityrecommend 同源） */
const officialGroups = ref([])
const lobbyBotNicks = ref([])
const LOBBY_TICKER_FIXED =
  '❤️ 欢迎来到【红宝】直营站 ❤️  【红宝全球首创 · 多元体验】 🔥 福利专群｜推广赚钱 📰 新闻资讯｜白嫖曝光 ✨ 更多精彩栏目持续上线  一站汇聚多元内容，打造属于红宝的全新体验！  🌐 易记网址：qhb.app 🔴 【红宝唯一指定官网】'
const tickerText = ref(LOBBY_TICKER_FIXED)
/** 后台大厅装修（lobbyhome）；未加载前不展示本地占位图 */
const remoteLobby = ref(null)

const LOBBY_ASSET_VER = '23'

/** 红宝分类图：本地路径名；实际加载优先 OSS 加速 */
const LOBBY_CAT_LOCAL = Object.freeze({
  hot: 'home/lobby/cat-1.png',
  games: 'home/lobby/cat-1.png',
  live: 'home/lobby/cat-live.png',
  notice: 'home/lobby/fission-hongbao.png',
  fission: 'home/lobby/fission-hongbao.png',
  commission: 'home/lobby/cat-4.png',
})

function resolveLocalCatIcon(cat, index) {
  if (!cat) return ''
  const action = String(cat.action || '')
  const id = String(cat.id || cat.key || '').toLowerCase()
  if (LOBBY_CAT_LOCAL[action]) return LOBBY_CAT_LOCAL[action]
  if (LOBBY_CAT_LOCAL[id]) return LOBBY_CAT_LOCAL[id]
  const raw = String(cat.iconRaw || cat.iconUrl || cat.iconStatic || '')
  const m = raw.match(/(?:^|\/)(cat-\d+|cat-live|fission-hongbao|commission|[1-4])\.(png|jpe?g|webp|gif)/i)
  if (m) return 'home/lobby/' + m[1].toLowerCase() + '.' + m[2].toLowerCase()
  const byIdx = [
    'home/lobby/cat-1.png',
    'home/lobby/cat-live.png',
    'home/lobby/fission-hongbao.png',
    'home/lobby/cat-4.png',
  ]
  const i = Math.max(0, Number(index) | 0)
  return byIdx[i] || byIdx[byIdx.length - 1]
}

function safeRegExp(pattern) {
  const s = String(pattern || '').trim()
  if (!s) return null
  try {
    return new RegExp(s)
  } catch (e) {
    return null
  }
}

function normalizeRemotePath(raw) {
  let p = String(raw || '').trim().replace(/^\/+/, '')
  if (!p) return ''
  if (/^https?:\/\//i.test(p)) return p
  p = p.replace(/^static\//, '')
  if (p.indexOf('home/lobby/') === 0) return p.slice('home/lobby/'.length)
  return p
}

/** 只用后台返回地址：绝对 URL /uploads → OSS；home/lobby 走 OSS static（不再用本地打包占位覆盖） */
function mediaUrl(resolved, raw) {
  const u = String(resolved || '').trim()
  if (/^https?:\/\//i.test(u) || u.indexOf('data:') === 0) return u
  let p = String(raw || u || '').trim()
  if (/^https?:\/\//i.test(p)) return p
  p = p.replace(/^\/+/, '').replace(/^static\//, '')
  if (!p) return ''
  const base = String(getUploadsBase() || '').replace(/\/+$/, '')
  if (p.indexOf('uploads/') === 0) {
    return base ? base + '/' + p : '/' + p
  }
  if (p.indexOf('999/static/') === 0) {
    return base ? base + '/' + p + '?v=' + LOBBY_ASSET_VER : '/' + p + '?v=' + LOBBY_ASSET_VER
  }
  let lobbyFile = ''
  if (p.indexOf('home/lobby/') === 0) lobbyFile = p.slice('home/lobby/'.length)
  else if (p.indexOf('/') < 0 && /\.(png|jpe?g|webp|gif)$/i.test(p)) lobbyFile = p
  if (lobbyFile) {
    // 后台仍存种子路径时，读 OSS 上的同名图；无 CDN 时回退打包 static（App/本地）
    if (base) return base + '/999/static/home/lobby/' + lobbyFile + '?v=' + LOBBY_ASSET_VER
    return packagedStaticUrl('home/lobby/' + lobbyFile) + '?v=' + LOBBY_ASSET_VER
  }
  return ''
}

const lobbyCategories = computed(() => {
  const rows = remoteLobby.value && remoteLobby.value.categories
  if (!Array.isArray(rows) || !rows.length) return []
  return rows.map((c, idx) => {
    const action = String(c.action || 'filter')
    const key = String(c.key || c.id || '')
    const title = String(c.title || c.key || '')
    // 原「红宝公告」挪到底栏「社区」；分类位改为裂变红宝入口
    if (action === 'notice' || key === 'notice' || /公告/.test(title)) {
      return {
        id: 'fission',
        label: '裂变红宝',
        iconUrl: '',
        iconRaw: '',
        iconStatic: LOBBY_CAT_LOCAL.fission,
        action: 'fission',
        actionUrl: '',
      }
    }
    const mapped = {
      id: key,
      label: title,
      iconUrl: '',
      iconRaw: String(c.icon_raw || c.icon || ''),
      iconStatic: String(c.icon_static || ''),
      action,
      actionUrl: String(c.action_url || ''),
    }
    // 四分类统一走本地种子名 → OSS 加速（不再单独依赖后台绝对 URL）
    mapped.iconStatic = resolveLocalCatIcon(mapped, idx)
    mapped.iconUrl = ''
    mapped.iconRaw = ''
    return mapped
  })
})

const lobbyGamesList = computed(() => {
  const rows = remoteLobby.value && remoteLobby.value.games
  if (!Array.isArray(rows) || !rows.length) return []
  return rows.map((g) => ({
    id: String(g.key || g.id || ''),
    title: String(g.title || ''),
    ogGameId: Number(g.og_game_id) || 0,
    onlineCount: Number(g.online_count) || 0,
    cover: normalizeRemotePath(g.cover_raw || g.cover || ''),
    coverUrl: String(g.cover || ''),
    badge: String(g.badge || ''),
    cats: Array.isArray(g.cats) ? g.cats.map(String) : [],
    groupMatch: safeRegExp(g.group_match),
    sumGroupMatch: safeRegExp(g.sum_group_match),
    comingSoon: !!g.coming_soon,
    order: Number(g.order) || 0,
  }))
})

const lobbyBanners = computed(() => {
  const rows = remoteLobby.value && remoteLobby.value.banners
  if (!Array.isArray(rows) || !rows.length) return []
  return rows
    .map((b, i) => ({
      id: b.id || 'b' + i,
      image: String(b.image || ''),
      imageRaw: String(b.image_raw || b.image || ''),
      linkType: String(b.link_type || 'none'),
      linkUrl: String(b.link_url || ''),
    }))
    .filter((b) => !!mediaUrl(b.image, b.imageRaw))
})

const lobbyInvite = computed(() => {
  const rows = remoteLobby.value && remoteLobby.value.invites
  if (!Array.isArray(rows) || !rows.length) return null
  const b = rows[0]
  return {
    image: String(b.image || ''),
    imageRaw: String(b.image_raw || b.image || ''),
    linkType: String(b.link_type || 'share'),
    linkUrl: String(b.link_url || ''),
  }
})

const inviteSrc = computed(() => {
  const inv = lobbyInvite.value
  if (!inv) return ''
  return mediaUrl(inv.image, inv.imageRaw)
})

/** 浮标关闭态绑定当前登录 token：换号/重新登录后自动再显示 */
const FLOAT_DISMISS_KEY = 'fanshub_lobby_floats_dismissed'

function floatDismissSession() {
  const tok = String(getToken() || '')
  return tok ? tok.slice(-32) : ''
}

function loadDismissedFloatIds() {
  try {
    const raw = uni.getStorageSync(FLOAT_DISMISS_KEY)
    const o = typeof raw === 'string' ? JSON.parse(raw || '{}') : raw && typeof raw === 'object' ? raw : null
    const session = floatDismissSession()
    if (!o || !session || String(o.session || '') !== session) return new Set()
    const ids = Array.isArray(o.ids) ? o.ids : []
    return new Set(ids.map((x) => String(x)))
  } catch (e) {
    return new Set()
  }
}

function persistDismissedFloatIds(ids) {
  const session = floatDismissSession()
  if (!session) return
  try {
    uni.setStorageSync(
      FLOAT_DISMISS_KEY,
      JSON.stringify({ session, ids: Array.from(ids) })
    )
  } catch (e) {}
}

const dismissedFloatIds = ref(loadDismissedFloatIds())

function onFloatDismiss(f) {
  if (!f || f.id == null) return
  const next = new Set(dismissedFloatIds.value)
  next.add(String(f.id))
  dismissedFloatIds.value = next
  persistDismissedFloatIds(next)
}

function syncFloatDismissForLogin() {
  dismissedFloatIds.value = loadDismissedFloatIds()
}

const lobbyFloats = computed(() => {
  const rows = remoteLobby.value && remoteLobby.value.floats
  if (!Array.isArray(rows) || !rows.length) return []
  const dismissed = dismissedFloatIds.value
  return rows
    .map((f, i) => {
      const src = mediaUrl(String(f.image || ''), String(f.image_raw || f.image || ''))
      if (!src) return null
      const id = String(f.id != null ? f.id : 'f' + i)
      if (dismissed.has(id)) return null
      const side = String(f.side || 'right').toLowerCase() === 'left' ? 'left' : 'right'
      return {
        id,
        side,
        linkType: String(f.link_type || 'internal'),
        linkUrl: String(f.link_url || ''),
        src,
      }
    })
    .filter(Boolean)
})

const lobbyFloatsLeft = computed(() => lobbyFloats.value.filter((f) => f.side === 'left'))
const lobbyFloatsRight = computed(() => lobbyFloats.value.filter((f) => f.side === 'right'))

/** 与社群页 groupMembersText 同一口径：优先 online_count；维护中强制 0 */
function groupDisplayOnline(g) {
  if (!g) return 0
  const maint = g.maintenance
  if (maint === true || maint === 1 || maint === '1') return 0
  const o = Number(g.online_count)
  if (!isNaN(o) && o > 0) return Math.floor(o)
  const m = Number(g.member_count != null ? g.member_count : g.display_member_count)
  return !isNaN(m) && m > 0 ? Math.floor(m) : 0
}

function findOfficialGroup(matcher) {
  const rows = officialGroups.value || []
  if (!matcher) return null
  if (typeof matcher === 'string') {
    return rows.find((g) => String(g.name || '').indexOf(matcher) >= 0) || null
  }
  return rows.find((g) => matcher.test(String(g.name || ''))) || null
}

function sumOfficialByMatch(matcher) {
  if (!matcher) return 0
  const rows = officialGroups.value || []
  let sum = 0
  for (let i = 0; i < rows.length; i++) {
    const name = String(rows[i].name || '')
    if (typeof matcher === 'string' ? name.indexOf(matcher) >= 0 : matcher.test(name)) {
      sum += groupDisplayOnline(rows[i])
    }
  }
  return sum
}

function gamePlayersCount(game) {
  if (!game || game.comingSoon) return 0
  const isLive =
    (Number(game.ogGameId) || 0) > 0 ||
    (Array.isArray(game.cats) && game.cats.indexOf('live') >= 0) ||
    /^og[_-]/i.test(String(game.id || ''))
  if (isLive) {
    const n = liveOnlineByKey(String(game.id || ''))
    if (n > 0) return n
    const fixed = Number(game.onlineCount) || 0
    if (fixed > 0) return Math.floor(fixed)
  }
  if (game.sumGroupMatch) return sumOfficialByMatch(game.sumGroupMatch)
  const row = findOfficialGroup(game.groupMatch)
  return row ? groupDisplayOnline(row) : 0
}

/** 全站在线约 20% 分给真人视讯四款，与横幅合计同步游走 */
function liveOnlineByKey(key) {
  const keys = ['og_baccarat', 'og_dragon', 'og_roulette', 'og_niuniu']
  const k = String(key || '').toLowerCase()
  if (keys.indexOf(k) < 0) return 0
  let total = Math.max(0, Number(onlineCountDisplay.value) || 0)
  if (total < 10000) total = 18000
  total = Math.max(16000, Math.min(20000, total))
  const budget = Math.max(400, Math.round(total * 0.2))
  const minute = Math.floor(Date.now() / 60000)
  const weights = {}
  let wSum = 0
  for (let i = 0; i < keys.length; i++) {
    const salt = 'live:' + keys[i] + ':' + minute
    let h = 0
    for (let j = 0; j < salt.length; j++) h = (Math.imul(31, h) + salt.charCodeAt(j)) | 0
    h = Math.abs(h)
    const ratio = 0.88 + ((h % 1000) / 1000) * 0.24
    weights[keys[i]] = ratio
    wSum += ratio
  }
  let assigned = 0
  const out = {}
  for (let i = 0; i < keys.length; i++) {
    const id = keys[i]
    if (i === keys.length - 1) {
      out[id] = Math.max(80, budget - assigned)
    } else {
      const v = Math.max(80, Math.round(budget * (weights[id] / wSum)))
      out[id] = v
      assigned += v
    }
  }
  return out[k] || 0
}

const lobbySectionTitle = computed(() => {
  if (activeCat.value === 'hot') return tt('lobby_hot_games', '热门游戏')
  const cat = lobbyCategories.value.find((c) => c.id === activeCat.value)
  return (cat && cat.label) || tt('lobby_hot_games', '热门游戏')
})

function catIconSrc(cat) {
  if (!cat) return ''
  const remote = String(cat.iconUrl || '')
  if (/^https?:\/\//i.test(remote)) {
    return remote.indexOf('?') >= 0 ? remote : remote + '?v=' + LOBBY_ASSET_VER
  }
  let p = String(cat.iconStatic || resolveLocalCatIcon(cat, 0) || '')
    .replace(/^\/+/, '')
    .replace(/^static\//, '')
  if (!p) return ''
  if (p.indexOf('home/lobby/') !== 0) {
    p = 'home/lobby/' + p.replace(/^home\/lobby\//, '')
  }
  const oss = mediaUrl('', p)
  if (oss) return oss
  return packagedStaticUrl(p) + '?v=' + LOBBY_ASSET_VER
}

function bannerSrc(b) {
  if (!b) return ''
  return mediaUrl(b.image, b.imageRaw)
}

function gameCoverSrc(game) {
  if (!game) return ''
  return mediaUrl(game.coverUrl, game.cover)
}

function applyLobbyExtras(data) {
  if (!data || typeof data !== 'object') return
  // 在线人数改走官方社群合计，不再用 partner_count 覆盖 banner
  const nicks = data.lobby_bot_nicks
  if (Array.isArray(nicks) && nicks.length) {
    lobbyBotNicks.value = nicks.map((x) => String(x || '').trim()).filter(Boolean)
  }
}

function rotateTicker() {
  // 大厅跑马灯固定文案，不再轮播虚假中奖
  tickerText.value = LOBBY_TICKER_FIXED
}

function startTicker() {
  stopTicker()
  rotateTicker()
}

function stopTicker() {
  if (tickerTimer) {
    clearInterval(tickerTimer)
    tickerTimer = null
  }
}

const onlineCount = computed(() => {
  const total = Number(lobbyOnlineTotal.value) || 0
  if (total > 0) return Math.floor(total)
  const rows = officialGroups.value || []
  let sum = 0
  for (let i = 0; i < rows.length; i++) {
    sum += groupDisplayOnline(rows[i])
  }
  if (sum > 0) return sum
  const live = Number(onlineCountLive.value) || 0
  if (live > 0) return Math.floor(live)
  return marketVirtualBase()
})

/** 展示用：基数 + 每分钟 ±10～30 浮动，夹在 16000～20000 */
const onlineCountDisplay = computed(() => {
  const base = Math.max(0, Number(onlineCount.value) || 0)
  const n = Math.max(1, base + (onlineCountJitter.value | 0))
  if (base >= 10000) {
    return Math.max(16000, Math.min(20000, n))
  }
  return n
})

const onlineCountText = computed(() => formatCountNum(onlineCountDisplay.value))

function tickOnlineJitter() {
  // 每分钟上下浮动 10～30，并限制相对基数不要漂太远
  const step = 10 + Math.floor(Math.random() * 21)
  const sign = Math.random() < 0.5 ? -1 : 1
  let next = (onlineCountJitter.value | 0) + sign * step
  next = Math.max(-90, Math.min(90, next))
  const base = Math.max(0, Number(onlineCount.value) || 0)
  if (base + next < 1) next = 1 - base
  onlineCountJitter.value = next
}

function startOnlineJitter() {
  stopOnlineJitter()
  tickOnlineJitter()
  // 20s 微动一次，配合服务端每分钟合计游走，大厅数字会持续变化
  onlineJitterTimer = setInterval(tickOnlineJitter, 20000)
}

function stopOnlineJitter() {
  if (onlineJitterTimer) {
    clearInterval(onlineJitterTimer)
    onlineJitterTimer = null
  }
}

const visibleGames = computed(() => {
  return lobbyGamesList.value
    .filter((g) => {
      if (activeCat.value === 'hot') return g.cats.includes('hot')
      if (activeCat.value === 'games') return g.cats.includes('games')
      return g.cats.includes(activeCat.value)
    })
    .map((g) => {
      const n = gamePlayersCount(g)
      const ready = (officialGroups.value || []).length > 0
      return {
        ...g,
        playersText: g.comingSoon
          ? tt('lobby_coming_soon', '敬请期待')
          : ready
            ? formatCountNum(n)
            : '—',
      }
    })
    .sort((a, b) => (b.order || 0) - (a.order || 0))
})

function onLobbyCat(cat) {
  if (!cat) return
  const action = String(cat.action || '')
  if (action === 'fission') {
    uni.navigateTo({
      url: '/pages/fission/detail',
      fail: () => uni.reLaunch({ url: '/pages/fission/detail' }),
    })
    return
  }
  if (action === 'notice') {
    uni.switchTab({
      url: '/pages/notice/notice',
      fail: () => uni.reLaunch({ url: '/pages/notice/notice' }),
    })
    return
  }
  if (action === 'commission') {
    uni.navigateTo({ url: '/pages/commission/commission' })
    return
  }
  if (action === 'url' && cat.actionUrl) {
    const u = String(cat.actionUrl).trim()
    if (/^https?:\/\//i.test(u)) {
      openExternalHttpUrl(u)
    } else if (u.indexOf('/pages/') === 0) {
      uni.navigateTo({ url: u, fail: () => uni.reLaunch({ url: u }) })
    }
    return
  }
  activeCat.value = cat.id
}

function onBannerTap(b) {
  const lt = String((b && b.linkType) || 'none')
  if (lt === 'fission') {
    onCarnivalBanner()
    return
  }
  if (lt === 'messages') {
    goTab('/pages/messages/messages')
    return
  }
  if (lt === 'notice') {
    goTab('/pages/notice/notice')
    return
  }
  if (lt === 'url' && b && b.linkUrl) {
    openLobbyLink(b.linkUrl)
    return
  }
  if (lt === 'none') return
  onCarnivalBanner()
}

/** 大厅轮播/邀请/浮标：兼容 #/pages/...、/pages/...；tab 页走 switchTab，query 用本地缓存透传 */
function openLobbyLink(raw) {
  let u = String(raw || '').trim()
  if (!u) return
  if (u.charAt(0) === '#') u = u.slice(1)
  if (/^https?:\/\//i.test(u)) {
    openExternalHttpUrl(u)
    return
  }
  if (u.charAt(0) !== '/') u = '/' + u
  const qIdx = u.indexOf('?')
  const pathOnly = qIdx >= 0 ? u.slice(0, qIdx) : u
  const qs = qIdx >= 0 ? u.slice(qIdx + 1) : ''
  const params = {}
  if (qs) {
    qs.split('&').forEach((pair) => {
      const i = pair.indexOf('=')
      const k = decodeURIComponent(i >= 0 ? pair.slice(0, i) : pair)
      const v = decodeURIComponent(i >= 0 ? pair.slice(i + 1) : '')
      if (k) params[k] = v
    })
  }
  // switchTab 无法带 query：社区分类 / 社群子 Tab 写入本地后再跳
  if (pathOnly === '/pages/notice/notice' && params.cat) {
    try {
      uni.setStorageSync('fanshub_notice_cat', String(params.cat))
    } catch (e) {}
  }
  if (pathOnly === '/pages/community/community' && params.sub) {
    try {
      uni.setStorageSync('fanshub_community_sub', String(params.sub))
    } catch (e2) {}
  }
  const TAB = {
    '/pages/home/home': 1,
    '/pages/messages/messages': 1,
    '/pages/notice/notice': 1,
    '/pages/community/community': 1,
    '/pages/profile/profile': 1,
  }
  if (TAB[pathOnly]) {
    uni.switchTab({
      url: pathOnly,
      fail: () => uni.reLaunch({ url: pathOnly }),
    })
    return
  }
  if (u.indexOf('/pages/') === 0) {
    uni.navigateTo({
      url: u,
      fail: () => uni.reLaunch({ url: u }),
    })
  }
}

function onFloatTap(f) {
  if (!f) return
  const lt = String(f.linkType || 'internal')
  if (lt === 'none') return
  const url = String(f.linkUrl || '').trim()
  if (!url) return
  if (lt === 'external') {
    if (/^https?:\/\//i.test(url)) {
      openExternalHttpUrl(url)
    } else {
      uni.showToast({ title: '外链无效', icon: 'none' })
    }
    return
  }
  // internal（及兼容旧值）
  openLobbyLink(url)
}

function onCarnivalBanner() {
  if (fissionEntryState.value !== 'hidden') {
    goFission()
    return
  }
  goTab('/pages/messages/messages')
}

function onInviteTap() {
  // App/Safari：@click + @tap 可能同一次手势双触发，去重
  if (inviteTapLock) return
  inviteTapLock = true
  setTimeout(() => {
    inviteTapLock = false
  }, 450)
  const inv = lobbyInvite.value || {}
  const lt = String(inv.linkType || 'share')
  if (lt === 'url' && inv.linkUrl) {
    openLobbyLink(inv.linkUrl)
    return
  }
  if (lt === 'none') return
  copyShareLink()
}

function closeInviteCopySheet() {
  inviteCopySheet.value = false
}

async function retryInviteCopy() {
  const s = String(inviteCopyCache.value || '').trim()
  if (!s) return
  try {
    await copyText(s)
    inviteCopySheet.value = false
    uni.showToast({ title: '邀请链接已复制', icon: 'success' })
  } catch (e) {
    uni.showToast({ title: '请长按上方文字复制', icon: 'none' })
  }
}

/** 预取邀请文案：点击时同步 copy，保住 Safari/IPA 手势 */
async function prefetchInviteCopy() {
  if (!getToken()) return
  try {
    const data = await apiRequest('share', 'POST', { copy_only: true })
    const out = buildShareCopyText(data)
    if (out) inviteCopyCache.value = out
  } catch (e) {}
}

function onGameTap(game) {
  if (!game) return
  if (game.comingSoon) {
    uni.showToast({ title: tt('lobby_coming_soon', '敬请期待'), icon: 'none' })
    return
  }
  const ogId = Number(game.ogGameId) || 0
  const isLive =
    ogId > 0 ||
    (Array.isArray(game.cats) && game.cats.indexOf('live') >= 0) ||
    /^og[_-]/i.test(String(game.id || ''))
  if (isLive) {
    openOgSheet(game, ogId)
    return
  }
  uni.navigateTo({ url: '/pages/home/game-detail?game=' + encodeURIComponent(game.id) })
}

function openOgSheet(game, ogId) {
  if (!getToken()) {
    uni.showToast({ title: '请先登录', icon: 'none' })
    setTimeout(() => uni.reLaunch({ url: '/pages/login/login' }), 400)
    return
  }
  ogPendingGameId.value = ogId > 0 ? ogId : 0
  ogSheetTitle.value = String((game && game.title) || 'OG 视讯')
  const p = profile.value || {}
  const hb = p.hongbao != null ? p.hongbao : p.account?.hongbao
  if (hb != null) ogHongbao.value = Number(hb) || 0
  ogSheetOpen.value = true
  nextTick(() => {
    measureLobbySafeBottom()
  })
  refreshOgSheetBal()
  loadOgRebate().catch(() => {})
}

function closeOgSheet() {
  if (ogBusy.value) return
  ogSheetOpen.value = false
}

async function refreshOgSheetBal() {
  try {
    const data = await ogBalance()
    ogBal.value = String(data?.current_balance ?? data?.og_balance ?? '0.00')
    ogHongbao.value = Number(data?.hongbao ?? ogHongbao.value) || 0
  } catch (e) {
    try {
      const snap = await ogPlayer()
      if (snap?.hongbao != null) ogHongbao.value = Number(snap.hongbao) || 0
    } catch (e2) {}
  }
}

async function ensureHomeOgReady() {
  try {
    const snap = await ogPlayer()
    if (snap?.hongbao != null) ogHongbao.value = Number(snap.hongbao) || 0
    if (snap?.registered) return snap
  } catch (e) {}
  return ogRegister()
}

function onOgEnterPrimary() {
  if (!getToken() || ogBusy.value) return
  const ogId = Number(ogPendingGameId.value) || 0
  if (!(ogId > 0)) {
    uni.showToast({ title: '未配置 GameID', icon: 'none' })
    return
  }
  const title = encodeURIComponent(String(ogSheetTitle.value || '真人视讯'))
  const url = '/pages/og/live?game_id=' + ogId + '&title=' + title + '&auto=1'
  ogSheetOpen.value = false
  uni.navigateTo({
    url,
    fail: () => uni.redirectTo({ url }),
  })
}

async function onOgWithdraw() {
  if (!getToken() || ogBusy.value) return
  ogBusyWithdraw.value = true
  try {
    await ensureHomeOgReady()
    await refreshOgSheetBal()
    const amt = ogNum.value
    if (!(amt > 0)) {
      uni.showToast({ title: 'OG 无可提出余额', icon: 'none' })
      return
    }
    const ret = await ogWithdraw(amt)
    ogBal.value = String(ret?.balance ?? '0.00')
    if (ret?.hongbao != null) ogHongbao.value = Number(ret.hongbao) || 0
    notifyProfileUpdated()
    uni.showToast({ title: '已全部提出', icon: 'success' })
    await refreshOgSheetBal()
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '提出失败', icon: 'none' })
  } finally {
    ogBusyWithdraw.value = false
  }
}

const withdrawThreshold = computed(() => {
  const c = config.value || {}
  const n = parseFloat(c.withdraw_threshold)
  return !isNaN(n) && n > 0 ? n : 50
})

const sharePrice = computed(() => {
  const j = jackpot.value || config.value || {}
  const n = parseFloat(j.current_share_price != null ? j.current_share_price : j.share_price)
  return !isNaN(n) && n > 0 ? n : 5
})

const hongbaoNum = computed(() => {
  const p = profile.value || {}
  const n = p.hongbao != null ? p.hongbao : p.account?.hongbao
  return Math.max(0, Number(n) || 0)
})
const rightsNum = computed(() => {
  const p = profile.value || {}
  const n = p.rights != null ? p.rights : p.account?.rights
  return Math.max(0, Number(n) || 0)
})
const frozenNum = computed(() => {
  const p = profile.value || {}
  return Math.max(0, Number(p.hongbao_frozen) || 0)
})

const hongbaoText = computed(() => hongbaoNum.value.toFixed(2))
const rightsText = computed(() => rightsNum.value.toFixed(2))
const frozenText = computed(() => frozenNum.value.toFixed(2))
const frozenVisible = computed(() => frozenNum.value > 0.00001)

const rightsValuationHint = computed(() => {
  const r = rightsNum.value
  if (r <= 0) return ''
  const amt = Math.round(r * sharePrice.value * 100) / 100
  return '(💡当前估值:￥' + amt.toFixed(2) + ' 元 )'
})

const progressPct = computed(() => {
  const th = withdrawThreshold.value
  if (th <= 0) return 0
  return Math.min(100, (hongbaoNum.value / th) * 100)
})

function formatCountNum(num) {
  return Number(num || 0).toLocaleString('en-US')
}

/** 对齐 888 formatMoney：￥1,234.56 */
function formatMoney(amount) {
  const val = Number(amount)
  const n = isNaN(val) ? 0 : val
  return '￥' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

const jackpotMoneyText = computed(() => {
  const j = jackpot.value || {}
  const n = j.amount != null ? j.amount : j.cumulative_payout != null ? j.cumulative_payout : j.jackpot
  return formatMoney(n != null ? n : 0)
})

const partnersText = computed(() => {
  const j = jackpot.value || config.value || {}
  const count = j.partner_count != null ? j.partner_count : j.partners
  const up = j.partner_today_up != null ? j.partner_today_up : 0
  const n = Number(count)
  const countFmt = formatCountNum(!isNaN(n) && n > 0 ? n : marketVirtualBase())
  const upFmt = formatCountNum(up)
  return (
    t('jackpot_partners', { partner_count: countFmt, partner_today_up: upFmt }) ||
    `📈 当前全网股份人数：${countFmt} 人 ( 🚀 今日暴涨 +${upFmt} 人 )`
  )
})

const priceText = computed(() => {
  const j = jackpot.value || config.value || {}
  const price = j.current_share_price != null ? j.current_share_price : j.share_price
  const pct = j.price_up_pct != null ? j.price_up_pct : 0
  if (price == null) return ''
  const price2 = Number(price).toFixed(2)
  const pctNum = Number(pct) || 0
  return (
    t('jackpot_price_line', {
      current_share_price: price2,
      price_up_pct: pctNum,
    }) || `💎 今日大盘实时持仓行权价：￥${price2} / 份 ( 🔥 较昨日大盘拉升 +${pctNum}% )`
  )
})

const mobileMask = computed(() => {
  const p = profile.value || {}
  return p.mobile_mask || p.mobile || '----'
})

const isMasterRank = computed(() => {
  const p2 = (profile.value && profile.value.phase2) || {}
  return !!(p2.enabled && p2.user_mode === 'master')
})

const fissionEntryState = computed(() => {
  const f = fissionEntry.value
  if (!f || !f.has_activity) return 'hidden'
  return String(f.entry_state || 'hidden')
})
const fissionEntryTitle = computed(() => {
  const a = (fissionEntry.value && fissionEntry.value.activity) || {}
  return a.title || tt('fission_home_entry_title', '全网裂变红宝')
})
const fissionEntrySub = computed(() => {
  if (fissionEntryState.value === 'ended') {
    const st = (fissionEntry.value && fissionEntry.value.activity && fissionEntry.value.activity.status) | 0
    return st === 2
      ? tt('fission_home_entry_sub_ended_drawn', '已开奖 · 入口已关闭')
      : tt('fission_home_entry_sub_ended_void', '未集齐已作废 · 关系仍保留')
  }
  const a = (fissionEntry.value && fissionEntry.value.activity) || {}
  const pool = a.pool_amount != null ? a.pool_amount : 1000
  const g = a.global_quals | 0
  const cap = a.global_cap || 100
  return tt('fission_home_entry_sub_active', '¥{pool} 奖金池 · {quals}/{cap} 份资格', {
    pool,
    quals: g,
    cap,
  })
})
const fissionPopupPool = computed(() => {
  const a = (fissionEntry.value && fissionEntry.value.activity) || {}
  const p = fissionEntry.value && fissionEntry.value.popup
  if (p && p.pool_amount != null) return p.pool_amount
  return a.pool_amount != null ? a.pool_amount : 1000
})
const fissionPopupQuals = computed(() => ((fissionEntry.value && fissionEntry.value.activity && fissionEntry.value.activity.global_quals) | 0))
const fissionPopupCap = computed(() => ((fissionEntry.value && fissionEntry.value.activity && fissionEntry.value.activity.global_cap) || 100))
const fissionPopupRemain = computed(() => {
  const s = Math.max(0, fissionPopupRemainSec.value | 0)
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  const sec = s % 60
  const pad = (n) => (n < 10 ? '0' + n : '' + n)
  return pad(h) + ':' + pad(m) + ':' + pad(sec)
})

const flowStage = computed(() => (profile.value && profile.value.flow_stage) || 'stage1')
const mainUidAudit = computed(() => (profile.value && profile.value.main_uid_audit) || '')

const stepperSteps = computed(() => {
  const uid = String(gameUid.value || '').trim()
  const opened = flowStage.value === 'stage2' || !!uid
  const exchanged = hongbaoNum.value > 0
  const claimReady = hongbaoNum.value >= withdrawThreshold.value
  const doneFlags = [true, opened, exchanged, !!uid, claimReady]
  let current = 1
  for (let i = 0; i < doneFlags.length; i++) {
    if (doneFlags[i]) current = i + 1
    else {
      current = i + 1
      break
    }
  }
  if (claimReady) current = 5
  const labels = [
    t('stepper_1') || '入厅',
    t('stepper_2') || '开户',
    t('stepper_3') || '闪兑',
    t('stepper_4') || '账号',
    t('stepper_5') || '领取',
  ]
  return labels.map((label, i) => {
    const n = i + 1
    return {
      n,
      label,
      done: !!doneFlags[i],
      active: !doneFlags[i] && n === current,
    }
  })
})

const openAccountParts = computed(() => {
  const rights = (config.value && config.value.open_account_rights) != null ? config.value.open_account_rights : 2
  const vars = { open_account_rights: rights }
  const raw = t('open_account_btn', vars) || ''
  const fallbackBadge = t('open_account_badge_fallback', vars) || `立送 ${rights} 份大盘股。`
  const fillRights = (s) =>
    String(s || '').replace(/\{open_account_rights\}/g, String(rights))
  const bracket = String(raw).match(/^(.+?)\[(.+?)\]\((.+?)\)\s*$/)
  if (bracket) {
    return {
      label: fillRights((bracket[1] + '[' + bracket[2] + ']').trim()),
      badge: fillRights(bracket[3].trim()),
    }
  }
  const paren = String(raw).match(/^(.+?)\(([^)]+)\)\s*$/)
  if (paren) {
    return { label: fillRights(paren[1].trim()), badge: fillRights(paren[2].trim()) }
  }
  return { label: fillRights(raw || fallbackBadge), badge: fillRights(fallbackBadge) }
})
const openAccountLabel = computed(() => openAccountParts.value.label)
const openAccountBadge = computed(() => openAccountParts.value.badge)

const settleTitle = computed(() => {
  if (hongbaoNum.value >= withdrawThreshold.value) return t('settle_title_high') || '🏦 申请 VIP 人工加急特批绿通'
  return t('settle_title_low') || '🏦 申请 VIP 人工加急特批绿通'
})
const settleSub = computed(() => {
  if (hongbaoNum.value >= withdrawThreshold.value) return t('settle_sub_high') || '金额已达标，可生成密令联系客服上分'
  return t('settle_sub_low') || '金额不足时也可提前联系专属客服协助凑数'
})

const uidLocked = computed(() => mainUidAudit.value === 'pending' || mainUidAudit.value === 'approved')
const uidApproved = computed(() => mainUidAudit.value === 'approved')
const uidBtnDisabled = computed(() => mainUidAudit.value === 'pending' || mainUidAudit.value === 'approved')
const displayGameUid = computed(() => {
  const p = profile.value || {}
  const fromProfile = String(p.main_uid || p.main_uid_pending || '').trim()
  return fromProfile || String(gameUid.value || '').trim() || '—'
})
const hasGameAccount = computed(() => {
  const p = profile.value || {}
  return !!(String(p.main_uid || '').trim() || String(p.main_uid_pending || '').trim())
})
const showNewbieOpenPanel = computed(() => !isMasterRank.value && !hasGameAccount.value)
const uidBtnText = computed(() => {
  if (mainUidAudit.value === 'pending') return t('uid_submit_pending') || '正在审核中'
  return t('uid_submit_btn') || '提交账号审核'
})
const uidHintClass = computed(() => {
  const a = mainUidAudit.value
  if (a === 'pending' || a === 'approved' || a === 'rejected') return a
  return ''
})
const uidHintText = computed(() => {
  const a = mainUidAudit.value
  const reason = (profile.value && profile.value.main_uid_reject_reason) || ''
  if (a === 'pending') return t('uid_hint_pending') || '正在审核中，请耐心等待客服后台核销上分'
  if (a === 'approved') return t('uid_hint_approved') || '游戏账号已通过核销，账号已锁定'
  if (a === 'rejected') {
    const base = t('uid_hint_rejected') || '审核失败'
    return reason ? base + '：' + reason : base
  }
  return t('uid_hint_idle') || '请填写游戏账号（数字或英文数字组合均可），每个账号仅可提交一次审核'
})

const withdrawTitle = computed(() => {
  if (hongbaoNum.value >= withdrawThreshold.value) return t('withdraw_title_vip') || '🔒 官方 VIP 福利派发中心'
  return t('withdraw_title_green') || '🔒 官方 VIP 福利派发中心'
})

/** 对齐 888 withdraw_step1~3（文案含 HTML），去掉标签后展示 */
function withdrawStepText(n) {
  const fallbacks = {
    1: '点击下方按钮，一键复制密令并跳转红宝专属客服',
    2: '将密令发送给在线客服小妹，获取官方红宝聊天App下载指引',
    3: '下载并添加官方 App 后，客服将为您完成主站账号充值（保障资金与账号绝对安全）',
  }
  const key = 'withdraw_step' + n
  let raw = t(key)
  if (!raw || raw === key) raw = fallbacks[n] || ''
  return String(raw)
    .replace(/<[^>]+>/g, '')
    .replace(/^\s*\d+\s*/, '')
    .trim()
}

const marqueeItems = computed(() => {
  const cfg = config.value || {}
  if (Array.isArray(cfg.marquee_items) && cfg.marquee_items.length) {
    return cfg.marquee_items.map((s) => String(s || '').trim()).filter(Boolean)
  }
  const raw = t('marquee_text') || ''
  const parts = String(raw)
    .split(/[\n|｜]/)
    .map((s) => s.trim())
    .filter(Boolean)
  if (parts.length) return parts
  const fallback = (t('marquee_fallback_prefix') || '') + (t('marquee_fallback') || '恭喜合伙人完成闪兑 · 福利实时到账')
  return [fallback]
})

function goTab(url) {
  uni.switchTab({ url })
}

function goFission() {
  if (fissionEntryState.value === 'hidden') return
  uni.navigateTo({
    url: '/pages/fission/detail',
    fail: () => uni.reLaunch({ url: '/pages/fission/detail' }),
  })
}

function applyFissionEntry(f) {
  fissionEntry.value = f || null
  const act = (f && f.activity) || {}
  const st = Number((f && f.server_time) || Math.floor(Date.now() / 1000))
  const popupRem = f && f.popup && f.popup.remain_sec
  fissionPopupRemainSec.value = Math.max(
    0,
    popupRem != null ? Number(popupRem) : Number(act.end_time || 0) - st
  )
  startFissionPopupTick()
  maybeShowFissionPopup()
}

function startFissionPopupTick() {
  if (fissionPopupTick) {
    clearInterval(fissionPopupTick)
    fissionPopupTick = null
  }
  if (fissionPopupRemainSec.value <= 0) return
  fissionPopupTick = setInterval(() => {
    if (fissionPopupRemainSec.value > 0) fissionPopupRemainSec.value -= 1
    else {
      clearInterval(fissionPopupTick)
      fissionPopupTick = null
    }
  }, 1000)
}

function maybeShowFissionPopup() {
  const f = fissionEntry.value
  if (!f || !f.popup || !f.popup.show) return
  const aid = String((f.popup.activity_id || (f.activity && f.activity.id) || '') || '')
  try {
    // 仅按活动去重；关掉后本浏览器该活动不再弹（换活动会再弹）
    if (aid && uni.getStorageSync('fission_popup_seen_' + aid) === '1') return
  } catch (e) {}
  fissionPopupOpen.value = true
}

function dismissFissionPopup() {
  fissionPopupOpen.value = false
  try {
    const aid = String(
      (fissionEntry.value && fissionEntry.value.popup && fissionEntry.value.popup.activity_id) ||
        (fissionEntry.value && fissionEntry.value.activity && fissionEntry.value.activity.id) ||
        ''
    )
    if (aid) uni.setStorageSync('fission_popup_seen_' + aid, '1')
  } catch (e) {}
}

function openFissionFromPopup() {
  dismissFissionPopup()
  uni.navigateTo({
    url: '/pages/fission/detail',
    fail: () => uni.reLaunch({ url: '/pages/fission/detail' }),
  })
}

function rankBadge(rank) {
  const r = rank | 0
  if (r === 1) return '🥇'
  if (r === 2) return '🥈'
  if (r === 3) return '🥉'
  return String(r || '-')
}

function inviteCountText(count) {
  return t('leaderboard_invite_template', { count: count | 0 }) || '邀 ' + (count | 0) + ' 人'
}

function sanitizeUidValue(raw) {
  return String(raw || '')
    .replace(/[^A-Za-z0-9]/g, '')
    .slice(0, 32)
}

function onUidBlur() {
  if (uidLocked.value) return
  gameUid.value = sanitizeUidValue(gameUid.value)
}

function applyConfig(cfg) {
  if (!cfg) return
  config.value = Object.assign({}, config.value || {}, cfg)
  if (cfg.copy) applyServerCopy(cfg.copy)
  if (cfg.main_station_url) mainStationUrl.value = cfg.main_station_url
  if (cfg.app_download_url) appDownloadUrl.value = cfg.app_download_url
  if (cfg.customer_service_url) {
    /* reserved for jump */
  }
  // 用 config 快照补大屏，避免首屏一直 0 / 空白
  if (
    cfg.partner_count != null ||
    cfg.fission_user_count != null ||
    cfg.cumulative_payout != null ||
    cfg.share_price != null ||
    cfg.current_share_price != null
  ) {
    applyMarketScreen(cfg)
  } else if (!jackpot.value || !(jackpot.value.partner_count > 0)) {
    applyMarketScreen({ partner_count: marketVirtualBase() })
  }
}

function syncUidFromProfile(p) {
  if (!p) return
  const displayUid = String(p.main_uid || p.main_uid_pending || '').trim()
  if (displayUid) gameUid.value = displayUid
}

watch(
  () => {
    const p = profile.value
    if (!p) return ''
    return String(p.main_uid || '') + '|' + String(p.main_uid_pending || '') + '|' + String(p.main_uid_audit || '')
  },
  () => {
    syncUidFromProfile(profile.value)
  }
)

async function loadLobbyHome() {
  try {
    const token = String(lobbyLiveTestToken.value || '').trim()
    const data = token
      ? await apiRequest('lobbyhometest', 'GET', { token })
      : await apiRequest('lobbyhome', 'GET', {})
    if (data && typeof data === 'object') {
      remoteLobby.value = data
      lobbyLiveEnabled.value = !!data.live_enabled
      const cats = lobbyCategories.value
      if (cats.length && !cats.some((c) => c.id === activeCat.value && (!c.action || c.action === 'filter'))) {
        const prefer = cats.find((c) => c.id === 'hot' || c.id === 'games')
        const first = prefer || cats.find((c) => !c.action || c.action === 'filter')
        if (first) activeCat.value = first.id
      }
    }
  } catch (e) {
    /* keep defaults */
  }
}

function readLiveTestTokenFromQuery(q) {
  const raw = (q && (q.live_test || q.liveTest || q.token)) || ''
  return String(raw || '').trim()
}

onLoad((q) => {
  const t = readLiveTestTokenFromQuery(q)
  if (t) lobbyLiveTestToken.value = t
  // #ifdef H5
  try {
    if (!lobbyLiveTestToken.value && typeof location !== 'undefined') {
      const m = String(location.hash || location.search || '').match(/[?&]live_test=([^&]+)/i)
      if (m && m[1]) lobbyLiveTestToken.value = decodeURIComponent(m[1])
    }
  } catch (e) {}
  // #endif
})

async function loadBootstrap() {
  try {
    const data = await apiRequest('bootstrap', 'GET', { include: 'home' })
    if (data) {
      if (data.profile) {
        profile.value = data.profile
        syncUidFromProfile(data.profile)
        try {
          uni.$emit && uni.$emit('fanshub-profile-updated', data.profile)
        } catch (e0) {}
      }
      if (data.config) applyConfig(data.config)
      if (data.market) {
        applyMarketScreen(data.market)
        applyLobbyExtras(data.market)
      } else if (data.jackpot) {
        applyMarketScreen(data.jackpot)
        applyLobbyExtras(data.jackpot)
      }
      // 排行榜统一走 loadLeaderboard（虚拟榜+真实合并），不直接用 bootstrap 短列表
      if (data.home && data.home.fission) applyFissionEntry(data.home.fission)
    }
  } catch (e) {
    /* fallback below */
  }
  if (!fissionEntry.value) {
    try {
      const fe = await apiRequest('fissionentry', 'GET', {})
      applyFissionEntry(fe)
    } catch (eF) {}
  }
  if (!profile.value) {
    try {
      profile.value = await fetchProfile()
      syncUidFromProfile(profile.value)
    } catch (e2) {
      uni.showToast({ title: e2.message || '资料失败', icon: 'none' })
    }
  }
  if (!config.value) {
    try {
      const cfg = await apiRequest('config', 'GET')
      applyConfig(cfg)
    } catch (e3) {}
  }
  if (!jackpot.value && isRightsMarketOn()) await pollJackpot()
  await loadLeaderboard()
}

function leaderboardDaySeed() {
  const d = new Date()
  // 按分钟换种子，虚拟榜人数/号码会随刷新 visibly 变化
  return d.getFullYear() * 10000 + (d.getMonth() + 1) * 100 + d.getDate() + Math.floor(Date.now() / 60000)
}

function leaderboardRng(seed) {
  let s = seed | 0
  return function () {
    s = (s * 1103515245 + 12345) & 0x7fffffff
    return s / 0x7fffffff
  }
}

function buildVirtualLeaderboard(limit) {
  limit = Math.max(1, Math.min(20, limit || 10))
  const rnd = leaderboardRng(leaderboardDaySeed() ^ 0xf15510)
  const pools = [
    { dial: '+86', heads: ['130', '131', '135', '136', '137', '138', '139', '150', '158', '186', '188'], tailLen: 4 },
    { dial: '+63', heads: ['905', '906', '915', '916', '917', '918', '919', '920', '921', '927'], tailLen: 4 },
    { dial: '+84', heads: ['90', '91', '93', '94', '96', '97', '98', '32', '33', '35'], tailLen: 4 },
    { dial: '+60', heads: ['10', '11', '12', '13', '14', '16', '17', '18', '19'], tailLen: 4 },
    { dial: '+855', heads: ['10', '11', '12', '15', '16', '17', '61', '69', '70', '77'], tailLen: 3 },
    { dial: '+62', heads: ['812', '813', '814', '815', '816', '817', '818', '819', '821', '822'], tailLen: 4 },
  ]
  const base = [28, 24, 21, 17, 14, 12, 10, 8, 6, 5]
  const hourBoost = Math.floor((Date.now() % 86400000) / 3600000)
  const minuteBoost = Math.floor((Date.now() % 3600000) / 60000)
  const rows = []
  for (let i = 0; i < limit; i++) {
    const pool = pools[Math.floor(rnd() * pools.length)]
    const head = pool.heads[Math.floor(rnd() * pool.heads.length)]
    let tail = ''
    for (let t = 0; t < pool.tailLen; t++) tail += String(Math.floor(rnd() * 10))
    const jitter = Math.floor(rnd() * 3)
    const count = Math.max(
      2,
      (base[i] || Math.max(2, 12 - i)) + Math.floor(hourBoost * 0.12) + Math.floor(minuteBoost * 0.08) + jitter
    )
    rows.push({
      rank: i + 1,
      mobile_mask: pool.dial + ' ' + head + '****' + tail,
      invite_count: count,
    })
  }
  rows.sort((a, b) => b.invite_count - a.invite_count)
  rows.forEach((r, idx) => {
    r.rank = idx + 1
  })
  return rows
}

function normalizeLeaderboard(rows) {
  return (rows || []).slice(0, 10).map((r, i) => ({
    rank: r.rank || i + 1,
    mobile_mask: r.mobile_mask || '',
    invite_count: Number(r.invite_count) || 0,
    user_id: r.user_id,
  }))
}

async function loadLeaderboard() {
  let rows = buildVirtualLeaderboard(10)
  try {
    let real = await apiRequest('inviteleaderboard', 'GET', { limit: 10 })
    if (real && !Array.isArray(real)) {
      real = real.list || real.rows || real.data || []
    }
    if (Array.isArray(real) && real.length) {
      const map = {}
      real.forEach((r) => {
        if (!r || !r.mobile_mask) return
        map[r.mobile_mask] = {
          mobile_mask: r.mobile_mask,
          invite_count: Number(r.invite_count) || 0,
        }
      })
      rows.forEach((v) => {
        if (!map[v.mobile_mask]) map[v.mobile_mask] = v
      })
      rows = Object.keys(map).map((k) => map[k])
      rows.sort((a, b) => (b.invite_count || 0) - (a.invite_count || 0))
      rows = rows.slice(0, 10)
      rows.forEach((r, i) => {
        r.rank = i + 1
      })
    }
  } catch (e) {
    /* keep virtual */
  }
  leaderboard.value = rows
}

async function pollJackpot() {
  if (!isRightsMarketOn()) return
  try {
    const data = await apiRequest('jackpot', 'GET')
    if (data) {
      applyMarketScreen(data)
      applyLobbyExtras(data)
    }
  } catch (e) {}
}

async function pollOnlineLive() {
  try {
    const rec = await apiRequest('communityrecommend', 'GET', {})
    const rows = (rec && (rec.list || rec.rows || rec.items)) || rec || []
    if (Array.isArray(rows)) {
      officialGroups.value = rows
    }
    const ot = Number(rec && rec.online_total)
    if (!isNaN(ot) && ot > 0) {
      lobbyOnlineTotal.value = Math.floor(ot)
    } else if (Array.isArray(rows) && rows.length) {
      let sum = 0
      for (let i = 0; i < rows.length; i++) sum += groupDisplayOnline(rows[i])
      if (sum > 0) lobbyOnlineTotal.value = sum
    }
  } catch (e) {}
  if (!isRightsMarketOn()) return
  try {
    const data = await apiRequest('jackpot', 'GET')
    if (data) applyLobbyExtras(data)
  } catch (e2) {}
}

function marketVirtualBase() {
  const cfg = config.value || {}
  const n = parseInt(cfg.market_virtual_base != null ? cfg.market_virtual_base : cfg.partner_count, 10)
  return !isNaN(n) && n > 0 ? n : 8000
}

/** 股份大盘是否启用（关闭后不再轮询 jackpot / 本地氛围） */
function isRightsMarketOn() {
  const cfg = config.value || {}
  if (Object.prototype.hasOwnProperty.call(cfg, 'rights_market_enabled')) {
    return !!cfg.rights_market_enabled
  }
  const j = jackpot.value || {}
  if (Object.prototype.hasOwnProperty.call(j, 'rights_market')) {
    return !!j.rights_market
  }
  return cfg.jackpot_server_sync !== false
}

function applyMarketScreen(data) {
  if (!data || typeof data !== 'object') return
  const prev = jackpot.value && typeof jackpot.value === 'object' ? jackpot.value : {}
  const next = Object.assign({}, prev, data)

  const rawAmt = data.cumulative_payout !== undefined ? data.cumulative_payout : data.amount
  if (rawAmt !== undefined) {
    const n = parseFloat(rawAmt) || 0
    const prevAmt = parseFloat(prev.cumulative_payout != null ? prev.cumulative_payout : prev.amount) || 0
    // 大盘累计价值：会话内只升不降
    const amt = Math.max(prevAmt, n)
    next.amount = amt
    next.cumulative_payout = amt
  }

  if (data.partner_count !== undefined || data.fission_user_count !== undefined || data.partners !== undefined) {
    const raw =
      data.partner_count !== undefined
        ? data.partner_count
        : data.fission_user_count !== undefined
          ? data.fission_user_count
          : data.partners
    let n = Math.max(0, parseInt(raw, 10) || 0)
    if (n <= 0) n = marketVirtualBase()
    const prevN = Math.max(0, parseInt(prev.partner_count != null ? prev.partner_count : prev.partners, 10) || 0)
    next.partner_count = Math.max(prevN, n)
    next.partners = next.partner_count
  }

  if (data.partner_today_up !== undefined) {
    next.partner_today_up = Math.max(0, parseInt(data.partner_today_up, 10) || 0)
  }
  if (data.share_price !== undefined || data.current_share_price !== undefined) {
    const p = parseFloat(data.current_share_price != null ? data.current_share_price : data.share_price)
    if (!isNaN(p) && p > 0) {
      const prevP = parseFloat(prev.current_share_price != null ? prev.current_share_price : prev.share_price) || 0
      const price = Math.max(prevP, p)
      next.share_price = price
      next.current_share_price = price
    }
  }
  if (data.price_up_pct !== undefined) {
    next.price_up_pct = Math.max(0, parseFloat(data.price_up_pct) || 0)
  }
  // 首屏无人数字段时用营销基数，避免一直显示 0
  if (!(next.partner_count > 0) && !(next.partners > 0)) {
    next.partner_count = Math.max(prev.partner_count | 0, marketVirtualBase())
  }
  jackpot.value = next
}

function tickMarketLocal() {
  if (!isRightsMarketOn()) return
  const cfg = config.value || {}
  if (cfg.jackpot_server_sync !== false) return
  const prev = jackpot.value && typeof jackpot.value === 'object' ? { ...jackpot.value } : {}
  let amt = parseFloat(prev.cumulative_payout != null ? prev.cumulative_payout : prev.amount) || 0
  const ceiling = parseFloat(cfg.jackpot_ceiling) || 20000
  if (amt < ceiling) {
    const minG = parseFloat(cfg.jackpot_grow_min) || 0.02
    const maxG = parseFloat(cfg.jackpot_grow_max) || 0.08
    amt = Math.min(ceiling, amt + minG + Math.random() * Math.max(0, maxG - minG))
    prev.amount = amt
    prev.cumulative_payout = amt
  }
  const hour = new Date().getHours()
  const isDay = hour >= 8 && hour < 23
  const add = isDay ? 3 + Math.floor(Math.random() * 10) : Math.floor(Math.random() * 3)
  const pc = Math.max(0, parseInt(prev.partner_count != null ? prev.partner_count : prev.partners, 10) || 0)
  const nextPc = pc + add
  prev.partner_count = nextPc
  // 大厅在线人数以官方社群合计为准，本地氛围不再改写 onlineCountLive
  jackpot.value = prev
}

function startPoll() {
  stopPoll()
  pollOnlineLive()
  loadLeaderboard()
  startTicker()
  startOnlineJitter()
  if (isRightsMarketOn()) {
    pollJackpot()
    pollTimer = setInterval(() => {
      pollJackpot()
    }, 20000)
    // 本地氛围：金额/人数微动（仅非服务端同步时）
    if (!pollLocalTimer) {
      pollLocalTimer = setInterval(tickMarketLocal, 60000)
    }
  }
  onlinePollTimer = setInterval(() => {
    pollOnlineLive()
  }, 20000)
  // 排行榜每分钟刷新一次（虚拟榜 minuteBoost + 真实合并）
  if (!lbTimer) {
    lbTimer = setInterval(() => {
      loadLeaderboard()
    }, 60000)
  }
}

function stopPoll() {
  stopTicker()
  stopOnlineJitter()
  if (pollTimer) {
    clearInterval(pollTimer)
    pollTimer = null
  }
  if (onlinePollTimer) {
    clearInterval(onlinePollTimer)
    onlinePollTimer = null
  }
  if (pollLocalTimer) {
    clearInterval(pollLocalTimer)
    pollLocalTimer = null
  }
  if (lbTimer) {
    clearInterval(lbTimer)
    lbTimer = null
  }
}

function buildShareCopyText(data) {
  const link = String((data && data.share_link) || '').trim()
  const shareText = String((data && data.share_text) || '').trim()
  // 优先复制带邀请码的专属链接；文案里没有链接时用 share_link
  let out = link
  if (shareText && (/https?:\/\//i.test(shareText) || /code=/i.test(shareText))) {
    out = shareText
  } else if (link && shareText) {
    out = shareText + (shareText.indexOf(link) >= 0 ? '' : '\n' + link)
  } else if (shareText) {
    out = shareText
  }
  return out
}

/** iOS Safari / IPA：优先同步栈 copy 缓存文案；否则 ClipboardItem 异步写入 */
function copyShareLink() {
  if (shareSubmitting.value) return
  if (!getToken()) {
    try {
      uni.setStorageSync('fanshub_login_return', '/pages/home/home')
    } catch (e) {}
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  shareSubmitting.value = true
  const cached = String(inviteCopyCache.value || '').trim()
  const finishOk = (text) => {
    if (text) inviteCopyCache.value = text
    uni.showToast({ title: '邀请链接已复制', icon: 'success' })
  }
  const finishFail = (e, text) => {
    const s = String(text || inviteCopyCache.value || '').trim()
    if (s) {
      inviteCopyCache.value = s
      inviteCopySheet.value = true
      uni.showToast({ title: '请长按复制邀请文案', icon: 'none' })
      return
    }
    uni.showToast({ title: (e && e.message) || t('alert_share_fail') || '复制失败', icon: 'none' })
  }
  if (cached) {
    copyText(cached)
      .then(() => finishOk(cached))
      .catch((e) => finishFail(e, cached))
      .finally(() => {
        shareSubmitting.value = false
      })
    // 后台刷新缓存，不阻塞本次手势
    prefetchInviteCopy()
    return
  }
  const work = (async () => {
    const data = await apiRequest('share', 'POST', { copy_only: true })
    const out = buildShareCopyText(data)
    if (!out) throw new Error('暂无邀请链接')
    inviteCopyCache.value = out
    return out
  })()
  copyTextDeferred(work)
    .then(() => finishOk(inviteCopyCache.value))
    .catch((e) => finishFail(e, inviteCopyCache.value))
    .finally(() => {
      shareSubmitting.value = false
    })
}

function goToMainStation() {
  try {
    uni.setStorageSync('fans_hub_pending_open', 'true')
    uni.setStorageSync('fans_hub_pending_open_reward', 'true')
  } catch (e) {}
  uni.showToast({ title: t('alert_open_account') || '请前往主站完成开户', icon: 'none' })
  const url = mainStationUrl.value || 'https://555.bio'
  setTimeout(() => {
    // #ifdef H5
    if (typeof window !== 'undefined') window.open(url, '_blank')
    // #endif
    // #ifndef H5
    try {
      // eslint-disable-next-line no-undef
      plus.runtime.openURL(url)
    } catch (e2) {
      copyText(url).catch(() => {})
    }
    // #endif
  }, 600)
}

async function submitUID() {
  if (uidSubmitting.value) return
  const audit = mainUidAudit.value
  if (audit === 'approved') {
    uni.showToast({ title: t('srv_uid_already_approved') || '账号已通过核销', icon: 'none' })
    return
  }
  if (audit === 'pending') {
    uni.showToast({ title: t('srv_uid_pending') || t('uid_hint_pending') || '正在审核中', icon: 'none' })
    return
  }
  const uid = sanitizeUidValue(gameUid.value)
  gameUid.value = uid
  if (uid.length < 2) {
    uni.showToast({ title: t('srv_uid_format_invalid') || t('alert_uid_required') || '请填写有效账号', icon: 'none' })
    return
  }
  if (!getToken()) {
    uni.showToast({ title: t('api_operation_fail') || '请先登录', icon: 'none' })
    return
  }
  uidSubmitting.value = true
  try {
    const p = await apiRequest('binduid', 'POST', { main_uid: uid })
    if (p) {
      profile.value = p
      syncUidFromProfile(p)
    }
    uni.showToast({ title: t('api_bind_ok') || t('uid_hint_pending') || '提交成功', icon: 'none' })
  } catch (e) {
    uni.showToast({ title: e.message || t('api_operation_fail') || '提交失败', icon: 'none' })
  } finally {
    uidSubmitting.value = false
  }
}

function newRequestId(prefix) {
  return prefix + '_' + Date.now() + '_' + Math.random().toString(36).slice(2, 10)
}

function startSecretCountdown() {
  stopSecretCountdown()
  let remaining = secretLockSeconds.value | 0
  const tick = () => {
    if (remaining <= 0) {
      secretCountdown.value = t('withdraw_secret_expired') || '已过期'
      stopSecretCountdown()
      return
    }
    const m = Math.floor(remaining / 60)
    const s = remaining % 60
    secretCountdown.value = String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0')
    remaining -= 1
  }
  tick()
  secretTimer = setInterval(tick, 1000)
}

function stopSecretCountdown() {
  if (secretTimer) {
    clearInterval(secretTimer)
    secretTimer = null
  }
}

async function openWithdrawModal() {
  const balance = hongbaoNum.value
  const isVipTier = balance >= withdrawThreshold.value
  if (isVipTier && flowStage.value === 'stage2' && (mainUidAudit.value !== 'approved' || !(profile.value && profile.value.main_uid))) {
    uni.showToast({
      title:
        mainUidAudit.value === 'pending'
          ? t('uid_hint_pending') || '账号审核中'
          : t('alert_uid_required') || '请先提交游戏账号',
      icon: 'none',
    })
    return
  }
  if (isVipTier && flowStage.value === 'stage1') {
    uni.showToast({ title: t('alert_open_first') || '请先完成开户', icon: 'none' })
    return
  }
  try {
    if (!secretRequestId) secretRequestId = newRequestId('sec')
    const data = await apiRequest('createsecret', 'POST', { request_id: secretRequestId })
    secretCode.value = (data && data.secret && data.secret.code) || ''
    if (data && data.app_download_url) appDownloadUrl.value = data.app_download_url
    if (data && data.profile) {
      profile.value = data.profile
      syncUidFromProfile(data.profile)
    }
    secretLockSeconds.value = (data && data.secret && data.secret.lock_seconds) || 900
    withdrawOpen.value = true
    startSecretCountdown()
  } catch (e) {
    uni.showToast({ title: e.message || t('alert_secret_fail') || '密令生成失败', icon: 'none' })
  }
}

function closeWithdrawModal() {
  withdrawOpen.value = false
  stopSecretCountdown()
  secretRequestId = ''
}

async function copySecretCode() {
  if (!secretCode.value) return
  try {
    await copyText(secretCode.value)
    uni.showToast({ title: t('alert_secret_copied_clipboard') || '密令已复制', icon: 'none' })
  } catch (e) {
    uni.showToast({ title: '复制失败', icon: 'none' })
  }
}

async function jumpToCustomerService() {
  if (!secretCode.value) {
    uni.showToast({ title: t('alert_secret_required') || '请先生成密令', icon: 'none' })
    return
  }
  try {
    await copyText(secretCode.value)
  } catch (e) {}
  uni.showToast({ title: t('alert_secret_copied') || '密令已复制，正在跳转客服', icon: 'none' })
  const code = String(secretCode.value || '')
  closeWithdrawModal()
  // 打开红宝客服私聊，并带上待发送密令
  try {
    uni.setStorageSync('fans_hub_pending_cs_secret', code)
  } catch (e2) {}
  const csId = 88888888
  setTimeout(() => {
    uni.navigateTo({
      url:
        '/pages/chat/chat?type=1&peer=' +
        encodeURIComponent(csId) +
        '&id=' +
        encodeURIComponent('') +
        '&title=' +
        encodeURIComponent('红宝客服') +
        '&nickname=' +
        encodeURIComponent('红宝客服'),
    })
  }, 350)
}

async function openAppDownload() {
  const url = String(appDownloadUrl.value || '').trim()
  if (!url) {
    uni.showToast({ title: '暂无下载链接', icon: 'none' })
    return
  }
  try {
    await copyText(url)
  } catch (e) {
    /* 打开链接优先，复制失败不阻断 */
  }
  if (openExternalHttpUrl(url)) {
    uni.showToast({ title: t('withdraw_app_copy_ok') || '下载链接已复制并打开', icon: 'none' })
    return
  }
  uni.showToast({ title: '链接已复制，请在浏览器打开', icon: 'none' })
}

watch(locale, () => {
  /* 文案随语言刷新，数据保留 */
})

function onProfileUpdated(p) {
  if (p && typeof p === 'object') {
    profile.value = p
    syncUidFromProfile(p)
  }
}

function onLotteryDone(payload) {
  const shares = Number(payload && payload.shares) || 5
  if (profile.value && typeof profile.value === 'object') {
    const next = { ...profile.value }
    if (next.account && typeof next.account === 'object') {
      next.account = { ...next.account, rights: shares }
    }
    next.rights = shares
    profile.value = next
  }
  fetchProfile()
    .then((p) => {
      if (p) {
        profile.value = p
        syncUidFromProfile(p)
        notifyProfileUpdated(p)
      }
    })
    .catch(() => {})
}

onShow(async () => {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  syncFloatDismissForLogin()
  measureLobbySafeBottom()
  try {
    uni.$on && uni.$on('fanshub-profile-updated', onProfileUpdated)
  } catch (e) {}
  await loadBootstrap()
  await loadLobbyHome()
  loadOgRebate().catch(() => {})
  prefetchInviteCopy()
  imConnect().catch(() => {})
  startPoll()
  nextTick(() => {
    measureLobbySafeBottom()
    try {
      lotteryRef.value && lotteryRef.value.schedule && lotteryRef.value.schedule()
    } catch (e2) {}
  })
})

onHide(() => {
  stopPoll()
  stopSecretCountdown()
  if (fissionPopupTick) {
    clearInterval(fissionPopupTick)
    fissionPopupTick = null
  }
  try {
    uni.$off && uni.$off('fanshub-profile-updated', onProfileUpdated)
  } catch (e) {}
})
onUnmounted(() => {
  stopPoll()
  stopSecretCountdown()
  if (fissionPopupTick) {
    clearInterval(fissionPopupTick)
    fissionPopupTick = null
  }
  try {
    uni.$off && uni.$off('fanshub-profile-updated', onProfileUpdated)
  } catch (e) {}
})
</script>

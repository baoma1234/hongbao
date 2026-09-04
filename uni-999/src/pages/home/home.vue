<template>
  <view class="game-lobby-page">
    <TopBar />
    <view class="game-lobby" :style="lobbyPageStyle">
      <view class="game-lobby-inner">
        <view class="game-lobby-banner" hover-class="game-lobby-hit" @click="onCarnivalBanner">
          <image class="game-lobby-banner-img" :src="lobbyAsset('750x400.png')" mode="widthFix" />
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
              <image
                v-if="cat.iconImg || cat.iconStatic"
                class="game-lobby-cat-ico"
                :src="lobbyCatIcon(cat)"
                mode="aspectFit"
              />
              <text v-else class="game-lobby-cat-ico game-lobby-cat-ico-emoji">{{ cat.icon }}</text>
              <text class="game-lobby-cat-lab">{{ cat.label }}</text>
            </view>
          </view>
        </view>

        <view class="game-lobby-section-hd">
          <text class="game-lobby-section-title">{{ tt('lobby_hot_games', '热门游戏') }}</text>
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
              <image class="game-lobby-card-img" :src="lobbyAsset(game.cover)" mode="aspectFill" />
            </view>
            <view v-if="game.badge" class="game-lobby-badge" :class="game.badge">{{ game.badge.toUpperCase() }}</view>
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

        <view class="game-lobby-invite" hover-class="game-lobby-hit" @click="copyShareLink">
          <image class="game-lobby-invite-img" :src="lobbyAsset('750x150.png')" mode="widthFix" />
        </view>
        <!-- 末项留白：App/Safari 底栏 + Home 指示条，避免邀请条被挡 -->
        <view class="game-lobby-scroll-pad" aria-hidden="true" />
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
              <text class="fission-popup-progress">当前 {{ fissionPopupQuals }} / {{ fissionPopupCap }} 份资格</text>
              <text class="fission-popup-remain">剩余 {{ fissionPopupRemain }}</text>
              <text class="fission-popup-cta">点击拆开红包</text>
              <text class="fission-popup-risk">有资格即可拆包，无需等人数满</text>
            </view>
          </view>
          <view class="fission-popup-close" @click="dismissFissionPopup">×</view>
        </view>
      </view>
    </view>
    <WelcomeLottery ref="lotteryRef" :share-price="sharePrice" @done="onLotteryDone" />
    <BottomTabBar active="home" />
  </view>
</template>

<script setup>
import { computed, nextTick, onUnmounted, ref, watch } from 'vue'
import { onShow, onHide } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import BottomTabBar from '../../components/BottomTabBar.vue'
import WelcomeLottery from '../../components/WelcomeLottery.vue'
import { apiRequest, fetchProfile, getToken } from '../../utils/auth.js'
import { localeState, t, tt, applyServerCopy } from '../../utils/i18n.js'
import { imConnect } from '../../utils/im.js'
import { copyText } from '../../utils/master.js'
import { openExternalHttpUrl } from '../../utils/wallet.js'
import { getUploadsBase, packagedStaticUrl } from '../../utils/config.js'
import { applySafeAreaCssVars, getSafeAreaInsets } from '../../utils/safe-area.js'
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
    '--lobby-safe-bottom': (Number(lobbySafeBottom.value) || 0) + 'px',
    '--lobby-tab-pad': pad + 'px',
  }
})

const activeCat = ref('hot')
const onlineCountLive = ref(0)
const lobbyBotNicks = ref([])
const tickerText = ref('')
const tickerGames = ['红包扫雷', '红包接龙', '红包牛牛', '趣味鱼虾蟹', '红包对战', '幸运盲盒']

const lobbyCategories = computed(() => [
  { id: 'hot', iconImg: '1.png', label: tt('lobby_cat_hot', '热门推荐') },
  { id: 'games', iconStatic: 'logo.png', label: tt('lobby_cat_games', '红宝游戏') },
  { id: 'notice', iconImg: '66.png', label: tt('lobby_cat_notice', '红宝公告'), action: 'notice' },
  {
    id: 'commission',
    iconImg: 'commission.png',
    label: tt('lobby_cat_commission', '红宝佣金'),
    action: 'commission',
  },
])

const LOBBY_ASSET_VER = '10'

/** 01–06 固定排序：接龙 / 扫雷 / 牛牛 / 对战 / 盲盒 / 鱼虾蟹 */
const lobbyGames = [
  { id: 'jielong', cover: '01.png', badge: 'hot', ratio: 0.82, cats: ['hot', 'rp'], route: 'messages' },
  { id: 'saolei', cover: '02.png', badge: '', ratio: 0.65, cats: ['hot', 'rp'], route: 'messages' },
  { id: 'niuniu', cover: '03.png', badge: '', ratio: 0.55, cats: ['hot', 'card'], route: 'messages' },
  { id: 'battle', cover: '04.png', badge: '', ratio: 0.5, cats: ['hot', 'pvp'], route: 'messages' },
  { id: 'blindbox', cover: '05.png', badge: 'new', ratio: 0.43, cats: ['hot', 'event'], route: 'fission', comingSoon: true },
  { id: 'yxx', cover: '06.png', badge: '', ratio: 0.34, cats: ['hot', 'card', 'rp'], route: 'yxx', comingSoon: true },
]

function lobbyAsset(name) {
  const p = String(name || '').replace(/^\/+/, '')
  const oss = String(getUploadsBase() || '').replace(/\/+$/, '')
  if (oss) {
    return oss + '/999/static/home/lobby/' + p + '?v=' + LOBBY_ASSET_VER
  }
  return packagedStaticUrl('home/lobby/' + p) + '?v=' + LOBBY_ASSET_VER
}

function lobbyCatIcon(cat) {
  if (cat && cat.iconStatic) {
    const p = String(cat.iconStatic || '').replace(/^\/+/, '')
    const oss = String(getUploadsBase() || '').replace(/\/+$/, '')
    if (oss) return oss + '/999/static/' + p + '?v=' + LOBBY_ASSET_VER
    return packagedStaticUrl(p) + '?v=' + LOBBY_ASSET_VER
  }
  return lobbyAsset(cat && cat.iconImg)
}

function applyLobbyExtras(data) {
  if (!data || typeof data !== 'object') return
  const raw =
    data.partner_count !== undefined
      ? data.partner_count
      : data.fission_user_count !== undefined
        ? data.fission_user_count
        : data.partners
  if (raw !== undefined) {
    let n = Math.max(0, parseInt(raw, 10) || 0)
    if (n <= 0) n = marketVirtualBase()
    onlineCountLive.value = n
  }
  const nicks = data.lobby_bot_nicks
  if (Array.isArray(nicks) && nicks.length) {
    lobbyBotNicks.value = nicks.map((x) => String(x || '').trim()).filter(Boolean)
    if (!tickerText.value) {
      rotateTicker()
    }
  }
}

function pickTickerNick() {
  const list = lobbyBotNicks.value.length ? lobbyBotNicks.value : ['红包玩家88', '幸运星', '财神到']
  const i = Math.floor(Math.random() * list.length)
  return list[i] || list[0]
}

function rotateTicker() {
  const game = tickerGames[Math.floor(Math.random() * tickerGames.length)] || '红包扫雷'
  const amt = (50 + Math.floor(Math.random() * 950)).toFixed(2)
  const name = pickTickerNick()
  tickerText.value =
    tt('lobby_ticker', '恭喜玩家 {name} 在 {game} 中获得 {amount} 红包!', {
      name,
      game,
      amount: amt,
    }) || `恭喜玩家 ${name} 在 ${game} 中获得 ${amt} 红包!`
}

function startTicker() {
  stopTicker()
  rotateTicker()
  tickerTimer = setInterval(rotateTicker, 6000)
}

function stopTicker() {
  if (tickerTimer) {
    clearInterval(tickerTimer)
    tickerTimer = null
  }
}

const onlineCount = computed(() => {
  const live = Number(onlineCountLive.value) || 0
  if (live > 0) return Math.floor(live)
  const j = jackpot.value || config.value || {}
  const n = Number(j.partner_count != null ? j.partner_count : j.partners)
  if (!isNaN(n) && n > 0) return Math.floor(n)
  return marketVirtualBase()
})

const onlineCountText = computed(() => formatCountNum(onlineCount.value))

const visibleGames = computed(() => {
  const base = onlineCount.value
  return lobbyGames
    .filter((g) => {
      if (activeCat.value === 'hot') return g.cats.includes('hot')
      if (activeCat.value === 'games') return g.cats.includes('rp')
      return g.cats.includes(activeCat.value)
    })
    .map((g) => ({
      ...g,
      playersText: g.comingSoon
        ? tt('lobby_coming_soon', '敬请期待')
        : formatCountNum(Math.max(120, Math.floor(base * g.ratio))),
    }))
    .sort((a, b) => String(a.cover).localeCompare(String(b.cover)))
})

function onLobbyCat(cat) {
  if (!cat) return
  const action = String(cat.action || '')
  if (action === 'notice') {
    uni.navigateTo({ url: '/pages/notice/notice' })
    return
  }
  if (action === 'commission') {
    uni.navigateTo({ url: '/pages/commission/commission' })
    return
  }
  activeCat.value = cat.id
}

function onCarnivalBanner() {
  if (fissionEntryState.value !== 'hidden') {
    goFission()
    return
  }
  goTab('/pages/messages/messages')
}

function onGameTap(game) {
  if (!game) return
  if (game.comingSoon) {
    uni.showToast({ title: tt('lobby_coming_soon', '敬请期待'), icon: 'none' })
    return
  }
  uni.navigateTo({ url: '/pages/home/game-detail?game=' + encodeURIComponent(game.id) })
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
  uni.switchTab({ url: '/pages/fission/detail' })
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
  uni.switchTab({ url: '/pages/fission/detail' })
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
  if (!jackpot.value) await pollJackpot()
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
    const data = await apiRequest('jackpot', 'GET')
    if (data) applyLobbyExtras(data)
  } catch (e) {}
}

function marketVirtualBase() {
  const cfg = config.value || {}
  const n = parseInt(cfg.market_virtual_base != null ? cfg.market_virtual_base : cfg.partner_count, 10)
  return !isNaN(n) && n > 0 ? n : 8000
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
  onlineCountLive.value = nextPc
  jackpot.value = prev
}

function startPoll() {
  stopPoll()
  pollJackpot()
  loadLeaderboard()
  startTicker()
  pollTimer = setInterval(() => {
    pollJackpot()
  }, 20000)
  onlinePollTimer = setInterval(() => {
    pollOnlineLive()
  }, 5000)
  // 本地氛围：金额/人数微动（仅非服务端同步时）
  if (!pollLocalTimer) {
    pollLocalTimer = setInterval(tickMarketLocal, 60000)
  }
  // 排行榜每分钟刷新一次（虚拟榜 minuteBoost + 真实合并）
  if (!lbTimer) {
    lbTimer = setInterval(() => {
      loadLeaderboard()
    }, 60000)
  }
}

function stopPoll() {
  stopTicker()
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

async function copyShareLink() {
  if (shareSubmitting.value) return
  if (!getToken()) {
    try {
      uni.setStorageSync('fanshub_login_return', '/pages/home/home')
    } catch (e) {}
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  shareSubmitting.value = true
  try {
    const data = await apiRequest('share', 'POST', { copy_only: true })
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
    if (!out) {
      throw new Error('暂无邀请链接')
    }
    await copyText(out)
    uni.showToast({ title: '邀请链接已复制', icon: 'success' })
  } catch (e) {
    uni.showToast({ title: (e && e.message) || t('alert_share_fail') || '复制失败', icon: 'none' })
  } finally {
    shareSubmitting.value = false
  }
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
      }
    })
    .catch(() => {})
}

onShow(async () => {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  measureLobbySafeBottom()
  try {
    uni.$on && uni.$on('fanshub-profile-updated', onProfileUpdated)
  } catch (e) {}
  await loadBootstrap()
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

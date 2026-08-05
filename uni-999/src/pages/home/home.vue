<template>
  <view :key="locale">
    <TopBar />
    <view id="tabHome" class="tab-page active">
      <!-- 大奖池（结构/class 对齐 888 tab-home） -->
      <view class="jackpot-container">
        <text class="jackpot-label">{{ t('jackpot_label') || '📊 红宝 全网生态实时大屏' }}</text>
        <text class="jackpot-meta">{{ partnersText }}</text>
        <text class="jackpot-pool-label">{{ t('jackpot_pool_label') || '💰 平台已累计为合伙人创造价值' }}</text>
        <text class="jackpot-val">{{ jackpotMoneyText }}</text>
        <text class="jackpot-price-line">{{ priceText }}</text>
        <text class="jackpot-hint-line">{{ t('jackpot_hint') || '💡 等额闪兑销毁股份 · 累计价值只涨不跌 · 股价由达标转化人数驱动' }}</text>
      </view>

      <!-- 资产面板 -->
      <view class="header-panel">
        <view class="assets-grid">
          <view class="asset-card asset-card-rights">
            <text class="asset-label">{{ t('asset_rights_label') || '持有资产 (未清算)' }}</text>
            <view class="asset-value asset-value-gold">
              <text>{{ rightsText }}</text>
              <text class="asset-unit"> {{ t('asset_rights_unit') || '份' }}</text>
            </view>
            <view class="asset-valuation-hint" v-if="rightsValuationHint" :style="{ display: 'block' }">{{ rightsValuationHint }}</view>
          </view>
          <view class="asset-card-divider" aria-hidden="true" />
          <view class="asset-card asset-card-hongbao">
            <text class="asset-label">{{ t('asset_hongbao_label') || '红宝' }}</text>
            <view class="asset-value asset-value-green">
              <text class="asset-currency">￥</text><text>{{ hongbaoText }}</text>
            </view>
            <view class="asset-frozen-hint" v-if="frozenVisible">
              <text>{{ t('asset_hongbao_frozen_label') || '冻结' }}</text>
              <text class="asset-currency">￥</text><text>{{ frozenText }}</text>
            </view>
            <view class="asset-progress-wrap">
              <view class="asset-progress-bar">
                <view class="asset-progress-fill" :style="{ width: progressPct + '%' }" />
              </view>
            </view>
          </view>
        </view>

        <view class="user-info">
          <view class="user-id">
            <text>{{ t('user_account_status') || '资产账户状态:' }} </text>
            <text>{{ mobileMask }}</text>
            <text> </text>
            <text>{{ t('user_status_shield') || '密匙防护已开启' }}</text>
          </view>
          <view id="userRank" class="status-tag" :class="{ 'master-tag': isMasterRank }">{{ rankText }}</view>
        </view>

        <view class="flow-stepper" aria-label="福利进度">
          <view
            v-for="s in stepperSteps"
            :key="s.n"
            class="flow-step"
            :class="{ done: s.done, active: s.active }"
            :data-step="s.n"
          >
            <text class="n">{{ s.n }}</text>
            <text class="t">{{ s.label }}</text>
          </view>
        </view>

        <view id="newbiePromoBlock">
          <view class="share-promo-card" role="button" @click="copyShareLink">
            <view class="share-promo-glow" />
            <view class="share-promo-inner single-line">
              <text class="share-promo-text">{{ t('share_promo_btn') || '📢 邀请 1 人开户 ➡️ 额外送 1 份股 (多邀多得)' }}</text>
              <button type="button" class="btn-share-action" @click.stop="copyShareLink">
                {{ t('share_promo_action_btn') || '点击立即分享' }}
              </button>
            </view>
          </view>
        </view>
      </view>

      <!-- 开户 CTA：已绑定游戏账号或团长时隐藏（对齐 888 团长隐藏 + 有账号不再引导开户） -->
      <view class="user-panel" id="newbieOpenPanel" v-if="showNewbieOpenPanel">
        <button type="button" class="cta-open-account" @click="goToMainStation">
          <text class="cta-open-account-label">{{ openAccountLabel }}</text>
          <text class="cta-open-account-badge">{{ openAccountBadge }}</text>
        </button>
      </view>

      <!-- VIP 领取 -->
      <view class="home-claim-section" id="homeClaimSection">
        <view class="page-hero-title">{{ t('page_hero_claim_title') || '🏦 VIP 特批领取' }}</view>
        <view class="page-hero-sub">{{ t('page_hero_claim_sub') || '回填现金站游戏账号 → 提交核销 → 生成密令联系客服上分' }}</view>
        <view class="match-card" style="padding: 15px">
          <view class="uid-section visible" id="uidSection">
            <text class="uid-label">{{ t('uid_label') || '🔑 第一步：请输入您在红宝现金站注册成功的账号' }}</text>
            <!-- 锁定态用 view 展示：uni disabled input 常不刷新/文字透明导致空白 -->
            <view v-if="uidLocked" class="id-input-box is-locked">{{ displayGameUid }}</view>
            <input
              v-else
              class="id-input-box"
              type="text"
              maxlength="32"
              v-model="gameUid"
              :placeholder="t('uid_placeholder') || '例如：555bio（必须使用同手机号注册），否则小妹无法在后台核销上分'"
              @blur="onUidBlur"
              @confirm="submitUID"
            />
            <view class="uid-submit-row" v-if="!uidApproved">
              <button type="button" class="btn-uid-submit" :disabled="uidBtnDisabled || uidSubmitting" @click="submitUID">
                {{ uidBtnText }}
              </button>
            </view>
            <view class="uid-status-hint" :class="uidHintClass">{{ uidHintText }}</view>
          </view>
        </view>
        <button type="button" class="btn-manual-settle" @click="openWithdrawModal">
          <text>{{ settleTitle }}</text>
          <text class="sub-label">{{ settleSub }}</text>
        </button>
      </view>

      <!-- 快捷入口 -->
      <view class="home-quick-grid">
        <button type="button" class="home-quick-btn hq-exchange" @click="goTab('/pages/exchange/exchange')">
          <text>{{ t('home_quick_exchange') || '⚡ 去闪兑' }}</text>
          <text>{{ t('home_quick_exchange_sub') || '股份秒变红宝' }}</text>
        </button>
        <button type="button" class="home-quick-btn hq-master" @click="goTab('/pages/master/master')">
          <text>{{ t('home_quick_master') || '👑 团长大厅' }}</text>
          <text>{{ t('home_quick_master_sub') || '天梯 + 7天星火暴击' }}</text>
        </button>
        <button type="button" class="home-quick-btn hq-messages" @click="goTab('/pages/messages/messages')">
          <text>{{ t('home_quick_messages') || '红宝社区' }}</text>
          <text>{{ t('home_quick_messages_sub') || '私聊 · 群聊 · 红包' }}</text>
        </button>
        <button type="button" class="home-quick-btn hq-profile" @click="goTab('/pages/profile/profile')">
          <text>{{ t('home_quick_profile') || '👤 个人中心' }}</text>
          <text>{{ t('home_quick_profile_sub') || '资料 · 密码 · 退出' }}</text>
        </button>
      </view>

      <!-- 跑马灯 -->
      <view class="marquee-box" style="margin-top: 14px">
        <view class="marquee-content">
          <view v-for="(m, i) in marqueeItems" :key="i" class="marquee-item">{{ m }}</view>
        </view>
      </view>

      <!-- 排行榜 + 页脚 -->
      <view class="home-social-section" id="homeSocialSection">
        <view class="page-hero-title">{{ t('page_hero_social_title') || '💬 互动大厅' }}</view>
        <view class="page-hero-sub">{{ t('page_hero_social_sub') || '看排行 · 刷视频文 · 蹭气氛，专注拉新与晒单' }}</view>
        <view class="match-card" style="margin-top: 0; padding: 15px; text-align: left">
          <view style="font-size: 14px; margin-bottom: 10px; color: var(--secondary); font-weight: 700">
            {{ t('leaderboard_title') || '🏆 邀请裂变排行榜 TOP10' }}
          </view>
          <view class="leaderboard-list">
            <view v-if="!leaderboard.length" class="text-muted" style="font-size: 12px">
              {{ t('leaderboard_loading') || '加载中...' }}
            </view>
            <view v-for="item in leaderboard" :key="item.rank + '-' + item.mobile_mask" class="leaderboard-item">
              <view>
                <text class="leaderboard-rank">{{ rankBadge(item.rank) }}</text>
                <text> {{ item.mobile_mask || (t('leaderboard_user_fallback') || '用户') }}</text>
              </view>
              <view class="leaderboard-count">{{ inviteCountText(item.invite_count) }}</view>
            </view>
          </view>
        </view>
        <view style="margin-top: 25px; padding: 10px; font-size: 10px; color: #657786; text-align: center; line-height: 1.6">
          <view>{{ t('footer_line1') || '📊 本平台性质为【红宝 官方活跃粉丝度模拟福利推广营销互动调查大厅】' }}</view>
          <view>{{ t('footer_line2') || '安全承诺：全盘无资金充值入口。所有股份及红宝均属于用户活跃度内部福利。领取统一由官方 VIP 福利中心人工核准，并采用安全方式精准充值至您的 红宝 主站账户中。活动最终解释权归 红宝 官方所有。' }}</view>
          <view>{{ t('footer_line3') || '© 2026 红宝 Open-Marketing Platform. 服务协议 | 合规声明' }}</view>
        </view>
      </view>
    </view>

    <!-- VIP 密令弹层（挂在页外避免滚动裁剪） -->
    <view class="home-modal-root">
      <view class="modal-mask" :class="{ 'is-open': withdrawOpen }" @click="closeWithdrawModal">
        <view class="modal-box" @click.stop>
          <view class="modal-title">{{ withdrawTitle }}</view>
          <view style="font-size: 12px; color: var(--text-muted)">{{ t('withdraw_amount_label') || '当前待领取福利总金额' }}</view>
          <view class="modal-money">￥{{ hongbaoText }}</view>
          <view style="font-size: 12px; color: var(--accent); font-weight: bold; margin-bottom: 6px">
            {{ t('withdraw_secret_label') || '🔐 您的专属密令（请点击复制）' }}
          </view>
          <view class="secret-code-box" @click="copySecretCode">{{ secretCode || (t('withdraw_secret_loading') || 'FH-LOADING') }}</view>
          <view class="secret-timer">
            <text>{{ t('withdraw_secret_timer') || '⏳ 专属密令安全锁定剩余：' }}</text>
            <text>{{ secretCountdown }}</text>
          </view>
          <view class="cs-step-card">
            <view><text class="step-num">1</text>{{ t('withdraw_step1_plain') || '点击下方按钮，一键复制密令并跳转红宝专属客服' }}</view>
            <view><text class="step-num">2</text>{{ t('withdraw_step2_plain') || '将密令发送给在线客服小妹，获取官方红宝聊天App下载指引' }}</view>
            <view><text class="step-num">3</text>{{ t('withdraw_step3_plain') || '下载并添加官方 App 后，客服将为您完成主站账号充值' }}</view>
          </view>
          <button type="button" class="modal-action-btn primary" @click="jumpToCustomerService">
            {{ t('withdraw_btn_cs') || '💬 一键复制密令 · 匹配专属客服' }}
          </button>
          <button type="button" class="modal-action-btn gold" v-if="appDownloadUrl" @click="copyAppUrl">
            {{ t('withdraw_btn_app') || '📥 下载官方红宝聊天App' }}
          </button>
          <button type="button" class="modal-close-btn" @click="closeWithdrawModal">
            {{ t('withdraw_btn_close') || '返回继续攒股份' }}
          </button>
        </view>
      </view>
    </view>
    <BottomTabBar active="home" />
  </view>
</template>

<script setup>
import { computed, onUnmounted, ref, watch } from 'vue'
import { onShow, onHide } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import BottomTabBar from '../../components/BottomTabBar.vue'
import { apiRequest, fetchProfile, getToken } from '../../utils/auth.js'
import { localeState, t, applyServerCopy } from '../../utils/i18n.js'
import { imConnect } from '../../utils/im.js'
import { copyText } from '../../utils/master.js'
import '../../styles/home.css'
import '../../styles/tabs-extra.css'
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
let pollTimer = null
let secretTimer = null
let secretRequestId = ''

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
  const countFmt = formatCountNum(count == null ? 8000 : count)
  const upFmt = formatCountNum(up)
  if (count == null) {
    return (
      t('jackpot_partners', { partner_count: countFmt, partner_today_up: upFmt }) ||
      `📈 当前全网股份人数：${countFmt} 人 ( 🚀 今日暴涨 +${upFmt} 人 )`
    )
  }
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

const rankText = computed(() => {
  const p = profile.value || {}
  const p2 = p.phase2 || {}
  const rank = p.invite_rank || {}
  if (p2.enabled && p2.user_mode === 'master') {
    const c = p2.total_register_count || rank.invite_count || 0
    return t('user_rank_master', { count: c }) || `👑 荣誉团长 · 已邀${c}人`
  }
  if (rank.invite_count > 0) {
    return t('user_rank_template', { rank: rank.rank, count: rank.invite_count }) || `邀请排行第 ${rank.rank} · 已邀 ${rank.invite_count} 人`
  }
  return t('user_rank_default') || '特权确权账户'
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
  const raw = t('open_account_btn') || ''
  const rights = (config.value && config.value.open_account_rights) != null ? config.value.open_account_rights : 2
  const fallbackBadge = t('open_account_badge_fallback', { open_account_rights: rights }) || `立送 ${rights} 份大盘股。`
  const bracket = String(raw).match(/^(.+?)\[(.+?)\]\((.+?)\)\s*$/)
  if (bracket) return { label: (bracket[1] + '[' + bracket[2] + ']').trim(), badge: bracket[3].trim() }
  const paren = String(raw).match(/^(.+?)\(([^)]+)\)\s*$/)
  if (paren) return { label: paren[1].trim(), badge: paren[2].trim() }
  return { label: raw || fallbackBadge, badge: fallbackBadge }
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

const marqueeItems = computed(() => {
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
      }
      if (data.config) applyConfig(data.config)
      if (data.market) jackpot.value = data.market
      else if (data.jackpot) jackpot.value = data.jackpot
      const lb = (data.home && data.home.leaderboard) || data.leaderboard
      if (Array.isArray(lb) && lb.length) {
        leaderboard.value = normalizeLeaderboard(lb)
      }
    }
  } catch (e) {
    /* fallback below */
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
  if (!leaderboard.value.length) await loadLeaderboard()
}

function leaderboardDaySeed() {
  const d = new Date()
  return d.getFullYear() * 10000 + (d.getMonth() + 1) * 100 + d.getDate()
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
  const rows = []
  for (let i = 0; i < limit; i++) {
    const pool = pools[Math.floor(rnd() * pools.length)]
    const head = pool.heads[Math.floor(rnd() * pool.heads.length)]
    let tail = ''
    for (let t = 0; t < pool.tailLen; t++) tail += String(Math.floor(rnd() * 10))
    const jitter = Math.floor(rnd() * 3)
    const count = Math.max(2, (base[i] || Math.max(2, 12 - i)) + Math.floor(hourBoost * 0.12) + jitter)
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
    const real = await apiRequest('inviteleaderboard', 'GET', { limit: 10 })
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
    if (data) jackpot.value = data
  } catch (e) {}
}

function startPoll() {
  stopPoll()
  pollTimer = setInterval(pollJackpot, 20000)
}

function stopPoll() {
  if (pollTimer) {
    clearInterval(pollTimer)
    pollTimer = null
  }
}

async function copyShareLink() {
  try {
    const data = await apiRequest('share', 'POST', {})
    if (data && data.profile) {
      profile.value = data.profile
      syncUidFromProfile(data.profile)
    }
    await copyText((data && data.share_text) || '')
    let toastMsg = (data && data.message) || ''
    if (!toastMsg) {
      toastMsg = data && data.rewarded ? t('alert_share_rewarded') || '分享成功，奖励已到账' : t('alert_share_copied') || '分享文案已复制'
    }
    toastMsg = String(toastMsg)
      .replace(/【[^】]*】/g, '')
      .replace(/\\n+/g, ' ')
      .trim()
    uni.showToast({ title: toastMsg || '分享文案已复制', icon: 'none' })
  } catch (e) {
    uni.showToast({ title: e.message || t('alert_share_fail') || '分享失败', icon: 'none' })
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
  closeWithdrawModal()
  setTimeout(() => goTab('/pages/messages/messages'), 400)
}

async function copyAppUrl() {
  if (!appDownloadUrl.value) return
  try {
    await copyText(appDownloadUrl.value)
    uni.showToast({ title: t('withdraw_app_copy_ok') || '下载链接已复制', icon: 'none' })
  } catch (e) {
    uni.showToast({ title: '复制失败', icon: 'none' })
  }
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

onShow(async () => {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  try {
    uni.$on && uni.$on('fanshub-profile-updated', onProfileUpdated)
  } catch (e) {}
  await loadBootstrap()
  imConnect().catch(() => {})
  startPoll()
})

onHide(() => {
  stopPoll()
  stopSecretCountdown()
  try {
    uni.$off && uni.$off('fanshub-profile-updated', onProfileUpdated)
  } catch (e) {}
})
onUnmounted(() => {
  stopPoll()
  stopSecretCountdown()
  try {
    uni.$off && uni.$off('fanshub-profile-updated', onProfileUpdated)
  } catch (e) {}
})
</script>

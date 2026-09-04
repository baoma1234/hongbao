<template>
  <view class="messages-page commission-page">
    <TopBar title="佣金" />
    <view
      id="tabCommission"
      class="tab-page active msg-tab-root"
      :style="tabRootStyle"
    >
      <view class="chat-shell">
        <view class="chat-list-pane">
          <view class="chat-list-main">
            <view
              id="chatHomePanelCommission"
              class="chat-home-panel chat-commission-panel"
              :style="panelHostStyle"
            >
              <scroll-view scroll-y class="chat-commission-body-scroll" :style="panelScrollStyle">
                <view class="chat-commission-hero-card">
                  <view class="chat-commission-hero-top">
                    <text class="chat-commission-hero-label">累计佣金</text>
                    <view class="chat-commission-withdraw-btn" @click="goCommission"><text>提现</text></view>
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
                      <text class="chat-commission-stat-label">红宝返佣</text>
                      <text class="chat-commission-stat-value">¥ {{ money(commission.rebate_money) }}</text>
                    </view>
                  </view>
                </view>

                <view class="chat-commission-nav-grid">
                  <view
                    class="chat-commission-nav-item"
                    :class="{ 'is-active': commissionListMode === 'promo' }"
                    @click="goCommissionNav('promo')"
                  >
                    <view class="chat-commission-nav-ico">
                      <text class="chat-commission-nav-glyph">☰</text>
                    </view>
                    <text class="chat-commission-nav-label">推广结算 ›</text>
                  </view>
                  <view
                    class="chat-commission-nav-item"
                    :class="{ 'is-active': commissionListMode === 'rebate' }"
                    @click="goCommissionNav('rebate')"
                  >
                    <view class="chat-commission-nav-ico">
                      <text class="chat-commission-nav-glyph">🧧</text>
                    </view>
                    <text class="chat-commission-nav-label">红宝返佣 ›</text>
                  </view>
                  <view
                    class="chat-commission-nav-item"
                    :class="{ 'is-active': commissionListMode === 'ledger' }"
                    @click="goCommissionNav('ledger')"
                  >
                    <view class="chat-commission-nav-ico">
                      <text class="chat-commission-nav-glyph">◎</text>
                    </view>
                    <text class="chat-commission-nav-label">收益明细 ›</text>
                  </view>
                  <view
                    class="chat-commission-nav-item"
                    :class="{ 'is-active': commissionListMode === 'withdraw_list' }"
                    @click="goCommissionNav('withdraw_list')"
                  >
                    <view class="chat-commission-nav-ico">
                      <text class="chat-commission-nav-glyph">⬇</text>
                    </view>
                    <text class="chat-commission-nav-label">提现记录 ›</text>
                  </view>
                </view>

                <view class="chat-commission-recent-card">
                  <view class="chat-commission-recent-hd">{{ commissionListTitle }}</view>
                  <view class="chat-commission-list">
                    <view
                      class="chat-commission-row"
                      :class="{ 'is-dual-rebate': isDualRebate(row) }"
                      v-for="(row, idx) in commissionRows"
                      :key="row.id || idx"
                    >
                      <view class="chat-commission-row-ico" aria-hidden="true">
                        <text class="chat-commission-nav-glyph">✓</text>
                      </view>
                      <view class="chat-commission-row-main">
                        <view class="chat-commission-row-title">{{ commissionRowTitle(row) }}</view>
                        <view class="chat-commission-row-time">{{ formatNoticeTime(row) }}</view>
                      </view>
                      <view class="chat-commission-row-amt" :class="{ 'is-out': isAmtOut(row) }">{{ formatCommissionAmt(row) }}</view>
                    </view>
                    <view v-if="!commissionRows.length" class="chat-empty chat-empty-glass">{{ commissionEmptyText }}</view>
                    <view class="chat-list-scroll-pad" aria-hidden="true">
                      <text class="chat-list-scroll-pad-mark"> </text>
                    </view>
                  </view>
                </view>
              </scroll-view>
            </view>
          </view>
        </view>
      </view>
    </view>
    <BottomTabBar active="home" />
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import BottomTabBar from '../../components/BottomTabBar.vue'
import '../../styles/chat-messages-list.css'
import '../../styles/chat-uni-adapter.css'
import '../../styles/chat-messages-parity.css'
import '../../styles/chat-qq-theme.css'
import { apiRequest, getToken } from '../../utils/auth.js'
import { applySafeAreaCssVars, getSafeAreaInsets, getTopBarContentHeight } from '../../utils/safe-area.js'
import { formatConvTime } from '../../utils/chat.js'

const panelScrollPx = ref(420)
const tabRootPx = ref(0)
const commission = ref({})
const commissionListMode = ref('recent')
let pageAlive = false

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
  return { height: h + 'px', minHeight: h + 'px', maxHeight: h + 'px', flex: 'none' }
})

function measureCommissionLayout() {
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
    panelScrollPx.value = Math.max(220, shell - 56)
  } catch (e) {
    tabRootPx.value = 0
    panelScrollPx.value = 420
  }
}

function money(v) {
  const n = Number(v || 0)
  return isFinite(n) ? n.toFixed(2) : '0.00'
}

function isDualRebate(row) {
  const r = row || {}
  return String(r.revenue_type || '') === 'dual' || String(r.type || '') === 'red_packet_dual_rebate_in'
}

function isAmtOut(row) {
  const t = String(formatCommissionAmt(row) || '')
  return t.indexOf('-') === 0
}

function commissionRowTitle(row) {
  row = row || {}
  if (row.type_label) return String(row.type_label)
  if (row.title) return String(row.title)
  if (row.scene_text) return String(row.scene_text)
  if (row.type_text) return String(row.type_text)
  const rt = String(row.revenue_type || '')
  if (rt === 'dual') return '🔥 群主+推荐双重返佣'
  if (rt === 'invite') return '🔗 推荐发包返佣'
  if (rt === 'owner') return '群主返佣'
  const typ = String(row.type || '')
  if (typ === 'red_packet_dual_rebate_in') return '🔥 群主+推荐双重返佣'
  if (typ === 'red_packet_invite_rebate_in' || typ === 'red_packet_rebate') return '🔗 推荐发包返佣'
  if (typ === 'red_packet_agent_rebate_in') return '群主返佣'
  return typ || '结算记录'
}

function formatCommissionAmt(row) {
  row = row || {}
  if (row.amount_text) return String(row.amount_text)
  const h = Number(row.hongbao_change || 0)
  const b = Number(row.balance_change || 0)
  const r = Number(row.rights_change || 0)
  const parts = []
  if (Math.abs(r) > 1e-9) {
    parts.push((r > 0 ? '+' : '-') + Math.abs(r).toFixed(2) + '股')
  }
  if (Math.abs(h) > 1e-9 || Math.abs(b) > 1e-9) {
    const moneyAmt = Math.abs(h) > 1e-9 ? h : b
    parts.push((moneyAmt > 0 ? '+¥ ' : '-¥ ') + Math.abs(moneyAmt).toFixed(2))
  }
  if (parts.length) return parts.join(' ')
  return '¥ 0.00'
}

function noticeTs(n) {
  return (n && (n.publishtime || n.createtime || n.updatetime || n.time)) | 0
}

function formatNoticeTime(n) {
  return formatConvTime(noticeTs(n))
}

const commissionListTitle = computed(() => {
  const mode = commissionListMode.value
  if (mode === 'promo') return '推广结算'
  if (mode === 'rebate') return '红宝返佣'
  if (mode === 'withdraw_list') return '提现记录'
  if (mode === 'ledger') return '收益明细'
  return '最近结算'
})

const commissionEmptyText = computed(() => {
  const mode = commissionListMode.value
  if (mode === 'promo') return '暂无推广结算'
  if (mode === 'rebate') return '暂无红宝返佣'
  if (mode === 'withdraw_list') return '暂无提现记录'
  if (mode === 'ledger') return '暂无收益明细'
  return '登录后查看佣金明细'
})

const commissionRows = computed(() => {
  const data = commission.value || {}
  const mode = commissionListMode.value
  let list = []
  if (mode === 'promo') list = data.promo_recent
  else if (mode === 'rebate') list = data.rebate_recent
  else if (mode === 'withdraw_list') list = data.withdraw_recent
  else list = data.recent
  return Array.isArray(list) ? list : []
})

async function loadCommission() {
  try {
    const data = await apiRequest('commission', 'GET', {})
    commission.value = data || {}
  } catch (e) {
    commission.value = {}
  }
}

function goCommission() {
  uni.navigateTo({ url: '/pages/wallet/withdraw' })
}

function goCommissionNav(kind) {
  if (kind === 'promo' || kind === 'rebate' || kind === 'withdraw_list' || kind === 'ledger') {
    commissionListMode.value = kind === 'ledger' ? 'ledger' : kind
  }
}

onShow(() => {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  pageAlive = true
  measureCommissionLayout()
  setTimeout(() => {
    if (pageAlive) measureCommissionLayout()
  }, 50)
  void loadCommission()
})
</script>

<style scoped>
.chat-commission-body-scroll {
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

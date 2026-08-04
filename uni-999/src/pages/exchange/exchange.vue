<template>
  <view :key="locale">
    <TopBar />
    <view id="tabExchange" class="tab-page active">
      <view class="page-hero-title">{{ t('page_hero_exchange_title') || '⚡ VIP 闪兑大厅' }}</view>
      <view class="page-hero-sub">{{ t('page_hero_exchange_sub') || '股份 ↔ 红宝 · 实时预估到账' }}</view>
      <view class="exchange-closed-banner" v-if="!anyEnabled">
        {{ t('profile_ex_r2b_closed') || t('alert_exchange_disabled') || '股份兑换红宝已关闭' }}
      </view>

      <view class="share-swap" id="dualExchangeSection" v-if="anyEnabled">
        <view class="share-swap-panel" id="shareSwapPanel">
          <view class="share-swap-header">
            <view class="share-swap-title">{{ swapTitle }}</view>
            <view class="share-swap-avail">{{ availText }}</view>
          </view>

          <view class="share-swap-section-title">{{ t('swap_from_label') || '转出' }}</view>
          <view class="share-swap-input-card share-swap-select-card">
            <view class="share-swap-icon-circle">{{ fromIcon }}</view>
            <picker :range="fromLabels" :value="fromIndex" @change="onFromPick">
              <view class="share-swap-select-face">
                <text>{{ fromLabel }}</text>
                <text class="share-swap-chevron">▾</text>
              </view>
            </picker>
          </view>
          <view class="share-swap-input-card">
            <view class="share-swap-icon-circle">#</view>
            <input
              class="share-swap-input"
              type="digit"
              v-model="amount"
              :placeholder="minHint"
            />
            <button type="button" class="share-swap-btn-all" @click="fillAll">
              {{ t('swap_all_btn') || '全部' }}
            </button>
          </view>
          <view class="share-swap-hint">{{ minHint }}</view>

          <view class="share-swap-divider">
            <button type="button" class="share-swap-arrow" @click="flip" :aria-label="t('swap_aria_flip') || '互换方向'">
              <svg viewBox="0 0 24 24" width="20" height="20">
                <path
                  fill="currentColor"
                  d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"
                />
              </svg>
            </button>
          </view>

          <view class="share-swap-section-title">
            <svg class="share-swap-icon-prefix" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
              <path
                fill="currentColor"
                d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 14H4V6h16v12zm-8-3c1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3 1.34 3 3 3z"
              />
            </svg>
            <text>{{ t('swap_to_label') || '兑换目标' }}</text>
          </view>
          <view class="share-swap-input-card share-swap-select-card">
            <view class="share-swap-icon-circle">{{ toIcon }}</view>
            <picker :range="toLabels" :value="toIndex" @change="onToPick">
              <view class="share-swap-select-face">
                <text>{{ toLabel }}</text>
                <text class="share-swap-chevron">▾</text>
              </view>
            </picker>
          </view>

          <view class="share-swap-summary">
            <view class="share-swap-summary-left">
              <view class="share-swap-summary-label">{{ t('swap_rate_label') || '兑换比例' }}</view>
              <view class="share-swap-summary-value">{{ rateText }}</view>
              <view class="share-swap-summary-currency">CNY</view>
              <view class="share-swap-summary-time">{{ nowText }}</view>
            </view>
            <view class="share-swap-vdiv" aria-hidden="true" />
            <view class="share-swap-summary-right">
              <view class="share-swap-summary-label">{{ t('swap_est_label') || '预计到账' }}</view>
              <view class="share-swap-amount">{{ estText }}</view>
              <view class="share-swap-summary-label">{{ toLabel }}</view>
            </view>
          </view>

          <button
            type="button"
            class="share-swap-submit"
            :disabled="submitting || !pair.enabled"
            @click="onSubmit"
          >
            {{ submitting ? (t('loading_generic') || '处理中…') : (t('swap_submit') || '确认兑换') }}
          </button>
          <view class="share-swap-closed" v-if="!pair.enabled">
            {{ t('alert_exchange_pair_invalid') || '当前兑换方向已关闭' }}
          </view>
        </view>
      </view>
    </view>
    <BottomTabBar active="exchange" />
  </view>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import BottomTabBar from '../../components/BottomTabBar.vue'
import { getToken } from '../../utils/auth.js'
import {
  estimateCredit,
  freeRightsOf,
  hongbaoOf,
  loadExchangeBootstrap,
  pairInfo,
  rateLine,
  submitSwap,
} from '../../utils/exchange.js'
import { localeState, t } from '../../utils/i18n.js'
import '../../styles/share-swap.css'
import '../../styles/exchange-uni-adapter.css'

const ASSETS = ['rights', 'hongbao']
const locale = localeState()
const config = ref({})
const profile = ref({})
const fromAsset = ref('rights')
const toAsset = ref('hongbao')
const amount = ref('1')
const submitting = ref(false)
const nowText = ref('')

const pair = computed(() => pairInfo(config.value, fromAsset.value, toAsset.value))
const anyEnabled = computed(() =>
  ASSETS.some((f) => ASSETS.some((to) => f !== to && pairInfo(config.value, f, to).enabled))
)

function assetLabel(a) {
  if (a === 'hongbao' || a === 'balance') {
    return t('swap_asset_hongbao') || t('asset_hongbao_label') || '红宝'
  }
  return t('swap_asset_rights') || t('asset_shares_label') || '股份'
}
function assetIcon(a) {
  if (a === 'hongbao' || a === 'balance') return t('swap_unit_hongbao') || '宝'
  return t('swap_unit_share') || '股'
}

const fromLabel = computed(() => {
  void locale.value
  return assetLabel(fromAsset.value)
})
const toLabel = computed(() => {
  void locale.value
  return assetLabel(toAsset.value)
})
const fromIcon = computed(() => {
  void locale.value
  return assetIcon(fromAsset.value)
})
const toIcon = computed(() => {
  void locale.value
  return assetIcon(toAsset.value)
})
const fromLabels = computed(() => {
  void locale.value
  return ASSETS.map(assetLabel)
})
const toOptions = computed(() =>
  ASSETS.filter((a) => a !== fromAsset.value && pairInfo(config.value, fromAsset.value, a).enabled)
)
const toLabels = computed(() => {
  void locale.value
  return toOptions.value.map(assetLabel)
})
const fromIndex = computed(() => Math.max(0, ASSETS.indexOf(fromAsset.value)))
const toIndex = computed(() => Math.max(0, toOptions.value.indexOf(toAsset.value)))

const swapTitle = computed(() => {
  void locale.value
  return (
    t('swap_title_pair', { from: fromLabel.value, to: toLabel.value }) ||
    fromLabel.value + '兑换' + toLabel.value
  )
})

const avail = computed(() => {
  if (fromAsset.value === 'hongbao') return hongbaoOf(profile.value)
  return freeRightsOf(profile.value)
})
const availText = computed(() => {
  void locale.value
  if (fromAsset.value === 'hongbao') {
    return t('swap_avail_hongbao', { amount: avail.value.toFixed(2) }) || ('可用 ' + avail.value.toFixed(2) + ' 红宝')
  }
  return t('swap_avail_rights', { amount: Math.floor(avail.value) }) || ('可用 ' + Math.floor(avail.value) + ' 份')
})

const minHint = computed(() => {
  void locale.value
  const p = pair.value
  return t('profile_ex_max_hint', { max: p.max }) || ('单笔上限 ' + p.max)
})

const sharePrice = computed(() => {
  const c = config.value || {}
  return c.current_share_price || c.single_ticket_value || 5
})

const rateText = computed(() => {
  void locale.value
  const r = rateLine(config.value, fromAsset.value, toAsset.value, sharePrice.value)
  return (
    t('swap_rate_line', {
      from: fromLabel.value,
      to: toLabel.value,
      rate: r.toFixed(4),
    }) ||
    ('1 ' + fromLabel.value + ' = ' + r.toFixed(4) + ' ' + toLabel.value)
  )
})

const estText = computed(() => {
  void locale.value
  const credit = estimateCredit(
    config.value,
    fromAsset.value,
    toAsset.value,
    amount.value,
    sharePrice.value
  )
  if (toAsset.value === 'hongbao') return credit.toFixed(2)
  return t('swap_est_shares', { amount: credit.toFixed(2) }) || credit.toFixed(2) + ' 份'
})

function tickTime() {
  const d = new Date()
  const pad = (n) => (n < 10 ? '0' + n : '' + n)
  nowText.value =
    d.getFullYear() +
    '-' +
    pad(d.getMonth() + 1) +
    '-' +
    pad(d.getDate()) +
    ' ' +
    pad(d.getHours()) +
    ':' +
    pad(d.getMinutes()) +
    ':' +
    pad(d.getSeconds())
}

function ensureToValid() {
  const allowed = toOptions.value
  if (!allowed.length) return
  if (allowed.indexOf(toAsset.value) < 0) toAsset.value = allowed[0]
}

function onFromPick(e) {
  const idx = (e.detail && e.detail.value) | 0
  fromAsset.value = ASSETS[idx] || 'rights'
  ensureToValid()
}

function onToPick(e) {
  const idx = (e.detail && e.detail.value) | 0
  const next = toOptions.value[idx]
  if (next) toAsset.value = next
}

function flip() {
  const nextFrom = toAsset.value
  const nextTo = fromAsset.value
  const allowed = ASSETS.filter(
    (a) => a !== nextFrom && pairInfo(config.value, nextFrom, a).enabled
  )
  if (allowed.indexOf(nextTo) < 0) {
    uni.showToast({ title: t('alert_exchange_pair_invalid') || '当前方向无法互换', icon: 'none' })
    return
  }
  fromAsset.value = nextFrom
  toAsset.value = nextTo
}

function fillAll() {
  const p = pair.value
  const max = Math.min(avail.value, p.max || 99999)
  amount.value =
    fromAsset.value === 'rights' ? String(Math.max(0, Math.floor(max))) : max.toFixed(2)
}

async function onSubmit() {
  const p = pair.value
  if (!p.enabled) {
    uni.showToast({ title: t('alert_exchange_disabled') || '兑换功能已关闭', icon: 'none' })
    return
  }
  let amt = parseFloat(amount.value) || 0
  if (fromAsset.value === 'rights') amt = parseInt(amount.value, 10) || 0
  if (!(amt > 0)) {
    uni.showToast({ title: t('alert_exchange_amount_invalid') || '请输入转出数量', icon: 'none' })
    return
  }
  if (amt > (p.max || 99999) + 1e-8) {
    uni.showToast({ title: t('alert_exchange_max', { max: p.max }) || ('单次最高 ' + p.max), icon: 'none' })
    return
  }
  if (amt > avail.value + 1e-8) {
    uni.showToast({
      title:
        fromAsset.value === 'hongbao'
          ? '红宝不足，可用 ' + avail.value.toFixed(2)
          : '可兑股份不足，可用 ' + Math.floor(avail.value),
      icon: 'none',
    })
    return
  }
  submitting.value = true
  try {
    const data = await submitSwap(fromAsset.value, toAsset.value, amt)
    if (data && (data.hongbao != null || data.rights != null || data.profile)) {
      profile.value = data.profile || data
    } else {
      const boot = await loadExchangeBootstrap()
      profile.value = boot.profile
      config.value = boot.config
    }
    uni.showToast({ title: t('alert_exchange_swap_ok') || '兑换成功', icon: 'success' })
  } catch (e) {
    uni.showToast({ title: (e && e.message) || t('alert_exchange_fail') || '兑换失败', icon: 'none' })
  } finally {
    submitting.value = false
    tickTime()
  }
}

async function load() {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  try {
    const boot = await loadExchangeBootstrap()
    config.value = boot.config
    profile.value = boot.profile
    ensureToValid()
    tickTime()
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '加载失败', icon: 'none' })
  }
}

watch([fromAsset, toAsset], () => tickTime())

onShow(load)
</script>

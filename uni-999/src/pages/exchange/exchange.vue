<template>
  <view :key="pageKey">
    <TopBar />
    <view id="tabExchange" class="tab-page active">
      <view class="page-hero-title">{{ tt('page_hero_exchange_title', '⚡ VIP 闪兑大厅') }}</view>
      <view class="exchange-closed-banner" v-if="!anyEnabled">
        {{ tt('profile_ex_r2b_closed', tt('alert_exchange_disabled', '股份兑换红宝已关闭')) }}
      </view>

      <view class="share-swap" id="dualExchangeSection" v-if="anyEnabled">
        <view class="share-swap-panel" id="shareSwapPanel">
          <view class="share-swap-header">
            <view class="share-swap-title">{{ swapTitle }}</view>
            <view class="share-swap-avail">{{ availText }}</view>
          </view>

          <view class="share-swap-section-title">{{ fromSectionTitle }}</view>
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
              :disabled="!pair.enabled"
              :placeholder="minHint"
              @blur="clampAmount"
              @input="tickTime"
            />
            <button type="button" class="share-swap-btn-all" @click="fillAll">
              {{ tt('swap_all_btn', '全部') }}
            </button>
          </view>
          <view class="share-swap-hint">{{ minHint }}</view>

          <view class="share-swap-divider">
            <button type="button" class="share-swap-arrow" @click="flip" :aria-label="tt('swap_aria_flip', '互换方向')">
              <text class="share-swap-arrow-char">↕</text>
            </button>
          </view>

          <view class="share-swap-section-title">
            <text class="share-swap-icon-prefix-char">◎</text>
            <text>{{ tt('swap_to_label', '兑换目标') }}</text>
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
              <view class="share-swap-summary-label">{{ tt('swap_rate_label', '兑换比例') }}</view>
              <view class="share-swap-summary-value">{{ rateText }}</view>
              <view class="share-swap-summary-currency">CNY</view>
              <view class="share-swap-summary-time">{{ nowText }}</view>
            </view>
            <view class="share-swap-vdiv" aria-hidden="true" />
            <view class="share-swap-summary-right">
              <view class="share-swap-summary-label">{{ tt('swap_est_label', '预计到账') }}</view>
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
            {{ tt('swap_submit', '确认兑换') }}
          </button>
          <view class="share-swap-closed" v-if="!pair.enabled">
            {{ pairClosedText }}
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
  applySwapProfile,
  estimateCredit,
  freeRightsOf,
  hongbaoOf,
  loadExchangeBootstrap,
  pairInfo,
  rateLine,
  rightsLockedOf,
  submitSwap,
} from '../../utils/exchange.js'
import {
  copyState,
  ensureLocaleLoaded,
  getLocale,
  localeState,
  tt,
} from '../../utils/i18n.js'
import '../../styles/share-swap.css'
import '../../styles/exchange-uni-adapter.css'

const ASSETS = ['rights', 'hongbao']
const locale = localeState()
const copyTick = copyState()
const pageKey = computed(() => String(locale.value || '') + '-' + String(copyTick.value || 0))
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

function touchCopy() {
  void locale.value
  void copyTick.value
}

function assetLabel(a) {
  if (a === 'hongbao' || a === 'balance') {
    return tt('swap_asset_hongbao', tt('asset_hongbao_label', '红宝'))
  }
  return tt('swap_asset_rights', tt('asset_shares_label', '股份'))
}
function assetIcon(a) {
  if (a === 'hongbao' || a === 'balance') return tt('swap_unit_hongbao', '宝')
  return tt('swap_unit_share', '股')
}

const fromLabel = computed(() => {
  touchCopy()
  return assetLabel(fromAsset.value)
})
const toLabel = computed(() => {
  touchCopy()
  return assetLabel(toAsset.value)
})
const fromIcon = computed(() => {
  touchCopy()
  return assetIcon(fromAsset.value)
})
const toIcon = computed(() => {
  touchCopy()
  return assetIcon(toAsset.value)
})
const fromLabels = computed(() => {
  touchCopy()
  return ASSETS.map(assetLabel)
})
const toOptions = computed(() =>
  ASSETS.filter((a) => a !== fromAsset.value && pairInfo(config.value, fromAsset.value, a).enabled)
)
const toLabels = computed(() => {
  touchCopy()
  return toOptions.value.map(assetLabel)
})
const fromIndex = computed(() => Math.max(0, ASSETS.indexOf(fromAsset.value)))
const toIndex = computed(() => Math.max(0, toOptions.value.indexOf(toAsset.value)))

const fromSectionTitle = computed(() => {
  touchCopy()
  return tt('swap_from_with_asset', '转出{asset}', { asset: fromLabel.value })
})

const swapTitle = computed(() => {
  touchCopy()
  return tt('swap_title_pair', '{from}兑换{to}', {
    from: fromLabel.value,
    to: toLabel.value,
  })
})

const pairClosedText = computed(() => {
  touchCopy()
  return tt(
    'swap_pair_closed',
    tt('alert_exchange_pair_invalid', '{from}兑换{to}已关闭'),
    { from: fromLabel.value, to: toLabel.value }
  )
})

const avail = computed(() => {
  if (fromAsset.value === 'hongbao') return hongbaoOf(profile.value)
  return freeRightsOf(profile.value)
})
const availText = computed(() => {
  touchCopy()
  if (fromAsset.value === 'hongbao') {
    return tt('swap_avail_hongbao', '可用 {amount} 红宝', {
      amount: avail.value.toFixed(2),
    })
  }
  const locked = rightsLockedOf(profile.value)
  if (locked > 0) {
    return tt('share_swap_rights_locked_hint', '可兑 {free} 份（锁定 {locked} 份，次日可兑）', {
      free: Math.floor(avail.value),
      locked: Math.ceil(locked),
    })
  }
  return tt('swap_avail_rights', '可用 {amount} 份', {
    amount: Math.floor(avail.value),
  })
})

const minHint = computed(() => {
  touchCopy()
  const p = pair.value
  return tt('profile_ex_max_hint', '单笔上限 {max}', { max: p.max })
})

const sharePrice = computed(() => {
  const c = config.value || {}
  return c.current_share_price || c.single_ticket_value || 5
})

const rateText = computed(() => {
  touchCopy()
  const r = rateLine(config.value, fromAsset.value, toAsset.value, sharePrice.value)
  return tt('swap_rate_line', '1 {from} = {rate} {to}', {
    from: fromLabel.value,
    to: toLabel.value,
    rate: r.toFixed(4),
  })
})

const estText = computed(() => {
  touchCopy()
  const credit = estimateCredit(
    config.value,
    fromAsset.value,
    toAsset.value,
    amount.value,
    sharePrice.value
  )
  if (toAsset.value === 'hongbao') return credit.toFixed(2)
  return tt('swap_est_shares', '{amount} 份', { amount: credit.toFixed(2) })
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

function parseAmount() {
  if (fromAsset.value === 'rights') return parseInt(String(amount.value), 10) || 0
  return parseFloat(String(amount.value)) || 0
}

function clampAmount() {
  const p = pair.value
  const min = Math.max(1, Number(p.min) || 1)
  const max = Math.max(min, Number(p.max) || 99999)
  let raw = parseAmount()
  if (!(raw > 0)) {
    amount.value = fromAsset.value === 'rights' ? '1' : '1'
    return
  }
  if (raw > max + 1e-8) {
    amount.value =
      fromAsset.value === 'rights' ? String(Math.floor(max)) : String(Number(max.toFixed(2)))
    return
  }
  if (fromAsset.value === 'rights') {
    amount.value = String(Math.floor(raw))
  } else {
    amount.value = String(Number(raw.toFixed(2)))
  }
}

function insufficientMsg(av) {
  if (fromAsset.value === 'hongbao') {
    return tt('alert_exchange_insufficient', '数量不足，当前可用红宝 {avail}', {
      avail: Number(av).toFixed(2),
    })
  }
  return tt('alert_exchange_insufficient', '数量不足，当前可兑股份 {avail} 份', {
    avail: Math.floor(av),
  })
}

function onFromPick(e) {
  const idx = (e.detail && e.detail.value) | 0
  fromAsset.value = ASSETS[idx] || 'rights'
  ensureToValid()
  tickTime()
}

function onToPick(e) {
  const idx = (e.detail && e.detail.value) | 0
  const next = toOptions.value[idx]
  if (next) toAsset.value = next
  tickTime()
}

function flip() {
  const nextFrom = toAsset.value
  const nextTo = fromAsset.value
  const allowed = ASSETS.filter(
    (a) => a !== nextFrom && pairInfo(config.value, nextFrom, a).enabled
  )
  if (allowed.indexOf(nextTo) < 0) {
    uni.showToast({
      title: tt('alert_exchange_pair_invalid', '当前方向无法互换'),
      icon: 'none',
    })
    return
  }
  fromAsset.value = nextFrom
  toAsset.value = nextTo
  tickTime()
}

function fillAll() {
  const p = pair.value
  const max = Math.min(avail.value, p.max || 99999)
  amount.value =
    fromAsset.value === 'rights' ? String(Math.max(0, Math.floor(max))) : Number(Math.max(0, max)).toFixed(2)
  tickTime()
}

async function onSubmit() {
  const p = pair.value
  if (!p.enabled) {
    uni.showToast({ title: tt('alert_exchange_disabled', '兑换功能已关闭'), icon: 'none' })
    return
  }
  clampAmount()
  const amt = parseAmount()
  const min = Math.max(1, Number(p.min) || 1)
  const max = Math.max(min, Number(p.max) || 99999)
  if (!(amt > 0)) {
    uni.showToast({
      title: tt('alert_exchange_amount_invalid', '请输入转出数量'),
      icon: 'none',
    })
    return
  }
  if (amt > max + 1e-8) {
    uni.showToast({
      title: tt('alert_exchange_max', '单次最高兑换 {max}', { max }),
      icon: 'none',
    })
    return
  }
  if (amt > avail.value + 1e-8) {
    uni.showToast({ title: insufficientMsg(avail.value), icon: 'none' })
    return
  }
  submitting.value = true
  try {
    const data = await submitSwap(fromAsset.value, toAsset.value, amt)
    const next = applySwapProfile(data)
    if (next) {
      profile.value = next
    } else {
      const boot = await loadExchangeBootstrap()
      profile.value = boot.profile
      config.value = boot.config
      applySwapProfile(boot.profile)
    }
    uni.showToast({ title: tt('alert_exchange_swap_ok', '🎉 兑换成功'), icon: 'success' })
  } catch (e) {
    uni.showToast({
      title: (e && e.message) || tt('alert_exchange_fail', '兑换失败'),
      icon: 'none',
    })
  } finally {
    submitting.value = false
    tickTime()
  }
}

async function load() {
  try {
    await ensureLocaleLoaded(getLocale())
  } catch (e) {}
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  try {
    uni.setNavigationBarTitle({ title: tt('tab_bar_exchange', '闪兑') })
  } catch (e) {}
  try {
    const boot = await loadExchangeBootstrap()
    config.value = boot.config
    profile.value = boot.profile
    ensureToValid()
    tickTime()
  } catch (e) {
    uni.showToast({
      title: (e && e.message) || tt('loading_generic', '加载失败'),
      icon: 'none',
    })
  }
}

watch([fromAsset, toAsset], () => tickTime())

onShow(load)
</script>

<template>
  <view class="hb-page" :key="locale">
    <TopBar />
    <view class="hero">
      <text class="title">{{ t('page_hero_exchange_title') || 'VIP 闪兑大厅' }}</text>
      <text class="sub">{{ t('page_hero_exchange_sub') || '股份 ↔ 红宝 · 实时预估到账' }}</text>
    </view>

    <view class="assets card">
      <view class="asset">
        <text class="a-lab">{{ t('asset_hongbao_label') || '红宝' }}</text>
        <text class="a-val">{{ hongbaoText }}</text>
      </view>
      <view class="divider" />
      <view class="asset">
        <text class="a-lab">{{ t('asset_shares_label') || '股份' }}</text>
        <text class="a-val">{{ rightsText }}</text>
        <text class="a-hint" v-if="lockedText">{{ lockedText }}</text>
      </view>
    </view>

    <view class="swap card" v-if="anyEnabled">
      <view class="swap-head">
        <text class="swap-title">{{ swapTitle }}</text>
        <text class="swap-avail">{{ availText }}</text>
      </view>

      <text class="sec">{{ t('swap_from_label') || '转出' }}</text>
      <view class="row-line">
        <view class="ico">{{ fromIcon }}</view>
        <picker :range="fromLabels" :value="fromIndex" @change="onFromPick">
          <view class="picker">{{ fromLabel }} ▾</view>
        </picker>
        <input
          class="amt"
          type="digit"
          v-model="amount"
          :placeholder="minHint"
        />
        <button class="all" size="mini" @click="fillAll">{{ t('swap_all_btn') || '全部' }}</button>
      </view>
      <text class="hint">{{ minHint }}</text>

      <view class="flip-wrap">
        <view class="flip" @click="flip">⇅</view>
      </view>

      <text class="sec">{{ t('swap_to_label') || '兑换目标' }}</text>
      <view class="row-line">
        <view class="ico">{{ toIcon }}</view>
        <picker :range="toLabels" :value="toIndex" @change="onToPick">
          <view class="picker">{{ toLabel }} ▾</view>
        </picker>
      </view>

      <view class="summary">
        <view class="sum-col">
          <text class="sum-lab">{{ t('swap_rate_label') || '兑换比例' }}</text>
          <text class="sum-val">{{ rateText }}</text>
          <text class="sum-time">{{ nowText }}</text>
        </view>
        <view class="sum-col">
          <text class="sum-lab">{{ t('swap_est_label') || '预计到账' }}</text>
          <text class="sum-amt">{{ estText }}</text>
          <text class="sum-lab">{{ toLabel }}</text>
        </view>
      </view>

      <button class="submit" :disabled="submitting || !pair.enabled" @click="onSubmit">
        {{ submitting ? (t('loading_generic') || '处理中…') : (t('swap_submit') || '确认兑换') }}
      </button>
    </view>

    <view class="card closed" v-else>
      <text>{{ t('alert_exchange_disabled') || '兑换功能已关闭' }}</text>
    </view>

    <button class="link-wallet" @click="goWallet">{{ t('profile_menu_ledger') || '资金流水' }} ›</button>
  </view>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
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

const hongbaoText = computed(() => hongbaoOf(profile.value).toFixed(2))
const rightsText = computed(() => {
  const n = Number((profile.value && profile.value.rights) || 0)
  return n.toFixed(2)
})
const lockedText = computed(() => {
  const locked = Number((profile.value && profile.value.rights_locked) || 0)
  const free = freeRightsOf(profile.value)
  if (!(locked > 0)) return ''
  return t('share_swap_rights_locked_hint', {
    free: Math.floor(free),
    locked: locked.toFixed(2),
  }) || ('可兑 ' + Math.floor(free) + ' · 锁定 ' + locked.toFixed(2))
})

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

function goWallet() {
  uni.navigateTo({ url: '/pages/wallet/ledger' })
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

<style scoped>
.hero { margin-bottom: 14px; }
.title { display: block; font-size: 22px; font-weight: 800; color: var(--text-main, #1f1714); }
.sub { display: block; margin-top: 6px; font-size: 13px; color: var(--text-muted, #8a7a6e); }
.card {
  background: var(--bg-card, #fff);
  border-radius: 16px;
  padding: 16px;
  margin-bottom: 12px;
  box-shadow: 0 4px 14px rgba(40, 20, 10, 0.05);
}
.assets { display: flex; align-items: stretch; }
.asset { flex: 1; text-align: center; }
.divider { width: 1px; background: rgba(40, 20, 10, 0.08); margin: 0 8px; }
.a-lab { display: block; font-size: 12px; color: var(--text-muted, #9a8574); font-weight: 700; }
.a-val { display: block; margin-top: 6px; font-size: 22px; font-weight: 800; color: var(--text-main, #1f1714); }
.a-hint { display: block; margin-top: 4px; font-size: 11px; color: var(--text-muted, #9a8574); }
.swap-head { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; margin-bottom: 12px; }
.swap-title { font-size: 17px; font-weight: 800; color: var(--text-main, #1f1714); }
.swap-avail { font-size: 12px; color: var(--text-muted, #9a8574); }
.sec { display: block; font-size: 12px; font-weight: 700; color: var(--text-muted, #8a7a6e); margin: 8px 0 8px; }
.row-line { display: flex; align-items: center; gap: 8px; }
.ico {
  width: 36px; height: 36px; border-radius: 50%;
  background: linear-gradient(145deg, #fff5f3, #ffe3df);
  color: #c61114; font-weight: 800; font-size: 13px;
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.picker {
  min-width: 72px; padding: 10px 8px; font-size: 14px; font-weight: 700;
  color: var(--text-main, #1f1714); background: #f7f2ec; border-radius: 10px;
}
.amt {
  flex: 1; height: 40px; padding: 0 12px; background: #f7f2ec; border-radius: 10px;
  font-size: 16px; font-weight: 700; color: var(--text-main, #1f1714);
}
.all {
  margin: 0; background: #fff8f0; color: #c45a1a; border: 1px solid #f0b04a; font-weight: 700;
}
.hint { display: block; margin-top: 8px; font-size: 12px; color: var(--text-muted, #9a8574); }
.flip-wrap { display: flex; justify-content: center; margin: 10px 0; }
.flip {
  width: 40px; height: 40px; border-radius: 50%;
  background: linear-gradient(#fff, #fff) padding-box,
    linear-gradient(145deg, #ffe9b0, #f0b04a, #e07a22) border-box;
  border: 1.5px solid transparent;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px; font-weight: 800; color: #c45a1a;
}
.summary {
  display: flex; gap: 12px; margin: 16px 0;
  padding: 14px; border-radius: 12px; background: #faf6f1;
}
.sum-col { flex: 1; min-width: 0; }
.sum-lab { display: block; font-size: 11px; color: var(--text-muted, #9a8574); font-weight: 700; }
.sum-val, .sum-amt {
  display: block; margin-top: 6px; font-size: 16px; font-weight: 800; color: var(--text-main, #1f1714);
  word-break: break-all;
}
.sum-amt { color: #c61114; font-size: 22px; }
.sum-time { display: block; margin-top: 4px; font-size: 10px; color: #b8aaa0; }
.submit {
  width: 100%; margin-top: 4px; background: var(--primary, #c61114); color: #fff;
  font-weight: 800; border-radius: 12px; padding: 12px 0;
}
.submit[disabled] { opacity: 0.55; }
.closed { text-align: center; color: var(--text-muted, #9a8574); }
.link-wallet {
  margin-top: 8px; background: transparent; color: var(--text-muted, #8a7a6e);
  font-size: 13px; font-weight: 700;
}
</style>

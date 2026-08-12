<template>
  <view class="hb-page profile-sub-page">
    <TopBar />
    <view class="profile-sub-hd">
      <text class="profile-back-btn" @click="goBack">‹</text>
      <text class="profile-sub-title">充值</text>
      <text class="profile-sub-spacer" />
    </view>
    <view class="profile-sub-body hb-sub">
      <view class="match-card profile-card">
        <view class="profile-field">
          <text class="lab">选择充值通道</text>
          <view
            v-if="groups.length > 1"
            class="wallet-partition-tabs"
            :class="{ 'is-3': groups.length >= 3 }"
          >
            <view
              v-for="g in groups"
              :key="g.key"
              class="wallet-partition-tab"
              :class="{ active: activeKey === g.key }"
              @click="selectGroup(g.key)"
            >
              {{ g.name }}
            </view>
          </view>
        </view>

        <view class="wallet-channel-list is-grid" v-if="visibleChannels.length">
          <view
            v-for="ch in visibleChannels"
            :key="ch.id"
            class="wallet-channel-item"
            :class="{ active: selectedId === Number(ch.id) }"
            @click="selectChannel(ch)"
          >
            <WalletChannelIcon :channel="ch" :name="shortName(ch)" />
            <view class="wallet-channel-meta">
              <text class="wallet-channel-name">{{ shortName(ch) }}</text>
            </view>
          </view>
        </view>
        <view
          v-if="hiddenMoreCount > 0"
          class="wallet-channel-more-btn"
          :class="{ 'is-open': showMore }"
          @click="showMore = !showMore"
        >
          {{ showMore ? '收起' : ('更多通道 · ' + hiddenMoreCount) }}
        </view>
        <view class="wallet-channel-empty" v-else-if="!loading && !visibleChannels.length">
          暂无可用通道，请联系客服
        </view>

        <view id="profileRechargeForm" class="wallet-amount-panel" v-if="selected">
          <view class="profile-field">
            <text class="lab">{{ amountLabel }}</text>
            <view class="wallet-quick-amounts" v-if="quickAmounts.length">
              <view
                v-for="q in quickAmounts"
                :key="q"
                class="wallet-quick-amt"
                :class="{
                  active: String(amount) === String(q),
                  'is-disabled': isQuickDisabled(q),
                }"
                @click="pickQuick(q)"
              >
                {{ formatQuick(q) }}
              </view>
            </view>
            <input class="hb-input" type="digit" v-model="amount" :placeholder="amountPh" />
            <view class="profile-meta-line wallet-fx-hint" v-if="fxText">{{ fxText }}</view>
          </view>
          <button class="btn-uid-submit" :disabled="submitting" @click="onSubmit">
            {{ submitting ? '提交中…' : '确认充值' }}
          </button>
        </view>
      </view>

      <view class="wallet-ledger-empty" v-if="loading">加载中…</view>
      <view class="wallet-warn" v-if="error" style="text-align:center;margin-top:12px">{{ error }}</view>
    </view>
  </view>
</template>

<script setup>
import { safeNavigateBack, HOME_TAB } from '../../utils/nav.js'
import { computed, ref, watch } from 'vue'
import TopBar from '../../components/TopBar.vue'
import WalletChannelIcon from '../../components/WalletChannelIcon.vue'
import { onShow } from '@dcloudio/uni-app'
import { applySafeAreaCssVars } from '../../utils/safe-area.js'
import { getToken } from '../../utils/auth.js'
import {
  CHANNEL_GRID_VISIBLE,
  clearWalletCache,
  findChannel,
  formatQuickAmtLabel,
  fxHintText,
  groupByPartitions,
  isQuickAmtDisabled,
  isUsdtRechargeChannel,
  loadWalletBootstrap,
  money,
  openPayResult,
  organizeWalletChannels,
  rechargeQuickAmounts,
  sanitizePayMessage,
  shortChannelName,
  submitRecharge,
  validateChannelAmount,
} from '../../utils/wallet.js'
import '../../styles/hb.css'

function goBack() {
  safeNavigateBack(HOME_TAB)
}

const loading = ref(false)
const error = ref('')
const channels = ref([])
const partitions = ref([])
const activeKey = ref('')
const selectedId = ref(0)
const amount = ref('')
const submitting = ref(false)
const showMore = ref(false)

const groups = computed(() => groupByPartitions(channels.value, partitions.value))
const activeChannels = computed(() => {
  const g = groups.value.find((x) => x.key === activeKey.value)
  return (g && g.channels) || []
})
const organized = computed(() => organizeWalletChannels(activeChannels.value))
const orderedChannels = computed(() => organized.value.pinned.concat(organized.value.more))
const visibleChannels = computed(() => {
  const all = orderedChannels.value
  if (showMore.value || all.length <= CHANNEL_GRID_VISIBLE) return all
  return all.slice(0, CHANNEL_GRID_VISIBLE)
})
const hiddenMoreCount = computed(() => {
  const n = orderedChannels.value.length - CHANNEL_GRID_VISIBLE
  return n > 0 && !showMore.value ? n : 0
})
const selected = computed(() => findChannel(channels.value, selectedId.value))
const amountLabel = computed(() =>
  isUsdtRechargeChannel(selected.value) ? '充值红宝金额（U）' : '充值红宝金额（元）'
)
const amountPh = computed(() => {
  const ch = selected.value
  if (!ch) return '请输入金额'
  const min = Number(ch.min_amount || 0)
  const max = Number(ch.max_amount || 0)
  if (min > 0 && max > 0) return money(min) + ' ~ ' + money(max)
  if (min > 0) return '最低 ' + money(min)
  return '请输入金额'
})
const fxText = computed(() => fxHintText(selected.value, amount.value))
const quickAmounts = computed(() => rechargeQuickAmounts(selected.value))

function shortName(ch) {
  return shortChannelName(ch)
}
function formatQuick(q) {
  return formatQuickAmtLabel(q)
}
function isQuickDisabled(q) {
  return isQuickAmtDisabled(selected.value, q)
}
function pickQuick(q) {
  if (isQuickDisabled(q)) return
  amount.value = String(q)
}
function selectGroup(key) {
  activeKey.value = key
  showMore.value = false
  autoPick()
}
function selectChannel(ch) {
  selectedId.value = Number(ch.id)
  amount.value = ''
}
function autoPick() {
  const list = orderedChannels.value
  if (list.length) {
    selectedId.value = Number(list[0].id)
    amount.value = ''
  } else {
    selectedId.value = 0
  }
}

watch(activeKey, () => {
  showMore.value = false
})

async function onSubmit() {
  const ch = selected.value
  const err = validateChannelAmount(ch, amount.value)
  if (err) {
    uni.showToast({ title: err, icon: 'none' })
    return
  }
  const submitAmount = Number(amount.value)
  submitting.value = true
  try {
    const data = await submitRecharge(selectedId.value, submitAmount)
    const info = (data && data.pay_info) || {}
    uni.showToast({ title: sanitizePayMessage(info.message, '提交成功'), icon: 'none' })
    openPayResult(info)
    clearWalletCache()
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '失败', icon: 'none' })
  } finally {
    submitting.value = false
  }
}

async function refresh() {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  loading.value = true
  error.value = ''
  try {
    const bundle = await loadWalletBootstrap(true)
    const r = (bundle && bundle.recharge) || {}
    channels.value = r.list || []
    partitions.value = r.partitions || []
    if (!activeKey.value && groups.value.length) activeKey.value = groups.value[0].key
    autoPick()
  } catch (e) {
    error.value = (e && e.message) || '加载失败'
  } finally {
    loading.value = false
  }
}

onShow(() => {
  applySafeAreaCssVars()
  refresh()
})
</script>

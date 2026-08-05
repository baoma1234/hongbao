<template>
  <view class="hb-page profile-sub-page">
    <TopBar :no-spacer="true" />
    <view class="profile-sub-hd">
      <text class="profile-back-btn" @click="goBack">‹</text>
      <text class="profile-sub-title">充值</text>
      <text class="profile-sub-spacer" />
    </view>
    <view class="profile-sub-body hb-sub">
    <view class="match-card">
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

      <view class="wallet-channel-list is-grid" v-if="activeChannels.length">
        <view
          v-for="ch in activeChannels"
          :key="ch.id"
          class="wallet-channel-item"
          :class="{ active: selectedId === Number(ch.id) }"
          @click="selectChannel(ch)"
        >
          <image
            v-if="iconUrl(ch)"
            class="wallet-channel-icon"
            :src="iconUrl(ch)"
            mode="aspectFill"
          />
          <view v-else class="wallet-channel-icon wallet-channel-icon--placeholder">
            {{ shortName(ch).charAt(0) }}
          </view>
          <view class="wallet-channel-meta">
            <text class="wallet-channel-name">{{ shortName(ch) }}</text>
          </view>
        </view>
      </view>
      <view class="wallet-channel-empty" v-else-if="!loading">暂无可用通道，请联系客服</view>

      <view class="wallet-amount-panel" v-if="selected">
        <view class="profile-field">
          <text class="lab">{{ amountLabel }}</text>
          <view class="wallet-quick-amounts" v-if="quickAmounts.length">
            <view
              v-for="q in quickAmounts"
              :key="q"
              class="wallet-quick-amt"
              :class="{ active: String(amount) === String(q) }"
              @click="amount = String(q)"
            >
              {{ q }}
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
import { computed, ref } from 'vue'
import TopBar from '../../components/TopBar.vue'
import { onShow } from '@dcloudio/uni-app'
import { getToken } from '../../utils/auth.js'
import {
  channelIconUrl,
  clearWalletCache,
  findChannel,
  fxHintText,
  groupByPartitions,
  isUsdtRechargeChannel,
  loadWalletBootstrap,
  money,
  openPayResult,
  shortChannelName,
  submitRecharge,
  validateChannelAmount,
} from '../../utils/wallet.js'
import '../../styles/hb.css'

function goBack() {
  uni.navigateBack({ fail: () => uni.switchTab({ url: '/pages/profile/profile' }) })
}

const loading = ref(false)
const error = ref('')
const channels = ref([])
const partitions = ref([])
const activeKey = ref('')
const selectedId = ref(0)
const amount = ref('')
const submitting = ref(false)

const groups = computed(() => groupByPartitions(channels.value, partitions.value))
const activeChannels = computed(() => {
  const g = groups.value.find((x) => x.key === activeKey.value)
  return (g && g.channels) || []
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
const quickAmounts = computed(() => {
  const ch = selected.value
  if (!ch) return []
  const raw = ch.quick_amounts || ch.amounts || ch.fixed_amounts || []
  if (Array.isArray(raw)) return raw.map(Number).filter((n) => n > 0).slice(0, 8)
  return []
})

function shortName(ch) {
  return shortChannelName(ch)
}
function iconUrl(ch) {
  return channelIconUrl(ch)
}
function selectGroup(key) {
  activeKey.value = key
  selectedId.value = 0
  amount.value = ''
}
function selectChannel(ch) {
  selectedId.value = Number(ch.id)
  amount.value = ''
}

async function onSubmit() {
  const ch = selected.value
  const err = validateChannelAmount(ch, amount.value)
  if (err) {
    uni.showToast({ title: err, icon: 'none' })
    return
  }
  // USDT：输入/提交均为 U（如 50）；入账人民币由网关回调按汇率换算（如 50×7=350）
  const submitAmount = Number(amount.value)
  submitting.value = true
  try {
    const data = await submitRecharge(selectedId.value, submitAmount)
    const info = (data && data.pay_info) || {}
    uni.showToast({ title: info.message || '充值申请已提交', icon: 'none' })
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
  } catch (e) {
    error.value = (e && e.message) || '加载失败'
  } finally {
    loading.value = false
  }
}

onShow(() => {
  refresh()
})
</script>

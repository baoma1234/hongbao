<template>
  <view class="page">
    <view class="section" v-if="groups.length">
      <scroll-view scroll-x class="tabs">
        <view
          v-for="g in groups"
          :key="g.key"
          class="tab"
          :class="{ on: activeKey === g.key }"
          @click="selectGroup(g.key)"
        >
          {{ g.name }}
        </view>
      </scroll-view>

      <view class="chans">
        <view
          v-for="ch in activeChannels"
          :key="ch.id"
          class="chan"
          :class="{ on: selectedId === Number(ch.id) }"
          @click="selectChannel(ch)"
        >
          <text class="name">{{ shortName(ch) }}</text>
          <text class="meta">{{ channelMeta(ch) }}</text>
        </view>
      </view>
    </view>

    <view class="form" v-if="selected">
      <view class="field">
        <text class="lab">充值金额</text>
        <input type="digit" v-model="amount" :placeholder="amountPh" />
        <text class="fx" v-if="fxText">{{ fxText }}</text>
      </view>
      <view class="quicks" v-if="quickAmounts.length">
        <view
          v-for="q in quickAmounts"
          :key="q"
          class="q"
          @click="amount = String(q)"
        >
          {{ q }}
        </view>
      </view>
      <button class="submit" :disabled="submitting" @click="onSubmit">
        {{ submitting ? '提交中…' : '确认充值' }}
      </button>
    </view>

    <view class="empty" v-else-if="!loading">请选择充值通道</view>
    <view class="empty" v-if="loading">加载中…</view>
    <view class="empty err" v-if="error">{{ error }}</view>
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { getToken } from '../../utils/auth.js'
import {
  clearWalletCache,
  findChannel,
  fxHintText,
  groupByPartitions,
  loadWalletBootstrap,
  money,
  openPayResult,
  shortChannelName,
  submitRecharge,
  validateChannelAmount,
} from '../../utils/wallet.js'

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
const amountPh = computed(() => {
  const ch = selected.value
  if (!ch) return '金额'
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
function channelMeta(ch) {
  const min = Number(ch.min_amount || 0)
  const max = Number(ch.max_amount || 0)
  if (min > 0 || max > 0) return money(min) + '~' + money(max)
  return ''
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
  submitting.value = true
  try {
    const data = await submitRecharge(selectedId.value, Number(amount.value))
    const info = (data && data.pay_info) || {}
    uni.showToast({
      title: info.message || '充值申请已提交',
      icon: 'none',
    })
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
    if (!activeKey.value && groups.value.length) {
      activeKey.value = groups.value[0].key
    }
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

<style scoped>
.page {
  padding: 24rpx 28rpx 80rpx;
  min-height: 100vh;
}
.section {
  background: #fff;
  border-radius: 16rpx;
  padding: 20rpx;
  margin-bottom: 20rpx;
}
.tabs {
  white-space: nowrap;
  margin-bottom: 16rpx;
}
.tab {
  display: inline-block;
  padding: 12rpx 28rpx;
  margin-right: 12rpx;
  border-radius: 999rpx;
  font-size: 24rpx;
  background: #f6f1ea;
  color: #6b5648;
}
.tab.on {
  background: #c61114;
  color: #fff;
}
.chans {
  display: flex;
  flex-wrap: wrap;
  gap: 12rpx;
}
.chan {
  min-width: 28%;
  flex: 1 1 28%;
  padding: 20rpx 16rpx;
  border-radius: 12rpx;
  background: #faf7f3;
  border: 2rpx solid transparent;
}
.chan.on {
  border-color: #c61114;
  background: #fff5f5;
}
.name {
  display: block;
  font-size: 26rpx;
  font-weight: 700;
}
.meta {
  display: block;
  margin-top: 6rpx;
  font-size: 20rpx;
  color: #9a8574;
}
.form {
  background: #fff;
  border-radius: 16rpx;
  padding: 24rpx;
}
.field {
  margin-bottom: 20rpx;
}
.lab {
  display: block;
  font-size: 24rpx;
  color: #6b5648;
  margin-bottom: 8rpx;
}
input {
  background: #f6f1ea;
  border-radius: 10rpx;
  padding: 16rpx 20rpx;
  font-size: 28rpx;
}
.fx {
  display: block;
  margin-top: 8rpx;
  font-size: 22rpx;
  color: #9a8574;
}
.quicks {
  display: flex;
  flex-wrap: wrap;
  gap: 12rpx;
  margin-bottom: 20rpx;
}
.q {
  padding: 12rpx 24rpx;
  background: #f6f1ea;
  border-radius: 10rpx;
  font-size: 26rpx;
}
.submit {
  background: #c61114;
  color: #fff;
}
.submit[disabled] {
  opacity: 0.5;
}
.empty {
  text-align: center;
  color: #9a8574;
  padding: 40rpx;
  font-size: 26rpx;
}
.empty.err {
  color: #c61114;
}
</style>

<template>
  <view class="page">
    <view v-if="!list.length && !loading" class="empty">暂无资金流水</view>
    <view v-for="item in list" :key="rowKey(item)" class="row">
      <view class="left">
        <text class="title">{{ item.title || item.type_text || item.biz_type || '变动' }}</text>
        <text class="time">{{ item.createtime_text || item.created_at || item.createtime || '' }}</text>
        <text class="remark" v-if="item.remark">{{ item.remark }}</text>
      </view>
      <view class="right">
        <text class="amt" :class="amountCls(item)">{{ amountText(item) }}</text>
        <text class="after" v-if="afterText(item)">余 {{ afterText(item) }}</text>
      </view>
    </view>

    <view class="more" v-if="hasMore">
      <button size="mini" :disabled="loading" @click="loadMore">
        {{ loading ? '加载中…' : '加载更多' }}
      </button>
    </view>
    <view class="empty" v-if="error">{{ error }}</view>
  </view>
</template>

<script setup>
import { ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { getToken } from '../../utils/auth.js'
import { fetchLedger, ledgerAmountText, money } from '../../utils/wallet.js'

const list = ref([])
const page = ref(1)
const hasMore = ref(false)
const loading = ref(false)
const error = ref('')

function rowKey(item) {
  return item.id || item.createtime + '-' + (item.hongbao_change || item.balance_change)
}

function amountText(item) {
  return ledgerAmountText(item).text
}
function amountCls(item) {
  return ledgerAmountText(item).cls
}
function afterText(item) {
  let afterHb = item.hongbao_after
  if ((afterHb == null || afterHb === '') && item.balance_after != null) {
    afterHb = item.balance_after
  }
  if (afterHb == null || afterHb === '') return ''
  return money(afterHb)
}

async function load(p, append) {
  if (loading.value) return
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  loading.value = true
  error.value = ''
  try {
    const data = await fetchLedger(p, 20)
    const rows = (data && data.list) || []
    page.value = (data && data.page) || p
    hasMore.value = !!(data && data.has_more)
    list.value = append ? list.value.concat(rows) : rows
  } catch (e) {
    if (!append) list.value = []
    error.value = (e && e.message) || '加载失败'
  } finally {
    loading.value = false
  }
}

function loadMore() {
  if (!hasMore.value || loading.value) return
  load(page.value + 1, true)
}

onShow(() => {
  list.value = []
  load(1, false)
})
</script>

<style scoped>
.page {
  padding: 24rpx 28rpx 80rpx;
  min-height: 100vh;
}
.row {
  display: flex;
  justify-content: space-between;
  gap: 20rpx;
  background: #fff;
  border-radius: 16rpx;
  padding: 24rpx;
  margin-bottom: 16rpx;
}
.left {
  flex: 1;
  min-width: 0;
}
.title {
  display: block;
  font-size: 28rpx;
  font-weight: 700;
  color: #2a1f18;
}
.time {
  display: block;
  margin-top: 6rpx;
  font-size: 22rpx;
  color: #9a8574;
}
.remark {
  display: block;
  margin-top: 6rpx;
  font-size: 22rpx;
  color: #6b5648;
  word-break: break-all;
}
.right {
  text-align: right;
  flex-shrink: 0;
}
.amt {
  display: block;
  font-size: 30rpx;
  font-weight: 800;
}
.amt.plus {
  color: #1a7f37;
}
.amt.minus {
  color: #c61114;
}
.after {
  display: block;
  margin-top: 6rpx;
  font-size: 20rpx;
  color: #9a8574;
}
.more {
  text-align: center;
  padding: 20rpx;
}
.empty {
  text-align: center;
  color: #9a8574;
  padding: 60rpx 20rpx;
  font-size: 26rpx;
}
</style>

<template>
  <view class="hb-sub">
    <view class="wallet-ledger-list" v-if="list.length">
      <view v-for="item in list" :key="rowKey(item)" class="wallet-ledger-item">
        <view class="wallet-ledger-main">
          <view class="wallet-ledger-title">
            {{ item.type_label || item.title || item.type_text || item.type || '变动' }}
          </view>
          <view class="wallet-ledger-sub" v-if="item.remark && item.remark !== (item.type_label || item.title)">{{ item.remark }}</view>
          <view class="wallet-ledger-time">
            {{ item.createtime_text || item.created_at || item.createtime || '' }}
          </view>
        </view>
        <view>
          <view class="wallet-ledger-amount" :class="amountCls(item)">{{ amountText(item) }}</view>
          <view class="wallet-ledger-time" v-if="afterText(item)" style="text-align:right">
            余 {{ afterText(item) }}
          </view>
        </view>
      </view>
    </view>
    <view class="wallet-ledger-empty" v-else-if="!loading">暂无资金流水</view>
    <view class="wallet-ledger-empty" v-if="loading && !list.length">加载中…</view>
    <view class="wallet-warn" v-if="error" style="text-align:center">{{ error }}</view>

    <button
      v-if="hasMore"
      class="wallet-ledger-more"
      :disabled="loading"
      @click="loadMore"
    >
      {{ loading ? '加载中…' : '加载更多' }}
    </button>
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

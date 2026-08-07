<template>
  <view class="hb-page profile-sub-page">
    <TopBar :no-spacer="true" />
    <view class="profile-sub-hd">
      <text class="profile-back-btn" @click="goBack">‹</text>
      <text class="profile-sub-title">资金流水</text>
      <text class="profile-sub-spacer" />
    </view>
    <view class="profile-sub-body hb-sub">
    <view class="wallet-ledger-filters">
      <view
        class="wallet-ledger-filter"
        :class="{ 'is-on': category === 'all' }"
        @click="setCategory('all')"
      >全部</view>
      <view
        class="wallet-ledger-filter"
        :class="{ 'is-on': category === 'hongbao_in' }"
        @click="setCategory('hongbao_in')"
      >红宝入账</view>
      <view
        class="wallet-ledger-filter"
        :class="{ 'is-on': category === 'refund' }"
        @click="setCategory('refund')"
      >红宝退回</view>
      <view
        class="wallet-ledger-filter"
        :class="{ 'is-on': category === 'rebate' }"
        @click="setCategory('rebate')"
      >红包返佣</view>
    </view>

    <view class="wallet-ledger-list" v-if="list.length">
      <view v-for="item in list" :key="rowKey(item)" class="wallet-ledger-item">
        <view class="wallet-ledger-main">
          <view class="wallet-ledger-title">
            {{ item.type_label || item.title || item.type_text || item.type || '变动' }}
          </view>
          <view class="wallet-ledger-sub" v-if="item.remark && item.remark !== (item.type_label || item.title)">{{ item.remark }}</view>
          <view class="wallet-ledger-time">
            {{ formatLedgerTime(item) }}
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
    <view class="wallet-ledger-empty" v-else-if="!loading">
      {{ emptyText }}
    </view>
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
  </view>
</template>

<script setup>
import TopBar from '../../components/TopBar.vue'
import { ref, computed } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { getToken } from '../../utils/auth.js'
import { fetchLedger, ledgerAmountText, money } from '../../utils/wallet.js'
import '../../styles/hb.css'

function goBack() {
  uni.navigateBack({ fail: () => uni.switchTab({ url: '/pages/profile/profile' }) })
}

const list = ref([])
const page = ref(1)
const hasMore = ref(false)
const loading = ref(false)
const error = ref('')
const category = ref('all')
const emptyText = computed(() => {
  if (category.value === 'rebate') return '暂无红包返佣流水'
  if (category.value === 'hongbao_in') return '暂无红宝入账流水'
  if (category.value === 'refund') return '暂无红宝退回流水'
  return '暂无资金流水'
})

function rowKey(item) {
  return item.id || item.createtime + '-' + (item.hongbao_change || item.balance_change)
}

function formatLedgerTime(item) {
  const raw = item && (item.createtime != null ? item.createtime : item.created_at)
  let ts = 0
  if (typeof raw === 'number') {
    ts = raw
  } else if (typeof raw === 'string') {
    const s = raw.trim()
    if (/^\d+$/.test(s)) ts = parseInt(s, 10)
    else {
      const parsed = Date.parse(s.replace(/-/g, '/'))
      if (!isNaN(parsed)) ts = Math.floor(parsed / 1000)
    }
  }
  if (ts > 1e12) ts = Math.floor(ts / 1000)
  if (!ts) {
    const text = String((item && item.createtime_text) || '').trim()
    return text
  }
  const d = new Date(ts * 1000)
  if (isNaN(d.getTime())) return ''
  const p = (n) => (n < 10 ? '0' + n : '' + n)
  return (
    d.getFullYear() +
    '-' +
    p(d.getMonth() + 1) +
    '-' +
    p(d.getDate()) +
    ' ' +
    p(d.getHours()) +
    ':' +
    p(d.getMinutes()) +
    ':' +
    p(d.getSeconds())
  )
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
    const data = await fetchLedger(p, 20, category.value)
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

function setCategory(cat) {
  if (category.value === cat) return
  category.value = cat
  list.value = []
  load(1, false)
}

onShow(() => {
  list.value = []
  load(1, false)
})
</script>

<style scoped>
.wallet-ledger-filters {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 12px;
  padding: 0 2px;
}
.wallet-ledger-filter {
  border: 1px solid #e1e8ed;
  background: #f5f6f8;
  color: #555;
  border-radius: 999px;
  padding: 6px 14px;
  font-size: 13px;
  font-weight: 600;
}
.wallet-ledger-filter.is-on {
  background: #1a1a1a;
  border-color: #1a1a1a;
  color: #fff;
}
</style>

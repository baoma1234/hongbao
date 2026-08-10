<template>
  <view class="hb-page profile-sub-page">
    <TopBar />
    <view class="profile-sub-hd">
      <text class="profile-back-btn" @click="goBack">‹</text>
      <text class="profile-sub-title">资金流水</text>
      <text class="profile-sub-spacer" />
    </view>
    <view class="profile-sub-body hb-sub">
    <view class="wallet-ledger-filters">
      <view
        v-for="tab in filterTabs"
        :key="tab.key"
        class="wallet-ledger-filter"
        :class="['tone-' + tab.tone, { 'is-on': category === tab.key }]"
        @click="setCategory(tab.key)"
      >
        <text class="wallet-ledger-filter-ico">{{ tab.ico }}</text>
        <text class="wallet-ledger-filter-lab">{{ tab.label }}</text>
      </view>
    </view>

    <view class="wallet-ledger-list" v-if="list.length">
      <view
        v-for="item in list"
        :key="rowKey(item)"
        class="wallet-ledger-item"
        :class="{ 'is-rp': canOpenRp(item) }"
        @click="onItemClick(item)"
      >
        <view class="wallet-ledger-main">
          <view class="wallet-ledger-title">
            {{ item.type_label || item.title || item.type_text || item.type || '变动' }}
          </view>
          <view class="wallet-ledger-sub" v-if="item.remark && item.remark !== (item.type_label || item.title)">{{ item.remark }}</view>
          <view class="wallet-ledger-time">
            {{ formatLedgerTime(item) }}
          </view>
        </view>
        <view class="wallet-ledger-side">
          <view class="wallet-ledger-amount" :class="amountCls(item)">{{ amountText(item) }}</view>
          <view class="wallet-ledger-time" v-if="afterText(item)" style="text-align:right">
            余 {{ afterText(item) }}
          </view>
          <view
            v-if="canOpenRp(item)"
            class="wallet-ledger-rp-link"
            @click.stop="onItemClick(item)"
          >查看红宝记录</view>
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
import { safeNavigateBack, HOME_TAB } from '../../utils/nav.js'
import TopBar from '../../components/TopBar.vue'
import { ref, computed } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { getToken } from '../../utils/auth.js'
import { fetchLedger, ledgerAmountText, money } from '../../utils/wallet.js'
import '../../styles/hb.css'

function goBack() {
  safeNavigateBack(HOME_TAB)
}

const list = ref([])
const page = ref(1)
const hasMore = ref(false)
const loading = ref(false)
const error = ref('')
const category = ref('all')
const filterTabs = [
  { key: 'all', label: '全部', ico: '☰', tone: 'all' },
  { key: 'recharge', label: '充值', ico: '↓', tone: 'in' },
  { key: 'withdraw', label: '提现', ico: '↑', tone: 'out' },
  { key: 'hongbao_in', label: '红宝入账', ico: '🧧', tone: 'hb' },
  { key: 'refund', label: '红宝退回', ico: '↩', tone: 'back' },
  { key: 'rebate', label: '红宝返佣', ico: '%', tone: 'rebate' },
  { key: 'freeze', label: '冻结记录', ico: '❄', tone: 'freeze' },
]
const emptyText = computed(() => {
  if (category.value === 'rebate') return '暂无红宝返佣流水'
  if (category.value === 'hongbao_in') return '暂无红宝入账流水'
  if (category.value === 'refund') return '暂无红宝退回流水'
  if (category.value === 'freeze') return '暂无冻结记录'
  if (category.value === 'recharge') return '暂无充值流水'
  if (category.value === 'withdraw') return '暂无提现流水'
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

function canOpenRp(item) {
  if (!item) return false
  if (item.can_open_rp) return true
  const typ = String(item.type || '')
  if (typ.indexOf('red_packet_') === 0) {
    return !!(item.packet_id || item.ref_id || item.packet_no || item.biz_no)
  }
  return false
}

function onItemClick(item) {
  if (!canOpenRp(item)) return
  const pid = Number(item.packet_id || item.ref_id || 0) || 0
  const pno = String(item.packet_no || item.biz_no || '').trim()
  let url = '/pages/wallet/rp-detail?'
  if (pid > 0) url += 'packet_id=' + pid
  if (pno) url += (pid > 0 ? '&' : '') + 'packet_no=' + encodeURIComponent(pno)
  uni.navigateTo({ url })
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
  flex-wrap: nowrap;
  gap: 8px;
  margin: 0 -4px 14px;
  padding: 4px 4px 8px;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  scrollbar-width: none;
}
.wallet-ledger-filters::-webkit-scrollbar {
  display: none;
}
.wallet-ledger-filter {
  flex: 0 0 auto;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  border: 1px solid #e8ecf1;
  background: #fff;
  color: #5a6573;
  border-radius: 12px;
  padding: 8px 12px;
  font-size: 12px;
  font-weight: 700;
  box-shadow: 0 2px 8px rgba(26, 33, 45, 0.04);
  transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
}
.wallet-ledger-filter-ico {
  font-size: 12px;
  line-height: 1;
  opacity: 0.9;
}
.wallet-ledger-filter-lab {
  white-space: nowrap;
}
.wallet-ledger-filter.tone-in.is-on {
  background: linear-gradient(135deg, #1b8f4a, #147a3d);
  border-color: transparent;
  color: #fff;
  box-shadow: 0 6px 14px rgba(20, 122, 61, 0.28);
}
.wallet-ledger-filter.tone-out.is-on {
  background: linear-gradient(135deg, #e4572e, #c62828);
  border-color: transparent;
  color: #fff;
  box-shadow: 0 6px 14px rgba(198, 40, 40, 0.28);
}
.wallet-ledger-filter.tone-hb.is-on,
.wallet-ledger-filter.tone-rebate.is-on {
  background: linear-gradient(135deg, #ff7043, #e53935);
  border-color: transparent;
  color: #fff;
  box-shadow: 0 6px 14px rgba(229, 57, 53, 0.26);
}
.wallet-ledger-filter.tone-back.is-on {
  background: linear-gradient(135deg, #5c6bc0, #3949ab);
  border-color: transparent;
  color: #fff;
  box-shadow: 0 6px 14px rgba(57, 73, 171, 0.26);
}
.wallet-ledger-filter.tone-freeze.is-on {
  background: linear-gradient(135deg, #546e7a, #37474f);
  border-color: transparent;
  color: #fff;
  box-shadow: 0 6px 14px rgba(55, 71, 79, 0.26);
}
.wallet-ledger-filter.tone-all.is-on {
  background: linear-gradient(135deg, #2c3340, #1a1f29);
  border-color: transparent;
  color: #fff;
  box-shadow: 0 6px 14px rgba(26, 31, 41, 0.28);
}
.wallet-ledger-item.is-rp {
  cursor: pointer;
}
.wallet-ledger-item.is-rp:active {
  background: #f7f8fa;
}
.wallet-ledger-side {
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 4px;
  min-width: 88px;
}
.wallet-ledger-rp-link {
  margin-top: 2px;
  font-size: 11px;
  font-weight: 700;
  color: #c62828;
  line-height: 1.2;
  white-space: nowrap;
}
</style>

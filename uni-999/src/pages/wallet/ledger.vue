<template>
  <ProfileSubPage title="资金流水" body-class="hb-sub">
    <view class="wallet-ledger-filters">
      <view class="wallet-ledger-filters-row">
        <view
          v-for="tab in primaryTabs"
          :key="tab.key"
          class="wallet-ledger-filter"
          :class="['tone-' + tab.tone, { 'is-on': category === tab.key }]"
          @click="setCategory(tab.key)"
        >
          <text class="wallet-ledger-filter-ico">{{ tab.ico }}</text>
          <text class="wallet-ledger-filter-lab">{{ tab.label }}</text>
        </view>
        <view
          class="wallet-ledger-filter tone-more"
          :class="{ 'is-on': filtersExpanded || moreCatOn }"
          @click="toggleFiltersMore"
        >
          <text class="wallet-ledger-filter-ico">{{ filtersExpanded ? '▴' : '▾' }}</text>
          <text class="wallet-ledger-filter-lab">{{ filtersExpanded ? '收起' : '更多' }}</text>
        </view>
      </view>
      <view v-if="filtersExpanded" class="wallet-ledger-filters-row wallet-ledger-filters-more">
        <view
          v-for="tab in moreTabs"
          :key="tab.key"
          class="wallet-ledger-filter"
          :class="['tone-' + tab.tone, { 'is-on': category === tab.key }]"
          @click="setCategory(tab.key)"
        >
          <text class="wallet-ledger-filter-ico">{{ tab.ico }}</text>
          <text class="wallet-ledger-filter-lab">{{ tab.label }}</text>
        </view>
      </view>
    </view>

    <view class="wallet-ledger-list" v-if="list.length">
      <view
        v-for="item in list"
        :key="rowKey(item)"
        class="wallet-ledger-item"
        :class="{ 'is-rp': canOpenRp(item) || canOpenNn(item) }"
        @click="onItemClick(item)"
      >
        <view class="wallet-ledger-main">
          <view class="wallet-ledger-title">
            {{ typeTitle(item) }}
          </view>
          <view class="wallet-ledger-sub" v-if="item.remark && item.remark !== typeTitle(item)">{{ item.remark }}</view>
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
            @click.stop="openRp(item)"
          >查看红宝记录</view>
          <view
            v-else-if="canOpenNn(item)"
            class="wallet-ledger-rp-link"
            @click.stop="openNn(item)"
          >查看牛牛明细</view>
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
  </ProfileSubPage>
</template>

<script setup>
import ProfileSubPage from '../../components/ProfileSubPage.vue'
import { ref, computed } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { getToken } from '../../utils/auth.js'
import { fetchLedger, ledgerAmountText, money } from '../../utils/wallet.js'
import { tt } from '../../utils/i18n.js'
import '../../styles/hb.css'

function goBack() {
  safeNavigateBack(HOME_TAB)
}

function typeTitle(item) {
  const type = String((item && item.type) || '')
  if (type) {
    const key = 'wallet_ledger_type_' + type
    const translated = tt(key, '')
    if (translated && translated !== key) return translated
  }
  const lab = String((item && (item.type_label || item.title || item.type_text)) || '')
  if (lab && lab !== type) return lab
  return type || '变动'
}

const list = ref([])
const page = ref(1)
const hasMore = ref(false)
const loading = ref(false)
const error = ref('')
const category = ref('all')
const filtersExpanded = ref(false)
const primaryTabs = [
  { key: 'all', label: '全部', ico: '☰', tone: 'all' },
  { key: 'recharge', label: '充值', ico: '↓', tone: 'in' },
  { key: 'withdraw', label: '提现', ico: '↑', tone: 'out' },
]
const moreTabs = [
  { key: 'rights', label: '股份', ico: '股', tone: 'rights' },
  { key: 'hongbao_in', label: '红宝入账', ico: '🧧', tone: 'hb' },
  { key: 'hongbao_niuniu', label: '红宝牛牛', ico: '🐂', tone: 'nn' },
  { key: 'refund', label: '红宝退回', ico: '↩', tone: 'back' },
  { key: 'rebate', label: '红宝返佣', ico: '%', tone: 'rebate' },
]
const moreCatKeys = moreTabs.map((t) => t.key)
const moreCatOn = computed(() => moreCatKeys.indexOf(category.value) >= 0)

function toggleFiltersMore() {
  filtersExpanded.value = !filtersExpanded.value
}
const emptyText = computed(() => {
  if (category.value === 'rights') return '暂无股份流水'
  if (category.value === 'rebate') return '暂无红宝返佣流水'
  if (category.value === 'hongbao_in') return '暂无红宝入账流水'
  if (category.value === 'hongbao_niuniu') return '暂无红宝牛牛流水'
  if (category.value === 'refund') return '暂无红宝退回流水'
  if (category.value === 'freeze') return '暂无冻结记录'
  if (category.value === 'recharge') return '暂无充值流水'
  if (category.value === 'withdraw') return '暂无提现流水'
  return '暂无资金流水'
})

function rowKey(item) {
  return (
    item.id ||
    item.createtime +
      '-' +
      (item.rights_change || 0) +
      '-' +
      (item.hongbao_change || item.balance_change || 0)
  )
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
  return ledgerAmountText(item, { category: category.value }).text
}
function amountCls(item) {
  return ledgerAmountText(item, { category: category.value }).cls
}
function afterText(item) {
  const rights = parseFloat(item && item.rights_change) || 0
  // 股份 Tab：结余只看股份
  if (category.value === 'rights') {
    if (item.rights_after != null && item.rights_after !== '') {
      return Number(item.rights_after).toFixed(2) + '股'
    }
    return ''
  }
  // 有股份变动时优先展示股份结余（同笔红宝结余不混显）
  if (Math.abs(rights) > 1e-9 && item.rights_after != null && item.rights_after !== '') {
    return Number(item.rights_after).toFixed(2) + '股'
  }
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

function canOpenNn(item) {
  if (!item) return false
  if (item.can_open_niuniu && Number(item.round_id) > 0) return true
  const typ = String(item.type || '')
  if (typ.indexOf('niuniu_') === 0) {
    return Number(item.round_id || 0) > 0
  }
  return false
}

function openRp(item) {
  const pid = Number(item.packet_id || item.ref_id || 0) || 0
  const pno = String(item.packet_no || item.biz_no || '').trim()
  let url = '/pages/wallet/rp-detail?'
  if (pid > 0) url += 'packet_id=' + pid
  if (pno) url += (pid > 0 ? '&' : '') + 'packet_no=' + encodeURIComponent(pno)
  uni.navigateTo({ url })
}

function openNn(item) {
  const rid = Number(item.round_id || 0) || 0
  if (rid <= 0) return
  uni.navigateTo({ url: '/pages/wallet/nn-detail?round_id=' + rid })
}

function onItemClick(item) {
  if (canOpenRp(item)) {
    openRp(item)
    return
  }
  if (canOpenNn(item)) {
    openNn(item)
  }
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
    const beforeId =
      append && list.value.length ? Number(list.value[list.value.length - 1].id) || 0 : 0
    const data = await fetchLedger(p, 20, category.value, beforeId)
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
  if (moreCatKeys.indexOf(cat) >= 0) filtersExpanded.value = true
  if (category.value === cat) return
  category.value = cat
  list.value = []
  load(1, false)
}

onShow(() => {
  if (moreCatOn.value) filtersExpanded.value = true
  list.value = []
  load(1, false)
})
</script>

<style scoped>
.wallet-ledger-filters {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin: 0 0 14px;
  padding: 0;
  overflow: visible;
}
.wallet-ledger-filters-row {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 6px;
  width: 100%;
  box-sizing: border-box;
}
.wallet-ledger-filter {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  min-width: 0;
  width: 100%;
  box-sizing: border-box;
  border: 1px solid #e8ecf1;
  background: #fff;
  color: #5a6573;
  border-radius: 10px;
  padding: 8px 2px;
  font-size: 11px;
  font-weight: 700;
  box-shadow: 0 2px 8px rgba(26, 33, 45, 0.04);
}
.wallet-ledger-filter-ico {
  font-size: 12px;
  line-height: 1;
  opacity: 0.9;
}
.wallet-ledger-filter-lab {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 100%;
  font-size: 11px;
  line-height: 1.2;
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
.wallet-ledger-filter.tone-rebate.is-on,
.wallet-ledger-filter.tone-nn.is-on,
.wallet-ledger-filter.tone-rights.is-on {
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
.wallet-ledger-filter.tone-more.is-on {
  background: linear-gradient(135deg, #607d8b, #455a64);
  border-color: transparent;
  color: #fff;
  box-shadow: 0 6px 14px rgba(69, 90, 100, 0.26);
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

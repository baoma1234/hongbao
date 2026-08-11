<template>
  <view class="hb-page profile-sub-page">
    <TopBar />
    <view class="profile-sub-hd">
      <text class="profile-back-btn" @click="goBack">‹</text>
      <text class="profile-sub-title">领取明细</text>
      <text class="profile-sub-spacer" />
    </view>
    <view class="profile-sub-body hb-sub nn-detail-main">
      <view v-if="loading" class="nn-detail-empty">加载中…</view>
      <view v-else-if="error" class="wallet-warn" style="text-align:center">{{ error }}</view>
      <template v-else-if="detail">
        <view class="nn-detail-summary">
          <text>奖池 {{ poolText }}</text>
          <text class="nn-detail-verify" @click="openFair">本站验证 ›</text>
        </view>
        <view class="nn-detail-frame">
          <view class="nn-detail-head">
            <text class="nn-dh-av">头像</text>
            <text class="nn-dh-name">昵称</text>
            <text class="nn-dh-amt">金额</text>
            <text class="nn-dh-res">结果</text>
            <text class="nn-dh-win">奖金</text>
          </view>
          <view class="nn-detail-scroll">
            <view
              v-for="(row, idx) in shares"
              :key="row.id || row.user_id || idx"
              class="nn-detail-person"
              :class="{ mine: row.is_mine }"
            >
              <view class="nn-dh-av">
                <image class="nn-user-av" :src="avatarSrc(row.avatar)" mode="aspectFill" />
              </view>
              <text class="nn-dh-name">{{ row.is_mine ? '我' : (row.nickname || ('用户' + row.user_id)) }}</text>
              <text class="nn-dh-amt">{{ formatPacketAmount(row) }}</text>
              <view class="nn-dh-res-wrap">
                <text class="nn-dh-res">{{ formatResultShort(row) }}</text>
                <text v-if="row.claimed_at" class="nn-dh-time">{{ formatTime(row.claimed_at) }}</text>
                <text v-else class="nn-dh-time">未领取</text>
              </view>
              <text class="nn-dh-win" :class="{ win: Number(row.win_amount) > 0 }">{{ formatBonus(row.win_amount) }}</text>
            </view>
            <view v-if="!shares.length" class="nn-detail-empty">暂无明细</view>
          </view>
        </view>
      </template>
    </view>
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import { safeNavigateBack } from '../../utils/nav.js'
import { apiRequest, getToken } from '../../utils/auth.js'
import { avatarSrc } from '../../utils/chat.js'
import '../../styles/hb.css'

function goBack() {
  safeNavigateBack('/pages/wallet/ledger')
}

const loading = ref(true)
const error = ref('')
const detail = ref(null)
const queryRoundId = ref(0)

const round = computed(() => (detail.value && detail.value.round) || {})
const shares = computed(() => (detail.value && detail.value.shares) || [])
const poolText = computed(() => {
  const n = Number(round.value.pool_amount)
  if (isNaN(n) || n <= 0) return '0'
  return String(Math.round(n * 100) / 100)
})

function formatTime(ts) {
  const t = Number(ts) || 0
  if (!t) return ''
  const d = new Date(t < 1e12 ? t * 1000 : t)
  if (isNaN(d.getTime())) return ''
  const p = (n) => (n < 10 ? '0' + n : '' + n)
  return p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds())
}

function formatBonus(v) {
  const n = Number(v)
  if (isNaN(n) || n <= 0) return '0'
  return String(Math.round(n * 10000) / 10000)
}

function formatPacketAmount(row) {
  if (!row) return '--'
  if (!row.claimed) return '--'
  if (row.amount != null && row.amount !== '' && !isNaN(Number(row.amount))) {
    return (Math.round(Number(row.amount) * 100) / 100).toFixed(2)
  }
  const tail = row.tail_digits != null && row.tail_digits !== '' ? String(row.tail_digits) : ''
  if (!tail) return '--'
  const n = parseInt(String(tail).replace(/\D/g, '').slice(-2) || '0', 10)
  return (Math.round(n) / 100).toFixed(2)
}

function formatResultShort(row) {
  if (!row) return '--'
  if (!row.claimed) return '未领取'
  const tail = row.tail_digits != null && row.tail_digits !== '' ? String(row.tail_digits) : ''
  const niu = row.niu_label || row.niu_type || row.category || ''
  if (tail) return tail + (niu ? ' ' + niu : '')
  if (row.result && String(row.result) !== '未领取') {
    return String(row.result).replace(/^尾数/, '')
  }
  return '--'
}

function openFair() {
  const rid = Number(round.value.id || queryRoundId.value) || 0
  if (!rid) return
  uni.navigateTo({
    url: '/pages/common/fair-verify?kind=niuniu&round_id=' + encodeURIComponent(String(rid)),
  })
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    if (!getToken()) {
      uni.reLaunch({ url: '/pages/login/login' })
      return
    }
    const data = await apiRequest('nndetail', 'GET', { round_id: queryRoundId.value })
    detail.value = data || null
    if (!detail.value) error.value = '无数据'
  } catch (e) {
    error.value = (e && e.message) || '加载失败'
    detail.value = null
  } finally {
    loading.value = false
  }
}

onLoad((q) => {
  queryRoundId.value = parseInt(String((q && q.round_id) || '0'), 10) || 0
  if (!queryRoundId.value) {
    loading.value = false
    error.value = '缺少局号'
    return
  }
  load()
})
</script>

<style scoped>
.nn-detail-main {
  display: flex;
  flex-direction: column;
  min-height: 0;
  background: #f7f8fa;
  overflow-x: hidden;
  width: 100%;
  box-sizing: border-box;
  padding-bottom: 0;
}
.nn-detail-summary {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 10px 14px;
  padding: 12px 14px;
  background: #fff;
  border-bottom: 1px solid #eef0f3;
  border-radius: 12px 12px 0 0;
  font-size: 13px;
  font-weight: 800;
  color: #c62828;
  flex-shrink: 0;
  box-sizing: border-box;
}
.nn-detail-verify {
  color: #1565c0;
  font-weight: 700;
}
.nn-detail-frame {
  flex: 1;
  min-height: 0;
  display: flex;
  flex-direction: column;
  width: 100%;
  padding: 0 10px 16px;
  box-sizing: border-box;
  overflow-x: hidden;
}
.nn-detail-head,
.nn-detail-person {
  display: grid;
  grid-template-columns: 40px minmax(0, 1fr) 52px minmax(0, 1.35fr) 52px;
  align-items: center;
  column-gap: 6px;
  padding: 10px 6px;
  box-sizing: border-box;
  width: 100%;
  max-width: 100%;
  overflow: hidden;
}
.nn-detail-head {
  flex-shrink: 0;
  font-size: 12px;
  font-weight: 800;
  color: #888;
  background: #fff;
  border-radius: 10px 10px 0 0;
  border-bottom: 1px solid #eef0f3;
  margin-top: 6px;
}
.nn-detail-scroll {
  flex: 1;
  min-height: 0;
  width: 100%;
  max-width: 100%;
  overflow-x: hidden;
  box-sizing: border-box;
  padding-bottom: calc(24px + env(safe-area-inset-bottom, 0px));
}
.nn-detail-person {
  background: #fff;
  border-radius: 10px;
  border: 1px solid #e8ebf0;
  margin-top: 6px;
  font-size: 13px;
  color: #333;
}
.nn-detail-person.mine {
  border-color: #f0c36d;
  background: linear-gradient(180deg, #fff8e8, #fff);
  box-shadow: inset 0 0 0 1px rgba(212, 136, 6, 0.12);
}
.nn-dh-amt,
.nn-dh-win {
  text-align: center;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.nn-dh-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 13px;
  font-weight: 700;
  min-width: 0;
}
.nn-dh-res-wrap {
  min-width: 0;
  max-width: 100%;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 2px;
  overflow: hidden;
}
.nn-dh-res {
  font-size: 13px;
  font-weight: 800;
  color: #333;
  line-height: 1.2;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.nn-dh-time {
  font-size: 11px;
  color: #999;
  font-weight: 500;
  line-height: 1.2;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.nn-dh-av {
  display: flex;
  align-items: center;
  justify-content: center;
}
.nn-user-av {
  width: 30px;
  height: 30px;
  border-radius: 50%;
  background: #eee;
  flex-shrink: 0;
}
.nn-detail-person.mine .nn-dh-name {
  color: #d48806;
  font-weight: 800;
}
.nn-dh-amt {
  font-weight: 800;
  color: #c62828;
}
.nn-dh-win {
  font-weight: 800;
  color: #888;
}
.nn-dh-win.win {
  color: #c62828;
}
.nn-detail-empty {
  text-align: center;
  color: #999;
  padding: 40px 0;
  font-size: 13px;
}
</style>

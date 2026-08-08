<template>
  <view class="hb-page profile-sub-page">
    <TopBar :no-spacer="true" />
    <view class="profile-sub-hd">
      <text class="profile-back-btn" @click="goBack">‹</text>
      <text class="profile-sub-title">红包详情</text>
      <text class="profile-sub-spacer" />
    </view>
    <view class="profile-sub-body hb-sub">
      <view v-if="loading" class="wallet-ledger-empty">加载中…</view>
      <view v-else-if="error" class="wallet-warn" style="text-align:center">{{ error }}</view>
      <view v-else-if="detail" class="rp-detail">
        <view class="rp-detail-head">
          <text class="rp-detail-bless">{{ blessTitle }}</text>
          <text class="rp-detail-meta">
            {{ typeLabel }} · 共 {{ totalCount }} 个 · ￥{{ totalAmt }}
          </text>
          <text v-if="sendTime" class="rp-detail-time">发送时间 {{ sendTime }}</text>
          <text v-if="displayPacketNo" class="rp-detail-no">单号 {{ displayPacketNo }}</text>
          <view v-if="myAmtText" class="rp-detail-my">
            你领取了 <text class="rp-detail-my-amt">￥{{ myAmtText }}</text>
          </view>
          <text v-if="mineDigitText" class="rp-detail-meta">{{ mineDigitText }}</text>
          <text v-if="!finished" class="rp-detail-tip">红包进行中，他人领取暂未公开</text>
        </view>

        <view class="rp-detail-list">
          <view v-for="r in records" :key="r.id || r.user_id" class="rp-detail-item">
            <image class="rp-detail-avatar" :src="avatarSrc(r.avatar)" mode="aspectFill" />
            <view class="rp-detail-main">
              <text class="rp-detail-name">{{ r.nickname || ('用户' + r.user_id) }}</text>
              <text class="rp-detail-amt">
                ￥{{ formatAmt(r.amount) }}
                <text v-if="r.is_best"> · 手气最佳</text>
                <text v-if="r.is_worst"> · 手气最差</text>
                <text v-if="r.is_mine_hit" class="is-hit"> · 中雷</text>
              </text>
              <text v-if="r.createtime" class="rp-detail-time">领取时间 {{ formatTime(r.createtime) }}</text>
            </view>
          </view>
          <view v-if="!records.length" class="wallet-ledger-empty">暂无领取记录</view>
        </view>

        <button
          v-if="canFair"
          type="button"
          class="btn-uid-submit"
          style="margin-top:16px"
          @click="openFair"
        >本站验证详情</button>
      </view>
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
const queryPacketId = ref(0)
const queryPacketNo = ref('')

const packet = computed(() => (detail.value && detail.value.packet) || {})
const records = computed(() => (detail.value && detail.value.records) || [])
const finished = computed(() => !!(detail.value && detail.value.finished))
const canFair = computed(() => !!(detail.value && detail.value.can_fair_verify))
const typeLabel = computed(() => packet.value.type_label || '红包')
const totalCount = computed(() => Number(packet.value.total_count || 0))
const totalAmt = computed(() => formatAmt(packet.value.total_amount))
const displayPacketNo = computed(() => String(packet.value.packet_no || queryPacketNo.value || ''))
const sendTime = computed(() => formatTime(packet.value.createtime))
const blessTitle = computed(() => {
  const b = String(packet.value.blessing || '').trim()
  const name = packet.value.from_nickname || '好友'
  return b || (name + '的红包')
})
const myAmtText = computed(() => {
  const m = detail.value && detail.value.mine
  return m && m.amount != null ? formatAmt(m.amount) : ''
})
const mineDigitText = computed(() => {
  if (Number(packet.value.packet_type) !== 3) return ''
  const d = packet.value.mine_digit
  return d == null || d === '' ? '' : ('埋雷数字：' + d)
})

function formatAmt(v) {
  const n = Number(v)
  if (!isFinite(n)) return '0.00'
  return n.toFixed(2)
}

function formatTime(ts) {
  ts = Number(ts || 0)
  if (!ts) return ''
  if (ts > 1e12) ts = Math.floor(ts / 1000)
  const d = new Date(ts * 1000)
  const pad = (n) => (n < 10 ? '0' + n : '' + n)
  return (
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
  )
}

function openFair() {
  const no = String(packet.value.packet_no || queryPacketNo.value || '').trim()
  if (!no) {
    uni.showToast({ title: '无红包单号', icon: 'none' })
    return
  }
  uni.navigateTo({ url: '/pages/common/fair-verify?packet_no=' + encodeURIComponent(no) })
}

async function load() {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  loading.value = true
  error.value = ''
  try {
    const params = {}
    if (queryPacketId.value > 0) params.packet_id = queryPacketId.value
    if (queryPacketNo.value) params.packet_no = queryPacketNo.value
    const data = await apiRequest('rpdetail', 'GET', params)
    detail.value = data || null
    if (!data) error.value = '红包不存在'
  } catch (e) {
    error.value = (e && e.message) || '加载失败'
    detail.value = null
  } finally {
    loading.value = false
  }
}

onLoad((q) => {
  queryPacketId.value = Number((q && (q.packet_id || q.id)) || 0) || 0
  queryPacketNo.value = decodeURIComponent(String((q && (q.packet_no || q.no)) || '').trim())
  if (!queryPacketId.value && !queryPacketNo.value) {
    error.value = '缺少红包参数'
    loading.value = false
    return
  }
  load()
})
</script>

<style scoped>
.rp-detail-head {
  text-align: center;
  padding: 8px 8px 18px;
  background: linear-gradient(180deg, #fff5e8, #fff);
  border-radius: 14px;
  margin-bottom: 12px;
  border: 1px solid #f0e2d0;
}
.rp-detail-bless {
  display: block;
  font-size: 18px;
  font-weight: 800;
  color: #222;
  margin-bottom: 8px;
}
.rp-detail-meta,
.rp-detail-time,
.rp-detail-no,
.rp-detail-tip {
  display: block;
  font-size: 12px;
  color: #889;
  line-height: 1.6;
}
.rp-detail-my {
  margin-top: 10px;
  font-size: 14px;
  color: #333;
}
.rp-detail-my-amt {
  font-size: 22px;
  font-weight: 800;
  color: #c62828;
}
.rp-detail-list {
  background: #fff;
  border-radius: 14px;
  border: 1px solid #eef1f5;
  overflow: hidden;
}
.rp-detail-item {
  display: flex;
  gap: 10px;
  padding: 12px;
  border-bottom: 1px solid #f2f4f7;
}
.rp-detail-item:last-child {
  border-bottom: none;
}
.rp-detail-avatar {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background: #eee;
  flex: 0 0 auto;
}
.rp-detail-main {
  flex: 1;
  min-width: 0;
}
.rp-detail-name {
  display: block;
  font-size: 14px;
  font-weight: 700;
  color: #222;
}
.rp-detail-amt {
  display: block;
  font-size: 13px;
  color: #444;
  margin-top: 2px;
}
.rp-detail-amt .is-hit {
  color: #c62828;
  font-weight: 700;
}
</style>

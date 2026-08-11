<template>
  <view class="hb-page profile-sub-page">
    <TopBar />
    <view class="profile-sub-hd">
      <text class="profile-back-btn" @click="goBack">‹</text>
      <text class="profile-sub-title">牛牛明细</text>
      <text class="profile-sub-spacer" />
    </view>
    <view class="profile-sub-body hb-sub">
      <view v-if="loading" class="wallet-ledger-empty">加载中…</view>
      <view v-else-if="error" class="wallet-warn" style="text-align:center">{{ error }}</view>
      <view v-else-if="detail" class="rp-detail">
        <view class="rp-detail-head">
          <text class="rp-detail-bless">尾数牛牛 #{{ round.id }}</text>
          <text class="rp-detail-meta">
            {{ round.status_label || '' }} · {{ round.share_count || 0 }} 份 · 奖池 ￥{{ money(round.pool_amount) }}
          </text>
          <text v-if="createTime" class="rp-detail-time">开局 {{ createTime }}</text>
        </view>

        <view class="rp-detail-list">
          <view v-for="r in shares" :key="r.id || r.user_id" class="rp-detail-item">
            <image class="rp-detail-avatar" :src="avatarSrc(r.avatar)" mode="aspectFill" />
            <view class="rp-detail-main">
              <text class="rp-detail-name">
                {{ r.nickname || ('用户' + r.user_id) }}
                <text v-if="r.is_mine"> · 我</text>
              </text>
              <text class="rp-detail-amt">
                <text v-if="r.claimed">尾数 {{ r.tail_digits || '--' }} · {{ r.niu_type || '-' }}</text>
                <text v-else>未领取</text>
                <text v-if="Number(r.win_amount) > 0"> · 奖金 ￥{{ money(r.win_amount) }}</text>
                <text v-else-if="Number(r.amount) > 0"> · 红包 ￥{{ money(r.amount) }}</text>
              </text>
              <text v-if="r.claimed_at" class="rp-detail-time">领取 {{ formatTime(r.claimed_at) }}</text>
            </view>
          </view>
          <view v-if="!shares.length" class="wallet-ledger-empty">暂无明细</view>
        </view>

        <button type="button" class="btn-uid-submit" style="margin-top:16px" @click="openFair">本站验证详情</button>
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
import { money } from '../../utils/wallet.js'
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
const createTime = computed(() => formatTime(round.value.createtime))

function formatTime(ts) {
  const t = Number(ts) || 0
  if (!t) return ''
  const d = new Date(t < 1e12 ? t * 1000 : t)
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
    p(d.getMinutes())
  )
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
.rp-detail-head {
  background: #fff;
  border-radius: 14px;
  padding: 16px;
  margin-bottom: 12px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.rp-detail-bless {
  font-size: 18px;
  font-weight: 800;
  color: #222;
}
.rp-detail-meta,
.rp-detail-time {
  font-size: 12px;
  color: #888;
}
.rp-detail-list {
  background: #fff;
  border-radius: 14px;
  overflow: hidden;
}
.rp-detail-item {
  display: flex;
  gap: 10px;
  padding: 12px 14px;
  border-bottom: 1px solid #f0f0f0;
}
.rp-detail-avatar {
  width: 40px;
  height: 40px;
  border-radius: 10px;
  flex-shrink: 0;
  background: #f5f5f5;
}
.rp-detail-main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 3px;
}
.rp-detail-name {
  font-size: 14px;
  font-weight: 700;
  color: #222;
}
.rp-detail-amt {
  font-size: 12px;
  color: #555;
}
</style>

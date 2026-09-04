<template>
  <ProfileSubPage title="领取记录" body-class="hb-sub" back-fallback="/pages/fission/detail">
    <view v-if="loading" class="wallet-ledger-empty">加载中…</view>
    <view v-else-if="error" class="wallet-warn" style="text-align:center">{{ error }}</view>
    <view v-else class="rp-detail">
      <view class="rp-detail-head">
        <text class="rp-detail-bless">奖金池领取记录</text>
        <text class="rp-detail-meta">
          奖金池 ￥{{ formatAmt(summary.pool_amount) }} · 余额 ￥{{ formatAmt(summary.remain_balance) }}
        </text>
        <text class="rp-detail-meta">
          已领 {{ summary.claimed_count || 0 }} 份 · ￥{{ formatAmt(summary.claimed_amount) }}
        </text>
        <text class="rp-detail-meta">
          待领 {{ summary.unclaimed_count || 0 }} 份 · ￥{{ formatAmt(summary.unclaimed_amount) }}
        </text>
        <text v-if="actTitle" class="rp-detail-time">{{ actTitle }}</text>
      </view>

      <view class="rp-detail-list">
        <view v-for="r in list" :key="r.id || r.user_id" class="rp-detail-item">
          <image class="rp-detail-avatar" :src="avatarSrc(r.avatar)" mode="aspectFill" />
          <view class="rp-detail-main">
            <text class="rp-detail-name">{{ r.nickname || ('用户' + r.user_id) }}</text>
            <text class="rp-detail-amt">
              ￥{{ formatAmt(r.amount) }}
              <text v-if="r.claimed" class="is-claimed"> · 已领取</text>
              <text v-else class="is-pending"> · 待领取</text>
            </text>
            <text v-if="r.claimed && r.claimed_at" class="rp-detail-time">
              领取时间 {{ formatTime(r.claimed_at) }}
            </text>
            <text v-else-if="r.createtime" class="rp-detail-time">
              获得资格 {{ formatTime(r.createtime) }}
            </text>
          </view>
        </view>
        <view v-if="!list.length" class="wallet-ledger-empty">暂无领取记录</view>
      </view>
    </view>
  </ProfileSubPage>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import ProfileSubPage from '../../components/ProfileSubPage.vue'
import { apiRequest } from '../../utils/auth.js'
import { avatarSrc } from '../../utils/chat.js'
import '../../styles/hb.css'

const loading = ref(true)
const error = ref('')
const payload = ref(null)
const activityId = ref(0)

const summary = computed(() => (payload.value && payload.value.summary) || {})
const list = computed(() => (payload.value && payload.value.list) || [])
const actTitle = computed(() => {
  const a = (payload.value && payload.value.activity) || {}
  return String(a.title || a.name || '').trim()
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

async function load() {
  loading.value = true
  error.value = ''
  try {
    const data = await apiRequest(
      'fissionclaims',
      'GET',
      { activity_id: activityId.value || 0 },
      { skipAuthRedirect: true }
    )
    payload.value = data || null
    if (!data || data.has_activity === false) {
      error.value = '暂无活动记录'
    }
  } catch (e) {
    error.value = (e && e.message) || '加载失败'
    payload.value = null
  } finally {
    loading.value = false
  }
}

onLoad((q) => {
  activityId.value = Number((q && (q.activity_id || q.id)) || 0) || 0
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
.rp-detail-time {
  display: block;
  font-size: 12px;
  color: #889;
  line-height: 1.6;
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
.rp-detail-amt .is-claimed {
  color: #c62828;
  font-weight: 700;
}
.rp-detail-amt .is-pending {
  color: #889;
}
</style>

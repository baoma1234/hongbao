<template>
  <ProfileSubPage title="领取明细" body-class="hb-sub nn-detail-main" back-fallback="/pages/wallet/ledger">
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
            <text class="nn-dh-amt">金额/份</text>
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
              <text class="nn-dh-amt">{{ formatAmountShares(row) }}</text>
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
  </ProfileSubPage>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import ProfileSubPage from '../../components/ProfileSubPage.vue'
import { apiRequest, getToken } from '../../utils/auth.js'
import { avatarSrc } from '../../utils/chat.js'
import '../../styles/hb.css'

const loading = ref(true)
const error = ref('')
const detail = ref(null)
const queryRoundId = ref(0)

const round = computed(() => (detail.value && detail.value.round) || {})
const poolText = computed(() => {
  const n = Number(round.value.pool_amount)
  if (isNaN(n) || n <= 0) return '0'
  return String(Math.round(n * 100) / 100)
})

/** 与聊天领取明细一致：同一用户只展示一行（多份合并奖金） */
function calcNiuLabelFromTail(tail) {
  const s = String(tail || '').replace(/\D/g, '').padStart(2, '0').slice(-2)
  if (!s || s.length < 2) return ''
  const a = parseInt(s[0], 10)
  const b = parseInt(s[1], 10)
  if (isNaN(a) || isNaN(b)) return ''
  const point = (a + b) % 10
  return point === 0 ? '牛牛' : '牛' + point
}

function resolveNiuLabel(row) {
  if (!row) return ''
  let niu = String(row.niu_label || row.niu_type || row.category || '').trim()
  if (niu && niu !== '未领取') return niu
  const pt = Number(row.niu_point)
  if (!isNaN(pt) && pt >= 0 && pt <= 9) return pt === 0 ? '牛牛' : '牛' + pt
  if (row.result && String(row.result) !== '未领取') {
    const m = String(row.result).replace(/^尾数/, '').trim().match(/^\S+\s+(.+)$/)
    if (m && m[1]) return m[1].trim()
  }
  const tail = row.tail_digits != null && row.tail_digits !== '' ? String(row.tail_digits) : ''
  if (tail) return calcNiuLabelFromTail(tail)
  return ''
}

function mergeSharesByUser(list) {
  const rows = Array.isArray(list) ? list.filter(Boolean) : []
  const map = new Map()
  rows.forEach((s) => {
    const id = (Number(s.user_id) || 0) | 0
    const key = id > 0 ? 'u' + id : 'r' + (Number(s.id) || map.size)
    if (!map.has(key)) {
      map.set(
        key,
        Object.assign({}, s, {
          share_count: (Number(s.share_count) || 0) | 0 || 1,
          win_amount: Number(s.win_amount) || 0,
          claimed_at: (Number(s.claimed_at) || 0) | 0,
          claim_seq: (Number(s.claim_seq) || 0) | 0,
          niu_label: s.niu_label || s.niu_type || '',
          niu_type: s.niu_type || s.niu_label || '',
        })
      )
      return
    }
    const g = map.get(key)
    g.share_count = ((Number(g.share_count) || 0) | 0 || 1) + 1
    g.win_amount = (Number(g.win_amount) || 0) + (Number(s.win_amount) || 0)
    const ca = (Number(s.claimed_at) || 0) | 0
    if (ca > 0 && (!(Number(g.claimed_at) || 0) || ca < (Number(g.claimed_at) || 0))) {
      g.claimed_at = ca
    }
    const cs = (Number(s.claim_seq) || 0) | 0
    if (cs > 0 && (!(Number(g.claim_seq) || 0) || cs < (Number(g.claim_seq) || 0))) {
      g.claim_seq = cs
      if (s.claimed && s.tail_digits) {
        g.tail_digits = s.tail_digits
        g.niu_label = s.niu_label || s.niu_type || g.niu_label
        g.niu_type = s.niu_type || s.niu_label || g.niu_type
        g.amount = s.amount != null ? s.amount : g.amount
        g.id = (Number(s.id) || 0) || g.id
        g.claimed = true
      }
    }
    if (!g.nickname && s.nickname) g.nickname = s.nickname
    if (!g.avatar && s.avatar) g.avatar = s.avatar
    if (s.claimed && (!g.tail_digits || g.tail_digits === '') && s.tail_digits != null && s.tail_digits !== '') {
      g.tail_digits = s.tail_digits
      g.niu_label = s.niu_label || s.niu_type || g.niu_label
      g.niu_type = s.niu_type || s.niu_label || g.niu_type
      g.amount = s.amount != null ? s.amount : g.amount
      g.id = (Number(s.id) || 0) || g.id
      g.claimed = true
    }
    if (s.is_mine) g.is_mine = true
  })
  return Array.from(map.values())
    .map((g) => {
      const out = Object.assign({}, g, {
        win_amount: Math.round((Number(g.win_amount) || 0) * 10000) / 10000,
      })
      const niu = resolveNiuLabel(out)
      if (niu) {
        out.niu_label = niu
        out.niu_type = niu
        out.category = niu
      }
      return out
    })
    .sort((a, b) => {
      const ca = a && a.claimed ? 0 : 1
      const cb = b && b.claimed ? 0 : 1
      if (ca !== cb) return ca - cb
      const ta = (Number(a && a.claimed_at) || 0) | 0
      const tb = (Number(b && b.claimed_at) || 0) | 0
      if (ta !== tb) return ta - tb
      const sa = (Number(a && a.claim_seq) || 0) | 0
      const sb = (Number(b && b.claim_seq) || 0) | 0
      if (sa !== sb) return sa - sb
      return ((Number(a && a.id) || 0) | 0) - ((Number(b && b.id) || 0) | 0)
    })
}

const shares = computed(() => mergeSharesByUser((detail.value && detail.value.shares) || []))

function formatTime(ts) {
  const t = Number(ts) || 0
  if (!t) return ''
  const d = new Date(t < 1e12 ? t * 1000 : t)
  if (isNaN(d.getTime())) return ''
  const p = (n) => (n < 10 ? '0' + n : '' + n)
  return (
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

/** 金额/份：如 0.25/5 */
function formatAmountShares(row) {
  const amt = formatPacketAmount(row)
  if (amt === '--') return '--'
  const shares = Math.max(1, (Number(row && (row.share_count || row.weight)) || 0) | 0)
  return amt + '/' + shares
}

function formatResultShort(row) {
  if (!row) return '--'
  if (!row.claimed) return '未领取'
  const tail = row.tail_digits != null && row.tail_digits !== '' ? String(row.tail_digits) : ''
  const niu = resolveNiuLabel(row)
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
  grid-template-columns: 40px minmax(0, 1fr) 64px minmax(0, 1.25fr) 52px;
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

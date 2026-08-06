<template>
  <view class="fv-page">
    <TopBar :no-spacer="true" />
    <view class="profile-sub-hd">
      <text class="profile-back-btn" @click="goBack">‹</text>
      <text class="profile-sub-title">波场官方哈希验证</text>
      <text class="profile-sub-spacer" />
    </view>
    <scroll-view scroll-y class="fv-scroll">
      <view class="fv-wrap">
        <view class="fv-h1">波场官方哈希验证</view>
        <view class="fv-sub">
          拼手气金额由「波场 Block Hash + 单号」链下拆分。扫雷另要求：哈希末位必须等于手填雷号后才拆包开抢；中雷看金额尾数。可在 TronScan / OKLink 核验。
        </view>

        <view class="fv-card">
          <text class="fv-label">红包单号 packet_no</text>
          <input class="fv-input" v-model="packetNo" placeholder="rp..." />
          <button type="button" class="fv-btn" :disabled="busy" @click="verify()">查询并验证</button>
          <button type="button" class="fv-btn fv-btn2" @click="goBack">返回红包详情</button>
          <view v-if="formErr" class="fv-err">{{ formErr }}</view>
        </view>

        <view v-if="result" class="fv-result">
          <view class="fv-card">
            <view class="fv-row"><text>玩法</text><text class="strong">{{ result.type_label || '—' }}</text></view>
            <view class="fv-row"><text>红包状态</text><text>{{ statusLabel(result.status) }}</text></view>
            <view class="fv-row"><text>波场开奖</text><text>{{ tronStatusLabel(result.tron_status) }}</text></view>
            <view class="fv-row">
              <text>可抢池</text>
              <text>¥{{ poolAmount }} / {{ (result.total_count || 0) }}个</text>
            </view>
            <view class="fv-row"><text>发包总额</text><text>¥{{ money(result.total_amount) }}</text></view>
            <view v-if="Number(result.packet_type || 0) === 3" class="fv-row">
              <text>手填雷号</text><text class="strong">{{ result.mine_digit != null ? result.mine_digit : '—' }}</text>
            </view>
          </view>

          <view class="fv-card">
            <view class="fv-sub tight">波场（TRON）官方区块哈希</view>
            <view class="fv-row"><text>官方区块高度</text><text class="strong">{{ blockNum || '—' }}</text></view>
            <view v-if="lucky" class="fv-row"><text>哈希末位字符</text><text class="strong">{{ lucky }}</text></view>
            <view v-if="result.revealed && result.lucky_digit != null" class="fv-row">
              <text>末位映射 0-9</text><text class="strong">{{ result.lucky_digit }}</text>
            </view>
            <text class="fv-label mt">Block Hash</text>
            <view class="fv-mono">{{ blockId || '尚未出块，请稍后刷新' }}</view>
            <view v-if="result.verify_hint" class="fv-sub">{{ result.verify_hint }}</view>
            <view v-if="tronscan" class="fv-link" @click="openUrl(tronscan)">前往 TronScan 官方核实</view>
            <view
              v-if="blockNum || blockId"
              class="fv-link dark"
              @click="openUrl('https://www.oklink.com/zh-hans/tron/block/' + encodeURIComponent(String(blockNum || blockId)))"
            >前往 OKLink 核实</view>
            <view v-if="!result.revealed" class="fv-wait-row">
              <text class="fv-sub">尚未绑定波场哈希；页面将自动重试</text>
              <text class="fv-tag wait">待开奖</text>
            </view>
            <text v-else class="fv-tag ok">波场哈希已公开</text>
          </view>

          <view v-if="result.revealed" class="fv-card">
            <view class="fv-sub tight"><text class="strong">金额验证</text>（哈希 + 单号链下复算）</view>
            <view class="fv-row">
              <text>总体结果</text>
              <text :class="av.ok ? 'fv-tag ok' : 'fv-tag bad'">{{ av.ok ? '金额校验通过' : '金额校验失败' }}</text>
            </view>
            <view class="fv-row">
              <text>复算合计=可抢池</text>
              <text :class="av.sum_ok ? 'fv-tag ok' : 'fv-tag bad'">{{ av.sum_ok ? '一致' : '不一致' }}</text>
            </view>
            <view class="fv-row">
              <text>与入库拆包序列</text>
              <text v-if="av.has_stored" :class="av.match_stored ? 'fv-tag ok' : 'fv-tag bad'">
                {{ av.match_stored ? '完全一致' : '不一致' }}
              </text>
              <text v-else class="fv-tag wait">无入库序列（旧包）</text>
            </view>
            <view class="fv-row">
              <text>与实际领取顺序</text>
              <text v-if="av.has_grabs" :class="av.match_grabs ? 'fv-tag ok' : 'fv-tag bad'">
                {{ av.match_grabs ? '前缀一致' : '不一致' }}
              </text>
              <text v-else class="fv-tag wait">尚无领取</text>
            </view>
            <view v-if="Number(result.packet_type || 0) === 3" class="fv-row">
              <text>哈希末位=手填雷号</text>
              <text :class="av.mine_digit_match ? 'fv-tag ok' : 'fv-tag bad'">
                {{ av.mine_digit_match ? ('已匹配 ' + result.mine_digit) : '不匹配' }}
              </text>
            </view>
            <text class="fv-label mt">链下复算金额序列</text>
            <view class="fv-cents">
              <text v-for="(c, i) in computedCents" :key="'c' + i" class="fv-cent">#{{ i + 1 }} ¥{{ (c / 100).toFixed(2) }}</text>
              <text v-if="!computedCents.length" class="fv-sub">—</text>
            </view>
            <template v-if="storedCents.length">
              <text class="fv-label mt">入库 fair_cents</text>
              <view class="fv-cents">
                <text v-for="(c, i) in storedCents" :key="'s' + i" class="fv-cent">#{{ i + 1 }} ¥{{ (c / 100).toFixed(2) }}</text>
              </view>
            </template>
            <template v-if="grabCents.length">
              <text class="fv-label mt">实际领取（按抢包顺序）</text>
              <view class="fv-cents">
                <text v-for="(c, i) in grabCents" :key="'g' + i" class="fv-cent">#{{ i + 1 }} ¥{{ (c / 100).toFixed(2) }}</text>
              </view>
            </template>
          </view>
        </view>
      </view>
    </scroll-view>
  </view>
</template>

<script setup>
import { computed, onUnmounted, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import { apiRequest, getToken } from '../../utils/auth.js'
import '../../styles/hb.css'

const packetNo = ref('')
const packetId = ref(0)
const formErr = ref('')
const result = ref(null)
const busy = ref(false)
let retryTimer = null

const av = computed(() => (result.value && result.value.amount_verify) || {})
const blockNum = computed(() => {
  const d = result.value || {}
  return d.targetBlockNum || d.tron_block_num || 0
})
const blockId = computed(() => {
  const d = result.value || {}
  return d.block_id || d.tron_block_id || d.fair_hash || ''
})
const lucky = computed(() => {
  const d = result.value || {}
  return d.luckyNumber || d.tron_lucky || ''
})
const tronscan = computed(() => {
  const d = result.value || {}
  if (d.tronscan_url) return d.tronscan_url
  if (blockNum.value) return 'https://tronscan.org/#/block/' + blockNum.value
  if (blockId.value) return 'https://tronscan.org/#/block/' + blockId.value
  return ''
})
const poolAmount = computed(() => {
  const d = result.value || {}
  return money(d.pool_amount != null ? d.pool_amount : d.total_amount)
})
const computedCents = computed(() => (result.value && result.value.computed_cents) || [])
const storedCents = computed(() => (result.value && result.value.fair_cents) || [])
const grabCents = computed(() => (result.value && result.value.grab_cents) || [])

function money(n) {
  const v = Number(n)
  return (isFinite(v) ? v : 0).toFixed(2)
}

function statusLabel(st) {
  const m = { 1: '可抢', 2: '已抢完', 3: '已过期', 4: '已关闭', 5: '已结算' }
  return m[st] || ('status ' + st)
}

function tronStatusLabel(st) {
  const m = { 0: '未开奖', 1: '等待区块确认', 2: '已绑定波场哈希', 3: '拉取失败（可重试）' }
  return m[st | 0] || ''
}

function goBack() {
  uni.navigateBack({
    fail: () => uni.switchTab({ url: '/pages/messages/messages' }),
  })
}

function openUrl(url) {
  // #ifdef H5
  if (typeof window !== 'undefined') {
    window.open(url, '_blank')
    return
  }
  // #endif
  uni.setClipboardData({
    data: String(url || ''),
    success: () => uni.showToast({ title: '链接已复制', icon: 'none' }),
  })
}

async function verify(opts) {
  opts = opts || {}
  formErr.value = ''
  const no = String(packetNo.value || '').trim()
  if (!no) {
    formErr.value = '请输入红包单号'
    return
  }
  if (!getToken()) {
    formErr.value = '请先登录'
    return
  }
  busy.value = true
  try {
    const data = await apiRequest('rpfair', 'GET', { packet_no: no })
    result.value = data || {}
    if (retryTimer) {
      clearTimeout(retryTimer)
      retryTimer = null
    }
    if (!(data && data.revealed) && !opts.noRetry) {
      retryTimer = setTimeout(() => {
        verify({ noRetry: false }).catch(() => {})
      }, 3500)
    }
  } catch (e) {
    formErr.value = (e && e.message) || '网络错误'
    result.value = null
  } finally {
    busy.value = false
  }
}

onLoad((q) => {
  const no = decodeURIComponent(String((q && (q.packet_no || q.no)) || ''))
  const pid = parseInt(String((q && (q.packet_id || q.pid)) || '0'), 10) || 0
  if (no) packetNo.value = no
  if (pid > 0) packetId.value = pid
  if (packetNo.value) verify().catch(() => {})
})

onUnmounted(() => {
  if (retryTimer) clearTimeout(retryTimer)
})
</script>

<style scoped>
.fv-page {
  min-height: 100vh;
  background: #f5f6f8;
  display: flex;
  flex-direction: column;
}
.fv-scroll {
  flex: 1;
  height: 0;
}
.fv-wrap {
  max-width: 720px;
  margin: 0 auto;
  padding: 16px 14px 40px;
  box-sizing: border-box;
}
.fv-h1 {
  font-size: 20px;
  font-weight: 800;
  margin: 8px 0 4px;
  color: #1a1a1a;
}
.fv-sub {
  color: #666;
  font-size: 13px;
  margin-bottom: 16px;
  line-height: 1.5;
}
.fv-sub.tight {
  margin-top: 0;
  margin-bottom: 8px;
}
.fv-card {
  background: #fff;
  border-radius: 12px;
  padding: 14px;
  margin-bottom: 12px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
}
.fv-label {
  display: block;
  font-size: 13px;
  color: #666;
  margin-bottom: 6px;
}
.fv-label.mt {
  margin-top: 10px;
}
.fv-input {
  width: 100%;
  padding: 10px 12px;
  border: 1px solid #ddd;
  border-radius: 8px;
  font-size: 15px;
  background: #fff;
  box-sizing: border-box;
}
.fv-btn {
  margin-top: 10px;
  width: 100%;
  padding: 12px;
  border: 0;
  border-radius: 8px;
  background: #e53935;
  color: #fff;
  font-size: 15px;
  font-weight: 600;
}
.fv-btn2 {
  background: orange;
}
.fv-err {
  color: #c62828;
  font-size: 13px;
  margin-top: 8px;
}
.fv-row {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding: 6px 0;
  font-size: 14px;
  border-bottom: 1px dashed #eee;
  color: #1a1a1a;
}
.fv-row:last-child {
  border-bottom: 0;
}
.strong {
  font-weight: 800;
}
.fv-mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 12px;
  word-break: break-all;
  line-height: 1.5;
  background: #fafafa;
  border: 1px solid #eee;
  border-radius: 8px;
  padding: 10px;
}
.fv-tag {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 12px;
}
.fv-tag.ok {
  background: #e8f5e9;
  color: #2e7d32;
}
.fv-tag.bad {
  background: #ffebee;
  color: #c62828;
}
.fv-tag.wait {
  background: #fff8e1;
  color: #f57f17;
}
.fv-link {
  display: block;
  margin-top: 14px;
  text-align: center;
  padding: 12px;
  border-radius: 8px;
  background: #1a1a1a;
  color: #fff;
  font-size: 14px;
  font-weight: 700;
}
.fv-link.dark {
  background: #0d47a1;
  margin-top: 8px;
}
.fv-wait-row {
  margin-top: 10px;
}
.fv-cents {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 8px;
}
.fv-cent {
  background: #fff3e0;
  color: #e65100;
  border-radius: 6px;
  padding: 4px 8px;
  font-size: 12px;
  font-family: monospace;
}
</style>

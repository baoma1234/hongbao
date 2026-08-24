<template>
  <view class="fv-page" :style="profileSubPageStyle">
    <TopBar />
    <view class="fv-hd profile-sub-hd" :style="profileSubHdStyle">
      <text class="profile-back-btn" @click="goBack">‹</text>
      <text class="profile-sub-title">{{ pageTitle }}</text>
      <text class="profile-sub-spacer" />
    </view>
    <scroll-view scroll-y class="fv-scroll" :style="{ height: scrollH }">
      <view class="fv-wrap">
        <view class="fv-h1">{{ pageTitle }}</view>
        <view class="fv-sub">{{ pageSub }}</view>

        <view class="fv-card">
          <text class="fv-label">{{ queryLabel }}</text>
          <input
            class="fv-input"
            v-model="queryNo"
            :placeholder="queryPlaceholder"
          />
          <button type="button" class="fv-btn" :disabled="busy" @click="verify()">查询并验证</button>
          <button type="button" class="fv-btn fv-btn2" @click="goBack">
            {{ backLabel }}
          </button>
          <view v-if="formErr" class="fv-err">{{ formErr }}</view>
        </view>

        <view v-if="result" class="fv-result">
          <view class="fv-card">
            <view class="fv-row"><text>玩法</text><text class="strong">{{ result.type_label || '—' }}</text></view>
            <view v-if="isYxx" class="fv-row"><text>期号</text><text>#{{ result.round_index || queryNo }}</text></view>
            <view v-if="isNiuniu" class="fv-row"><text>局号</text><text>#{{ result.round_id || queryNo }}</text></view>
            <view class="fv-row">
              <text>{{ statusTitle }}</text>
              <text>{{ statusText }}</text>
            </view>
            <view v-if="!isYxx || hasYxxTron" class="fv-row"><text>波场开奖</text><text>{{ tronStatusLabel(result.tron_status) }}</text></view>
            <view v-if="isYxx" class="fv-row">
              <text>结算门</text>
              <text class="strong">{{ result.settle_label || result.settle_face || '—' }}</text>
            </view>
            <view v-if="isYxx" class="fv-row">
              <text>三骰展示</text>
              <text>{{ (result.dice_labels || []).filter(Boolean).join(' / ') || '—' }}</text>
            </view>
            <view v-if="isRp" class="fv-row">
              <text>可抢池</text>
              <text>¥{{ poolAmount }} / {{ (result.total_count || 0) }}个</text>
            </view>
            <view v-if="isRp" class="fv-row"><text>发包总额</text><text>¥{{ money(result.total_amount) }}</text></view>
            <view v-if="isNiuniu" class="fv-row">
              <text>奖池 / 可发</text>
              <text>¥{{ money(result.pool_amount) }} / ¥{{ money(result.distributable) }}</text>
            </view>
            <view v-if="isNiuniu" class="fv-row">
              <text>包数</text>
              <text>{{ result.share_count || 0 }} × ¥{{ money(result.share_price) }}</text>
            </view>
            <view v-if="isRp && Number(result.packet_type || 0) === 3" class="fv-row">
              <text>手填雷号</text><text class="strong">{{ result.mine_digit != null ? result.mine_digit : '—' }}</text>
            </view>
          </view>

          <view v-if="isYxx" class="fv-card">
            <view class="fv-sub tight">哈希种子复算</view>
            <view class="fv-row"><text>公式</text><text class="strong">{{ result.hash_formula || 'SHA256' }}</text></view>
            <view class="fv-row"><text>规则</text><text>{{ result.hash_rule || '—' }}</text></view>
            <text class="fv-label mt">Hash Seed</text>
            <view class="fv-mono">{{ result.hash_seed || '尚未开奖' }}</view>
            <view v-if="result.verify_hint" class="fv-sub">{{ result.verify_hint }}</view>
            <view class="fv-row">
              <text>总体结果</text>
              <text v-if="result.revealed" :class="result.verify_ok ? 'fv-tag ok' : 'fv-tag bad'">
                {{ result.verify_ok ? '复算一致' : '复算不一致' }}
              </text>
              <text v-else class="fv-tag wait">待开奖</text>
            </view>
            <view v-if="result.revealed" class="fv-row">
              <text>归档图腾</text>
              <text :class="result.face_match ? 'fv-tag ok' : 'fv-tag bad'">{{ result.face_match ? '一致' : '不一致' }}</text>
            </view>
            <view v-if="result.revealed" class="fv-row">
              <text>归档种子</text>
              <text :class="result.seed_match ? 'fv-tag ok' : 'fv-tag bad'">{{ result.seed_match ? '一致' : '不一致' }}</text>
            </view>
          </view>

          <view v-if="!isYxx || hasYxxTron" class="fv-card">
            <view class="fv-sub tight">波场（TRON）官方区块哈希</view>
            <view class="fv-row"><text>官方区块高度</text><text class="strong">{{ blockNum || '—' }}</text></view>
            <view v-if="lucky" class="fv-row"><text>哈希末位字符</text><text class="strong">{{ lucky }}</text></view>
            <view v-if="isRp && result.revealed && result.lucky_digit != null" class="fv-row">
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

          <!-- 红包：金额验证 -->
          <view v-if="isRp && result.revealed" class="fv-card">
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

          <!-- 牛牛：尾数验证 -->
          <view v-if="isNiuniu && result.revealed" class="fv-card">
            <view class="fv-sub tight"><text class="strong">尾数验证</text>（Block Hash + 领取序号链下复算）</view>
            <view class="fv-row">
              <text>总体结果</text>
              <text
                v-if="tv.ok === true"
                class="fv-tag ok"
              >尾数校验通过</text>
              <text
                v-else-if="tv.ok === false"
                class="fv-tag bad"
              >尾数校验失败</text>
              <text v-else class="fv-tag wait">待对照入库尾数</text>
            </view>
            <view class="fv-row">
              <text>已对照份数</text>
              <text>{{ (tv.matched || 0) }}/{{ (tv.checked || 0) }}</text>
            </view>
            <text class="fv-label mt">复算尾数序列（按尾数升序，与领取明细一致）</text>
            <view class="fv-cents">
              <text
                v-for="(row, i) in computedTails"
                :key="'t' + i"
                class="fv-cent"
                :class="{ bad: row.stored_tail && !row.match }"
              >
                {{ i + 1 }}. 尾数{{ row.computed_tail }} {{ row.computed_niu }}
                <text v-if="(row.share_count|0) > 1"> ×{{ row.share_count }}</text>
                <text v-if="row.stored_tail">（入库{{ row.stored_tail }}）</text>
              </text>
              <text v-if="!computedTails.length" class="fv-sub">—</text>
            </view>
          </view>
        </view>
      </view>
    </scroll-view>
  </view>
</template>

<script setup>
import { safeNavigateBack, HOME_TAB } from '../../utils/nav.js'
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { onLoad, onShow } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import { apiRequest, getToken } from '../../utils/auth.js'
import { useProfileSubHdStyle } from '../../utils/profile-sub-layout.js'
import '../../styles/hb.css'

const kind = ref('rp') // rp | niuniu | yxx
const queryNo = ref('')
const packetId = ref(0)
const formErr = ref('')
const result = ref(null)
const busy = ref(false)
const scrollH = ref('70vh')
let retryTimer = null
const { profileSubHdStyle, profileSubPageStyle, refreshProfileSubLayout } = useProfileSubHdStyle()

const isNiuniu = computed(() => kind.value === 'niuniu' || (result.value && result.value.kind === 'niuniu'))
const isYxx = computed(() => kind.value === 'yxx' || (result.value && result.value.kind === 'yxx'))
const isRp = computed(() => !isNiuniu.value && !isYxx.value)
const hasYxxTron = computed(() => {
  const d = result.value || {}
  return !!(Number(d.tron_block_num || d.targetBlockNum || 0) > 0 || d.tron_block_id || d.block_id)
})
const pageTitle = computed(() => (isYxx.value ? '鱼虾蟹开奖验真' : '波场官方哈希验证'))
const queryLabel = computed(() => {
  if (isYxx.value) return '鱼虾蟹期号 round_index'
  if (isNiuniu.value) return '牛牛局号 round_id'
  return '红包单号 packet_no'
})
const queryPlaceholder = computed(() => {
  if (isYxx.value) return '例如 12345'
  if (isNiuniu.value) return '例如 128'
  return 'rp...'
})
const backLabel = computed(() => {
  if (isYxx.value) return '返回大厅'
  if (isNiuniu.value) return '返回开奖明细'
  return '返回红包详情'
})
const statusTitle = computed(() => {
  if (isYxx.value) return '开奖状态'
  if (isNiuniu.value) return '对局状态'
  return '红包状态'
})
const statusText = computed(() => {
  const d = result.value || {}
  if (isYxx.value || isNiuniu.value) return d.status_label || statusLabel(d.status)
  return statusLabel(d.status)
})
const pageSub = computed(() => {
  if (isYxx.value) {
    return '投注开始时锁定未来波场区块高度；开奖用该块 Block Hash 前 3 字节各自 mod 6 得到三骰，第一颗为结算门。可在 TronScan / OKLink 核验。旧局无波场锁定时仍用 SHA256 种子。'
  }
  return isNiuniu.value
    ? '尾数牛牛由「波场 Block Hash + 领取序号」派生 00-99 尾数（领取后才赋值）。先在本站查询复算，再跳转 TronScan / OKLink 核验区块。'
    : '拼手气金额由「波场 Block Hash + 单号」链下拆分。扫雷另要求：哈希末位必须等于手填雷号后才拆包开抢；中雷看金额尾数。可在 TronScan / OKLink 核验。'
})

function measureScroll() {
  try {
    const sys = uni.getSystemInfoSync() || {}
    const wh = Number(sys.windowHeight) || 640
    const topBar = 48
    const hd = 56
    scrollH.value = Math.max(240, wh - topBar - hd) + 'px'
  } catch (e) {
    scrollH.value = '70vh'
  }
}

const av = computed(() => (result.value && result.value.amount_verify) || {})
const tv = computed(() => (result.value && result.value.tail_verify) || {})
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
const computedTails = computed(() => (result.value && result.value.computed_tails) || [])

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
  if (isYxx.value) {
    uni.navigateTo({
      url: '/pages/yxx/hall',
      fail: () => safeNavigateBack(HOME_TAB),
    })
    return
  }
  safeNavigateBack(HOME_TAB)
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

function detectKindFromInput(raw) {
  const s = String(raw || '').trim()
  if (/^(?:yxx|yx)[#\-:_]?\d+$/i.test(s) || (kind.value === 'yxx' && /^\d+$/.test(s))) {
    return 'yxx'
  }
  if (/^(?:nn|niuniu)[#\-:_]?\d+$/i.test(s) || (kind.value === 'niuniu' && /^\d+$/.test(s))) {
    return 'niuniu'
  }
  if (/^rp/i.test(s)) return 'rp'
  if (kind.value === 'yxx') return 'yxx'
  return kind.value === 'niuniu' ? 'niuniu' : 'rp'
}

async function verify(opts) {
  opts = opts || {}
  formErr.value = ''
  const no = String(queryNo.value || '').trim()
  if (!no) {
    formErr.value = isYxx.value ? '请填写鱼虾蟹期号' : isNiuniu.value ? '请填写牛牛局号' : '请填写红包单号'
    return
  }
  if (!isYxx.value && !getToken()) {
    formErr.value = '请先登录后再查询验证'
    return
  }
  const useKind = detectKindFromInput(no)
  busy.value = true
  try {
    let data
    if (useKind === 'yxx') {
      const rid = parseInt(String(no).replace(/^(?:yxx|yx)[#\-:_]?/i, ''), 10)
      data = await apiRequest('yxxfair', 'GET', { round_index: isFinite(rid) ? rid : no }, { skipAuthRedirect: true })
      kind.value = 'yxx'
    } else if (useKind === 'niuniu') {
      const rid = parseInt(String(no).replace(/^(?:nn|niuniu)[#\-:_]?/i, ''), 10) || 0
      data = await apiRequest('nnfair', 'GET', { round_id: rid || no })
      kind.value = 'niuniu'
    } else {
      data = await apiRequest('rpfair', 'GET', { packet_no: no })
      kind.value = 'rp'
    }
    result.value = data || {}
    formErr.value = ''
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
    const msg = (e && e.message) || '网络错误'
    formErr.value = msg
    result.value = null
    if (/未领|不可|不存在|不支持|请先登录|尚未结束/.test(msg)) {
      if (retryTimer) {
        clearTimeout(retryTimer)
        retryTimer = null
      }
    }
  } finally {
    busy.value = false
  }
}

onLoad((q) => {
  refreshProfileSubLayout()
  measureScroll()
  const k = String((q && (q.kind || q.type)) || '').toLowerCase()
  if (k === 'niuniu' || k === 'nn' || k === 'niu') kind.value = 'niuniu'
  if (k === 'yxx' || k === 'yx') kind.value = 'yxx'
  const yxxRound = parseInt(String((q && (q.round_index || q.round)) || '0'), 10) || 0
  const rid = parseInt(String((q && (q.round_id || q.rid)) || '0'), 10) || 0
  const no = decodeURIComponent(String((q && (q.packet_no || q.no)) || ''))
  const pid = parseInt(String((q && (q.packet_id || q.pid)) || '0'), 10) || 0
  if (yxxRound > 0 || (kind.value === 'yxx' && rid > 0)) {
    kind.value = 'yxx'
    queryNo.value = String(yxxRound || rid)
  } else if (rid > 0) {
    kind.value = 'niuniu'
    queryNo.value = String(rid)
  } else if (no) {
    queryNo.value = no
    if (/^(?:yxx|yx)/i.test(no) || (/^\d+$/.test(no) && k === 'yxx')) kind.value = 'yxx'
    else if (/^(?:nn|niuniu)/i.test(no) || (/^\d+$/.test(no) && k === 'niuniu')) kind.value = 'niuniu'
  }
  if (pid > 0) packetId.value = pid
  if (queryNo.value) verify().catch(() => {})
})

onMounted(() => {
  refreshProfileSubLayout()
  measureScroll()
})

onShow(() => {
  refreshProfileSubLayout()
})

onUnmounted(() => {
  if (retryTimer) clearTimeout(retryTimer)
})
</script>

<style scoped>
.fv-page {
  min-height: 100vh;
  height: 100vh;
  background: #f5f6f8;
  display: flex;
  flex-direction: column;
  box-sizing: border-box;
  padding-top: 0;
  overflow: hidden;
}
.fv-hd {
  flex: 0 0 auto;
  position: relative;
  z-index: 5;
}
.fv-scroll {
  flex: 1 1 auto;
  width: 100%;
  min-height: 0;
  box-sizing: border-box;
}
.fv-wrap {
  max-width: 720px;
  margin: 0 auto;
  padding: 16px 14px 40px;
  box-sizing: border-box;
}
.fv-input {
  width: 100%;
  min-height: 44px;
  height: 44px;
  line-height: 22px;
  padding: 10px 12px;
  border: 1px solid #ddd;
  border-radius: 8px;
  font-size: 15px;
  background: #fff;
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
.fv-cent.bad {
  background: #ffebee;
  color: #c62828;
}
</style>

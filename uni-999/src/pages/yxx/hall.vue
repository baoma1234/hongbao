<template>
  <view class="yxx-page" :style="pagePad">
    <view class="yxx-header">
      <view class="yxx-back" hover-class="yxx-hit" @click="goBack">
        <text class="yxx-back-char">‹</text>
      </view>
      <view class="yxx-titles">
        <text class="yxx-title">鱼虾蟹</text>
        <text class="yxx-sub">Bầu Cua Cá Cọp</text>
      </view>
      <view class="yxx-rules-btn" hover-class="yxx-hit" @click="rulesOpen = true">规则</view>
    </view>

    <view class="yxx-stats">
      <view class="yxx-pill">
        <text class="yxx-pill-ico">⏱</text>
        <text>本局剩余 {{ remainSec }}秒</text>
      </view>
      <view class="yxx-pill">
        <text class="yxx-pill-ico">👤</text>
        <text>参与人数 {{ playerCount }}人</text>
      </view>
      <view class="yxx-pill gold">
        <text>本局奖金池 {{ poolText }}积分</text>
      </view>
    </view>

    <scroll-view scroll-y class="yxx-scroll" :style="{ height: scrollH }">
      <view class="yxx-grid-wrap">
        <view class="yxx-grid">
          <view
            v-for="f in faces"
            :key="f.id"
            class="yxx-cell"
            :class="{ on: selectedFace === f.id }"
            @click="pickFace(f.id)"
          >
            <view class="yxx-ring">
              <text class="yxx-emo">{{ f.emo }}</text>
            </view>
            <text class="yxx-lab">{{ f.label }}</text>
          </view>
        </view>
      </view>

      <view class="yxx-reveal">
        <view class="yxx-bowl" />
        <view class="yxx-dice-row">
          <view v-for="(d, i) in diceShow" :key="i" class="yxx-die">
            <text>{{ d }}</text>
          </view>
        </view>
        <text class="yxx-reveal-cap">{{ revealHint }}</text>
      </view>

      <view class="yxx-feed">
        <text class="yxx-hist">{{ historyLine }}</text>
        <view class="yxx-feed-list">
          <text v-for="(row, i) in feed" :key="i" class="yxx-feed-item">{{ row }}</text>
        </view>
      </view>
    </scroll-view>

    <view class="yxx-dock">
      <view class="yxx-amt">
        <input
          class="yxx-input"
          type="number"
          :value="String(stake)"
          :placeholder="'积分 ' + stakeMin + '-' + stakeMax"
          @input="onAmt"
        />
        <text class="yxx-amt-lab">积分</text>
      </view>
      <view class="yxx-confirm" hover-class="yxx-hit" @click="onBet">确认下注</view>
    </view>

    <view v-if="rulesOpen" class="yxx-mask" @click="rulesOpen = false">
      <view class="yxx-sheet" @click.stop>
        <text class="yxx-sheet-t">鱼虾蟹 · 大厅规则（内测）</text>
        <scroll-view scroll-y class="yxx-sheet-body">
          <text class="yxx-sheet-p">大厅：每局限选 1 门。起注 {{ stakeMin }} 积分，最高 {{ stakeMax }} 积分。</text>
          <text class="yxx-sheet-p">本页视觉按三骰传统台面；结算未上线前不扣款。正式规则将锁定为「单骰固定赔率」或「三骰彩池」其中一套。</text>
          <text class="yxx-sheet-p">计划抽水：3% 平台服务费 + 5% 爆点池，剩余 92% 分配。中奖示例（固定赔率）：50×6×92%=276。</text>
          <text class="yxx-sheet-p">爆点：30–50 有效局内必触发一次，释放 50% 或 100%；连续两次半爆后下次强制全清。</text>
        </scroll-view>
        <view class="yxx-sheet-ok" @click="rulesOpen = false">知道了</view>
      </view>
    </view>
  </view>
</template>

<script setup>
import { computed, onUnmounted, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { apiRequest, getToken } from '../../utils/auth.js'
import { applySafeAreaCssVars, getSafeAreaInsets } from '../../utils/safe-area.js'

const FACE_MAP = {
  gourd: { id: 'gourd', emo: '🎃', label: '葫芦', short: '葫芦' },
  crab: { id: 'crab', emo: '🦀', label: '螃蟹', short: '螃蟹' },
  shrimp: { id: 'shrimp', emo: '🦐', label: '虾', short: '虾' },
  fish: { id: 'fish', emo: '🐟', label: '鱼', short: '鱼' },
  rooster: { id: 'rooster', emo: '🐓', label: '公鸡', short: '公鸡' },
  tiger: { id: 'tiger', emo: '🐯', label: '老虎', short: '老虎' },
}

const faces = [FACE_MAP.gourd, FACE_MAP.crab, FACE_MAP.shrimp, FACE_MAP.fish, FACE_MAP.rooster, FACE_MAP.tiger]

const padTop = ref(20)
const padBottom = ref(8)
const scrollH = ref('60vh')
const selectedFace = ref('')
const stakeMin = ref(50)
const stakeMax = ref(200)
const stake = ref(50)
const pool = ref(24680)
const remainSec = ref(12)
const playerCount = ref(28)
const rulesOpen = ref(false)
const diceShow = ref(['虾', '鱼', '老虎'])
const historyLine = ref('上局：螃蟹 公鸡 老虎  |  上局：葫芦 虾 虾')
const feed = ref([
  '用户A  押虾  300积分',
  '用户B  押老虎  800积分',
  '用户C  押葫芦  50积分',
  '用户D  押鱼  150积分',
])
let tick = null

const pagePad = computed(() => ({
  paddingTop: padTop.value + 'px',
  paddingBottom: padBottom.value + 64 + 'px',
}))

const poolText = computed(() => {
  const n = Number(pool.value) || 0
  return n.toLocaleString('zh-CN')
})

const revealHint = computed(() =>
  selectedFace.value ? '已选「' + (FACE_MAP[selectedFace.value] || {}).label + '」· 开奖动画为预览' : '开奖区预览 · 正式局将按哈希开骰'
)

function measure() {
  try {
    applySafeAreaCssVars()
    const sys = uni.getSystemInfoSync() || {}
    const inset = getSafeAreaInsets()
    padTop.value = Math.max(12, Number(inset.top || sys.statusBarHeight || 20))
    padBottom.value = Math.max(8, Number(inset.bottom || 0))
    const header = 56
    const stats = 44
    const dock = 64 + padBottom.value
    const h = (sys.windowHeight || 667) - padTop.value - header - stats - dock
    scrollH.value = Math.max(240, h) + 'px'
  } catch (e) {
    scrollH.value = '58vh'
  }
}

function goBack() {
  uni.switchTab({
    url: '/pages/messages/messages',
    fail: () => uni.reLaunch({ url: '/pages/messages/messages' }),
  })
}

function pickFace(id) {
  selectedFace.value = selectedFace.value === id ? '' : id
}

function onAmt(e) {
  const n = parseInt(String((e && e.detail && e.detail.value) || ''), 10)
  if (!isFinite(n)) {
    stake.value = stakeMin.value
    return
  }
  stake.value = Math.min(stakeMax.value, Math.max(0, n))
}

function onBet() {
  if (!selectedFace.value) {
    uni.showToast({ title: '请先点选一个图案', icon: 'none' })
    return
  }
  if (stake.value < stakeMin.value) {
    uni.showToast({ title: '起注 ' + stakeMin.value + ' 积分', icon: 'none' })
    return
  }
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  uni.showToast({ title: '内测预览：本局不扣款', icon: 'none' })
}

function startTick() {
  if (tick) clearInterval(tick)
  tick = setInterval(() => {
    if (remainSec.value > 0) remainSec.value -= 1
    else remainSec.value = 12
  }, 1000)
}

async function loadHall() {
  try {
    const data = await apiRequest('yxxhall', 'GET', {}, { skipAuthRedirect: true })
    if (!data) return
    stakeMin.value = Number(data.stake_min || 50)
    stakeMax.value = Number(data.stake_max || 200)
    const r = data.round || {}
    if (r.pool) pool.value = Number(r.pool)
    if (r.remain_sec != null) remainSec.value = Number(r.remain_sec)
    if (r.player_count != null) playerCount.value = Number(r.player_count)
    if (Array.isArray(data.live_bets) && data.live_bets.length) {
      feed.value = data.live_bets.map((b) => String(b))
    }
    if (data.history_line) historyLine.value = String(data.history_line)
    if (Array.isArray(data.dice) && data.dice.length === 3) diceShow.value = data.dice.map(String)
    if (stake.value < stakeMin.value) stake.value = stakeMin.value
  } catch (e) {}
}

onShow(() => {
  measure()
  loadHall()
  startTick()
})

onUnmounted(() => {
  if (tick) {
    clearInterval(tick)
    tick = null
  }
})
</script>

<style scoped>
.yxx-page {
  min-height: 100vh;
  background:
    radial-gradient(circle at 50% 18%, rgba(180, 40, 30, 0.55) 0%, transparent 42%),
    linear-gradient(180deg, #6b0d0d 0%, #3a0508 42%, #1a0204 100%);
  color: #fff7e6;
  box-sizing: border-box;
  max-width: 480px;
  margin: 0 auto;
}
.yxx-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 4px 12px 6px;
}
.yxx-back,
.yxx-rules-btn {
  width: 40px;
  height: 40px;
  border-radius: 20px;
  background: rgba(80, 10, 10, 0.55);
  border: 1px solid rgba(255, 210, 120, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.yxx-rules-btn {
  width: auto;
  padding: 0 10px;
  border-radius: 10px;
  font-size: 13px;
  font-weight: 800;
  color: #ffe29a;
}
.yxx-back-char {
  font-size: 32px;
  font-weight: 700;
  color: #ffe29a;
  line-height: 1;
  transform: translateY(-1px);
}
.yxx-hit {
  opacity: 0.82;
}
.yxx-titles {
  flex: 1;
  text-align: center;
}
.yxx-title {
  display: block;
  font-size: 28px;
  font-weight: 900;
  letter-spacing: 4px;
  color: #ffd56a;
  text-shadow: 0 2px 0 #7a3a00, 0 0 12px rgba(255, 200, 80, 0.45);
}
.yxx-sub {
  display: block;
  margin-top: 2px;
  font-size: 11px;
  color: rgba(255, 255, 255, 0.78);
  letter-spacing: 0.4px;
}
.yxx-stats {
  display: flex;
  gap: 6px;
  padding: 0 10px 8px;
}
.yxx-pill {
  flex: 1;
  min-width: 0;
  background: rgba(90, 8, 12, 0.72);
  border: 1px solid rgba(255, 180, 80, 0.28);
  border-radius: 999px;
  padding: 6px 6px;
  font-size: 10px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 3px;
  white-space: nowrap;
}
.yxx-pill.gold {
  flex: 1.35;
  background: linear-gradient(180deg, #f0c14b, #c48418);
  color: #4a2200;
  border-color: #ffe29a;
  font-size: 10px;
}
.yxx-pill-ico {
  font-size: 11px;
}
.yxx-scroll {
  box-sizing: border-box;
}
.yxx-grid-wrap {
  margin: 4px 12px 0;
  padding: 12px 8px 56px;
  border-radius: 16px;
  background: linear-gradient(180deg, rgba(90, 12, 16, 0.92), rgba(50, 6, 10, 0.92));
  border: 2px solid #e0b24a;
  box-shadow: inset 0 0 0 1px rgba(255, 220, 140, 0.35), 0 8px 24px rgba(0, 0, 0, 0.35);
}
.yxx-grid {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-around;
}
.yxx-cell {
  width: 33.33%;
  box-sizing: border-box;
  padding: 6px 4px 10px;
  text-align: center;
}
.yxx-ring {
  width: 72px;
  height: 72px;
  margin: 0 auto;
  border-radius: 50%;
  background: radial-gradient(circle at 35% 30%, #fff3c4, #d7a23a 42%, #8a4a10);
  border: 3px solid #f3d07a;
  box-shadow: 0 4px 10px rgba(0, 0, 0, 0.35), inset 0 2px 6px rgba(255, 255, 255, 0.35);
  display: flex;
  align-items: center;
  justify-content: center;
}
.yxx-cell.on .yxx-ring {
  box-shadow: 0 0 0 3px #fff3c4, 0 0 16px rgba(255, 210, 80, 0.7);
}
.yxx-emo {
  font-size: 34px;
  line-height: 1;
}
.yxx-lab {
  display: block;
  margin-top: 6px;
  font-size: 13px;
  font-weight: 800;
  color: #ffe29a;
}
.yxx-reveal {
  margin-top: -48px;
  align-items: center;
  display: flex;
  flex-direction: column;
}
.yxx-bowl {
  width: 88px;
  height: 28px;
  border-radius: 44px 44px 10px 10px;
  background: linear-gradient(180deg, #f8e19a, #c48a22);
  box-shadow: 0 6px 12px rgba(0, 0, 0, 0.35);
  transform: rotate(-18deg) translateX(-36px);
}
.yxx-dice-row {
  display: flex;
  gap: 10px;
  margin-top: -6px;
  padding: 10px 16px 8px;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(180, 30, 30, 0.55), transparent 70%);
}
.yxx-die {
  width: 48px;
  height: 48px;
  border-radius: 8px;
  background: #fff;
  color: #b01018;
  font-size: 13px;
  font-weight: 900;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 4px 8px rgba(0, 0, 0, 0.35);
}
.yxx-reveal-cap {
  margin-top: 4px;
  font-size: 11px;
  color: rgba(255, 230, 180, 0.75);
}
.yxx-feed {
  margin: 10px 12px 16px;
  border-radius: 12px;
  background: rgba(40, 8, 10, 0.72);
  border: 1px solid rgba(224, 178, 74, 0.28);
  overflow: hidden;
}
.yxx-hist {
  display: block;
  padding: 8px 10px;
  font-size: 11px;
  color: #ffe29a;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}
.yxx-feed-list {
  padding: 6px 10px 8px;
}
.yxx-feed-item {
  display: block;
  font-size: 12px;
  color: rgba(255, 247, 230, 0.88);
  line-height: 1.7;
}
.yxx-dock {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 20;
  max-width: 480px;
  margin: 0 auto;
  display: flex;
  gap: 10px;
  padding: 8px 12px;
  padding-bottom: calc(8px + var(--safe-area-inset-bottom, env(safe-area-inset-bottom, 0px)));
  background: linear-gradient(180deg, rgba(80, 10, 12, 0.92), #3a0608);
  box-sizing: border-box;
}
.yxx-amt {
  flex: 1;
  position: relative;
  height: 44px;
  border-radius: 10px;
  background: #fff;
  display: flex;
  align-items: center;
}
.yxx-input {
  flex: 1;
  height: 44px;
  padding: 0 52px 0 12px;
  font-size: 16px;
  font-weight: 800;
  color: #3a1408;
}
.yxx-amt-lab {
  position: absolute;
  right: 10px;
  font-size: 13px;
  color: #9a6a3a;
  font-weight: 700;
}
.yxx-confirm {
  width: 128px;
  height: 44px;
  line-height: 44px;
  text-align: center;
  border-radius: 10px;
  font-size: 16px;
  font-weight: 900;
  color: #fff8e6;
  background: linear-gradient(180deg, #e23b32, #a31218);
  border: 1px solid #f0c14b;
  box-shadow: 0 4px 10px rgba(120, 10, 10, 0.4);
}
.yxx-mask {
  position: fixed;
  inset: 0;
  z-index: 40;
  background: rgba(0, 0, 0, 0.55);
  display: flex;
  align-items: flex-end;
  justify-content: center;
}
.yxx-sheet {
  width: 100%;
  max-width: 480px;
  background: #2a0a0c;
  border-radius: 16px 16px 0 0;
  padding: 16px 16px calc(16px + var(--safe-area-inset-bottom, env(safe-area-inset-bottom, 0px)));
  box-sizing: border-box;
}
.yxx-sheet-t {
  display: block;
  font-size: 16px;
  font-weight: 900;
  color: #ffd56a;
  margin-bottom: 8px;
}
.yxx-sheet-body {
  max-height: 42vh;
}
.yxx-sheet-p {
  display: block;
  font-size: 13px;
  line-height: 1.55;
  color: #f6e6c8;
  margin-bottom: 8px;
}
.yxx-sheet-ok {
  margin-top: 8px;
  height: 42px;
  line-height: 42px;
  text-align: center;
  border-radius: 10px;
  background: linear-gradient(180deg, #e23b32, #a31218);
  font-weight: 800;
}
</style>

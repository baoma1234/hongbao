<template>
  <!-- 游戏层：仅 TopBar，其下尽量铺满 -->
  <view v-if="gameUrl" class="hb-page webview-page og-game-page" :style="profileSubPageStyle">
    <TopBar
      :title="pageTitle"
      hide-lang
      hide-cs
      show-recycle
      show-refresh
      show-close
      @recycle="onWithdraw"
      @refresh="onTopRefresh"
      @close="onTopClose"
    />
    <view class="og-game-frame-wrap" :style="frameWrapStyle">
      <!-- #ifdef H5 -->
      <iframe
        class="og-game-frame"
        :src="gameUrl"
        :key="frameKey"
        title="OG视讯"
        allow="fullscreen; autoplay; payment"
        referrerpolicy="no-referrer-when-downgrade"
      />
      <!-- #endif -->
    </view>
  </view>

  <ProfileSubPage
    v-else
    :title="pageTitle"
    body-class="hb-sub og-live-body"
    page-class="og-live-page"
    hide-lang
    hide-cs
    show-recycle
    show-refresh
    show-close
    @recycle="onWithdraw"
    @refresh="onTopRefresh"
    @close="onTopClose"
  >
    <view class="og-tip">内测页。进入游戏后本页内嵌打开。</view>

    <view class="og-card" @click="openSheet">
      <view class="og-card-badge">LIVE</view>
      <view class="og-card-title">视讯</view>
      <view class="og-card-sub">OG 真人 · 点此打开</view>
      <view class="og-card-bal">
        <text>OG {{ ogBalText }}</text>
        <text class="og-card-sep">·</text>
        <text>红宝 {{ hbText }}</text>
      </view>
    </view>

    <view class="og-refresh" @click="refreshAll">刷新余额</view>

    <view class="og-mask" :class="{ 'is-open': sheetOpen }" @click="closeSheet">
      <view class="og-sheet" @click.stop>
        <view class="og-sheet-title">OG 视讯</view>
        <view class="og-sheet-bal">
          <text>OG余额 <strong>{{ ogBalText }}</strong></text>
          <text class="og-sheet-sep">｜</text>
          <text>本站红宝 <strong>{{ hbText }}</strong></text>
        </view>
        <view class="og-sheet-hint">进入：全部红宝自动转入 OG 再开游戏<br />提出：OG 余额全部提回红宝</view>
        <button
          type="button"
          class="og-btn primary"
          :disabled="busy"
          @click="onEnterGame"
        >
          {{ busyLaunch ? '进入中…' : ('进入游戏' + (hbNum > 0 ? '（转入 ' + hbText + '）' : '')) }}
        </button>
        <button
          type="button"
          class="og-btn warn"
          :disabled="busy"
          @click="onWithdraw"
        >
          {{ busyWithdraw ? '提出中…' : ('提出全部' + (ogNum > 0 ? '（' + ogBalText + '）' : '')) }}
        </button>
        <button type="button" class="og-btn close" :disabled="busy" @click="closeSheet">关闭</button>
      </view>
    </view>
  </ProfileSubPage>
</template>

<script setup>
import { computed, nextTick, ref } from 'vue'
import { onBackPress, onHide, onLoad, onShow, onUnload } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import ProfileSubPage from '../../components/ProfileSubPage.vue'
import { getToken, notifyProfileUpdated } from '../../utils/auth.js'
import { useProfileSubHdStyle } from '../../utils/profile-sub-layout.js'
import { HOME_TAB, safeNavigateBack } from '../../utils/nav.js'
import { applySafeAreaCssVars, getSafeAreaInsets, measureChatOverlayTop } from '../../utils/safe-area.js'
import {
  ogBalance,
  ogDeposit,
  ogGameList,
  ogLaunch,
  ogPlayer,
  ogRegister,
  ogWithdraw,
} from '../../utils/og.js'
import '../../styles/hb.css'

const APP_WV_ID = 'og-live-game'

const preferredGameId = ref(0)
const pageTitle = ref('真人视讯')
const autoLaunchPending = ref(false)
const sheetOpen = ref(false)
const gameUrl = ref('')
const frameKey = ref(0)
const frameWrapStyle = ref({ height: '60vh' })
const ogBal = ref('0.00')
const hongbao = ref(0)
const registered = ref(false)
const busyLaunch = ref(false)
const busyWithdraw = ref(false)

const { profileSubPageStyle, refreshProfileSubLayout } = useProfileSubHdStyle()

const busy = computed(() => busyLaunch.value || busyWithdraw.value)
const ogBalText = computed(() => money2(ogBal.value))
const hbText = computed(() => money2(hongbao.value))
const ogNum = computed(() => round2(ogBal.value))
const hbNum = computed(() => round2(hongbao.value))

function round2(v) {
  const n = Number(v)
  if (!Number.isFinite(n) || n <= 0) return 0
  return Math.floor(n * 100 + 1e-8) / 100
}

function money2(v) {
  return round2(v).toFixed(2)
}

function ensureLogin() {
  if (getToken()) return true
  uni.showToast({ title: '请先登录', icon: 'none' })
  setTimeout(() => {
    uni.reLaunch({ url: '/pages/login/login' })
  }, 400)
  return false
}

async function refreshAll() {
  if (!ensureLogin()) return
  try {
    const data = await ogBalance()
    ogBal.value = String(data?.current_balance ?? data?.og_balance ?? '0.00')
    hongbao.value = Number(data?.hongbao ?? 0) || 0
    registered.value = true
  } catch (e) {
    try {
      const snap = await ogPlayer()
      registered.value = !!snap?.registered
      hongbao.value = Number(snap?.hongbao ?? hongbao.value) || 0
    } catch (e2) {
      // ignore
    }
    const msg = (e && e.message) || '余额查询失败'
    if (!/未注册|not available|S-104/i.test(msg)) {
      uni.showToast({ title: msg, icon: 'none' })
    }
  }
}

async function ensureOgReady() {
  try {
    const snap = await ogPlayer()
    registered.value = !!snap?.registered
    if (snap?.hongbao != null) hongbao.value = Number(snap.hongbao) || 0
    if (snap?.registered) return snap
  } catch (e) {
    // continue
  }
  const ret = await ogRegister()
  registered.value = true
  return ret
}

function openSheet() {
  if (!ensureLogin()) return
  sheetOpen.value = true
  refreshAll()
}

function closeSheet() {
  if (busy.value) return
  sheetOpen.value = false
}

/** TopBar 底边之下铺满（含底部安全区） */
function measureFrameMetrics() {
  refreshProfileSubLayout()
  applySafeAreaCssVars()
  const inset = getSafeAreaInsets() || {}
  const sys = uni.getSystemInfoSync() || {}
  const top =
    measureChatOverlayTop() || Number(inset.top || sys.statusBarHeight || 0) + 48
  const bottom = Number(inset.bottom || 0)
  const winH = Number(sys.windowHeight) || 667
  const height = Math.max(200, winH - top - bottom)
  frameWrapStyle.value = {
    height: height + 'px',
    flex: 'none',
  }
  return { top, height, bottom }
}

function closeAppGameWebview() {
  // #ifdef APP-PLUS
  try {
    // eslint-disable-next-line no-undef
    const w = plus.webview.getWebviewById(APP_WV_ID)
    if (w) w.close()
  } catch (e) {
    // ignore
  }
  // #endif
}

function openAppGameWebview(url) {
  // #ifdef APP-PLUS
  closeAppGameWebview()
  const metrics = measureFrameMetrics()
  setTimeout(() => {
    try {
      // eslint-disable-next-line no-undef
      if (typeof plus === 'undefined' || !plus.webview) return
      const pages = getCurrentPages()
      const cur = pages && pages.length ? pages[pages.length - 1] : null
      const cw =
        (cur && typeof cur.$getAppWebview === 'function' && cur.$getAppWebview()) ||
        (cur && cur.$scope && typeof cur.$scope.$getAppWebview === 'function' && cur.$scope.$getAppWebview())
      if (!cw) return
      // eslint-disable-next-line no-undef
      const wv = plus.webview.create(url, APP_WV_ID, {
        top: metrics.top + 'px',
        height: metrics.height + 'px',
        left: '0px',
        width: '100%',
        position: 'absolute',
        kernel: 'WKWebview',
      })
      cw.append(wv)
    } catch (e) {
      // ignore
    }
  }, 80)
  // #endif
}

function openGameInPage(link) {
  gameUrl.value = link
  sheetOpen.value = false
  nextTick(() => {
    measureFrameMetrics()
    // #ifdef APP-PLUS
    openAppGameWebview(link)
    // #endif
  })
}

function closeGame() {
  closeAppGameWebview()
  gameUrl.value = ''
  refreshAll()
  nextTick(() => refreshProfileSubLayout())
}

async function onTopRefresh() {
  await refreshAll()
  if (!gameUrl.value) {
    uni.showToast({ title: '已刷新', icon: 'none' })
    return
  }
  frameKey.value += 1
  // #ifdef APP-PLUS
  try {
    // eslint-disable-next-line no-undef
    const w = plus.webview.getWebviewById(APP_WV_ID)
    if (w && typeof w.reload === 'function') {
      w.reload()
    } else if (gameUrl.value) {
      openAppGameWebview(gameUrl.value)
    }
  } catch (e) {
    if (gameUrl.value) openAppGameWebview(gameUrl.value)
  }
  // #endif
  uni.showToast({ title: '已刷新', icon: 'none' })
}

function onTopClose() {
  if (busy.value) return
  if (gameUrl.value) {
    closeGame()
    return
  }
  safeNavigateBack(HOME_TAB)
}

onLoad((q) => {
  const n = parseInt(q && q.game_id, 10)
  if (n > 0) {
    preferredGameId.value = n
    autoLaunchPending.value = true
  }
  let t = q && q.title ? String(q.title) : ''
  try {
    if (t) t = decodeURIComponent(t)
  } catch (e) {}
  if (t) pageTitle.value = t
})

async function resolveGameId() {
  const prefer = Number(preferredGameId.value) || 0
  if (prefer > 0) return prefer
  try {
    const list = await ogGameList({ refresh: false })
    const rows = Array.isArray(list?.records) ? list.records : []
    if (!rows.length) return 0
    const lobby = rows.find((r) => /lobby/i.test(String(r.game_name || '')))
    return Number((lobby || rows[0]).game_id) || 0
  } catch (e) {
    return 0
  }
}

async function onEnterGame() {
  if (!ensureLogin() || busy.value) return
  busyLaunch.value = true
  try {
    await ensureOgReady()
    await refreshAll()
    const dep = hbNum.value
    if (dep > 0) {
      const ret = await ogDeposit(dep)
      ogBal.value = String(ret?.balance ?? ogBal.value)
      if (ret?.hongbao != null) hongbao.value = Number(ret.hongbao) || 0
      notifyProfileUpdated()
    }
    const gameId = await resolveGameId()
    const ret = await ogLaunch(gameId > 0 ? { game_id: gameId } : {})
    const link = String(ret?.game_link || '').trim()
    if (!link) throw new Error('未返回游戏链接')
    if (!/^https?:\/\//i.test(link)) throw new Error('游戏链接无效')
    openGameInPage(link)
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '进入游戏失败', icon: 'none' })
  } finally {
    busyLaunch.value = false
    if (!gameUrl.value) refreshAll()
  }
}

async function onWithdraw() {
  if (!ensureLogin() || busy.value) return
  busyWithdraw.value = true
  try {
    await ensureOgReady()
    await refreshAll()
    const amt = ogNum.value
    if (!(amt > 0)) {
      uni.showToast({ title: 'OG 无可提出余额', icon: 'none' })
      return
    }
    const ret = await ogWithdraw(amt)
    ogBal.value = String(ret?.balance ?? '0.00')
    if (ret?.hongbao != null) hongbao.value = Number(ret.hongbao) || 0
    notifyProfileUpdated()
    uni.showToast({ title: '已全部提出', icon: 'success' })
    await refreshAll()
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '提出失败', icon: 'none' })
  } finally {
    busyWithdraw.value = false
  }
}

onShow(() => {
  refreshProfileSubLayout()
  if (getToken() && !gameUrl.value) refreshAll()
  if (gameUrl.value) {
    measureFrameMetrics()
    // #ifdef APP-PLUS
    // 从其它页返回时若已关子 webview，按当前 url 重建
    try {
      // eslint-disable-next-line no-undef
      const w = plus.webview.getWebviewById(APP_WV_ID)
      if (!w) openAppGameWebview(gameUrl.value)
    } catch (e) {
      openAppGameWebview(gameUrl.value)
    }
    // #endif
  } else if (autoLaunchPending.value && preferredGameId.value > 0) {
    autoLaunchPending.value = false
    if (!getToken()) {
      uni.showToast({ title: '请先登录', icon: 'none' })
      return
    }
    sheetOpen.value = true
    nextTick(() => {
      onEnterGame()
    })
  }
})

onHide(() => {
  // 离开页时关掉原生层，避免盖住其它页
  closeAppGameWebview()
})

onUnload(() => {
  closeAppGameWebview()
})

onBackPress(() => {
  if (gameUrl.value) {
    closeGame()
    return true
  }
  return false
})
</script>

<style scoped>
.og-live-body {
  padding: 24rpx 28rpx 80rpx;
}
.og-tip {
  font-size: 24rpx;
  color: rgba(255, 255, 255, 0.55);
  line-height: 1.5;
  margin-bottom: 24rpx;
}
.og-card {
  position: relative;
  border-radius: 24rpx;
  padding: 48rpx 36rpx 40rpx;
  background: linear-gradient(145deg, #1a2a4a 0%, #0d1528 55%, #1c1430 100%);
  border: 1px solid rgba(212, 175, 55, 0.35);
  box-shadow: 0 12rpx 40rpx rgba(0, 0, 0, 0.35);
}
.og-card-badge {
  position: absolute;
  top: 20rpx;
  right: 24rpx;
  font-size: 20rpx;
  letter-spacing: 2rpx;
  color: #1a1208;
  background: #d4af37;
  padding: 4rpx 14rpx;
  border-radius: 999rpx;
  font-weight: 700;
}
.og-card-title {
  font-size: 56rpx;
  font-weight: 700;
  color: #f5e6c8;
  letter-spacing: 8rpx;
}
.og-card-sub {
  margin-top: 8rpx;
  font-size: 26rpx;
  color: rgba(245, 230, 200, 0.65);
}
.og-card-bal {
  margin-top: 28rpx;
  font-size: 24rpx;
  color: rgba(255, 255, 255, 0.7);
  display: flex;
  flex-wrap: wrap;
  gap: 8rpx;
}
.og-card-sep {
  opacity: 0.4;
}
.og-refresh {
  margin-top: 28rpx;
  text-align: center;
  font-size: 26rpx;
  color: #d4af37;
  padding: 16rpx;
}

.og-mask {
  position: fixed;
  inset: 0;
  z-index: 1000;
  background: rgba(0, 0, 0, 0.62);
  display: flex;
  align-items: flex-end;
  justify-content: center;
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.2s ease;
}
.og-mask.is-open {
  opacity: 1;
  pointer-events: auto;
}
.og-sheet {
  width: 100%;
  max-width: 720rpx;
  background: #141820;
  border-radius: 28rpx 28rpx 0 0;
  padding: 36rpx 32rpx calc(36rpx + env(safe-area-inset-bottom, 0px));
  border-top: 1px solid rgba(212, 175, 55, 0.25);
  box-sizing: border-box;
}
.og-sheet-title {
  font-size: 34rpx;
  font-weight: 700;
  color: #f5e6c8;
  text-align: center;
  margin-bottom: 16rpx;
}
.og-sheet-bal {
  text-align: center;
  font-size: 24rpx;
  color: rgba(255, 255, 255, 0.7);
  margin-bottom: 16rpx;
}
.og-sheet-bal strong {
  color: #d4af37;
  font-weight: 600;
}
.og-sheet-sep {
  margin: 0 8rpx;
  opacity: 0.4;
}
.og-sheet-hint {
  text-align: center;
  font-size: 22rpx;
  color: rgba(255, 255, 255, 0.45);
  line-height: 1.55;
  margin-bottom: 24rpx;
}
.og-btn {
  width: 100%;
  margin-top: 16rpx;
  border-radius: 16rpx;
  font-size: 30rpx;
  font-weight: 600;
  padding: 22rpx 0;
  border: none;
  line-height: 1.2;
}
.og-btn::after {
  border: none;
}
.og-btn.primary {
  background: linear-gradient(90deg, #c9a227, #e8c85a);
  color: #1a1208;
}
.og-btn.warn {
  background: #2a3548;
  color: #f5e6c8;
  border: 1px solid rgba(212, 175, 55, 0.45);
}
.og-btn.close {
  background: transparent;
  color: rgba(255, 255, 255, 0.45);
  font-weight: 400;
  font-size: 26rpx;
  margin-top: 8rpx;
}
.og-btn[disabled] {
  opacity: 0.55;
}

/* —— 游戏内嵌：仅保留 TopBar，其余铺满 —— */
.og-game-page.hb-page,
.og-game-page {
  padding: 0 !important;
  display: flex;
  flex-direction: column;
  height: 100vh;
  height: 100dvh;
  background: #0d1528;
  overflow: hidden;
  box-sizing: border-box;
}
/* 大厅 ProfileSubPage 根节点也去掉 hb-page 垫白 */
:deep(.og-live-page.hb-page),
:deep(.og-live-page) {
  padding: 0 !important;
}
.og-game-frame-wrap {
  width: 100%;
  min-height: 0;
  background: #000;
  position: relative;
  overflow: hidden;
}
.og-game-frame {
  width: 100%;
  height: 100%;
  border: 0;
  display: block;
  background: #000;
}
</style>

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
    <view v-if="busyLaunch" class="og-entering">正在进入游戏…</view>
    <view v-else class="og-entering">加载中…</view>
  </ProfileSubPage>
</template>

<script setup>
import { computed, nextTick, ref } from 'vue'
import { onBackPress, onHide, onLoad, onShow, onUnload } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import ProfileSubPage from '../../components/ProfileSubPage.vue'
import { getToken, notifyProfileUpdated } from '../../utils/auth.js'
import { useProfileSubHdStyle } from '../../utils/profile-sub-layout.js'
import { goHomeTab } from '../../utils/nav.js'
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
    if (w) w.close('none')
  } catch (e) {}
  // #endif
}

function openAppGameWebview(url) {
  // #ifdef APP-PLUS
  closeAppGameWebview()
  try {
    const m = measureFrameMetrics()
    // eslint-disable-next-line no-undef
    const wv = plus.webview.create(
      url,
      APP_WV_ID,
      {
        top: m.top + 'px',
        height: m.height + 'px',
        bottom: m.bottom + 'px',
        scalable: false,
        kernel: 'WKWebview',
      },
      { preload: 'none' }
    )
    // eslint-disable-next-line no-undef
    const cur = plus.webview.currentWebview()
    cur.append(wv)
  } catch (e) {
    uni.showToast({ title: '打开游戏失败', icon: 'none' })
  }
  // #endif
}

function openGameInPage(link) {
  gameUrl.value = link
  frameKey.value += 1
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
  goHomeTab()
}

function onTopRefresh() {
  if (!gameUrl.value) {
    refreshAll()
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
  closeAppGameWebview()
  gameUrl.value = ''
  goHomeTab()
}

onLoad((q) => {
  const n = parseInt(q && q.game_id, 10)
  if (n > 0) preferredGameId.value = n
  let t = q && q.title ? String(q.title) : ''
  try {
    if (t) t = decodeURIComponent(t)
  } catch (e) {}
  if (t) pageTitle.value = t
  const auto = String((q && (q.auto || q.autolaunch)) || '')
  if (n > 0 && (auto === '1' || auto === 'true')) {
    autoLaunchPending.value = true
  }
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
    try {
      // eslint-disable-next-line no-undef
      const w = plus.webview.getWebviewById(APP_WV_ID)
      if (!w) openAppGameWebview(gameUrl.value)
    } catch (e) {
      openAppGameWebview(gameUrl.value)
    }
    // #endif
  } else if (autoLaunchPending.value) {
    autoLaunchPending.value = false
    if (!getToken()) {
      uni.showToast({ title: '请先登录', icon: 'none' })
      return
    }
    nextTick(() => {
      onEnterGame()
    })
  }
})

onHide(() => {
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
  padding: 48rpx 28rpx 80rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 40vh;
}
.og-entering {
  font-size: 28rpx;
  color: rgba(255, 255, 255, 0.55);
  text-align: center;
}

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
:deep(.og-live-page.hb-page),
:deep(.og-live-page) {
  padding: 0 !important;
}
.og-game-frame-wrap {
  width: 100%;
  min-height: 0;
  background: #000;
  flex: 1;
}
.og-game-frame {
  width: 100%;
  height: 100%;
  border: 0;
  display: block;
  background: #000;
}
</style>

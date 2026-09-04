<template>
  <view class="floating-top-bar" :class="{ 'is-guest': !isLoggedIn }" :style="barStyle">
    <view class="brand" @click="goHome">
      <image class="logo" :src="logoSrc" mode="aspectFit" />
      <view v-if="isLoggedIn" class="brand-meta">
        <text v-if="nickText" class="nick">{{ nickText }}</text>
        <view class="bal" hover-class="btn-hit" @click.stop="goRecharge">
          <text class="bal-ico">¥</text>
          <text class="bal-num">{{ balText }}</text>
        </view>
      </view>
    </view>

    <view class="actions">
      <template v-if="isLoggedIn">
        <view class="act-btn act-btn--recharge" hover-class="btn-hit" @click.stop="goRecharge">
          <text>{{ rechargeLab }}</text>
        </view>
        <view class="act-btn act-btn--cs" hover-class="btn-hit" @click.stop="openCs">
          <text>{{ csLab }}</text>
        </view>
      </template>
      <!-- 语言：登录页也显示；登录后收紧放右侧 -->
      <view
        class="lang-wrap"
        hover-class="lang-wrap-hover"
        :hover-stay-time="80"
        @click.stop="toggleLang"
      >
        <view class="lang-btn-glass" aria-hidden="true" />
        <image class="flag" :src="flagSrc" mode="aspectFill" />
        <text class="lang-text">{{ localeLabel }}</text>
        <text class="caret">▾</text>
      </view>
    </view>
  </view>

  <view
    v-if="langOpen"
    class="lang-mask"
    @click="closePanels"
    @touchmove.stop.prevent="noop"
  />
  <view v-if="langOpen" class="lang-panel" :style="langPanelStyle" @click.stop>
    <view
      v-for="opt in locales"
      :key="opt.id"
      class="panel-item"
      :class="{ on: opt.id === locale }"
      hover-class="panel-item-hover"
      @click.stop="pickLocale(opt.id)"
    >
      <image class="flag" :src="flagOf(opt.flagIso)" mode="aspectFill" />
      <text class="panel-lab">{{ opt.label }}</text>
    </view>
  </view>

  <view v-if="!noSpacer" class="top-bar-spacer" :style="spacerStyle" />
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import {
  flagUrl,
  getLocale,
  localeOptions,
  logoUrl,
  onLocaleChange,
  setLocale,
  tt,
} from '../utils/i18n.js'
import {
  applySafeAreaCssVars,
  getSafeAreaInsets,
  getTopBarContentHeight,
} from '../utils/safe-area.js'
import { fetchConfig, fetchProfile, getToken } from '../utils/auth.js'
import { openExternalHttpUrl } from '../utils/wallet.js'

defineProps({
  noSpacer: { type: Boolean, default: false },
  /** @deprecated 保留兼容 */
  fissionLink: { type: Boolean, default: true },
  /** @deprecated 顶栏标题已隐藏 */
  title: { type: String, default: '' },
})

const locale = ref(getLocale())
const langOpen = ref(false)
const padTop = ref(getSafeAreaInsets().top)
const profile = ref(null)
const csUrl = ref('')
let offLocale = null
let lastToggleAt = 0
let pickingLang = false

const logoSrc = logoUrl()
const locales = computed(() => {
  void locale.value
  return localeOptions()
})
const localeLabel = computed(() => {
  const opt = locales.value.find((x) => x.id === locale.value)
  return (opt && opt.label) || locale.value
})
const flagSrc = computed(() => {
  const opt = locales.value.find((x) => x.id === locale.value)
  return flagOf(opt ? opt.flagIso : 'cn')
})

function isLoginRoute() {
  try {
    const pages = typeof getCurrentPages === 'function' ? getCurrentPages() : []
    const cur = pages && pages.length ? pages[pages.length - 1] : null
    const route = String((cur && cur.route) || '')
    return (
      route.indexOf('pages/login/') === 0 ||
      route.indexOf('gfhwgkdhf11131djfh/') === 0
    )
  } catch (e) {
    return false
  }
}

const isLoggedIn = computed(() => !isLoginRoute() && !!getToken())

const nickText = computed(() => {
  void locale.value
  const p = profile.value || {}
  return String(
    p.nickname || p.username || p.mobile_mask || p.mobile || tt('lobby_member', '会员') || '会员'
  ).trim()
})

const balText = computed(() => {
  const p = profile.value || {}
  const n = p.hongbao != null ? p.hongbao : p.account && p.account.hongbao
  const v = Math.max(0, Number(n) || 0)
  return v.toFixed(2)
})

const rechargeLab = computed(() => {
  void locale.value
  return tt('lobby_recharge', '充值')
})
const csLab = computed(() => {
  void locale.value
  return tt('lobby_cs', '客服')
})

const barStyle = computed(() => {
  const p = Math.max(0, Number(padTop.value) || 0)
  const h = getTopBarContentHeight()
  return {
    paddingTop: p + 'px',
    height: h + p + 'px',
  }
})
const spacerStyle = computed(() => {
  const p = Math.max(0, Number(padTop.value) || 0)
  const h = getTopBarContentHeight()
  return { height: h + p + 'px' }
})
const langPanelStyle = computed(() => {
  const p = Math.max(0, Number(padTop.value) || 0)
  const h = getTopBarContentHeight()
  return { top: p + h + 4 + 'px' }
})

function flagOf(iso) {
  return flagUrl(iso)
}
function noop() {}
function closePanels() {
  if (pickingLang) return
  langOpen.value = false
}
function toggleLang() {
  const now = Date.now()
  if (now - lastToggleAt < 300) return
  lastToggleAt = now
  langOpen.value = !langOpen.value
}

async function pickLocale(id) {
  pickingLang = true
  langOpen.value = false
  try {
    if (id === locale.value) return
    await setLocale(id)
    locale.value = id
  } catch (e) {
    console.warn('setLocale', e)
  } finally {
    setTimeout(() => {
      pickingLang = false
    }, 120)
  }
}

function goHome() {
  closePanels()
  if (isLoginRoute() || !getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  uni.switchTab({
    url: '/pages/home/home',
    fail: () => uni.reLaunch({ url: '/pages/home/home' }),
  })
}

function goRecharge() {
  closePanels()
  uni.navigateTo({
    url: '/pages/wallet/recharge',
    fail: () =>
      uni.navigateTo({
        url: '/pages/wallet/wallet',
        fail: () => uni.switchTab({ url: '/pages/home/home' }),
      }),
  })
}

function openCs() {
  closePanels()
  const url = String(csUrl.value || '').trim()
  if (url && /^https?:\/\//i.test(url)) {
    openExternalHttpUrl(url)
    return
  }
  uni.navigateTo({
    url:
      '/pages/chat/chat?type=1&peer=' +
      encodeURIComponent('88888888') +
      '&id=' +
      encodeURIComponent('') +
      '&title=' +
      encodeURIComponent('红宝客服') +
      '&nickname=' +
      encodeURIComponent('红宝客服'),
  })
}

function applyProfile(p) {
  if (p && typeof p === 'object') profile.value = p
}

function onProfileUpdated(p) {
  applyProfile(p)
}

async function hydrateUser() {
  if (!getToken() || isLoginRoute()) {
    profile.value = null
    return
  }
  try {
    const p = await fetchProfile()
    applyProfile(p)
  } catch (e) {}
  try {
    const cfg = await fetchConfig()
    if (cfg) {
      const u = String(cfg.customer_service_url || cfg.login_cs_url || '').trim()
      if (u) csUrl.value = u
    }
  } catch (e2) {}
}

function refreshPad() {
  const r = applySafeAreaCssVars()
  padTop.value = r.top
}

onMounted(() => {
  refreshPad()
  locale.value = getLocale()
  offLocale = onLocaleChange((id) => {
    locale.value = id
  })
  hydrateUser()
  try {
    uni.$on && uni.$on('fanshub-profile-updated', onProfileUpdated)
  } catch (e) {}
})

onUnmounted(() => {
  if (offLocale) offLocale()
  try {
    uni.$off && uni.$off('fanshub-profile-updated', onProfileUpdated)
  } catch (e) {}
})
</script>

<style scoped>
.floating-top-bar {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  z-index: 20000;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding-left: 10px;
  padding-right: 10px;
  box-sizing: border-box;
  width: 100%;
  /* QQ 顶栏：白底 + 浅灰分割线 */
  background: #ffffff;
  border-bottom: 1px solid #e5e5e5;
  box-shadow: none;
  color: #191919;
  overflow: visible;
  pointer-events: auto;
  -webkit-transform: translateZ(0);
  transform: translateZ(0);
  -webkit-backface-visibility: hidden;
  backface-visibility: hidden;
}
.top-bar-spacer {
  width: 100%;
  flex-shrink: 0;
}
.brand {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
  flex: 1 1 auto;
  overflow: hidden;
  pointer-events: auto;
}
.logo {
  width: 46px;
  height: 46px;
  flex-shrink: 0;
  border-radius: 10px;
  pointer-events: none;
}
.brand-meta {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  justify-content: center;
  gap: 2px;
  min-width: 0;
  flex: 1 1 auto;
  overflow: hidden;
}
.nick {
  font-size: 14px;
  font-weight: 700;
  color: #191919;
  letter-spacing: 0.2px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 42vw;
  line-height: 1.15;
  pointer-events: none;
}
.actions {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
  position: relative;
  z-index: 2;
  pointer-events: auto;
  max-width: 100%;
  min-width: 0;
}
.bal {
  display: inline-flex;
  align-items: center;
  gap: 1px;
  max-width: 42vw;
  padding: 2px 8px 2px 7px;
  border-radius: 999px;
  background: rgba(7, 193, 96, 0.12);
  border: 1px solid rgba(7, 193, 96, 0.28);
  box-sizing: border-box;
  margin: 0;
  line-height: 1.15;
}
.bal-ico {
  font-size: 12px;
  font-weight: 700;
  color: #07c160;
  line-height: 1.15;
  flex-shrink: 0;
  font-family: inherit;
}
.bal-num {
  font-size: 12px;
  font-weight: 700;
  color: #07c160;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  line-height: 1.15;
}
.act-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 32px;
  padding: 0 10px;
  border-radius: 6px;
  box-sizing: border-box;
  font-size: 12px;
  font-weight: 700;
  line-height: 1;
  color: #191919;
}
.act-btn text {
  color: inherit;
  font-size: inherit;
  font-weight: inherit;
}
.act-btn--recharge {
  background: #07c160;
  color: #ffffff;
  border: 1px solid #07c160;
  box-shadow: none;
}
.act-btn--cs {
  background: #f7f7f7;
  border: 1px solid #e5e5e5;
  color: #191919;
}
.btn-hit {
  opacity: 0.88;
  transform: scale(0.97);
}
.lang-wrap {
  position: relative;
  z-index: 3;
  display: inline-flex;
  align-items: center;
  gap: 2px;
  box-sizing: border-box;
  min-height: 32px;
  padding: 3px 9px 3px 7px;
  border-radius: 6px;
  border: 1px solid #e5e5e5;
  overflow: hidden;
  isolation: isolate;
  background: #f7f7f7;
  box-shadow: none;
  transform: translateZ(0);
  cursor: pointer;
  pointer-events: auto;
  -webkit-tap-highlight-color: rgba(0, 0, 0, 0.06);
}
.lang-wrap-hover {
  opacity: 0.92;
  background: #eeeeee;
}
.lang-btn-glass {
  display: none;
}
.flag {
  position: relative;
  z-index: 1;
  width: 16px;
  height: 11px;
  border-radius: 2px;
  flex-shrink: 0;
  box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.08);
  pointer-events: none;
}
.lang-text,
.caret,
.panel-lab {
  pointer-events: none;
}
.lang-text {
  position: relative;
  z-index: 1;
  font-size: 10px;
  font-weight: 700;
  color: #191919;
  max-width: 56px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  letter-spacing: 0.2px;
}
.caret {
  position: relative;
  z-index: 1;
  font-size: 9px;
  color: #999999;
  margin-left: 1px;
  opacity: 0.9;
}
.lang-mask {
  position: fixed;
  inset: 0;
  z-index: 20010;
  background: transparent;
}
.lang-panel {
  position: fixed;
  right: 12px;
  z-index: 20020;
  min-width: 160px;
  max-height: 60vh;
  overflow-y: auto;
  background: #ffffff;
  border: 1px solid #e5e5e5;
  border-radius: 8px;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
  padding: 4px;
  -webkit-overflow-scrolling: touch;
  pointer-events: auto;
}
.panel-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 12px 12px;
  border-radius: 6px;
  font-size: 13px;
  font-weight: 600;
  color: #191919;
  cursor: pointer;
}
.panel-item.on,
.panel-item-hover {
  background: #f5f5f5;
  color: #07c160;
}
@media (max-width: 380px) {
  .logo {
    width: 42px;
    height: 42px;
  }
  .nick {
    font-size: 13px;
    max-width: 36vw;
  }
  .bal {
    max-width: 36vw;
  }
  .bal-num {
    font-size: 11px;
  }
  .act-btn {
    min-height: 30px;
    padding: 0 8px;
    font-size: 11px;
  }
  .lang-text {
    max-width: 36px;
  }
}
</style>

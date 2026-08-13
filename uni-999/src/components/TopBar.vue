<template>
  <view class="floating-top-bar" :style="barStyle">
    <view class="brand" @click="goHome">
      <image class="logo" :src="logoSrc" mode="aspectFit" />
      <text class="brand-text">{{ brand }}</text>
    </view>
    <view class="actions">
      <!-- 只用 @click：uni-app 会映射为 tap；再绑 @tap 会双触发导致开关立刻关闭 -->
      <view
        class="lang-wrap"
        hover-class="lang-wrap-hover"
        :hover-stay-time="80"
        @click.stop="toggleLang"
      >
        <image class="flag" :src="flagSrc" mode="aspectFill" />
        <text class="lang-text">{{ localeLabel }}</text>
        <text class="caret">▾</text>
      </view>
      <view class="fission-btn" hover-class="fission-btn-hover" @click.stop="goFission">
        <view class="fission-btn-glass" aria-hidden="true" />
        <text class="fission-ico">🧧</text>
        <text class="fission-lab">裂变红宝</text>
      </view>
    </view>
  </view>

  <!-- 面板/遮罩提到顶栏外，避免被顶栏 stacking / overflow 裁切或盖住 -->
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
  t,
} from '../utils/i18n.js'
import {
  applySafeAreaCssVars,
  getSafeAreaInsets,
  getTopBarContentHeight,
} from '../utils/safe-area.js'

defineProps({
  /** 为 true 时不插 spacer（页面自己用 padding 避让）；红宝会话页请用默认 spacer */
  noSpacer: { type: Boolean, default: false },
  /** @deprecated 全局已统一为裂变红包入口，保留兼容旧调用 */
  fissionLink: { type: Boolean, default: true },
})

const locale = ref(getLocale())
const langOpen = ref(false)
/** 状态栏垫高（px），内联保证不被 page CSS 变量盖掉 */
const padTop = ref(getSafeAreaInsets().top)
let offLocale = null
let lastToggleAt = 0
let pickingLang = false

const logoSrc = logoUrl()
const brand = computed(() => {
  void locale.value
  return t('brand_name')
})
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
  return {
    height: h + p + 'px',
  }
})
const langPanelStyle = computed(() => {
  const p = Math.max(0, Number(padTop.value) || 0)
  const h = getTopBarContentHeight()
  return {
    top: p + h + 4 + 'px',
  }
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
  // 防双击/双事件：300ms 内只响应一次
  if (now - lastToggleAt < 300) return
  lastToggleAt = now
  langOpen.value = !langOpen.value
}

function goFission() {
  closePanels()
  uni.switchTab({
    url: '/pages/fission/detail',
    fail: () => uni.navigateTo({ url: '/pages/fission/detail' }),
  })
}

async function pickLocale(id) {
  pickingLang = true
  langOpen.value = false
  try {
    if (id === locale.value) return
    await setLocale(id)
    locale.value = id
  } catch (e) {
    // 切语言失败不弹「请求失败」，保留当前语言
    console.warn('setLocale', e)
  } finally {
    setTimeout(() => {
      pickingLang = false
    }, 120)
  }
}

function goHome() {
  closePanels()
  uni.switchTab({
    url: '/pages/home/home',
    fail: () => uni.reLaunch({ url: '/pages/login/login' }),
  })
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
})

onUnmounted(() => {
  if (offLocale) offLocale()
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
  padding-left: 12px;
  padding-right: 12px;
  box-sizing: border-box;
  background: #ffffff;
  border-bottom: 1px solid rgba(101, 119, 134, 0.18);
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
  overflow: visible;
  pointer-events: auto;
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
  pointer-events: auto;
}
.logo {
  width: 34px;
  height: 34px;
  flex-shrink: 0;
  pointer-events: none;
}
.brand-text {
  font-size: 16px;
  font-weight: 900;
  color: #e80000;
  letter-spacing: 0.2px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 28vw;
  pointer-events: none;
}
.actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
  position: relative;
  z-index: 2;
  pointer-events: auto;
}
.lang-wrap {
  position: relative;
  z-index: 3;
  display: flex;
  align-items: center;
  gap: 3px;
  min-height: 32px;
  padding: 4px 8px;
  border-radius: 8px;
  border: 1px solid rgba(101, 119, 134, 0.26);
  background: #fafbfc;
  cursor: pointer;
  pointer-events: auto;
  -webkit-tap-highlight-color: rgba(232, 59, 26, 0.12);
}
.lang-wrap-hover {
  background: #fff4ec;
  border-color: rgba(232, 59, 26, 0.35);
}
.fission-btn {
  position: relative;
  display: inline-flex;
  align-items: center;
  gap: 2px;
  min-height: 32px;
  padding: 3px 9px 3px 7px;
  border-radius: 999px;
  border: 1px solid rgba(255, 255, 255, 0.42);
  overflow: hidden;
  isolation: isolate;
  background:
    linear-gradient(160deg, rgba(255, 120, 90, 0.55) 0%, rgba(220, 40, 40, 0.35) 42%, rgba(160, 20, 30, 0.45) 100%),
    linear-gradient(135deg, #ff5a3a 0%, #e12626 52%, #b01018 100%);
  box-shadow:
    0 3px 10px rgba(176, 16, 24, 0.38),
    0 1px 0 rgba(255, 200, 160, 0.32),
    inset 0 1px 0 rgba(255, 255, 255, 0.55),
    inset 0 -1px 3px rgba(90, 0, 0, 0.28);
  transform: translateZ(0);
  cursor: pointer;
  pointer-events: auto;
}
.fission-btn-hover {
  opacity: 0.92;
}
.fission-btn-glass {
  position: absolute;
  inset: 0;
  border-radius: inherit;
  pointer-events: none;
  z-index: 0;
  background:
    linear-gradient(180deg, rgba(255, 255, 255, 0.38) 0%, rgba(255, 255, 255, 0.06) 42%, rgba(255, 255, 255, 0) 70%),
    radial-gradient(120% 80% at 20% 0%, rgba(255, 255, 255, 0.35), transparent 55%);
}
.fission-ico,
.fission-lab {
  position: relative;
  z-index: 1;
  pointer-events: none;
}
.fission-ico {
  font-size: 11px;
  line-height: 1;
  filter: drop-shadow(0 1px 1px rgba(0, 0, 0, 0.2));
}
.fission-lab {
  font-size: 10px;
  font-weight: 900;
  color: #fff;
  white-space: nowrap;
  letter-spacing: 0.2px;
  text-shadow: 0 1px 2px rgba(80, 0, 0, 0.35);
  transform-origin: center center;
  animation: fission-lab-breathe 1.8s ease-in-out infinite;
}
@keyframes fission-lab-breathe {
  0%, 100% { transform: scale(1); opacity: 0.92; }
  50% { transform: scale(1.1); opacity: 1; }
}
.flag {
  width: 18px;
  height: 12px;
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
  font-size: 10px;
  font-weight: 800;
  color: var(--text-main, #1a212d);
  max-width: 56px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.caret {
  font-size: 9px;
  color: var(--text-muted, #657786);
  margin-left: 1px;
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
  background: #fff;
  border: 1px solid #e1e8ed;
  border-radius: 10px;
  box-shadow: 0 10px 28px rgba(0, 0, 0, 0.12);
  padding: 4px;
  -webkit-overflow-scrolling: touch;
  pointer-events: auto;
}
.panel-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 12px 12px;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 700;
  color: var(--text-main, #1a212d);
  cursor: pointer;
}
.panel-item.on,
.panel-item-hover {
  background: #fff4ec;
  color: #e83b1a;
}
@media (max-width: 480px) {
  .brand { max-width: 42vw; }
  .fission-lab { font-size: 9px; }
  .fission-btn { padding: 2px 8px 2px 6px; min-height: 28px; }
}
</style>

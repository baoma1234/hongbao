<template>
  <view class="floating-top-bar">
    <view class="brand" @click="goHome">
      <image class="logo" :src="logoSrc" mode="aspectFit" />
      <text class="brand-text">{{ brand }}</text>
    </view>
    <view class="actions">
      <view class="lang-wrap" @click.stop="toggleLang">
        <image class="flag" :src="flagSrc" mode="aspectFill" />
        <text class="lang-text">{{ localeLabel }}</text>
        <text class="caret">▾</text>
      </view>
      <view class="fission-btn" @click.stop="goFission">
        <text class="fission-ico">🧧</text>
        <text class="fission-lab">裂变红包</text>
      </view>
    </view>

    <view v-if="langOpen" class="mask" @click="closePanels" />

    <view v-if="langOpen" class="panel lang-panel" @click.stop>
      <view
        v-for="opt in locales"
        :key="opt.id"
        class="panel-item"
        :class="{ on: opt.id === locale }"
        @click="pickLocale(opt.id)"
      >
        <image class="flag" :src="flagOf(opt.flagIso)" mode="aspectFill" />
        <text>{{ opt.label }}</text>
      </view>
    </view>
  </view>
  <view v-if="!noSpacer" class="top-bar-spacer" />
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

defineProps({
  /** 红宝等页用 body padding-top 占位，勿再插 spacer 以免双倍空隙 */
  noSpacer: { type: Boolean, default: false },
  /** @deprecated 全局已统一为裂变红包入口，保留兼容旧调用 */
  fissionLink: { type: Boolean, default: true },
})

const locale = ref(getLocale())
const langOpen = ref(false)
let offLocale = null

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

function flagOf(iso) {
  return flagUrl(iso)
}

function closePanels() {
  langOpen.value = false
}

function toggleLang() {
  langOpen.value = !langOpen.value
}

function goFission() {
  closePanels()
  uni.navigateTo({
    url: '/pages/fission/detail',
    fail: () => uni.showToast({ title: '无法打开裂变红包', icon: 'none' }),
  })
}

async function pickLocale(id) {
  closePanels()
  if (id === locale.value) return
  await setLocale(id)
  locale.value = id
}

function goHome() {
  closePanels()
  uni.switchTab({
    url: '/pages/home/home',
    fail: () => uni.reLaunch({ url: '/pages/login/login' }),
  })
}

onMounted(() => {
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
  z-index: 14000;
  height: var(--top-bar-height, 48px);
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 12px;
  box-sizing: border-box;
  background: color-mix(in srgb, var(--bg-card, #fff) 94%, transparent);
  border-bottom: 1px solid color-mix(in srgb, var(--text-muted, #657786) 18%, transparent);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
}
.mask {
  position: fixed;
  inset: 0;
  z-index: 13990;
  background: transparent;
}
.top-bar-spacer {
  height: var(--top-bar-height, 48px);
  width: 100%;
  flex-shrink: 0;
}
.brand {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}
.logo {
  width: 34px;
  height: 34px;
  flex-shrink: 0;
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
}
.actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}
.lang-wrap {
  display: flex;
  align-items: center;
  gap: 4px;
  min-height: 32px;
  padding: 4px 8px;
  border-radius: 10px;
  border: 1px solid color-mix(in srgb, var(--text-muted, #657786) 26%, transparent);
  background: color-mix(in srgb, var(--bg-main, #f4f6f9) 6%, var(--bg-card, #fff) 94%);
}
.fission-btn {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  min-height: 32px;
  padding: 5px 12px 5px 9px;
  border-radius: 999px;
  border: none;
  background: linear-gradient(135deg, #ff6b3d 0%, #e53935 55%, #c62828 100%);
  box-shadow:
    0 4px 12px rgba(198, 40, 40, 0.35),
    inset 0 1px 0 rgba(255, 255, 255, 0.28);
}
.fission-btn:active {
  opacity: 0.92;
  transform: translateY(1px);
  box-shadow: 0 2px 8px rgba(198, 40, 40, 0.28);
}
.fission-ico {
  font-size: 13px;
  line-height: 1;
}
.fission-lab {
  font-size: 12px;
  font-weight: 900;
  color: #fff;
  white-space: nowrap;
  letter-spacing: 0.4px;
  text-shadow: 0 1px 0 rgba(0, 0, 0, 0.12);
}
.flag {
  width: 22px;
  height: 15px;
  border-radius: 2px;
  flex-shrink: 0;
  box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.08);
}
.lang-text {
  font-size: 11px;
  font-weight: 800;
  color: var(--text-main, #1a212d);
  max-width: 72px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.caret {
  font-size: 10px;
  color: var(--text-muted, #657786);
  margin-left: 2px;
}
.panel {
  position: absolute;
  top: calc(100% + 4px);
  z-index: 2;
  min-width: 148px;
  max-height: 260px;
  overflow-y: auto;
  background: #fff;
  border: 1px solid #e1e8ed;
  border-radius: 10px;
  box-shadow: 0 10px 28px rgba(0, 0, 0, 0.12);
  padding: 4px;
}
.lang-panel { right: 12px; }
.panel-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 12px;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 700;
  color: var(--text-main, #1a212d);
}
.panel-item.on,
.panel-item:active {
  background: #fff4ec;
  color: #e83b1a;
}
@media (max-width: 480px) {
  .brand { max-width: 42vw; }
  .fission-lab { font-size: 11px; }
  .fission-btn { padding: 5px 10px 5px 8px; }
}
</style>

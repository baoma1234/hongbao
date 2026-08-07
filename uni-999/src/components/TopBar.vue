<template>
  <view class="floating-top-bar" :class="{ flash: skinFlash }">
    <view class="brand" @click="goHome">
      <image
        class="brand-img"
        src="https://888jhdhifhbchashjdl.oss-accelerate.aliyuncs.com/uploads/brand/qhb-brand.png"
        mode="heightFix"
      />
    </view>
    <view class="actions">
      <view class="lang-wrap" @click.stop="toggleLang">
        <image class="flag" :src="flagSrc" mode="aspectFill" />
        <text class="lang-text">{{ localeLabel }}</text>
        <text class="caret">▾</text>
      </view>
      <view class="skin-wrap" @click.stop="toggleSkin">
        <text class="skin-lab">{{ skinLabel }}</text>
        <text class="skin-val">{{ skinName }}</text>
        <text class="caret">▾</text>
      </view>
    </view>

    <view v-if="langOpen || skinOpen" class="mask" @click="closePanels" />

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

    <view v-if="skinOpen" class="panel skin-panel" @click.stop>
      <view
        v-for="opt in skins"
        :key="opt.id"
        class="panel-item"
        :class="{ on: opt.id === skinId }"
        @click="pickSkin(opt.id)"
      >
        <text>{{ skinOptLabel(opt) }}</text>
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
  onLocaleChange,
  setLocale,
  t,
} from '../utils/i18n.js'
import {
  applySkin,
  getSkinId,
  onSkinChange,
  SKIN_OPTIONS,
} from '../utils/skin.js'

defineProps({
  /** 红宝等页用 body padding-top 占位，勿再插 spacer 以免双倍空隙 */
  noSpacer: { type: Boolean, default: false },
})

const locale = ref(getLocale())
const skinId = ref(getSkinId())
const langOpen = ref(false)
const skinOpen = ref(false)
const skinFlash = ref(false)
let offLocale = null
let offSkin = null
let flashTimer = null

const skinLabel = computed(() => {
  void locale.value
  return t('skin_label')
})
const locales = computed(() => {
  void locale.value
  return localeOptions()
})
const skins = SKIN_OPTIONS

const localeLabel = computed(() => {
  const opt = locales.value.find((x) => x.id === locale.value)
  return (opt && opt.label) || locale.value
})
const flagSrc = computed(() => {
  const opt = locales.value.find((x) => x.id === locale.value)
  return flagOf(opt ? opt.flagIso : 'cn')
})
const skinName = computed(() => {
  void locale.value
  const opt = skins.find((x) => x.id === skinId.value) || skins[0]
  return skinOptLabel(opt)
})

function flagOf(iso) {
  return flagUrl(iso)
}

function skinOptLabel(opt) {
  return t(opt.labelKey)
}

function closePanels() {
  langOpen.value = false
  skinOpen.value = false
}

function toggleLang() {
  skinOpen.value = false
  langOpen.value = !langOpen.value
}

function toggleSkin() {
  langOpen.value = false
  skinOpen.value = !skinOpen.value
}

async function pickLocale(id) {
  closePanels()
  if (id === locale.value) return
  await setLocale(id)
  locale.value = id
}

function pickSkin(id) {
  closePanels()
  applySkin(id, { flash: true })
  skinId.value = id
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
  skinId.value = getSkinId()
  offLocale = onLocaleChange((id) => {
    locale.value = id
  })
  offSkin = onSkinChange((id, flash) => {
    skinId.value = id
    if (flash) {
      skinFlash.value = true
      if (flashTimer) clearTimeout(flashTimer)
      flashTimer = setTimeout(() => {
        skinFlash.value = false
      }, 420)
    }
  })
})

onUnmounted(() => {
  if (offLocale) offLocale()
  if (offSkin) offSkin()
  if (flashTimer) clearTimeout(flashTimer)
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
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.mask {
  position: fixed;
  inset: 0;
  z-index: 13990;
  background: transparent;
}
.floating-top-bar.flash {
  border-bottom-color: color-mix(in srgb, var(--primary, #c61114) 50%, transparent);
  box-shadow:
    0 4px 20px rgba(0, 0, 0, 0.08),
    0 2px 0 color-mix(in srgb, var(--primary, #c61114) 35%, transparent);
}
.top-bar-spacer {
  height: var(--top-bar-height, 48px);
  width: 100%;
  flex-shrink: 0;
}
.brand {
  display: flex;
  align-items: center;
  min-width: 0;
  max-width: 46vw;
}
.brand-img {
  height: 36px;
  width: auto;
  max-width: 100%;
  flex-shrink: 0;
  border-radius: 6px;
  display: block;
}
.actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}
.lang-wrap,
.skin-wrap {
  display: flex;
  align-items: center;
  gap: 4px;
  min-height: 32px;
  padding: 4px 8px;
  border-radius: 10px;
  border: 1px solid color-mix(in srgb, var(--text-muted, #657786) 26%, transparent);
  background: color-mix(in srgb, var(--bg-main, #f4f6f9) 6%, var(--bg-card, #fff) 94%);
}
.flag {
  width: 22px;
  height: 15px;
  border-radius: 2px;
  flex-shrink: 0;
  box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.08);
}
.lang-text,
.skin-val {
  font-size: 11px;
  font-weight: 800;
  color: var(--text-main, #1a212d);
  max-width: 72px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.skin-lab {
  font-size: 10px;
  font-weight: 900;
  color: var(--text-muted, #657786);
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
.lang-panel { right: 120px; }
.skin-panel { right: 12px; }
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
  .brand-img { height: 32px; }
  .skin-val { max-width: 56px; }
  .lang-panel { right: 96px; }
}
</style>

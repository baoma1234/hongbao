<template>
  <view class="login-page">
    <!-- 全屏背景：CSS cover + top，四端避免 image aspectFill 裁切顶部 -->
    <view class="login-page-bg" :style="heroBgStyle" aria-hidden="true" />

    <!-- 语言：叠在背景图右上（无公共顶栏） -->
    <view class="login-chrome" :style="chromeStyle">
      <view
        class="login-lang-wrap"
        hover-class="login-lang-wrap--hover"
        :hover-stay-time="80"
        @click.stop="toggleLang"
      >
        <image class="login-lang-flag" :src="flagSrc" mode="aspectFill" />
        <text class="login-lang-text">{{ localeLabel }}</text>
        <text class="login-lang-caret">▾</text>
      </view>
    </view>
    <view
      v-if="langOpen"
      class="login-lang-mask"
      @click="closeLang"
      @touchmove.stop.prevent="noop"
    />
    <view v-if="langOpen" class="login-lang-panel" :style="langPanelStyle" @click.stop>
      <view
        v-for="opt in locales"
        :key="opt.id"
        class="login-lang-item"
        :class="{ on: opt.id === localeId }"
        hover-class="login-lang-item--hover"
        @click.stop="pickLocale(opt.id)"
      >
        <image class="login-lang-flag" :src="flagUrl(opt.flagIso)" mode="aspectFill" />
        <text class="login-lang-item-lab">{{ opt.label }}</text>
      </view>
    </view>

    <scroll-view scroll-y class="login-scroll" :show-scrollbar="false">
      <view class="login-shell">
        <!-- 背景图已含品牌与 slogan，此处仅留表单上方留白 -->
        <view class="login-hero-spacer" :style="heroSpacerStyle" aria-hidden="true" />

        <view class="login-card">
          <view class="login-card-hd">
            <view class="login-card-title-row">
              <text class="login-diamond">◆</text>
              <text class="login-card-title">{{ welcomeTitle }}</text>
              <text class="login-diamond">◆</text>
            </view>
            <text class="login-card-sub">{{ welcomeSub }}</text>
          </view>

          <view class="input-group">
            <view class="phone-row">
              <view class="country-select" @click="countryOpen = !countryOpen">
                <image class="flag" :src="flagUrl(countryMeta.flagIso)" mode="aspectFill" />
                <text class="dial">+{{ countryMeta.dial }}</text>
                <text class="caret">▾</text>
              </view>
              <input
                class="login-input phone-input"
                type="number"
                :maxlength="countryMeta.maxlen"
                v-model="mobile"
                :placeholder="phonePlaceholder"
                @input="onPhoneInput"
              />
            </view>
            <view v-if="countryOpen" class="country-panel">
              <view
                v-for="c in countries"
                :key="c.code"
                class="country-item"
                :class="{ on: c.code === country }"
                @click="pickCountry(c.code)"
              >
                <image class="flag" :src="flagUrl(c.flagIso)" mode="aspectFill" />
                <text class="cname">{{ t(c.labelKey) || c.code }}</text>
                <text class="cdial">+{{ c.dial }}</text>
              </view>
            </view>
          </view>

          <view class="input-group captcha-group">
            <view class="captcha-row">
              <view class="captcha-field">
                <text class="captcha-lock">🔒</text>
                <input
                  class="login-input captcha-input"
                  type="number"
                  maxlength="6"
                  v-model="captcha"
                  :placeholder="t('login_captcha_placeholder') || '请输入验证码'"
                />
              </view>
              <button
                class="captcha-btn"
                :class="{ disabled: smsLeft > 0 || sending }"
                :disabled="smsLeft > 0 || sending"
                @click="onSendSms"
              >
                {{ smsBtnText }}
              </button>
            </view>
          </view>

          <!-- 邀请码输入先隐藏：URL / OpenInstall 仍自动灌入 inviteCode 并随登录提交 -->
          <view v-if="false" class="input-group">
            <view class="input-label">{{ tt('login_invite_label', '🎁 邀请码（选填）') }}</view>
            <input
              class="login-input invite-input"
              type="text"
              maxlength="16"
              confirm-type="done"
              v-model="inviteCode"
              :placeholder="tt('login_invite_placeholder', '没有邀请码可留空')"
              @input="onInviteInput"
            />
          </view>

          <button class="btn-login-submit" :loading="loading" @click="onLogin">
            {{ loginSubmitText }}
          </button>

          <view
            v-if="csVisible"
            class="login-cs-link"
            hover-class="login-cs-link--active"
            role="link"
            @click="openLoginCs"
          >
            <text class="login-cs-link-text">{{ csLinkText }}</text>
          </view>
        </view>

        <view class="login-foot">
          <text class="login-foot-main">{{ footMain }}</text>
          <text class="login-foot-copy">{{ footCopy }}</text>
        </view>
      </view>
    </scroll-view>

    <SliderCaptcha ref="sliderRef" @success="onSliderOk" @cancel="onSliderCancel" />
  </view>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import SliderCaptcha from '../../components/SliderCaptcha.vue'
import { fetchConfig, getToken, login, sendSms } from '../../utils/auth.js'
import {
  applyServerCopy,
  copyState,
  flagUrl,
  getLocale,
  localeOptions,
  localeState,
  onLocaleChange,
  setLocale,
  t,
  tt,
} from '../../utils/i18n.js'
import { imConnect } from '../../utils/im.js'
import { syncRegistrationAfterLogin } from '../../utils/jpush.js'
import { openExternalHttpUrl } from '../../utils/wallet.js'
import {
  LOGIN_COUNTRIES,
  getCountryMeta,
  isValidNational,
  phoneInvalidKey,
  readStoredCountry,
  setStoredCountry,
  toE164,
} from '../../utils/login-country.js'
import {
  hydrateInviteCode,
  initOpenInstall,
  saveInviteCode,
  subscribeInviteCode,
} from '../../utils/openinstall.js'
import { getUploadsBase, packagedStaticUrl } from '../../utils/config.js'
import { applySafeAreaCssVars, getSafeAreaInsets } from '../../utils/safe-area.js'

const SMS_COOLDOWN_KEY = 'fanshub_sms_cooldown'
const LOGIN_BG_VER = '3'

const locale = localeState()
const copyTick = copyState()
const countries = LOGIN_COUNTRIES
const country = ref(readStoredCountry())
const countryOpen = ref(false)
const mobile = ref('')
const captcha = ref('')
const inviteCode = ref('')
const inviteTouched = ref(false)
const loading = ref(false)
const sending = ref(false)
const smsLeft = ref(0)
const smsSliderEnabled = ref(true)
const smsInterval = ref(60)
const registerRights = ref(5)
const sliderRef = ref(null)
const csEnabled = ref(true)
const csUrl = ref('')
let timer = null
let offLocale = null
let offInvite = null
let pendingSmsPhone = ''
let lastLangToggleAt = 0
let pickingLang = false

const heroBg = computed(() => {
  const oss = String(getUploadsBase() || '').replace(/\/+$/, '')
  if (oss) {
    return oss + '/999/static/login/bg-hero.jpg?v=' + LOGIN_BG_VER
  }
  return packagedStaticUrl('login/bg-hero.jpg') + '?v=' + LOGIN_BG_VER
})
const heroBgStyle = computed(() => ({
  backgroundImage: 'url("' + String(heroBg.value || '').replace(/\\/g, '/').replace(/"/g, '%22') + '")',
}))

const localeId = ref(getLocale())
const langOpen = ref(false)
const padTop = ref(getSafeAreaInsets().top)

const locales = computed(() => {
  void localeId.value
  return localeOptions()
})
const localeLabel = computed(() => {
  const opt = locales.value.find((x) => x.id === localeId.value)
  return (opt && opt.label) || localeId.value
})
const flagSrc = computed(() => {
  const opt = locales.value.find((x) => x.id === localeId.value)
  return flagUrl(opt ? opt.flagIso : 'cn')
})
const chromeStyle = computed(() => ({
  /* 语言按钮相对状态栏再下移一点，避免贴顶 */
  paddingTop: Math.max(0, Number(padTop.value) || 0) + 12 + 'px',
}))
const langPanelStyle = computed(() => {
  const top = Math.max(0, Number(padTop.value) || 0) + 12 + 52
  return { top: top + 'px' }
})
/** 表单上方留白含状态栏，避免内容顶到刘海/状态栏 */
const heroSpacerStyle = computed(() => ({
  paddingTop: Math.max(0, Number(padTop.value) || 0) + 'px',
}))

function noop() {}
function closeLang() {
  if (pickingLang) return
  langOpen.value = false
}
function toggleLang() {
  const now = Date.now()
  if (now - lastLangToggleAt < 300) return
  lastLangToggleAt = now
  langOpen.value = !langOpen.value
}
async function pickLocale(id) {
  pickingLang = true
  langOpen.value = false
  try {
    if (id === localeId.value) return
    await setLocale(id)
    localeId.value = id
  } catch (e) {
    console.warn('setLocale', e)
  } finally {
    setTimeout(() => {
      pickingLang = false
    }, 120)
  }
}

const countryMeta = computed(() => getCountryMeta(country.value))
const csVisible = computed(() => !!csEnabled.value)
const csLinkText = computed(() => {
  void locale.value
  void copyTick.value
  return tt('login_cs_link', '联系在线客服')
})
const phonePlaceholder = computed(() => {
  void locale.value
  return t(countryMeta.value.placeholderKey) || t('login_phone_placeholder') || '请输入您的手机号'
})
const smsBtnText = computed(() => {
  void locale.value
  if (smsLeft.value > 0) {
    return t('login_captcha_resend', { count: smsLeft.value }) || smsLeft.value + 's'
  }
  return t('login_captcha_btn') || '获取验证码'
})
const loginSubmitText = computed(() => {
  void locale.value
  void copyTick.value
  return tt('login_enter_game', '登录 / 进入游戏')
})
const welcomeTitle = computed(() => {
  void locale.value
  void copyTick.value
  return tt('login_welcome_title', '欢迎来到{brand}', { brand: t('brand_name') || '红宝' })
})
const welcomeSub = computed(() => {
  void locale.value
  void copyTick.value
  return tt('login_welcome_sub', '登录开启好运之旅')
})
const footMain = computed(() => {
  void locale.value
  void copyTick.value
  return tt('login_foot_main', '{brand} | 快乐游戏 好运常在', { brand: t('brand_name') || '红宝' })
})
const footCopy = computed(() => {
  void locale.value
  void copyTick.value
  return tt('login_foot_copy', '© {year} 红宝游戏 All Rights Reserved', { year: new Date().getFullYear() })
})

/** 从裂变等页点功能进登录时，登录成功后回到原页 */
function resolvePostLoginUrl() {
  try {
    const u = String(uni.getStorageSync('fanshub_login_return') || '').trim()
    uni.removeStorageSync('fanshub_login_return')
    if (u.indexOf('/pages/') === 0 && u.indexOf('..') < 0) return u
  } catch (e) {}
  return '/pages/messages/messages'
}

if (getToken()) {
  uni.reLaunch({ url: resolvePostLoginUrl() })
}

onLoad((q) => {
  initOpenInstall()
  let fromQuery = (q && (q.code || q.invite)) || ''
  // #ifdef H5
  try {
    if (typeof location !== 'undefined' && location.search) {
      const sp = new URLSearchParams(location.search)
      fromQuery = sp.get('code') || sp.get('invite') || fromQuery
    }
  } catch (e) {}
  // #endif
  inviteCode.value = hydrateInviteCode(fromQuery)
  offInvite = subscribeInviteCode((code) => {
    if (inviteTouched.value) return
    if (!String(inviteCode.value || '').trim() && code) {
      inviteCode.value = String(code)
      saveInviteCode(code)
    }
  })
})

function onInviteInput() {
  inviteTouched.value = true
  const v = String(inviteCode.value || '').replace(/[^\d]/g, '').slice(0, 16)
  inviteCode.value = v
  if (v) saveInviteCode(v)
}

function getSmsCooldownRemain(phone) {
  if (!phone) return 0
  try {
    const map = JSON.parse(uni.getStorageSync(SMS_COOLDOWN_KEY) || '{}')
    const until = map[phone]
    if (!until) return 0
    const remain = Math.ceil((until - Date.now()) / 1000)
    return remain > 0 ? remain : 0
  } catch (e) {
    return 0
  }
}

function setSmsCooldownUntil(phone, seconds) {
  if (!phone || seconds <= 0) return
  try {
    const map = JSON.parse(uni.getStorageSync(SMS_COOLDOWN_KEY) || '{}')
    map[phone] = Date.now() + seconds * 1000
    uni.setStorageSync(SMS_COOLDOWN_KEY, JSON.stringify(map))
  } catch (e) {}
}

function startCooldown(phone, sec) {
  const total = Math.max(1, parseInt(sec, 10) || smsInterval.value || 60)
  setSmsCooldownUntil(phone, total)
  smsLeft.value = total
  if (timer) clearInterval(timer)
  timer = setInterval(() => {
    const remain = getSmsCooldownRemain(phone)
    smsLeft.value = remain
    if (remain <= 0) {
      clearInterval(timer)
      timer = null
      smsLeft.value = 0
    }
  }, 1000)
}

function syncCooldownFromStorage() {
  const phone = toE164(mobile.value, country.value)
  if (!phone) {
    smsLeft.value = 0
    return
  }
  const remain = getSmsCooldownRemain(phone)
  if (remain > 0) startCooldown(phone, remain)
  else smsLeft.value = 0
}

function onPhoneInput() {
  syncCooldownFromStorage()
}

function pickCountry(code) {
  country.value = setStoredCountry(code)
  countryOpen.value = false
  mobile.value = ''
  syncCooldownFromStorage()
}

async function loadCfg() {
  const cfg = await fetchConfig()
  if (!cfg) return
  if (cfg.copy) applyServerCopy(cfg.copy)
  if (cfg.register_rights != null && cfg.register_rights !== '') {
    const n = parseInt(cfg.register_rights, 10)
    if (n > 0) registerRights.value = n
  }
  if (cfg.sms_slider_enabled === false) smsSliderEnabled.value = false
  else smsSliderEnabled.value = true
  if (cfg.sms_send_interval != null) {
    const n = parseInt(cfg.sms_send_interval, 10)
    if (n > 0) smsInterval.value = n
  }
  if (cfg.login_cs_enabled === false) csEnabled.value = false
  else csEnabled.value = true
  const url = String(cfg.login_cs_url || cfg.customer_service_url || '').trim()
  csUrl.value = url
}

function openLoginCs() {
  const url = String(csUrl.value || '').trim()
  if (!url || !/^https?:\/\//i.test(url)) {
    uni.showToast({ title: tt('alert_cs_not_configured', '客服链接未配置，请联系管理员在后台设置。'), icon: 'none' })
    return
  }
  if (!openExternalHttpUrl(url)) {
    uni.showToast({ title: tt('alert_cs_not_configured', '客服链接未配置，请联系管理员在后台设置。'), icon: 'none' })
  }
}

async function doSendSms(phone, sliderPayload) {
  sending.value = true
  try {
    const data = await sendSms(phone, Object.assign({
      country_code: country.value,
    }, sliderPayload || {}))
    const interval = (data && data.retry_after)
      ? parseInt(data.retry_after, 10)
      : (smsInterval.value || 60)
    startCooldown(phone, interval)
    if (data && data.mock_code) {
      captcha.value = String(data.mock_code)
    }
    const hint = (data && data.hint) ? data.hint : (t('alert_sms_hint_default') || '')
    uni.showToast({
      title: (t('alert_sms_sent') || '已发送') + (hint ? '\n' + hint : ''),
      icon: 'none',
      duration: 2600,
    })
  } catch (e) {
    if (e.payload && e.payload.retry_after) startCooldown(phone, e.payload.retry_after)
    uni.showToast({ title: e.message || t('alert_sms_fail') || '发送失败', icon: 'none' })
  } finally {
    sending.value = false
    pendingSmsPhone = ''
  }
}

function onSliderOk(payload) {
  const phone = pendingSmsPhone
  if (!phone) return
  doSendSms(phone, payload || {})
}

function onSliderCancel() {
  sending.value = false
  pendingSmsPhone = ''
}

async function onSendSms() {
  countryOpen.value = false
  const phone = toE164(mobile.value, country.value)
  if (!phone || !isValidNational(mobile.value, country.value)) {
    uni.showToast({
      title: t(phoneInvalidKey(country.value)) || t('alert_phone_invalid') || '手机号不正确',
      icon: 'none',
    })
    return
  }
  const remain = getSmsCooldownRemain(phone)
  if (remain > 0) {
    startCooldown(phone, remain)
    uni.showToast({
      title: t('api_sms_too_frequent_wait', { seconds: remain }) || ('请稍后再试 ' + remain + 's'),
      icon: 'none',
    })
    return
  }
  await loadCfg()
  if (smsSliderEnabled.value) {
    pendingSmsPhone = phone
    sending.value = true
    if (sliderRef.value && typeof sliderRef.value.open === 'function') {
      sliderRef.value.open()
    }
    return
  }
  await doSendSms(phone, {})
}

async function onLogin() {
  countryOpen.value = false
  const phone = toE164(mobile.value, country.value)
  const code = String(captcha.value || '').trim()
  if (!String(mobile.value || '').trim()) {
    uni.showToast({ title: t('alert_phone_required') || '请输入手机号', icon: 'none' })
    return
  }
  if (!phone || !isValidNational(mobile.value, country.value)) {
    uni.showToast({
      title: t(phoneInvalidKey(country.value)) || t('alert_phone_invalid') || '手机号不正确',
      icon: 'none',
    })
    return
  }
  if (!code) {
    uni.showToast({ title: t('alert_captcha_required') || '请输入验证码', icon: 'none' })
    return
  }
  loading.value = true
  try {
    await loadCfg()
    const data = await login(phone, code, String(inviteCode.value || '').trim(), {
      country_code: country.value,
    })
    uni.showToast({
      title: data && data.is_new
        ? (t('alert_login_new') || '注册成功')
        : (t('alert_login_back') || '登录成功'),
      icon: 'none',
    })
    if (data && data.is_new) {
      try {
        if (typeof sessionStorage !== 'undefined') sessionStorage.setItem('fans_hub_show_lottery', '1')
      } catch (e0) {}
      try {
        uni.setStorageSync('fans_hub_show_lottery', '1')
      } catch (e1) {}
    }
    try {
      await imConnect()
      syncRegistrationAfterLogin()
    } catch (e) {
      console.warn('im connect', e)
    }
    uni.reLaunch({ url: resolvePostLoginUrl() })
  } catch (e) {
    uni.showToast({ title: e.message || t('alert_login_fail') || '登录失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  const insets = applySafeAreaCssVars()
  padTop.value = (insets && insets.top != null) ? insets.top : getSafeAreaInsets().top
  localeId.value = getLocale()
  loadCfg()
  syncCooldownFromStorage()
  offLocale = onLocaleChange((id) => {
    localeId.value = id
  })
})

onUnmounted(() => {
  if (timer) clearInterval(timer)
  if (typeof offLocale === 'function') offLocale()
  if (typeof offInvite === 'function') offInvite()
})
</script>

<style scoped>
.login-page {
  min-height: 100vh;
  min-height: 100dvh;
  min-height: -webkit-fill-available;
  background: #8b020a;
  box-sizing: border-box;
  position: relative;
  overflow: hidden;
}
.login-page-bg {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  width: 100%;
  height: 100%;
  min-height: 100%;
  z-index: 0;
  pointer-events: none;
  background-color: #8b020a;
  background-repeat: no-repeat;
  background-position: center top;
  background-size: cover;
  -webkit-background-size: cover;
}
.login-chrome {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  z-index: 20;
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 10px;
  padding-left: 14px;
  padding-right: 14px;
  padding-bottom: 6px;
  box-sizing: border-box;
  pointer-events: none;
}
.login-lang-wrap {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  min-height: 32px;
  padding: 4px 10px 4px 8px;
  border-radius: 999px;
  box-sizing: border-box;
  background: rgba(255, 255, 255, 0.92);
  border: 1px solid rgba(255, 255, 255, 0.95);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.18);
  pointer-events: auto;
  -webkit-tap-highlight-color: rgba(0, 0, 0, 0.06);
}
.login-lang-wrap--hover {
  opacity: 0.92;
  transform: scale(0.98);
}
.login-lang-flag {
  width: 16px;
  height: 11px;
  border-radius: 2px;
  flex-shrink: 0;
  box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.08);
  pointer-events: none;
}
.login-lang-text {
  font-size: 11px;
  font-weight: 700;
  color: #1a1a1a;
  max-width: 64px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  pointer-events: none;
}
.login-lang-caret {
  font-size: 9px;
  color: #888;
  pointer-events: none;
}
.login-lang-mask {
  position: fixed;
  inset: 0;
  z-index: 30;
  background: transparent;
}
.login-lang-panel {
  position: fixed;
  right: 14px;
  z-index: 31;
  min-width: 148px;
  max-height: 56vh;
  overflow-y: auto;
  padding: 6px;
  border-radius: 12px;
  background: #fff;
  border: 1px solid #e8e8e8;
  box-shadow: 0 10px 28px rgba(0, 0, 0, 0.18);
  box-sizing: border-box;
}
.login-lang-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 10px;
  border-radius: 8px;
  box-sizing: border-box;
}
.login-lang-item--hover {
  background: #f5f5f5;
}
.login-lang-item.on {
  background: #fff5f5;
}
.login-lang-item-lab {
  font-size: 13px;
  font-weight: 600;
  color: #1a1a1a;
  pointer-events: none;
}
.login-scroll {
  position: relative;
  z-index: 2;
  height: 100vh;
  height: 100dvh;
  box-sizing: border-box;
}
.login-shell {
  max-width: 440px;
  margin: 0 auto;
  padding: 4px 16px 28px;
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  align-items: stretch;
  min-height: 100vh;
  min-height: 100dvh;
}
/* 顶部留出吉祥物区域，表单落在背景下方留白 */
.login-hero-spacer {
  flex: 0 0 auto;
  height: 36vh;
  min-height: 210px;
  max-height: 320px;
  box-sizing: border-box;
}
.login-card {
  position: relative;
  z-index: 3;
  margin-top: 120px;
  padding: 22px 18px 16px;
  background: #fff;
  border-radius: 28px;
  border: 2px solid rgba(232, 40, 40, 0.55);
  box-shadow: 0 12px 32px rgba(80, 0, 20, 0.28);
  box-sizing: border-box;
}
.login-card-hd {
  text-align: center;
  margin-bottom: 18px;
}
.login-card-title-row {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}
.login-diamond {
  color: #e82020;
  font-size: 10px;
  line-height: 1;
}
.login-card-title {
  font-size: 20px;
  font-weight: 800;
  color: #1a1a1a;
  letter-spacing: 0.5px;
}
.login-card-sub {
  display: block;
  margin-top: 6px;
  font-size: 13px;
  color: #9aa0a6;
  font-weight: 500;
}
.input-group {
  position: relative;
  margin-bottom: 14px;
  text-align: left;
}
.input-label {
  font-size: 12px;
  font-weight: bold;
  color: #1a212d;
  margin-bottom: 6px;
  display: block;
}
.login-input {
  width: 100%;
  height: 48px;
  padding: 0 14px;
  border: 1px solid #e6e8eb;
  border-radius: 12px;
  font-size: 16px;
  font-weight: 600;
  color: #1a212d;
  background: #f7f8fa;
  box-sizing: border-box;
}
.invite-input {
  font-size: 16px;
}
.phone-row {
  display: flex;
  gap: 0;
  align-items: stretch;
  border: 1px solid #e6e8eb;
  border-radius: 12px;
  background: #f7f8fa;
  overflow: hidden;
}
.country-select {
  flex: 0 0 102px;
  width: 102px;
  height: 48px;
  padding: 0 8px;
  border: none;
  border-right: 1px solid #e6e8eb;
  border-radius: 0;
  background: transparent;
  display: flex;
  align-items: center;
  gap: 4px;
  box-sizing: border-box;
}
.country-select .flag {
  width: 22px;
  height: 15px;
  border-radius: 2px;
  flex-shrink: 0;
}
.country-select .dial {
  flex: 1;
  font-size: 13px;
  font-weight: 700;
  color: #1a212d;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.country-select .caret {
  font-size: 10px;
  color: #657786;
}
.phone-input {
  flex: 1 1 auto;
  min-width: 0;
  width: auto;
  border: none;
  border-radius: 0;
  background: transparent;
  height: 48px;
}
.country-panel {
  position: absolute;
  left: 0;
  right: 0;
  top: 100%;
  margin-top: 4px;
  background: #fff;
  border: 1px solid #e1e8ed;
  border-radius: 12px;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
  z-index: 30;
  overflow: hidden;
  max-height: 260px;
  overflow-y: auto;
}
.country-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border-bottom: 1px solid #f0f3f6;
}
.country-item:last-child {
  border-bottom: none;
}
.country-item.on {
  background: #fff5f5;
}
.country-item .flag {
  width: 22px;
  height: 16px;
  border-radius: 2px;
}
.country-item .cname {
  flex: 1;
  font-size: 13px;
  font-weight: 600;
  color: #1a212d;
}
.country-item .cdial {
  font-size: 12px;
  color: #657786;
  font-weight: 600;
}
.captcha-row {
  display: flex;
  align-items: stretch;
  gap: 10px;
}
.captcha-field {
  flex: 1 1 auto;
  min-width: 0;
  position: relative;
  display: flex;
  align-items: center;
}
.captcha-lock {
  position: absolute;
  left: 12px;
  z-index: 1;
  font-size: 14px;
  line-height: 1;
  pointer-events: none;
}
.captcha-input {
  padding-left: 36px;
}
.captcha-btn {
  flex: 0 0 auto;
  margin: 0;
  min-width: 108px;
  height: 48px;
  padding: 0 14px;
  background: linear-gradient(180deg, #ff4d3a 0%, #e01a1a 100%);
  color: #fff;
  border: none;
  border-radius: 12px;
  font-size: 13px;
  font-weight: 700;
  line-height: 48px;
  box-shadow: 0 4px 10px rgba(224, 26, 26, 0.28);
}
.captcha-btn.disabled,
.captcha-btn[disabled] {
  background: #ccd6dd;
  color: #657786;
  box-shadow: none;
}
.btn-login-submit {
  width: 100%;
  margin-top: 6px;
  height: 50px;
  background: linear-gradient(90deg, #ff4d29 0%, #ff1a1a 100%);
  color: #fff;
  border: none;
  border-radius: 999px;
  font-size: 16px;
  font-weight: 800;
  letter-spacing: 0.5px;
  box-shadow:
    0 6px 16px rgba(255, 40, 40, 0.35),
    inset 0 1px 0 rgba(255, 220, 160, 0.35);
  line-height: 50px;
}
.login-cs-link {
  margin-top: 14px;
  padding: 4px 8px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.login-cs-link--active {
  opacity: 0.72;
}
.login-cs-link-text {
  font-size: 14px;
  font-weight: 600;
  color: #e01a1a;
  text-decoration: underline;
  text-underline-offset: 3px;
  line-height: 1.4;
}
.login-foot {
  margin-top: 18px;
  text-align: center;
  padding-bottom: calc(12px + env(safe-area-inset-bottom, 0px));
}
.login-foot-main {
  display: block;
  font-size: 13px;
  font-weight: 600;
  color: rgba(255, 255, 255, 0.92);
  letter-spacing: 0.5px;
}
.login-foot-copy {
  display: block;
  margin-top: 6px;
  font-size: 11px;
  color: rgba(255, 255, 255, 0.55);
}
/* #ifdef APP-PLUS */
.login-page-bg {
  /* App：铺满页，顶对齐 cover，避免裁切品牌顶区 */
  position: absolute;
}
.login-scroll {
  height: auto;
  min-height: 100vh;
}
.login-shell {
  min-height: 100vh;
}
.login-hero-spacer {
  height: 36vh;
  min-height: 205px;
}
.login-card {
  margin-top: 120px;
}
/* #endif */

@media screen and (max-height: 700px) {
  .login-hero-spacer {
    height: 32vh;
    min-height: 180px;
    max-height: 240px;
  }
  .login-card {
    margin-top: 120px;
    padding: 18px 16px 14px;
  }
}
</style>

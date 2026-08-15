<template>
  <view class="login-page">
    <TopBar />

    <view class="login-wrapper">
      <view class="login-brand">
        <image class="login-logo-img" :src="logo" mode="aspectFit" />
        <view class="login-logo">{{ t('brand_name') }}</view>
      </view>
      <view class="login-subtitle">
        <text class="login-subtitle-line">{{ t('login_subtitle_line1') || '红宝多样玩法 · 欢乐一站畅玩' }}</text>
        <text class="login-subtitle-line">{{ t('login_subtitle_line2') || '🔥火热开启领取888,888元' }}</text>
      </view>

      <view class="input-group">
        <view class="input-label">{{ t('login_phone_label') }}</view>
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
        <view class="input-label">{{ t('login_captcha_label') }}</view>
        <input
          class="login-input captcha-input"
          type="number"
          maxlength="6"
          v-model="captcha"
          :placeholder="t('login_captcha_placeholder')"
        />
        <button
          class="captcha-btn"
          :class="{ disabled: smsLeft > 0 || sending }"
          :disabled="smsLeft > 0 || sending"
          @click="onSendSms"
        >
          {{ smsBtnText }}
        </button>
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
    </view>

    <SliderCaptcha ref="sliderRef" @success="onSliderOk" @cancel="onSliderCancel" />

    <view
      v-if="csVisible"
      class="login-cs-float"
      role="button"
      hover-class="login-cs-float--active"
      @click="openLoginCs"
    >
      <view class="login-cs-img-wrap" :style="{ backgroundImage: 'url(' + csIcon + ')' }">
        <image class="login-cs-img" :src="csIcon" mode="aspectFit" />
      </view>
    </view>
  </view>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import SliderCaptcha from '../../components/SliderCaptcha.vue'
import { fetchConfig, getToken, login, sendSms } from '../../utils/auth.js'
import {
  applyServerCopy,
  copyState,
  flagUrl,
  localeState,
  logoUrl,
  onLocaleChange,
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

const SMS_COOLDOWN_KEY = 'fanshub_sms_cooldown'
const DEFAULT_CS_ICON =
  'https://888jhdhifhbchashjdl.oss-accelerate.aliyuncs.com/uploads/20260813/afe179a5f27ce0b94bf8a0f65af3d291.png'

const locale = localeState()
const copyTick = copyState()
const logo = logoUrl()
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
const csIconRemote = ref('')
let timer = null
let offLocale = null
let offInvite = null
let pendingSmsPhone = ''

const countryMeta = computed(() => getCountryMeta(country.value))
const csIcon = computed(() => {
  const remote = String(csIconRemote.value || '').trim()
  if (remote) return remote
  return DEFAULT_CS_ICON
})
const csVisible = computed(() => !!csEnabled.value)
const phonePlaceholder = computed(() => {
  void locale.value
  return t(countryMeta.value.placeholderKey) || t('login_phone_placeholder')
})
const smsBtnText = computed(() => {
  void locale.value
  if (smsLeft.value > 0) {
    return t('login_captcha_resend', { count: smsLeft.value }) || smsLeft.value + 's'
  }
  return t('login_captcha_btn')
})
const loginSubmitText = computed(() => {
  void locale.value
  void copyTick.value
  void registerRights.value
  return tt(
    'login_submit_btn',
    '进入官方福利大厅，白嫖初始{register_rights}股',
    { register_rights: registerRights.value }
  )
})

if (getToken()) {
  uni.reLaunch({ url: '/pages/home/home' })
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
  const icon = String(cfg.login_cs_icon || '').trim()
  if (icon) csIconRemote.value = icon
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
    uni.reLaunch({ url: '/pages/home/home' })
  } catch (e) {
    uni.showToast({ title: e.message || t('alert_login_fail') || '登录失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  loadCfg()
  syncCooldownFromStorage()
  offLocale = onLocaleChange(() => {
    // 语言切换后占位/按钮文案随 locale 计算属性刷新
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
  background: var(--bg-main, #f5f7fa);
  box-sizing: border-box;
  position: relative;
}
.login-cs-float {
  position: fixed;
  right: max(8px, env(safe-area-inset-right, 0px));
  top: 25%;
  z-index: 120;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  width: 80px;
  padding: 0;
  margin: 0;
  background: transparent;
  border: none;
}
.login-cs-float--active {
  opacity: 0.88;
}
.login-cs-img-wrap {
  width: 80px;
  height: 80px;
  background-color: transparent;
  background-repeat: no-repeat;
  background-position: center;
  background-size: contain;
}
.login-cs-img {
  width: 80px;
  height: 80px;
  display: block;
  background-color: transparent;
}
/* #ifdef APP-PLUS */
.login-cs-float {
  right: 10px;
  top: 25%;
}
.login-cs-img {
  width: 80px;
  height: 80px;
}
.login-cs-img-wrap {
  width: 80px;
  height: 80px;
}
/* #endif */
.login-wrapper {
  max-width: 400px;
  width: calc(100% - 32px);
  margin: 40px auto 0;
  padding: 30px 20px;
  background: #fff;
  border-radius: 16px;
  box-shadow: 0 4px 25px rgba(0, 0, 0, 0.06);
  border: 1px solid #e1e8ed;
  text-align: center;
  box-sizing: border-box;
}
.login-brand {
  display: flex;
  flex-direction: column;
  align-items: center;
  margin-bottom: 6px;
}
.login-logo-img {
  width: 112px;
  height: 112px;
  margin-bottom: 2px;
}
.login-logo {
  font-size: 28px;
  font-weight: 900;
  color: #e80000;
  margin-bottom: 6px;
  letter-spacing: 1px;
  line-height: 1.2;
}
.login-subtitle {
  font-size: 13px;
  color: #657786;
  margin-bottom: 25px;
  font-weight: 500;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  line-height: 1.35;
}
.login-subtitle-line {
  display: block;
  width: 100%;
  text-align: center;
}
.input-group {
  position: relative;
  margin-bottom: 18px;
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
  height: 44px;
  padding: 0 14px;
  border: 1px solid #ccd6dd;
  border-radius: 8px;
  font-size: 14px;
  font-weight: bold;
  color: #1a212d;
  background: #f8f9fa;
  box-sizing: border-box;
}
/* Safari：聚焦时避免小于 16px 被强制放大；App 同步 16px 也不伤布局 */
.invite-input {
  font-size: 16px;
}
.phone-row {
  display: flex;
  gap: 8px;
  align-items: stretch;
}
.country-select {
  flex: 0 0 108px;
  width: 108px;
  height: 44px;
  padding: 0 6px;
  border: 1px solid #ccd6dd;
  border-radius: 8px;
  background: #f8f9fa;
  display: flex;
  align-items: center;
  gap: 4px;
  box-sizing: border-box;
}
.country-select .flag {
  width: 20px;
  height: 14px;
  border-radius: 2px;
  flex-shrink: 0;
}
.country-select .dial {
  flex: 1;
  font-size: 12px;
  font-weight: bold;
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
}
.country-panel {
  position: absolute;
  left: 0;
  right: 0;
  top: 100%;
  margin-top: 4px;
  background: #fff;
  border: 1px solid #e1e8ed;
  border-radius: 10px;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
  z-index: 20;
  overflow: hidden;
  max-height: 280px;
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
  background: #f5f9ff;
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
.captcha-group .captcha-input {
  padding-right: 108px;
}
.captcha-btn {
  position: absolute;
  right: 8px;
  bottom: 5px;
  margin: 0;
  background: #0071ff;
  color: #fff;
  border: none;
  padding: 7px 12px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: bold;
  line-height: 1.2;
  height: auto;
}
.captcha-btn.disabled,
.captcha-btn[disabled] {
  background: #ccd6dd;
  color: #657786;
}
.btn-login-submit {
  width: 100%;
  margin-top: 10px;
  background: linear-gradient(135deg, #0071ff 0%, #00c853 100%);
  color: #fff;
  border: none;
  padding: 14px;
  border-radius: 8px;
  font-size: 15px;
  font-weight: bold;
  box-shadow: 0 4px 12px rgba(0, 113, 255, 0.2);
  line-height: 1.3;
}
</style>

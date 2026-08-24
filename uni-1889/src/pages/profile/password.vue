<template>
  <ProfileSubPage :page-key="locale" :title="t('profile_password_page_title') || '登录密码'">
      <view class="match-card profile-card">
        <view class="profile-pwd-modes">
          <button
            type="button"
            class="profile-mode-btn"
            :class="{ active: mode === 'old' }"
            @click="mode = 'old'"
          >{{ t('profile_pwd_mode_old') || '旧密码验证' }}</button>
          <button
            type="button"
            class="profile-mode-btn"
            :class="{ active: mode === 'sms' }"
            @click="mode = 'sms'"
          >{{ t('profile_pwd_mode_sms') || '短信验证码' }}</button>
        </view>

        <view v-if="mode === 'old'" class="profile-field">
          <text class="lab">{{ t('profile_old_password_label') || '旧密码' }}</text>
          <input
            class="hb-input"
            password
            v-model="oldPassword"
            :placeholder="t('profile_old_password_ph') || '请输入当前密码'"
          />
        </view>
        <view v-else class="profile-field phone-field">
          <text class="lab">{{ t('profile_mobile_label') || '绑定手机' }}</text>
          <view class="phone-row">
            <view class="country-select" @click="countryOpen = !countryOpen">
              <image class="flag" :src="flagUrl(countryMeta.flagIso)" mode="aspectFill" />
              <text class="dial">+{{ countryMeta.dial }}</text>
              <text class="caret">▾</text>
            </view>
            <input class="hb-input phone-input" disabled :value="nationalDisplay" />
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
        <view v-if="mode === 'sms'" class="profile-field">
          <text class="lab">{{ t('profile_sms_code_label') || '短信验证码' }}</text>
          <view class="profile-sms-row">
            <input
              class="hb-input"
              type="number"
              maxlength="8"
              v-model="captcha"
              :placeholder="t('profile_sms_code_ph') || '请输入验证码'"
            />
            <button type="button" class="btn-captcha" :disabled="smsLeft > 0 || smsBusy" @click="sendSmsCode">
              {{ smsLeft > 0 ? (smsLeft + 's') : (t('profile_sms_send_btn') || '获取验证码') }}
            </button>
          </view>
        </view>

        <view class="profile-field">
          <text class="lab">{{ t('profile_new_password_label') || '新密码' }}</text>
          <input
            class="hb-input"
            password
            v-model="newPassword"
            :placeholder="t('profile_new_password_ph') || '6-32位新密码'"
          />
        </view>
        <view class="profile-field">
          <text class="lab">{{ t('profile_confirm_password_label') || '确认新密码' }}</text>
          <input
            class="hb-input"
            password
            v-model="confirmPassword"
            :placeholder="t('profile_confirm_password_ph') || '再次输入新密码'"
          />
        </view>
        <button class="btn-uid-submit" :disabled="busy" @click="submit">
          {{ t('profile_password_btn') || '确认修改登录密码' }}
        </button>
      </view>
      <SliderCaptcha ref="sliderRef" @success="onSliderOk" @cancel="onSliderCancel" />
  </ProfileSubPage>
</template>

<script setup>
import { safeNavigateBack, HOME_TAB } from '../../utils/nav.js'
import ProfileSubPage from '../../components/ProfileSubPage.vue'
import SliderCaptcha from '../../components/SliderCaptcha.vue'
import { computed, onUnmounted, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import {
  changePassword,
  fetchConfig,
  fetchProfile,
  getToken,
  logoutLocal,
  logoutRemote,
  sendSms,
} from '../../utils/auth.js'
import { localeState, t, flagUrl } from '../../utils/i18n.js'
import {
  LOGIN_COUNTRIES,
  getCountryMeta,
  detectCountryFromE164,
  nationalFromE164,
} from '../../utils/login-country.js'
import { imDisconnect } from '../../utils/im.js'
import '../../styles/hb.css'

const locale = localeState()
const mode = ref('old')
const oldPassword = ref('')
const captcha = ref('')
const newPassword = ref('')
const confirmPassword = ref('')
const mobile = ref('')
const country = ref('CN')
const countryOpen = ref(false)
const countries = LOGIN_COUNTRIES
const countryMeta = computed(() => getCountryMeta(country.value))
const nationalDisplay = computed(() => nationalFromE164(mobile.value) || mobile.value)
const busy = ref(false)
const smsBusy = ref(false)
const smsLeft = ref(0)
const smsSliderEnabled = ref(true)
const sliderRef = ref(null)
let smsTimer = null
let pendingSmsPhone = ''

function pickCountry(code) {
  country.value = getCountryMeta(code).code
  countryOpen.value = false
}

function goBack() {
  safeNavigateBack(HOME_TAB)
}

function clearSmsTimer() {
  if (smsTimer) {
    clearInterval(smsTimer)
    smsTimer = null
  }
}

function startCooldown(sec) {
  clearSmsTimer()
  smsLeft.value = Math.max(1, sec | 0)
  smsTimer = setInterval(() => {
    smsLeft.value -= 1
    if (smsLeft.value <= 0) clearSmsTimer()
  }, 1000)
}

async function doSendSms(phone, sliderPayload) {
  smsBusy.value = true
  try {
    const data = await sendSms(phone, Object.assign({}, sliderPayload || {}))
    const interval = (data && data.retry_after) ? parseInt(data.retry_after, 10) : 60
    startCooldown(interval)
    if (data && data.mock_code) captcha.value = String(data.mock_code)
    uni.showToast({ title: t('alert_sms_sent') || '验证码已发送', icon: 'none' })
  } catch (e) {
    if (e && e.payload && e.payload.retry_after) startCooldown(e.payload.retry_after)
    uni.showToast({ title: (e && e.message) || (t('alert_sms_fail') || '发送失败'), icon: 'none' })
  } finally {
    smsBusy.value = false
    pendingSmsPhone = ''
  }
}

function onSliderOk(payload) {
  const phone = pendingSmsPhone
  if (!phone) return
  doSendSms(phone, payload || {})
}

function onSliderCancel() {
  smsBusy.value = false
  pendingSmsPhone = ''
}

async function sendSmsCode() {
  if (!mobile.value) {
    uni.showToast({ title: t('alert_phone_invalid') || '手机号无效', icon: 'none' })
    return
  }
  if (smsLeft.value > 0 || smsBusy.value) return
  try {
    const cfg = await fetchConfig()
    if (cfg && cfg.sms_slider_enabled === false) smsSliderEnabled.value = false
    else smsSliderEnabled.value = true
  } catch (e) {}
  if (smsSliderEnabled.value) {
    pendingSmsPhone = mobile.value
    smsBusy.value = true
    if (sliderRef.value && typeof sliderRef.value.open === 'function') {
      sliderRef.value.open()
    } else {
      smsBusy.value = false
      uni.showToast({ title: t('srv_slider_create_fail') || '滑块验证失败，请重试', icon: 'none' })
    }
    return
  }
  await doSendSms(mobile.value, {})
}

async function submit() {
  const np = String(newPassword.value || '')
  const cp = String(confirmPassword.value || '')
  if (np.length < 6) {
    uni.showToast({ title: t('alert_password_short') || '密码至少6位', icon: 'none' })
    return
  }
  if (np !== cp) {
    uni.showToast({ title: t('alert_password_mismatch') || '两次密码不一致', icon: 'none' })
    return
  }
  const body = {
    mode: mode.value,
    new_password: np,
    confirm_password: cp,
  }
  if (mode.value === 'old') body.old_password = oldPassword.value
  else body.captcha = captcha.value

  busy.value = true
  try {
    await changePassword(body)
    uni.showToast({ title: t('alert_password_ok') || '密码已修改，请重新登录', icon: 'none' })
    await logoutRemote()
    imDisconnect()
    logoutLocal()
    setTimeout(() => uni.reLaunch({ url: '/pages/login/login' }), 500)
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '修改失败', icon: 'none' })
  } finally {
    busy.value = false
  }
}

onShow(async () => {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  try {
    const p = await fetchProfile()
    mobile.value = p.mobile || ''
    if (mobile.value) country.value = detectCountryFromE164(mobile.value)
  } catch (e) {}
})

onUnmounted(clearSmsTimer)
</script>

<style scoped>
.phone-field {
  position: relative;
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
  border: 1px solid #e8ddd4;
  border-radius: 8px;
  background: #fff;
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
}
.country-panel {
  position: absolute;
  left: 0;
  right: 0;
  top: 100%;
  margin-top: 4px;
  background: #fff;
  border: 1px solid #e8ddd4;
  border-radius: 10px;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
  z-index: 20;
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
  background: #fff8f0;
}
.cname {
  flex: 1;
  font-size: 14px;
  color: #1a212d;
}
.cdial {
  font-size: 13px;
  color: #8a7a6e;
}
</style>

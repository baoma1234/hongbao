<template>
  <view class="paypassword-page">
    <ProfileSubPage :page-key="locale" :title="tt('profile_pay_password_title', '修改支付密码')">
      <view class="match-card profile-card">
        <text class="profile-hint">{{ hintText }}</text>

        <view v-if="hasPayPassword" class="profile-field">
          <text class="lab">{{ tt('profile_sms_code_label', '短信验证码') }}</text>
          <view class="profile-sms-row">
            <input
              class="hb-input"
              type="number"
              maxlength="8"
              v-model="captcha"
              :placeholder="tt('profile_sms_code_ph', '请输入验证码')"
            />
            <button type="button" class="btn-captcha" :disabled="smsLeft > 0 || smsBusy" @click="sendSmsCode">
              {{ smsLeft > 0 ? (smsLeft + 's') : tt('profile_sms_send_btn', '获取验证码') }}
            </button>
          </view>
        </view>

        <view class="profile-field">
          <text class="lab">{{ tt('profile_pay_password_label', '支付密码') }}</text>
          <input
            class="hb-input"
            password
            maxlength="32"
            v-model="payPassword"
            :placeholder="tt('profile_pay_password_ph', '6-32位支付密码')"
          />
        </view>
        <view class="profile-field">
          <text class="lab">{{ tt('profile_pay_password_confirm_label', '确认支付密码') }}</text>
          <input
            class="hb-input"
            password
            maxlength="32"
            v-model="confirmPassword"
            :placeholder="tt('profile_pay_password_confirm_ph', '再次输入支付密码')"
          />
        </view>
        <button class="btn-uid-submit" :disabled="busy" @click="submit">{{ btnText }}</button>
      </view>
    </ProfileSubPage>
    <!-- 勿放在 ProfileSubPage 内部：部分端 overflow/层叠会导致滑块拖到底无回调 -->
    <SliderCaptcha ref="sliderRef" @success="onSliderOk" @cancel="onSliderCancel" />
  </view>
</template>

<script setup>
import ProfileSubPage from '../../components/ProfileSubPage.vue'
import SliderCaptcha from '../../components/SliderCaptcha.vue'
import { computed, onUnmounted, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import {
  changePayPassword,
  fetchConfig,
  fetchProfile,
  getToken,
  sendSms,
  setPayPassword,
} from '../../utils/auth.js'
import { localeState, tt } from '../../utils/i18n.js'
import '../../styles/hb.css'

const locale = localeState()
const hasPayPassword = ref(false)
const mobile = ref('')
const payPassword = ref('')
const confirmPassword = ref('')
const captcha = ref('')
const busy = ref(false)
const smsBusy = ref(false)
const smsLeft = ref(0)
const smsSliderEnabled = ref(true)
const sliderRef = ref(null)
let smsTimer = null
let pendingSmsPhone = ''

function looksBrokenCopy(s) {
  const v = String(s || '')
  if (!v) return true
  // 未加载时 t() 会回退成 key 名；或服务端乱码（无汉字却有一堆 Latin-1）
  if (v.indexOf('profile_') === 0) return true
  if (v.indexOf('\uFFFD') >= 0) return true
  if (/[\u00C0-\u00FF]{3,}/.test(v) && !/[\u4e00-\u9fff]/.test(v)) return true
  // 典型 UTF-8→Latin1 乱码片段（含 ä/å/æ 等且几乎无正常中文词）
  if (/[ÃÂäåæçèé]/.test(v) && !/支付|密码|验证|短信|修改|设置|password|SMS|mật|kata|លេខ|sandi/i.test(v)) return true
  return false
}

function safeCopy(key, fallback) {
  const v = tt(key, fallback)
  return looksBrokenCopy(v) ? fallback : v
}

const hintText = computed(() => {
  if (hasPayPassword.value) {
    return safeCopy('profile_pay_password_change_hint', '修改支付密码需短信验证码')
  }
  return safeCopy('profile_pay_password_set_hint', '首次可直接设置支付密码；用于提现与绑定地址')
})
const btnText = computed(() => {
  if (hasPayPassword.value) {
    return safeCopy('profile_pay_password_change_btn', '确认修改支付密码')
  }
  return safeCopy('profile_pay_password_set_btn', '设置支付密码')
})

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
    uni.showToast({ title: safeCopy('alert_sms_sent', '验证码已发送'), icon: 'none' })
  } catch (e) {
    if (e && e.payload && e.payload.retry_after) startCooldown(e.payload.retry_after)
    uni.showToast({ title: (e && e.message) || safeCopy('alert_sms_fail', '发送失败'), icon: 'none' })
  } finally {
    smsBusy.value = false
    pendingSmsPhone = ''
  }
}

function onSliderOk(payload) {
  const phone = pendingSmsPhone
  if (!phone) {
    smsBusy.value = false
    return
  }
  doSendSms(phone, payload || {})
}

function onSliderCancel() {
  smsBusy.value = false
  pendingSmsPhone = ''
}

async function sendSmsCode() {
  if (!mobile.value) {
    uni.showToast({ title: safeCopy('alert_phone_invalid', '手机号无效'), icon: 'none' })
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
    await new Promise((r) => setTimeout(r, 30))
    if (sliderRef.value && typeof sliderRef.value.open === 'function') {
      sliderRef.value.open()
    } else {
      smsBusy.value = false
      pendingSmsPhone = ''
      uni.showToast({ title: safeCopy('alert_slider_fail', '滑块验证失败，请重试'), icon: 'none' })
    }
    return
  }
  await doSendSms(mobile.value, {})
}

async function submit() {
  const pwd = String(payPassword.value || '')
  const conf = String(confirmPassword.value || '')
  if (pwd.length < 6) {
    uni.showToast({ title: safeCopy('alert_password_short', '密码至少6位'), icon: 'none' })
    return
  }
  if (pwd !== conf) {
    uni.showToast({ title: safeCopy('alert_password_mismatch', '两次密码不一致'), icon: 'none' })
    return
  }
  busy.value = true
  try {
    const wasSet = hasPayPassword.value
    let data
    if (!wasSet) {
      data = await setPayPassword(pwd, conf)
    } else {
      if (!String(captcha.value || '').trim()) {
        uni.showToast({ title: safeCopy('profile_pay_password_sms_required', '请填写短信验证码'), icon: 'none' })
        busy.value = false
        return
      }
      data = await changePayPassword(pwd, conf, captcha.value)
    }
    if (data && data.profile) {
      hasPayPassword.value = !!data.profile.has_pay_password || true
    } else {
      hasPayPassword.value = true
    }
    payPassword.value = ''
    confirmPassword.value = ''
    captcha.value = ''
    uni.showToast({
      title: wasSet
        ? safeCopy('profile_pay_password_updated', '支付密码已更新')
        : safeCopy('profile_pay_password_set_ok', '支付密码已设置'),
      icon: 'none',
    })
  } catch (e) {
    uni.showToast({ title: (e && e.message) || safeCopy('alert_operation_fail', '操作失败'), icon: 'none' })
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
    hasPayPassword.value = !!p.has_pay_password
    mobile.value = p.mobile || ''
  } catch (e) {}
})

onUnmounted(clearSmsTimer)
</script>

<style scoped>
.paypassword-page {
  min-height: 100%;
}
.profile-hint {
  display: block;
  font-size: 13px;
  color: #8a7a6e;
  line-height: 1.5;
  margin-bottom: 4px;
}
</style>

<template>
  <view class="hb-page profile-sub-page" :key="locale">
    <TopBar />
    <view class="profile-sub-hd">
      <text class="profile-back-btn" @click="goBack">‹</text>
      <text class="profile-sub-title">{{ t('profile_pay_password_title') || '支付密码' }}</text>
      <text class="profile-sub-spacer" />
    </view>
    <view class="profile-sub-body">
      <view class="match-card profile-card">
        <text class="profile-hint">{{ hintText }}</text>

        <view v-if="hasPayPassword" class="profile-field">
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
          <text class="lab">{{ t('profile_pay_password_label') || '支付密码' }}</text>
          <input
            class="hb-input"
            password
            maxlength="32"
            v-model="payPassword"
            :placeholder="t('profile_pay_password_ph') || '6-32位支付密码'"
          />
        </view>
        <view class="profile-field">
          <text class="lab">{{ t('profile_pay_password_confirm_label') || '确认支付密码' }}</text>
          <input
            class="hb-input"
            password
            maxlength="32"
            v-model="confirmPassword"
            :placeholder="t('profile_pay_password_confirm_ph') || '再次输入支付密码'"
          />
        </view>
        <button class="btn-uid-submit" :disabled="busy" @click="submit">{{ btnText }}</button>
      </view>
    </view>
  </view>
</template>

<script setup>
import { safeNavigateBack, HOME_TAB } from '../../utils/nav.js'
import TopBar from '../../components/TopBar.vue'
import { computed, onUnmounted, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import {
  changePayPassword,
  fetchProfile,
  getToken,
  sendSms,
  setPayPassword,
} from '../../utils/auth.js'
import { localeState, t } from '../../utils/i18n.js'
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
let smsTimer = null

const hintText = computed(() =>
  hasPayPassword.value
    ? (t('profile_pay_password_change_hint') || '修改支付密码需短信验证码')
    : (t('profile_pay_password_set_hint') || '首次可直接设置支付密码；用于提现与绑定地址')
)
const btnText = computed(() =>
  hasPayPassword.value
    ? (t('profile_pay_password_change_btn') || '确认修改支付密码')
    : (t('profile_pay_password_set_btn') || '设置支付密码')
)

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

async function sendSmsCode() {
  if (!mobile.value) {
    uni.showToast({ title: t('alert_phone_invalid') || '手机号无效', icon: 'none' })
    return
  }
  smsBusy.value = true
  try {
    const data = await sendSms(mobile.value, {})
    const interval = (data && data.retry_after) ? parseInt(data.retry_after, 10) : 60
    startCooldown(interval)
    uni.showToast({ title: t('alert_sms_sent') || '验证码已发送', icon: 'none' })
  } catch (e) {
    if (e && e.payload && e.payload.retry_after) startCooldown(e.payload.retry_after)
    uni.showToast({ title: (e && e.message) || (t('alert_sms_fail') || '发送失败'), icon: 'none' })
  } finally {
    smsBusy.value = false
  }
}

async function submit() {
  const pwd = String(payPassword.value || '')
  const conf = String(confirmPassword.value || '')
  if (pwd.length < 6) {
    uni.showToast({ title: t('alert_password_short') || '密码至少6位', icon: 'none' })
    return
  }
  if (pwd !== conf) {
    uni.showToast({ title: t('alert_password_mismatch') || '两次密码不一致', icon: 'none' })
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
        uni.showToast({ title: t('api_params_incomplete') || '请填写短信验证码', icon: 'none' })
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
        ? (t('api_pay_password_change_ok') || '支付密码已更新')
        : (t('api_pay_password_set_ok') || '支付密码已设置'),
      icon: 'none',
    })
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '操作失败', icon: 'none' })
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
.profile-hint {
  display: block;
  font-size: 13px;
  color: #8a7a6e;
  line-height: 1.5;
  margin-bottom: 4px;
}
</style>

<template>
  <view class="hb-page profile-sub-page" :key="locale">
    <TopBar :no-spacer="true" />
    <view class="profile-sub-hd">
      <text class="profile-back-btn" @click="goBack">‹</text>
      <text class="profile-sub-title">{{ t('profile_password_page_title') || '修改密码' }}</text>
      <text class="profile-sub-spacer" />
    </view>
    <view class="profile-sub-body">
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
        <view v-else class="profile-field">
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
          {{ t('profile_password_btn') || '确认修改密码' }}
        </button>
      </view>
    </view>
  </view>
</template>

<script setup>
import TopBar from '../../components/TopBar.vue'
import { onUnmounted, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import {
  changePassword,
  fetchProfile,
  getToken,
  logoutLocal,
  logoutRemote,
  sendSms,
} from '../../utils/auth.js'
import { localeState, t } from '../../utils/i18n.js'
import { imDisconnect } from '../../utils/im.js'

const locale = localeState()
const mode = ref('old')
const oldPassword = ref('')
const captcha = ref('')
const newPassword = ref('')
const confirmPassword = ref('')
const mobile = ref('')
const busy = ref(false)
const smsBusy = ref(false)
const smsLeft = ref(0)
let smsTimer = null

function goBack() {
  uni.navigateBack({ fail: () => uni.switchTab({ url: '/pages/profile/profile' }) })
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
  } catch (e) {}
})

onUnmounted(clearSmsTimer)
</script>

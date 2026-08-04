<template>
  <view class="login-page" :key="locale">
    <TopBar />
    <view class="hero">
      <image class="logo-lg" :src="logo" mode="aspectFit" />
      <view class="brand">{{ t('brand_name') }}</view>
      <view class="sub">999 · uni-app</view>
    </view>

    <view class="card">
      <view class="label">{{ t('login_phone_label') }}</view>
      <input
        class="input"
        type="number"
        maxlength="11"
        v-model="mobile"
        :placeholder="t('login_phone_placeholder')"
      />

      <view class="label">{{ t('login_captcha_label') }}</view>
      <view class="row">
        <input
          class="input flex"
          type="number"
          maxlength="6"
          v-model="captcha"
          :placeholder="t('login_captcha_placeholder')"
        />
        <button class="sms-btn" :disabled="smsLeft > 0 || sending" @click="onSendSms">
          {{ smsLeft > 0 ? smsLeft + 's' : t('login_captcha_btn') }}
        </button>
      </view>

      <view class="label">邀请码（可选）</view>
      <input class="input" v-model="invite" placeholder="邀请码" />

      <button class="submit" :loading="loading" @click="onLogin">{{ t('login_submit_btn') }}</button>
      <view class="hint" v-if="hint">{{ hint }}</view>
    </view>
  </view>
</template>

<script setup>
import { onUnmounted, ref } from 'vue'
import TopBar from '../../components/TopBar.vue'
import { login, sendSms, getToken } from '../../utils/auth.js'
import { localeState, logoUrl, t } from '../../utils/i18n.js'
import { imConnect } from '../../utils/im.js'

const locale = localeState()
const logo = logoUrl()
const mobile = ref('')
const captcha = ref('')
const invite = ref('')
const loading = ref(false)
const sending = ref(false)
const hint = ref('')
const smsLeft = ref(0)
let timer = null

if (getToken()) {
  uni.reLaunch({ url: '/pages/home/home' })
}

function startCooldown(sec) {
  smsLeft.value = Math.max(0, parseInt(sec, 10) || 60)
  if (timer) clearInterval(timer)
  timer = setInterval(() => {
    smsLeft.value -= 1
    if (smsLeft.value <= 0) {
      clearInterval(timer)
      timer = null
      smsLeft.value = 0
    }
  }, 1000)
}

onUnmounted(() => {
  if (timer) clearInterval(timer)
})

async function onSendSms() {
  const phone = String(mobile.value || '').trim()
  if (!/^1\d{10}$/.test(phone)) {
    uni.showToast({ title: t('api_mobile_invalid') || '手机号不正确', icon: 'none' })
    return
  }
  sending.value = true
  hint.value = ''
  try {
    const data = await sendSms(phone)
    startCooldown((data && data.retry_after) || 60)
    if (data && data.mock_code) {
      captcha.value = String(data.mock_code)
      hint.value = data.hint || ('测试验证码：' + data.mock_code)
    } else {
      hint.value = (data && data.hint) || t('alert_sms_hint_default') || '验证码已发送'
    }
    uni.showToast({ title: '已发送', icon: 'success' })
  } catch (e) {
    if (e.payload && e.payload.retry_after) startCooldown(e.payload.retry_after)
    uni.showToast({ title: e.message || t('alert_sms_fail') || '发送失败', icon: 'none' })
  } finally {
    sending.value = false
  }
}

async function onLogin() {
  const phone = String(mobile.value || '').trim()
  const code = String(captcha.value || '').trim()
  if (!/^1\d{10}$/.test(phone)) {
    uni.showToast({ title: t('api_mobile_invalid') || '手机号不正确', icon: 'none' })
    return
  }
  if (!code) {
    uni.showToast({ title: t('alert_captcha_required') || '请输入验证码', icon: 'none' })
    return
  }
  loading.value = true
  try {
    const data = await login(phone, code, invite.value)
    uni.showToast({ title: '登录成功', icon: 'success' })
    try {
      await imConnect()
    } catch (e) {
      console.warn('im connect', e)
    }
    uni.reLaunch({ url: '/pages/home/home' })
    return data
  } catch (e) {
    uni.showToast({ title: e.message || t('alert_login_fail') || '登录失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.login-page {
  min-height: 100vh;
  padding: 0 40rpx 40rpx;
  background: linear-gradient(180deg, #c61114 0%, var(--bg-main, #f6f1ea) 42%);
  box-sizing: border-box;
}
.hero {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 24rpx 0 32rpx;
}
.logo-lg {
  width: 160rpx;
  height: 160rpx;
  margin-bottom: 8rpx;
}
.brand {
  color: #fff;
  font-size: 56rpx;
  font-weight: 800;
  letter-spacing: 4rpx;
}
.sub {
  color: rgba(255, 255, 255, 0.85);
  margin-top: 8rpx;
  font-size: 26rpx;
}
.card {
  background: var(--bg-card, #fff);
  border-radius: 24rpx;
  padding: 36rpx 28rpx;
  box-shadow: 0 12rpx 40rpx rgba(198, 17, 20, 0.12);
}
.label {
  font-size: 24rpx;
  color: var(--text-muted, #9a8574);
  margin: 18rpx 0 10rpx;
}
.input {
  height: 84rpx;
  padding: 0 24rpx;
  background: #f7f2ec;
  border-radius: 14rpx;
  font-size: 30rpx;
  color: var(--text-main, #1a212d);
}
.row {
  display: flex;
  gap: 16rpx;
  align-items: center;
}
.flex { flex: 1; }
.sms-btn {
  flex-shrink: 0;
  margin: 0;
  height: 84rpx;
  line-height: 84rpx;
  padding: 0 22rpx;
  font-size: 24rpx;
  color: #c61114;
  background: #ffe8e6;
  border-radius: 14rpx;
}
.submit {
  margin-top: 40rpx;
  height: 88rpx;
  line-height: 88rpx;
  background: var(--primary, #c61114);
  color: #fff;
  border-radius: 16rpx;
  font-size: 30rpx;
  font-weight: 700;
}
.hint {
  margin-top: 20rpx;
  font-size: 24rpx;
  color: var(--text-muted, #9a8574);
  line-height: 1.5;
  white-space: pre-wrap;
}
</style>

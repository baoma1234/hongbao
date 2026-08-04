<template>
  <view class="login-page">
    <view class="brand">红宝</view>
    <view class="sub">999 · uni-app</view>

    <view class="card">
      <view class="label">手机号</view>
      <input class="input" type="number" maxlength="11" v-model="mobile" placeholder="11位手机号" />

      <view class="label">验证码</view>
      <view class="row">
        <input class="input flex" type="number" maxlength="6" v-model="captcha" placeholder="短信验证码" />
        <button class="sms-btn" :disabled="smsLeft > 0 || sending" @click="onSendSms">
          {{ smsLeft > 0 ? smsLeft + 's' : '获取验证码' }}
        </button>
      </view>

      <view class="label">邀请码（可选）</view>
      <input class="input" v-model="invite" placeholder="邀请码" />

      <button class="submit" :loading="loading" @click="onLogin">登录 / 注册</button>
      <view class="hint" v-if="hint">{{ hint }}</view>
    </view>
  </view>
</template>

<script setup>
import { ref, onUnmounted } from 'vue'
import { login, sendSms, getToken } from '../../utils/auth.js'
import { imConnect } from '../../utils/im.js'

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
    uni.showToast({ title: '手机号不正确', icon: 'none' })
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
      hint.value = (data && data.hint) || '验证码已发送'
    }
    uni.showToast({ title: '已发送', icon: 'success' })
  } catch (e) {
    if (e.payload && e.payload.retry_after) startCooldown(e.payload.retry_after)
    uni.showToast({ title: e.message || '发送失败', icon: 'none' })
  } finally {
    sending.value = false
  }
}

async function onLogin() {
  const phone = String(mobile.value || '').trim()
  const code = String(captcha.value || '').trim()
  if (!/^1\d{10}$/.test(phone)) {
    uni.showToast({ title: '手机号不正确', icon: 'none' })
    return
  }
  if (!code) {
    uni.showToast({ title: '请输入验证码', icon: 'none' })
    return
  }
  loading.value = true
  try {
    const data = await login(phone, code, invite.value)
    uni.showToast({ title: '登录成功', icon: 'success' })
    try {
      await imConnect()
    } catch (e) {
      // 登录成功但 WS 失败不阻断进首页
      console.warn('im connect', e)
    }
    uni.reLaunch({ url: '/pages/home/home' })
    return data
  } catch (e) {
    uni.showToast({ title: e.message || '登录失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.login-page {
  min-height: 100vh;
  padding: 80rpx 40rpx 40rpx;
  background: linear-gradient(180deg, #c61114 0%, #f6f1ea 42%);
  box-sizing: border-box;
}
.brand {
  color: #fff;
  font-size: 64rpx;
  font-weight: 800;
  letter-spacing: 4rpx;
}
.sub {
  color: rgba(255, 255, 255, 0.85);
  margin-top: 8rpx;
  margin-bottom: 48rpx;
  font-size: 26rpx;
}
.card {
  background: #fff;
  border-radius: 24rpx;
  padding: 36rpx 28rpx;
  box-shadow: 0 12rpx 40rpx rgba(198, 17, 20, 0.12);
}
.label {
  font-size: 24rpx;
  color: #9a8574;
  margin: 18rpx 0 10rpx;
}
.input {
  height: 84rpx;
  padding: 0 24rpx;
  background: #f7f2ec;
  border-radius: 14rpx;
  font-size: 30rpx;
}
.row {
  display: flex;
  gap: 16rpx;
  align-items: center;
}
.flex {
  flex: 1;
}
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
  background: #c61114;
  color: #fff;
  border-radius: 16rpx;
  font-size: 32rpx;
  font-weight: 700;
}
.hint {
  margin-top: 20rpx;
  font-size: 24rpx;
  color: #9a8574;
  line-height: 1.5;
  white-space: pre-wrap;
}
</style>

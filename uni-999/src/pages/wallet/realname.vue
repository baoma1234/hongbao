<template>
  <ProfileSubPage title="绑定真实姓名" body-class="hb-sub">
    <view class="match-card profile-card">
      <view class="profile-meta-line">非 USDT 出款使用该姓名。绑定后提现单会带上此实名。</view>
      <view class="profile-meta-line" v-if="savedName">当前已绑定：<strong>{{ savedName }}</strong></view>
      <view class="profile-field">
        <text class="lab">真实姓名</text>
        <input class="hb-input" v-model="realName" placeholder="请输入真实姓名（2-20个汉字）" />
      </view>
      <button class="btn-uid-submit" :disabled="submitting" @click="onSubmit">
        {{ submitting ? '提交中…' : (savedName ? '更新绑定' : '确认绑定') }}
      </button>
    </view>
    <view class="wallet-ledger-empty" v-if="loading">加载中…</view>
    <view class="wallet-warn" v-if="error" style="text-align:center">{{ error }}</view>

    <view class="wallet-paypwd-modal" v-if="pwdVisible" @click="cancelPwd">
      <view class="wallet-paypwd-sheet" @click.stop>
        <view class="wallet-paypwd-title">{{ needSetPwd ? '设置支付密码' : '请输入支付密码' }}</view>
        <view class="profile-field">
          <text class="lab">支付密码</text>
          <input class="hb-input" password v-model="pwd" :placeholder="needSetPwd ? '6-32位支付密码' : '请输入支付密码'" />
        </view>
        <view class="profile-field" v-if="needSetPwd">
          <text class="lab">确认支付密码</text>
          <input class="hb-input" password v-model="pwd2" placeholder="再次输入" />
        </view>
        <view class="wallet-paypwd-actions">
          <button class="wallet-paypwd-cancel" @click="cancelPwd">取消</button>
          <button class="btn-uid-submit wallet-paypwd-ok" @click="confirmPwd">确认</button>
        </view>
      </view>
    </view>
  </ProfileSubPage>
</template>

<script setup>
import { ref } from 'vue'
import ProfileSubPage from '../../components/ProfileSubPage.vue'
import { onShow } from '@dcloudio/uni-app'
import { apiRequest, getToken } from '../../utils/auth.js'
import { loadWalletBootstrap } from '../../utils/wallet.js'
import '../../styles/hb.css'

const loading = ref(false)
const error = ref('')
const submitting = ref(false)
const realName = ref('')
const savedName = ref('')
const hasPayPassword = ref(false)
const pwdVisible = ref(false)
const pwd = ref('')
const pwd2 = ref('')
const needSetPwd = ref(false)
let pwdResolve = null
let pwdReject = null

function promptPayPassword() {
  needSetPwd.value = !hasPayPassword.value
  pwd.value = ''
  pwd2.value = ''
  pwdVisible.value = true
  return new Promise((resolve, reject) => {
    pwdResolve = resolve
    pwdReject = reject
  })
}
function cancelPwd() {
  pwdVisible.value = false
  if (pwdReject) pwdReject(new Error('已取消'))
  pwdResolve = null
  pwdReject = null
}
async function confirmPwd() {
  const p = String(pwd.value || '').trim()
  if (p.length < 6 || p.length > 32) {
    uni.showToast({ title: '支付密码需 6-32 位', icon: 'none' })
    return
  }
  if (needSetPwd.value) {
    if (p !== String(pwd2.value || '').trim()) {
      uni.showToast({ title: '两次密码不一致', icon: 'none' })
      return
    }
    try {
      await apiRequest('setpaypassword', 'POST', { pay_password: p, confirm_password: p })
      hasPayPassword.value = true
    } catch (e) {
      uni.showToast({ title: (e && e.message) || '设置失败', icon: 'none' })
      return
    }
  }
  pwdVisible.value = false
  const r = pwdResolve
  pwdResolve = null
  pwdReject = null
  if (r) r(p)
}

async function onSubmit() {
  const name = String(realName.value || '').trim()
  if (name.length < 2) {
    uni.showToast({ title: '请填写真实姓名', icon: 'none' })
    return
  }
  submitting.value = true
  try {
    const payPwd = await promptPayPassword()
    const data = await apiRequest('bindpayoutrealname', 'POST', {
      real_name: name,
      pay_password: payPwd,
    })
    const saved = (data && data.payout_real_name) || name
    savedName.value = saved
    realName.value = saved
    uni.showToast({ title: '真实姓名已绑定', icon: 'none' })
    setTimeout(() => {
      uni.navigateBack({ fail() {} })
    }, 400)
  } catch (e) {
    if ((e && e.message) !== '已取消') {
      uni.showToast({ title: (e && e.message) || '绑定失败', icon: 'none' })
    }
  } finally {
    submitting.value = false
  }
}

onShow(async () => {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  loading.value = true
  error.value = ''
  try {
    const bundle = await loadWalletBootstrap(true)
    const info = (bundle && bundle.info) || {}
    savedName.value = String(info.payout_real_name || '').trim()
    if (!realName.value && savedName.value) realName.value = savedName.value
    hasPayPassword.value = !!info.has_pay_password
  } catch (e) {
    error.value = (e && e.message) || '加载失败'
  } finally {
    loading.value = false
  }
})
</script>

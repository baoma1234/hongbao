<template>
  <view class="page">
    <view class="hint">每种钱包类型独立绑定，地址不可重复使用。绑定需支付密码。</view>

    <view class="section">
      <text class="h">虚拟币钱包</text>
      <view
        v-for="wt in walletTypes"
        :key="wt.type"
        class="item"
        :class="{ on: selectedType === wt.type }"
        @click="selectedType = wt.type"
      >
        <view class="row">
          <text class="name">{{ wt.label }}</text>
          <text class="st" :class="{ ok: !!binds[wt.type] }">
            {{ binds[wt.type] ? '已绑定' : '未绑定' }}
          </text>
        </view>
        <text class="addr mono" v-if="binds[wt.type]">{{ binds[wt.type].account_no }}</text>
      </view>
    </view>

    <view class="section">
      <text class="h">USDT 多链</text>
      <view v-for="c in usdtChains" :key="c.wallet_type" class="item">
        <view class="row">
          <text class="name">{{ c.label }}</text>
          <text class="st" :class="{ ok: !!binds[c.wallet_type] }">
            {{ binds[c.wallet_type] ? '已绑定' : '未绑定' }}
          </text>
        </view>
        <text class="addr mono" v-if="binds[c.wallet_type]">
          {{ binds[c.wallet_type].account_no }}
        </text>
        <input
          v-else
          v-model="usdtInputs[c.wallet_type]"
          :placeholder="'填写 ' + c.label + ' 地址'"
        />
        <button
          v-if="!binds[c.wallet_type]"
          class="bind-btn"
          size="mini"
          :disabled="submitting"
          @click="bindUsdt(c)"
        >
          绑定 {{ c.label }}
        </button>
      </view>
    </view>

    <view class="form" v-if="selectedType && !binds[selectedType]">
      <text class="h">绑定 {{ selectedLabel }}</text>
      <view class="field">
        <text class="lab">收款地址</text>
        <input v-model="accountNo" placeholder="钱包地址" />
      </view>
      <view class="field">
        <text class="lab">备注名（可选）</text>
        <input v-model="accountName" placeholder="默认：钱包用户" />
      </view>
      <button class="submit" :disabled="submitting" @click="onBindSelected">
        {{ submitting ? '提交中…' : '确认绑定' }}
      </button>
    </view>

    <view class="empty" v-if="loading">加载中…</view>
    <view class="empty err" v-if="error">{{ error }}</view>

    <view class="mask" v-if="pwdVisible" @click="cancelPwd">
      <view class="pwd-box" @click.stop>
        <text class="pwd-title">{{ needSetPwd ? '设置支付密码' : '请输入支付密码' }}</text>
        <input class="pwd-input" password v-model="pwd" placeholder="支付密码" />
        <input
          v-if="needSetPwd"
          class="pwd-input"
          password
          v-model="pwd2"
          placeholder="再次输入"
        />
        <view class="pwd-actions">
          <button size="mini" @click="cancelPwd">取消</button>
          <button size="mini" type="warn" @click="confirmPwd">确认</button>
        </view>
      </view>
    </view>
  </view>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { apiRequest, getToken } from '../../utils/auth.js'
import {
  bindWallet,
  clearWalletCache,
  loadWalletBootstrap,
  shortChannelName,
} from '../../utils/wallet.js'

const USDT_CHAINS = [
  { chain: 'TRC20', wallet_type: 'BS_USDT_TRC20', label: 'TRC20' },
  { chain: 'ERC20', wallet_type: 'BS_USDT_ERC20', label: 'ERC20' },
  { chain: 'TON', wallet_type: 'BS_USDT_TON', label: 'TON' },
]

const loading = ref(false)
const error = ref('')
const info = ref({})
const binds = ref({})
const walletTypes = ref([])
const selectedType = ref('')
const accountNo = ref('')
const accountName = ref('')
const usdtInputs = reactive({})
const usdtChains = USDT_CHAINS
const submitting = ref(false)

const pwdVisible = ref(false)
const pwd = ref('')
const pwd2 = ref('')
const needSetPwd = ref(false)
let pwdResolve = null
let pwdReject = null

const hasPayPassword = computed(() => !!(info.value && info.value.has_pay_password))
const selectedLabel = computed(() => {
  const hit = walletTypes.value.find((w) => w.type === selectedType.value)
  return (hit && hit.label) || selectedType.value
})

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
      await apiRequest('setpaypassword', 'POST', {
        pay_password: p,
        confirm_password: p,
      })
      if (info.value) info.value.has_pay_password = true
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

async function doBind(walletType, account_no, account_name) {
  const no = String(account_no || '').trim()
  if (!no) {
    uni.showToast({ title: '请填写地址', icon: 'none' })
    return
  }
  submitting.value = true
  try {
    const payPwd = await promptPayPassword()
    await bindWallet(
      walletType,
      {
        account_no: no,
        account_name: String(account_name || '').trim() || '钱包用户',
        bank_name: walletType,
      },
      payPwd
    )
    uni.showToast({ title: '绑定成功', icon: 'none' })
    clearWalletCache()
    accountNo.value = ''
    await refresh()
  } catch (e) {
    if ((e && e.message) !== '已取消') {
      uni.showToast({ title: (e && e.message) || '绑定失败', icon: 'none' })
    }
  } finally {
    submitting.value = false
  }
}

function onBindSelected() {
  return doBind(selectedType.value, accountNo.value, accountName.value)
}

function bindUsdt(c) {
  return doBind(c.wallet_type, usdtInputs[c.wallet_type], c.label)
}

function collectWalletTypes(withdrawChannels) {
  const map = {}
  ;(withdrawChannels || []).forEach((ch) => {
    if (String(ch.bind_mode || '') !== 'wallet') return
    const t = String(ch.wallet_type || ch.payment_channel || '').trim()
    if (!t || t === 'USDT_MULTI' || t.indexOf('BS_USDT_') === 0) return
    if (!map[t]) {
      map[t] = { type: t, label: shortChannelName(ch) || t }
    }
  })
  return Object.values(map)
}

async function refresh() {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  loading.value = true
  error.value = ''
  try {
    const bundle = await loadWalletBootstrap(true)
    info.value = (bundle && bundle.info) || {}
    const w = (bundle && bundle.withdraw) || {}
    binds.value = w.binds || {}
    walletTypes.value = collectWalletTypes(w.list || [])
    if (!selectedType.value && walletTypes.value.length) {
      selectedType.value = walletTypes.value[0].type
    }
  } catch (e) {
    error.value = (e && e.message) || '加载失败'
  } finally {
    loading.value = false
  }
}

onShow(() => {
  refresh()
})
</script>

<style scoped>
.page {
  padding: 24rpx 28rpx 80rpx;
  min-height: 100vh;
}
.hint {
  font-size: 22rpx;
  color: #9a8574;
  margin-bottom: 20rpx;
  line-height: 1.5;
}
.section,
.form {
  background: #fff;
  border-radius: 16rpx;
  padding: 24rpx;
  margin-bottom: 20rpx;
}
.h {
  display: block;
  font-size: 28rpx;
  font-weight: 800;
  margin-bottom: 16rpx;
}
.item {
  padding: 20rpx 0;
  border-bottom: 1px solid #f0e8df;
}
.item:last-child {
  border-bottom: none;
}
.item.on {
  background: #fff8f5;
  margin: 0 -12rpx;
  padding-left: 12rpx;
  padding-right: 12rpx;
  border-radius: 12rpx;
}
.row {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.name {
  font-size: 28rpx;
  font-weight: 700;
}
.st {
  font-size: 22rpx;
  color: #9a8574;
}
.st.ok {
  color: #1a7f37;
}
.addr {
  display: block;
  margin-top: 8rpx;
  font-size: 22rpx;
  color: #6b5648;
  word-break: break-all;
}
.mono {
  font-family: ui-monospace, monospace;
}
.field {
  margin-bottom: 16rpx;
}
.lab {
  display: block;
  font-size: 24rpx;
  color: #6b5648;
  margin-bottom: 8rpx;
}
input {
  background: #f6f1ea;
  border-radius: 10rpx;
  padding: 16rpx 20rpx;
  font-size: 26rpx;
  margin-top: 10rpx;
}
.bind-btn {
  margin-top: 12rpx;
  background: #fff;
  color: #c61114;
  border: 1px solid #c61114;
}
.submit {
  background: #c61114;
  color: #fff;
  margin-top: 8rpx;
}
.submit[disabled],
.bind-btn[disabled] {
  opacity: 0.5;
}
.empty {
  text-align: center;
  color: #9a8574;
  padding: 40rpx;
}
.empty.err {
  color: #c61114;
}
.mask {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 99;
}
.pwd-box {
  width: 80%;
  background: #fff;
  border-radius: 20rpx;
  padding: 36rpx 28rpx;
}
.pwd-title {
  display: block;
  font-size: 32rpx;
  font-weight: 700;
  text-align: center;
  margin-bottom: 16rpx;
}
.pwd-input {
  margin-top: 12rpx;
}
.pwd-actions {
  display: flex;
  justify-content: flex-end;
  gap: 16rpx;
  margin-top: 28rpx;
}
</style>

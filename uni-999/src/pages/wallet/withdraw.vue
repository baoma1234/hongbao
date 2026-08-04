<template>
  <view class="page">
    <view class="bal-bar">
      <text>可提余额 {{ balanceText }}</text>
      <text class="muted">{{ turnHint }}</text>
    </view>

    <view class="section" v-if="groups.length">
      <scroll-view scroll-x class="tabs">
        <view
          v-for="g in groups"
          :key="g.key"
          class="tab"
          :class="{ on: activeKey === g.key }"
          @click="selectGroup(g.key)"
        >
          {{ g.name }}
        </view>
      </scroll-view>

      <view class="chans">
        <view
          v-for="ch in activeChannels"
          :key="ch.id"
          class="chan"
          :class="{ on: selectedId === Number(ch.id) }"
          @click="selectChannel(ch)"
        >
          <text class="name">{{ shortName(ch) }}</text>
          <text class="meta">{{ channelMeta(ch) }}</text>
        </view>
      </view>
    </view>

    <view class="form" v-if="selected">
      <!-- 线上合作 -->
      <view v-if="isCoop" class="box">
        <text class="lab">主站账号</text>
        <text class="val">{{ mainUid || '未绑定 / 未通过审核' }}</text>
        <text class="warn" v-if="!mainUid">请先在「我的」绑定主站账号并等待审核通过</text>
        <view class="field" v-if="platforms.length > 1">
          <text class="lab">平台</text>
          <picker :range="platforms" :value="platformIndex" @change="onPlatformPick">
            <view class="picker">{{ platform }}</view>
          </picker>
        </view>
        <text class="muted" v-else>打款平台：{{ platform }}</text>
      </view>

      <!-- 钱包绑定 -->
      <view v-else-if="isWalletBind" class="box">
        <template v-if="bind">
          <text class="lab">已绑定地址</text>
          <text class="val mono">{{ bind.account_no }}</text>
        </template>
        <template v-else>
          <text class="warn">请先绑定该钱包收款地址</text>
          <button class="link-btn" size="mini" @click="goPayee">去绑定</button>
        </template>
      </view>

      <!-- 手动填写 -->
      <view v-else class="box">
        <view class="field" v-if="!isUsdt">
          <text class="lab">收款人</text>
          <input v-model="payeeName" placeholder="姓名" />
        </view>
        <view class="field">
          <text class="lab">{{ isUsdt ? 'USDT 地址' : '账号' }}</text>
          <input
            v-model="payeeAccount"
            :placeholder="isUsdt ? 'TRC20 收款地址' : '钱包地址 / 卡号'"
          />
        </view>
        <view class="field" v-if="!isUsdt">
          <text class="lab">银行/通道</text>
          <input v-model="payeeBank" placeholder="可选" />
        </view>
      </view>

      <view class="field" v-if="canEnterAmount">
        <text class="lab">提现金额</text>
        <input
          type="digit"
          v-model="amount"
          :placeholder="amountPh"
          @input="onAmountInput"
        />
        <text class="fx" v-if="fxText">{{ fxText }}</text>
      </view>

      <button
        class="submit"
        :disabled="submitting || !canSubmit"
        @click="onSubmit"
      >
        {{ submitting ? '提交中…' : '确认提现' }}
      </button>
    </view>

    <view class="empty" v-else-if="!loading">请选择提现通道</view>
    <view class="empty" v-if="loading">加载中…</view>
    <view class="empty err" v-if="error">{{ error }}</view>

    <!-- 支付密码 -->
    <view class="mask" v-if="pwdVisible" @click="cancelPwd">
      <view class="pwd-box" @click.stop>
        <text class="pwd-title">{{ needSetPwd ? '设置支付密码' : '请输入支付密码' }}</text>
        <text class="pwd-desc" v-if="needSetPwd">首次设置，用于提现与绑定地址</text>
        <input
          class="pwd-input"
          password
          v-model="pwd"
          :placeholder="needSetPwd ? '6-32 位' : '支付密码'"
        />
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
import { computed, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { apiRequest, getToken } from '../../utils/auth.js'
import {
  clearWalletCache,
  findChannel,
  fxHintText,
  getApprovedMainUid,
  groupByPartitions,
  isOnlineCoopChannel,
  loadProfileLite,
  loadWalletBootstrap,
  money,
  shortChannelName,
  submitWithdraw,
  turnoverHint,
  validateChannelAmount,
} from '../../utils/wallet.js'

const loading = ref(false)
const error = ref('')
const info = ref({})
const channels = ref([])
const partitions = ref([])
const binds = ref({})
const profile = ref(null)

const activeKey = ref('')
const selectedId = ref(0)
const amount = ref('')
const submitting = ref(false)

const payeeName = ref('')
const payeeAccount = ref('')
const payeeBank = ref('')
const platform = ref('555')

const pwdVisible = ref(false)
const pwd = ref('')
const pwd2 = ref('')
const needSetPwd = ref(false)
let pwdResolve = null
let pwdReject = null

const groups = computed(() => groupByPartitions(channels.value, partitions.value))
const activeChannels = computed(() => {
  const g = groups.value.find((x) => x.key === activeKey.value)
  return (g && g.channels) || []
})
const selected = computed(() => findChannel(channels.value, selectedId.value))
const isCoop = computed(() => isOnlineCoopChannel(selected.value))
const isWalletBind = computed(
  () =>
    !isCoop.value &&
    selected.value &&
    String(selected.value.bind_mode || '') === 'wallet'
)
const isUsdt = computed(
  () => selected.value && String(selected.value.handler || '').toLowerCase() === 'bs'
)
const walletType = computed(() => {
  const ch = selected.value
  if (!ch) return ''
  return String(ch.wallet_type || ch.payment_channel || '')
})
const bind = computed(() => {
  if (!isWalletBind.value) return null
  return (binds.value && binds.value[walletType.value]) || null
})
const mainUid = computed(() => getApprovedMainUid(profile.value))
const platforms = computed(() => {
  const ch = selected.value
  const list = ch && Array.isArray(ch.platforms) && ch.platforms.length ? ch.platforms : ['555']
  return list.map(String)
})
const platformIndex = computed(() => {
  const i = platforms.value.indexOf(platform.value)
  return i >= 0 ? i : 0
})
const balanceText = computed(() => {
  const i = info.value || {}
  const n = i.hongbao != null ? i.hongbao : i.balance
  return money(n || 0)
})
const turnHint = computed(() => turnoverHint(info.value))
const amountPh = computed(() => {
  const ch = selected.value
  if (!ch) return '金额'
  const min = Number(ch.min_amount || 0)
  const max = Number(ch.max_amount || 0)
  if (min > 0 && max > 0) return money(min) + ' ~ ' + money(max)
  if (min > 0) return '最低 ' + money(min)
  return '请输入金额'
})
const fxText = computed(() => fxHintText(selected.value, amount.value))
const canEnterAmount = computed(() => {
  if (!selected.value) return false
  if (isCoop.value) return !!mainUid.value
  if (isWalletBind.value) return !!bind.value
  if (isUsdt.value) return !!String(payeeAccount.value || '').trim()
  return !!String(payeeName.value || '').trim() && !!String(payeeAccount.value || '').trim()
})
const canSubmit = computed(() => canEnterAmount.value && Number(amount.value) > 0)
const hasPayPassword = computed(() => !!(info.value && info.value.has_pay_password))

function shortName(ch) {
  return shortChannelName(ch)
}
function channelMeta(ch) {
  if (isOnlineCoopChannel(ch)) return '线上合作'
  const min = Number(ch.min_amount || 0)
  const max = Number(ch.max_amount || 0)
  if (min > 0 || max > 0) return money(min) + '~' + money(max)
  return ''
}

function selectGroup(key) {
  activeKey.value = key
  selectedId.value = 0
  amount.value = ''
}

function selectChannel(ch) {
  selectedId.value = Number(ch.id)
  amount.value = ''
  if (isOnlineCoopChannel(ch)) {
    const plats = Array.isArray(ch.platforms) && ch.platforms.length ? ch.platforms : ['555']
    platform.value = String(plats[0] || '555')
  }
  if (String(ch.bind_mode || '') !== 'wallet' && !isOnlineCoopChannel(ch)) {
    const bankBind = binds.value && binds.value.BANK
    const usdtBind =
      binds.value && (binds.value.BS_USDT_TRC20 || binds.value.USDT_TRC20)
    const bs = String(ch.handler || '').toLowerCase() === 'bs'
    if (bs && usdtBind) {
      payeeAccount.value = usdtBind.account_no || ''
      payeeName.value = usdtBind.account_name || 'USDT'
      payeeBank.value = 'USDT-TRC20'
    } else if (bankBind) {
      if (!payeeName.value) payeeName.value = bankBind.account_name || ''
      if (!payeeAccount.value) payeeAccount.value = bankBind.account_no || ''
      if (!payeeBank.value) payeeBank.value = bankBind.bank_name || ''
    } else if (!payeeBank.value && ch.name) {
      payeeBank.value = String(ch.name).replace(/(充值|代付|提现)$/, '') || ''
    }
  }
}

function onPlatformPick(e) {
  const i = Number(e.detail.value) || 0
  platform.value = platforms.value[i] || '555'
}

function onAmountInput() {
  /* reactive via v-model */
}

function goPayee() {
  uni.navigateTo({ url: '/pages/wallet/payee' })
}

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

function buildAccountInfo() {
  const ch = selected.value
  if (isCoop.value) {
    const uid = mainUid.value
    const plat = platform.value || '555'
    return {
      method: 'online_coop',
      withdraw_mode: 'online_coop',
      platform: plat,
      main_uid: uid,
      account: uid,
      account_or_address: uid,
      accountname: '线上合作-' + plat,
      cardnumber: uid,
      bankname: '线上合作/' + plat,
    }
  }
  if (isWalletBind.value) {
    const b = bind.value
    const wtype = walletType.value
    return {
      bind_id: b.id,
      wallet_type: wtype,
      accountname: b.account_name || '钱包用户',
      cardnumber: b.account_no,
      account: b.account_no,
      account_or_address: b.account_no,
      bankname: b.bank_name || wtype,
    }
  }
  let accountname = String(payeeName.value || '').trim()
  const cardnumber = String(payeeAccount.value || '').trim()
  let bankname = String(payeeBank.value || '').trim()
  if (isUsdt.value) {
    if (!accountname) accountname = 'USDT'
  }
  if (!bankname) {
    bankname = ch && ch.name ? String(ch.name).replace(/(充值|代付|提现)$/, '') : '钱包'
  }
  return {
    accountname,
    cardnumber,
    account: cardnumber,
    account_or_address: cardnumber,
    bankname,
    pay_chanel: '102',
  }
}

async function onSubmit() {
  const ch = selected.value
  const err = validateChannelAmount(ch, amount.value)
  if (err) {
    uni.showToast({ title: err, icon: 'none' })
    return
  }
  if (isCoop.value && !mainUid.value) {
    uni.showToast({ title: '请先绑定并通过主站账号审核', icon: 'none' })
    return
  }
  if (isWalletBind.value && !bind.value) {
    uni.showToast({ title: '请先绑定该钱包地址', icon: 'none' })
    return
  }
  if (!isCoop.value && !isWalletBind.value) {
    const cardnumber = String(payeeAccount.value || '').trim()
    const accountname = String(payeeName.value || '').trim()
    if (isUsdt.value) {
      if (!cardnumber) {
        uni.showToast({ title: '请填写 USDT 收款地址', icon: 'none' })
        return
      }
    } else if (!accountname || !cardnumber) {
      uni.showToast({ title: '请填写收款人姓名与账号', icon: 'none' })
      return
    }
  }

  submitting.value = true
  try {
    const payPwd = await promptPayPassword()
    const accountInfo = buildAccountInfo()
    const data = await submitWithdraw(
      selectedId.value,
      Number(amount.value),
      accountInfo,
      payPwd
    )
    uni.showToast({
      title: (data && data.message) || '提现申请已提交',
      icon: 'none',
    })
    clearWalletCache()
    amount.value = ''
    await refresh(true)
  } catch (e) {
    if ((e && e.message) !== '已取消') {
      uni.showToast({ title: (e && e.message) || '失败', icon: 'none' })
    }
  } finally {
    submitting.value = false
  }
}

async function refresh(force = false) {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  loading.value = true
  error.value = ''
  try {
    const [bundle, p] = await Promise.all([
      loadWalletBootstrap(force),
      loadProfileLite(),
    ])
    profile.value = p
    info.value = (bundle && bundle.info) || {}
    const w = (bundle && bundle.withdraw) || {}
    channels.value = w.list || []
    partitions.value = w.partitions || []
    binds.value = w.binds || {}
    if (!activeKey.value && groups.value.length) {
      activeKey.value = groups.value[0].key
    }
  } catch (e) {
    error.value = (e && e.message) || '加载失败'
  } finally {
    loading.value = false
  }
}

onShow(() => {
  refresh(true)
})
</script>

<style scoped>
.page {
  padding: 24rpx 28rpx 80rpx;
  min-height: 100vh;
}
.bal-bar {
  background: #fff;
  border-radius: 16rpx;
  padding: 24rpx;
  margin-bottom: 20rpx;
  font-size: 28rpx;
  font-weight: 700;
}
.muted {
  display: block;
  margin-top: 8rpx;
  font-size: 22rpx;
  font-weight: 400;
  color: #9a8574;
}
.section {
  background: #fff;
  border-radius: 16rpx;
  padding: 20rpx;
  margin-bottom: 20rpx;
}
.tabs {
  white-space: nowrap;
  margin-bottom: 16rpx;
}
.tab {
  display: inline-block;
  padding: 12rpx 28rpx;
  margin-right: 12rpx;
  border-radius: 999rpx;
  font-size: 24rpx;
  background: #f6f1ea;
  color: #6b5648;
}
.tab.on {
  background: #c61114;
  color: #fff;
}
.chans {
  display: flex;
  flex-wrap: wrap;
  gap: 12rpx;
}
.chan {
  min-width: 28%;
  flex: 1 1 28%;
  padding: 20rpx 16rpx;
  border-radius: 12rpx;
  background: #faf7f3;
  border: 2rpx solid transparent;
}
.chan.on {
  border-color: #c61114;
  background: #fff5f5;
}
.name {
  display: block;
  font-size: 26rpx;
  font-weight: 700;
}
.meta {
  display: block;
  margin-top: 6rpx;
  font-size: 20rpx;
  color: #9a8574;
}
.form {
  background: #fff;
  border-radius: 16rpx;
  padding: 24rpx;
}
.box {
  margin-bottom: 20rpx;
}
.field {
  margin-bottom: 20rpx;
}
.lab {
  display: block;
  font-size: 24rpx;
  color: #6b5648;
  margin-bottom: 8rpx;
}
.val {
  display: block;
  font-size: 28rpx;
  font-weight: 600;
}
.mono {
  word-break: break-all;
  font-family: ui-monospace, monospace;
  font-size: 24rpx;
}
.warn {
  display: block;
  color: #c61114;
  font-size: 24rpx;
  margin: 8rpx 0;
}
.picker {
  padding: 16rpx;
  background: #f6f1ea;
  border-radius: 10rpx;
}
input {
  background: #f6f1ea;
  border-radius: 10rpx;
  padding: 16rpx 20rpx;
  font-size: 28rpx;
}
.fx {
  display: block;
  margin-top: 8rpx;
  font-size: 22rpx;
  color: #9a8574;
}
.submit {
  margin-top: 12rpx;
  background: #c61114;
  color: #fff;
}
.submit[disabled] {
  opacity: 0.5;
}
.link-btn {
  margin-top: 12rpx;
  background: #fff;
  color: #c61114;
  border: 1px solid #c61114;
}
.empty {
  text-align: center;
  color: #9a8574;
  padding: 40rpx;
  font-size: 26rpx;
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
}
.pwd-desc {
  display: block;
  margin: 12rpx 0;
  font-size: 22rpx;
  color: #9a8574;
  text-align: center;
}
.pwd-input {
  margin-top: 16rpx;
}
.pwd-actions {
  display: flex;
  justify-content: flex-end;
  gap: 16rpx;
  margin-top: 28rpx;
}
</style>

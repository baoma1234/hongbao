<template>
  <view class="hb-page profile-sub-page">
    <TopBar :no-spacer="true" />
    <view class="profile-sub-hd">
      <text class="profile-back-btn" @click="goBack">‹</text>
      <text class="profile-sub-title">提现</text>
      <text class="profile-sub-spacer" />
    </view>
    <view class="profile-sub-body hb-sub">
    <view class="match-card">
      <view class="wallet-bal-line">可提现红宝：<strong>￥{{ balanceText }}</strong></view>
      <view class="profile-meta-line" v-if="turnHint">{{ turnHint }}</view>

      <view
        v-if="groups.length > 1"
        class="wallet-partition-tabs"
        :class="{ 'is-3': groups.length >= 3 }"
      >
        <view
          v-for="g in groups"
          :key="g.key"
          class="wallet-partition-tab"
          :class="{ active: activeKey === g.key }"
          @click="selectGroup(g.key)"
        >
          {{ g.name }}
        </view>
      </view>

      <view class="wallet-channel-list is-grid" v-if="activeChannels.length">
        <view
          v-for="ch in activeChannels"
          :key="ch.id"
          class="wallet-channel-item"
          :class="{ active: selectedId === Number(ch.id) }"
          @click="selectChannel(ch)"
        >
          <image
            v-if="iconUrl(ch)"
            class="wallet-channel-icon"
            :src="iconUrl(ch)"
            mode="aspectFill"
          />
          <view v-else class="wallet-channel-icon wallet-channel-icon--placeholder">
            {{ shortName(ch).charAt(0) }}
          </view>
          <view class="wallet-channel-meta">
            <text class="wallet-channel-name">{{ shortName(ch) }}</text>
            <text
              v-if="isWalletCh(ch) && bindOf(ch)"
              class="wallet-channel-bound"
            >已绑定</text>
          </view>
        </view>
      </view>
      <view class="wallet-channel-empty" v-else-if="!loading">暂无可用通道，请联系客服</view>

      <view class="wallet-amount-panel" v-if="selected">
        <view v-if="isCoop" class="wallet-bind-panel">
          <view class="profile-field" v-if="platforms.length > 1">
            <text class="lab">合作平台</text>
            <picker :range="platforms" :value="platformIndex" @change="onPlatformPick">
              <view class="picker-val">{{ platform }}</view>
            </picker>
          </view>
          <view class="profile-meta-line" v-else>打款平台：{{ platform }}</view>
          <view class="profile-field">
            <text class="lab">主站账号</text>
            <input class="hb-input" :value="mainUid" disabled placeholder="未绑定 / 未通过审核" />
          </view>
          <view class="wallet-warn" v-if="!mainUid">请先绑定并通过主站账号审核</view>
          <view class="profile-meta-line" v-else>需绑定主站账号，人工审核出款</view>
        </view>

        <view v-else-if="isWalletBind" class="wallet-bind-panel">
          <template v-if="bind">
            <view class="profile-meta-line">
              钱包地址：<strong>{{ bind.account_no }}</strong>
            </view>
          </template>
          <template v-else>
            <view class="profile-meta-line">
              请先为该钱包绑定收款地址
              <text class="wallet-go-payee-btn" @click="goPayee">钱包地址</text>
            </view>
          </template>
        </view>

        <view v-else class="wallet-bind-panel">
          <view class="profile-field" v-if="!isUsdt">
            <text class="lab">收款人</text>
            <input class="hb-input" v-model="payeeName" placeholder="真实姓名" />
          </view>
          <view class="profile-field">
            <text class="lab">{{ isUsdt ? 'USDT 收款地址' : '账号' }}</text>
            <input
              class="hb-input"
              v-model="payeeAccount"
              :placeholder="isUsdt ? 'USDT 收款地址（TRC20）' : '钱包地址 / 银行卡号'"
            />
          </view>
          <view class="profile-field" v-if="!isUsdt">
            <text class="lab">银行/通道</text>
            <input class="hb-input" v-model="payeeBank" placeholder="可选" />
          </view>
        </view>

        <view v-if="canEnterAmount">
          <view class="profile-field">
            <text class="lab">提现红宝金额（元）</text>
            <input class="hb-input" type="digit" v-model="amount" :placeholder="amountPh" />
            <view class="profile-meta-line wallet-fx-hint" v-if="fxText">{{ fxText }}</view>
          </view>
          <view class="wallet-withdraw-verify-addr" v-if="verifyAddr">
            <text>钱包地址</text>
            <strong>{{ verifyAddr }}</strong>
          </view>
          <button class="btn-uid-submit" :disabled="submitting || !canSubmit" @click="onSubmit">
            {{ submitting ? '提交中…' : '确认提现' }}
          </button>
        </view>
      </view>
    </view>

    <view class="wallet-ledger-empty" v-if="loading">加载中…</view>
    <view class="wallet-warn" v-if="error" style="text-align:center;margin-top:12px">{{ error }}</view>

    <view class="wallet-paypwd-modal" v-if="pwdVisible" @click="cancelPwd">
      <view class="wallet-paypwd-sheet" @click.stop>
        <view class="wallet-paypwd-title">
          {{ needSetPwd ? '设置支付密码' : '请输入支付密码' }}
        </view>
        <view class="wallet-paypwd-desc" v-if="needSetPwd">首次设置支付密码，用于提现与绑定地址</view>
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
    </view>
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import TopBar from '../../components/TopBar.vue'
import { onShow } from '@dcloudio/uni-app'
import { apiRequest, getToken } from '../../utils/auth.js'
import {
  channelIconUrl,
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
import '../../styles/hb.css'

function goBack() {
  uni.navigateBack({ fail: () => uni.switchTab({ url: '/pages/profile/profile' }) })
}

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
  if (!ch) return '请输入金额'
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
const verifyAddr = computed(() => {
  if (isWalletBind.value && bind.value) return bind.value.account_no || ''
  return ''
})

function shortName(ch) {
  return shortChannelName(ch)
}
function iconUrl(ch) {
  return channelIconUrl(ch)
}
function isWalletCh(ch) {
  return ch && String(ch.bind_mode || '') === 'wallet' && !isOnlineCoopChannel(ch)
}
function bindOf(ch) {
  const t = String(ch.wallet_type || ch.payment_channel || '')
  return (binds.value && binds.value[t]) || null
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
    const usdtBind = binds.value && (binds.value.BS_USDT_TRC20 || binds.value.USDT_TRC20)
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
      await apiRequest('setpaypassword', 'POST', { pay_password: p, confirm_password: p })
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
  if (isUsdt.value && !accountname) accountname = 'USDT'
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
    const data = await submitWithdraw(
      selectedId.value,
      Number(amount.value),
      buildAccountInfo(),
      payPwd
    )
    uni.showToast({ title: (data && data.message) || '提现申请已提交', icon: 'none' })
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
    const [bundle, p] = await Promise.all([loadWalletBootstrap(force), loadProfileLite()])
    profile.value = p
    info.value = (bundle && bundle.info) || {}
    const w = (bundle && bundle.withdraw) || {}
    channels.value = w.list || []
    partitions.value = w.partitions || []
    binds.value = w.binds || {}
    if (!activeKey.value && groups.value.length) activeKey.value = groups.value[0].key
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

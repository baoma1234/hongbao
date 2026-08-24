<template>
  <ProfileSubPage title="提现" body-class="hb-sub">
    <view class="match-card profile-card">
      <view class="profile-meta-line">可提现红宝：<strong>￥{{ balanceText }}</strong></view>
      <view class="profile-meta-line" v-if="frozenText">冻结金额：<strong>￥{{ frozenText }}</strong></view>
      <view class="profile-meta-line" v-if="turnoverText">累计流水：￥{{ turnoverText }}</view>
      <view class="profile-meta-line" v-if="turnHint">{{ turnHint }}</view>

      <view class="profile-field">
        <text class="lab">选择提现通道</text>
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
      </view>

      <view class="wallet-channel-list is-grid" v-if="visibleChannels.length && !coopCompact">
        <view
          v-for="ch in visibleChannels"
          :key="ch.id"
          class="wallet-channel-item"
          :class="{ active: selectedId === Number(ch.id) }"
          @click="selectChannel(ch)"
        >
          <WalletChannelIcon :channel="ch" :name="shortName(ch)" />
          <view class="wallet-channel-meta">
            <text class="wallet-channel-name">{{ shortName(ch) }}</text>
            <text
              v-if="isWalletCh(ch) && bindOf(ch)"
              class="wallet-channel-bound"
            >已绑定</text>
          </view>
        </view>
      </view>
      <view
        v-if="hiddenMoreCount > 0 && !coopCompact"
        class="wallet-channel-more-btn"
        @click="showMore = !showMore"
      >
        {{ showMore ? '收起' : ('更多通道 · ' + hiddenMoreCount) }}
      </view>
      <view class="wallet-channel-empty" v-else-if="!loading && !visibleChannels.length && !coopCompact">
        暂无可用通道，请联系客服
      </view>

      <view class="wallet-amount-panel" :class="{ 'is-coop': isCoop }" v-if="selected">
        <view v-if="isCoop" class="wallet-bind-panel wallet-online-coop-panel">
          <view class="wallet-coop-badge">线上合作</view>
          <view class="profile-meta-line wallet-coop-platform">平台：555.bio</view>
          <view class="profile-field">
            <text class="lab wallet-coop-badge">BIO 账号</text>
            <input class="hb-input" :value="mainUid" disabled placeholder="未绑定 / 未通过审核" />
          </view>
          <view class="wallet-warn" v-if="!mainUid">请先绑定并通过主站账号审核</view>
          <view class="profile-meta-line wallet-coop-hint">需绑定BIO账号，人工审核出款</view>
        </view>

        <view v-else-if="isWalletBind" class="wallet-bind-panel">
          <template v-if="bind">
            <view class="profile-meta-line">
              钱包地址：<strong>{{ bind.account_no }}</strong>
            </view>
          </template>
          <template v-else>
            <view class="profile-meta-line">
              请先为该钱包绑定收款地址，每种钱包类型独立绑定，地址不可重复使用。
              <text class="wallet-go-payee-btn" @click="goPayee">去绑定</text>
            </view>
          </template>
        </view>

        <view v-else class="wallet-conventional-panel">
          <view class="profile-field" v-if="!isUsdt">
            <text class="lab">收款人姓名</text>
            <input class="hb-input" v-model="payeeName" placeholder="真实姓名 / 支付宝实名" />
          </view>
          <view class="profile-field">
            <text class="lab">{{ isUsdt ? 'USDT 收款地址' : '收款账号 / 钱包地址' }}</text>
            <input
              class="hb-input"
              v-model="payeeAccount"
              :placeholder="isUsdt ? 'USDT 收款地址（TRC20）' : '钱包地址 / 银行卡号 / 支付宝账号'"
            />
          </view>
          <view class="profile-field" v-if="!isUsdt">
            <text class="lab">银行名称</text>
            <input class="hb-input" v-model="payeeBank" placeholder="钱包通道可填通道名；支付宝填：支付宝" />
          </view>
        </view>

        <view v-if="canEnterAmount" class="wallet-amount-gate">
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
  </ProfileSubPage>
</template>

<script setup>
import { safeNavigateBack, HOME_TAB } from '../../utils/nav.js'
import { computed, ref } from 'vue'
import ProfileSubPage from '../../components/ProfileSubPage.vue'
import WalletChannelIcon from '../../components/WalletChannelIcon.vue'
import { onShow } from '@dcloudio/uni-app'
import { apiRequest, getToken } from '../../utils/auth.js'
import {
  CHANNEL_GRID_VISIBLE,
  clearWalletCache,
  findChannel,
  fxHintText,
  getApprovedMainUid,
  groupByPartitions,
  isOnlineCoopChannel,
  loadProfileLite,
  loadWalletBootstrap,
  money,
  organizeWalletChannels,
  sanitizePayMessage,
  shortChannelName,
  submitWithdraw,
  turnoverHint,
  validateChannelAmount,
} from '../../utils/wallet.js'
import '../../styles/hb.css'

function goBack() {
  safeNavigateBack(HOME_TAB)
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
const showMore = ref(false)

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
const organized = computed(() => organizeWalletChannels(activeChannels.value))
const orderedChannels = computed(() => organized.value.pinned.concat(organized.value.more))
const visibleChannels = computed(() => {
  const all = orderedChannels.value
  if (showMore.value || all.length <= CHANNEL_GRID_VISIBLE) return all
  return all.slice(0, CHANNEL_GRID_VISIBLE)
})
const hiddenMoreCount = computed(() => {
  const n = orderedChannels.value.length - CHANNEL_GRID_VISIBLE
  return n > 0 && !showMore.value ? n : 0
})
const coopCompact = computed(() => {
  // 线上合作分区：隐藏多余通道卡片，但分区 Tab 始终保留，避免点进去出不来
  if (String(activeKey.value || '') === 'online_coop') return true
  const list = orderedChannels.value
  return list.length === 1 && isOnlineCoopChannel(list[0])
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
const balanceText = computed(() => {
  const i = info.value || {}
  const n = i.hongbao != null ? i.hongbao : i.balance
  return money(n || 0)
})
const frozenText = computed(() => {
  const i = info.value || {}
  const n = Number(i.hongbao_frozen != null ? i.hongbao_frozen : i.frozen || 0)
  return n > 0 ? money(n) : ''
})
const turnoverText = computed(() => {
  const i = info.value || {}
  if (i.turnover == null && i.total_turnover == null) return ''
  return money(i.turnover != null ? i.turnover : i.total_turnover)
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
const fxText = computed(() => fxHintText(selected.value, amount.value, { forWithdraw: true }))
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
  if (!isCoop.value && !isWalletBind.value) {
    return String(payeeAccount.value || '').trim()
  }
  return ''
})

function shortName(ch) {
  return shortChannelName(ch)
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
  showMore.value = false
  autoPick()
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
function autoPick() {
  const list = orderedChannels.value
  if (list.length) {
    selectChannel(list[0])
  } else {
    selectedId.value = 0
  }
}
function goPayee() {
  const t = String(walletType.value || '').trim()
  let url = '/pages/wallet/payee?tab=wallet'
  if (t) url += '&type=' + encodeURIComponent(t)
  uni.navigateTo({ url })
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
    uni.showToast({ title: sanitizePayMessage(data && data.message, '提交成功'), icon: 'none' })
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
    if (!selectedId.value) autoPick()
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

<template>
  <view class="hb-page profile-sub-page">
    <TopBar :no-spacer="true" />
    <view class="profile-sub-hd">
      <text class="profile-back-btn" @click="goBack">‹</text>
      <text class="profile-sub-title">钱包地址</text>
      <text class="profile-sub-spacer" />
    </view>
    <view class="profile-sub-body hb-sub">
      <view class="match-card profile-card">
        <!-- 银行卡入口暂时隐藏，仅保留数字钱包绑定 -->
        <view>
          <view class="profile-meta-line">选择钱包类型，展示并管理已绑定地址（与提现钱包一致）</view>
          <view class="wallet-channel-list is-grid">
            <view
              v-for="wt in walletTypes"
              :key="wt.type"
              class="wallet-channel-item"
              :class="{ active: selectedType === wt.type }"
              @click="selectWallet(wt.type)"
            >
              <WalletChannelIcon :icon="wt.icon" :name="wt.label" />
              <view class="wallet-channel-meta">
                <text class="wallet-channel-name">{{ wt.label }}</text>
                <text
                  v-if="wt.multi ? usdtAnyBound : binds[wt.type]"
                  class="wallet-channel-bound"
                >已绑定</text>
              </view>
            </view>
          </view>

          <view class="wallet-bind-panel" v-if="selectedType && selectedType !== 'USDT_MULTI'">
            <view class="profile-meta-line">当前钱包：<strong>{{ selectedLabel }}</strong></view>
            <view class="profile-meta-line" v-if="binds[selectedType]">
              钱包地址：<strong>{{ binds[selectedType].account_no }}</strong>
            </view>
            <view class="profile-field">
              <text class="lab">钱包地址</text>
              <input class="hb-input" v-model="accountNo" placeholder="请输入钱包收款地址" />
            </view>
            <view class="profile-field">
              <text class="lab">备注姓名（可选）</text>
              <input class="hb-input" v-model="accountName" placeholder="可选" />
            </view>
            <button class="btn-uid-submit" :disabled="submitting" @click="onBindSelected">
              {{ submitting ? '提交中…' : (binds[selectedType] ? '更新绑定' : '确认绑定') }}
            </button>
          </view>

          <view class="wallet-bind-panel" v-if="selectedType === 'USDT_MULTI'">
            <view class="profile-meta-line">USDT 分三条填写：TRC20 / ERC20 / TON，可只绑其中一条或几条</view>
            <view v-for="c in usdtChains" :key="c.wallet_type" class="profile-field">
              <text class="lab">{{ c.label }} 地址</text>
              <view class="profile-meta-line" v-if="binds[c.wallet_type]">
                已绑：{{ binds[c.wallet_type].account_no }}
              </view>
              <input
                class="hb-input"
                v-model="usdtInputs[c.wallet_type]"
                :placeholder="'USDT-' + c.label + ' 收款地址'"
              />
            </view>
            <view class="profile-field">
              <text class="lab">备注姓名（可选）</text>
              <input class="hb-input" v-model="usdtName" placeholder="可选" />
            </view>
            <button class="btn-uid-submit" :disabled="submitting" @click="bindUsdtAll">
              {{ submitting ? '提交中…' : '确认绑定' }}
            </button>
          </view>
        </view>
      </view>

      <view class="wallet-ledger-empty" v-if="loading">加载中…</view>
      <view class="wallet-warn" v-if="error" style="text-align:center">{{ error }}</view>

      <view class="wallet-paypwd-modal" v-if="pwdVisible" @click="cancelPwd">
        <view class="wallet-paypwd-sheet" @click.stop>
          <view class="wallet-paypwd-title">
            {{ needSetPwd ? '设置支付密码' : '请输入支付密码' }}
          </view>
          <view class="wallet-paypwd-desc" v-if="needSetPwd">首次设置，用于提现与绑定地址</view>
          <view class="profile-field">
            <text class="lab">支付密码</text>
            <input class="hb-input" password v-model="pwd" placeholder="支付密码" />
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
import { safeNavigateBack, HOME_TAB } from '../../utils/nav.js'
import TopBar from '../../components/TopBar.vue'
import WalletChannelIcon from '../../components/WalletChannelIcon.vue'
import { computed, reactive, ref } from 'vue'
import { onLoad, onShow } from '@dcloudio/uni-app'
import { apiRequest, getToken } from '../../utils/auth.js'
import {
  bindWallet,
  clearWalletCache,
  collectPayeeWalletTypes,
  loadWalletBootstrap,
} from '../../utils/wallet.js'
import '../../styles/hb.css'

function goBack() {
  safeNavigateBack(HOME_TAB)
}

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
const tab = ref('wallet')
const selectedType = ref('')
const accountNo = ref('')
const accountName = ref('')
const bankName = ref('')
const bankNo = ref('')
const bankBank = ref('')
const usdtInputs = reactive({})
const usdtName = ref('')
const usdtChains = USDT_CHAINS
const submitting = ref(false)
let pendingType = ''

const pwdVisible = ref(false)
const pwd = ref('')
const pwd2 = ref('')
const needSetPwd = ref(false)
let pwdResolve = null
let pwdReject = null

const hasPayPassword = computed(() => !!(info.value && info.value.has_pay_password))
const bankBind = computed(() => (binds.value && binds.value.BANK) || null)
const selectedLabel = computed(() => {
  const hit = walletTypes.value.find((w) => w.type === selectedType.value)
  return (hit && hit.label) || selectedType.value
})
const usdtAnyBound = computed(() =>
  USDT_CHAINS.some((c) => binds.value && binds.value[c.wallet_type])
)

function selectWallet(type) {
  selectedType.value = type
  accountNo.value = ''
  accountName.value = ''
  if (type && type !== 'USDT_MULTI' && binds.value[type]) {
    accountNo.value = binds.value[type].account_no || ''
    accountName.value = binds.value[type].account_name || ''
  }
  if (type === 'USDT_MULTI') {
    USDT_CHAINS.forEach((c) => {
      const b = binds.value && binds.value[c.wallet_type]
      usdtInputs[c.wallet_type] = b ? (b.account_no || '') : (usdtInputs[c.wallet_type] || '')
    })
  }
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

async function doBind(walletType, account_no, account_name, bank_name, bindMode, payPwd) {
  const no = String(account_no || '').trim()
  if (!no || no.length < 6) {
    uni.showToast({ title: '地址长度至少 6 位', icon: 'none' })
    return false
  }
  const pwd = payPwd || (await promptPayPassword())
  await bindWallet(
    walletType,
    {
      account_no: no,
      account_name: String(account_name || '').trim() || '钱包用户',
      bank_name: bank_name || walletType,
    },
    pwd,
    bindMode
  )
  return true
}

async function onBindSelected() {
  submitting.value = true
  try {
    await doBind(selectedType.value, accountNo.value, accountName.value, selectedType.value, 'wallet')
    uni.showToast({ title: '绑定成功', icon: 'none' })
    clearWalletCache()
    await refresh()
  } catch (e) {
    if ((e && e.message) !== '已取消') {
      uni.showToast({ title: (e && e.message) || '绑定失败', icon: 'none' })
    }
  } finally {
    submitting.value = false
  }
}

async function bindBank() {
  if (!String(bankName.value || '').trim() || !String(bankNo.value || '').trim()) {
    uni.showToast({ title: '请填写开户名与银行卡号', icon: 'none' })
    return
  }
  submitting.value = true
  try {
    await doBind('BANK', bankNo.value, bankName.value, bankBank.value || '银行卡', 'bank')
    uni.showToast({ title: '保存成功', icon: 'none' })
    clearWalletCache()
    await refresh()
  } catch (e) {
    if ((e && e.message) !== '已取消') {
      uni.showToast({ title: (e && e.message) || '保存失败', icon: 'none' })
    }
  } finally {
    submitting.value = false
  }
}

async function bindUsdtAll() {
  const pending = []
  for (const c of USDT_CHAINS) {
    const no = String(usdtInputs[c.wallet_type] || '').trim()
    if (!no) continue
    if (no.length < 6) {
      uni.showToast({ title: c.label + ' 地址至少 6 位', icon: 'none' })
      return
    }
    pending.push(c)
  }
  if (!pending.length) {
    uni.showToast({ title: '请至少填写一条地址', icon: 'none' })
    return
  }
  submitting.value = true
  try {
    const payPwd = await promptPayPassword()
    for (const c of pending) {
      const no = String(usdtInputs[c.wallet_type] || '').trim()
      await doBind(c.wallet_type, no, usdtName.value || c.label, 'USDT-' + c.label, 'wallet', payPwd)
    }
    uni.showToast({ title: '绑定成功', icon: 'none' })
    clearWalletCache()
    await refresh()
  } catch (e) {
    if ((e && e.message) !== '已取消') {
      uni.showToast({ title: (e && e.message) || '绑定失败', icon: 'none' })
    }
  } finally {
    submitting.value = false
  }
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
    const r = (bundle && bundle.recharge) || {}
    binds.value = w.binds || {}
    walletTypes.value = collectPayeeWalletTypes(r, w)
    tab.value = 'wallet'
    const prefer = pendingType || selectedType.value
    if (prefer) {
      const hit = (walletTypes.value || []).find((w) => w.type === prefer)
      if (hit) selectWallet(prefer)
      else if (prefer.indexOf('USDT') >= 0) selectWallet('USDT_MULTI')
      else if ((walletTypes.value || []).length) selectWallet(walletTypes.value[0].type)
    } else if ((walletTypes.value || []).length && !selectedType.value) {
      selectWallet(walletTypes.value[0].type)
    } else if (selectedType.value) {
      selectWallet(selectedType.value)
    }
    pendingType = ''
  } catch (e) {
    error.value = (e && e.message) || '加载失败'
  } finally {
    loading.value = false
  }
}

onLoad((query) => {
  const q = query || {}
  tab.value = 'wallet'
  const t = decodeURIComponent(String(q.type || q.wallet_type || ''))
  if (t) pendingType = t
})

onShow(() => {
  refresh()
})
</script>

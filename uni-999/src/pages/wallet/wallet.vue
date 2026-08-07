<template>
  <view class="hb-page profile-sub-page">
    <TopBar :no-spacer="true" />
    <view class="profile-sub-hd">
      <text class="profile-back-btn" @click="goBack">‹</text>
      <text class="profile-sub-title">钱包</text>
      <text class="profile-sub-spacer" />
    </view>
    <view class="profile-sub-body">
      <view class="match-card" style="margin-bottom:14px">
        <view class="wallet-bal-line">红宝余额 <strong>{{ balanceText }}</strong></view>
        <view class="profile-meta-line" v-if="frozenText">冻结金额：{{ frozenText }}</view>
        <view class="profile-meta-line">累计流水：{{ turnoverText }}</view>
        <view class="profile-meta-line" v-if="turnHint">{{ turnHint }}</view>
      </view>

      <view class="profile-quick-sheet">
        <view class="profile-quick-item" @click="go('recharge')">
          <view class="profile-quick-ico profile-quick-ico-gold">
            <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
              <path fill="currentColor" d="M12 2a10 10 0 100 20 10 10 0 000-20zm1 5v4h3l-4 6v-4H9l4-6z" />
            </svg>
          </view>
          <text class="profile-quick-label">充值</text>
        </view>
        <view class="profile-quick-item" @click="go('withdraw')">
          <view class="profile-quick-ico profile-quick-ico-gold">
            <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
              <path fill="currentColor" d="M5 4h14a1 1 0 011 1v3H4V5a1 1 0 011-1zm-1 6h16v9a1 1 0 01-1 1H5a1 1 0 01-1-1v-9zm4 3v2h8v-2H8z" />
            </svg>
          </view>
          <text class="profile-quick-label">提现</text>
        </view>
        <view class="profile-quick-item" @click="go('ledger')">
          <view class="profile-quick-ico">
            <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
              <path fill="currentColor" d="M7 3h10a2 2 0 012 2v14a2 2 0 01-2 2H7a2 2 0 01-2-2V5a2 2 0 012-2zm1 4v2h8V7H8zm0 4v2h8v-2H8zm0 4v2h5v-2H8z" />
            </svg>
          </view>
          <text class="profile-quick-label">流水</text>
        </view>
        <view class="profile-quick-item" @click="go('payee')">
          <view class="profile-quick-ico">
            <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
              <path fill="currentColor" d="M3 6h18v3H3V6zm0 5h18v7a2 2 0 01-2 2H5a2 2 0 01-2-2v-7zm3 2v2h4v-2H6z" />
            </svg>
          </view>
          <text class="profile-quick-label">地址</text>
        </view>
      </view>

      <view class="profile-section">
        <view class="profile-section-label">资产服务</view>
        <view class="profile-menu-sheet">
          <view class="profile-menu-row" @click="go('payee')">
            <view class="profile-menu-ico">
              <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
                <path fill="currentColor" d="M3 6h18v3H3V6zm0 5h18v7a2 2 0 01-2 2H5a2 2 0 01-2-2v-7zm3 2v2h4v-2H6z" />
              </svg>
            </view>
            <view class="profile-menu-main">
              <text class="profile-menu-title">钱包地址</text>
              <text class="profile-menu-sub">绑定银行卡与数字钱包</text>
            </view>
            <text class="profile-menu-arrow">›</text>
          </view>
          <view class="profile-menu-row" @click="go('ledger')">
            <view class="profile-menu-ico">
              <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
                <path fill="currentColor" d="M7 3h10a2 2 0 012 2v14a2 2 0 01-2 2H7a2 2 0 01-2-2V5a2 2 0 012-2zm1 4v2h8V7H8zm0 4v2h8v-2H8zm0 4v2h5v-2H8z" />
              </svg>
            </view>
            <view class="profile-menu-main">
              <text class="profile-menu-title">资金流水</text>
              <text class="profile-menu-sub">红宝与股份变动明细</text>
            </view>
            <text class="profile-menu-arrow">›</text>
          </view>
        </view>
      </view>

      <view class="profile-meta-line" v-if="loading" style="text-align:center;padding:20px">加载中…</view>
      <view class="wallet-warn" v-else-if="error" style="text-align:center">{{ error }}</view>
    </view>
  </view>
</template>

<script setup>
import { safeNavigateBack, HOME_TAB } from '../../utils/nav.js'
import { computed, ref } from 'vue'
import TopBar from '../../components/TopBar.vue'
import { onShow } from '@dcloudio/uni-app'
import { getToken } from '../../utils/auth.js'
import { loadWalletBootstrap, money, turnoverHint } from '../../utils/wallet.js'
import '../../styles/hb.css'

const info = ref(null)
const loading = ref(false)
const error = ref('')

const balanceText = computed(() => {
  const i = info.value || {}
  const n = i.hongbao != null ? i.hongbao : i.balance
  return n != null ? money(n) : '—'
})
const frozenText = computed(() => {
  const i = info.value || {}
  const n = Math.max(0, Number(i.hongbao_frozen) || 0)
  return n > 0.00001 ? money(n) : ''
})
const turnoverText = computed(() => money((info.value && info.value.turnover) || 0))
const turnHint = computed(() => turnoverHint(info.value))

function goBack() {
  safeNavigateBack(HOME_TAB)
}

function go(which) {
  uni.navigateTo({ url: '/pages/wallet/' + which })
}

onShow(async () => {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  loading.value = true
  error.value = ''
  try {
    const bundle = await loadWalletBootstrap(false)
    info.value = (bundle && bundle.info) || {}
  } catch (e) {
    error.value = (e && e.message) || '加载失败'
  } finally {
    loading.value = false
  }
})
</script>

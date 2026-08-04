<template>
  <view class="hb-page">
    <view class="match-card" style="margin-bottom:14px">
      <view class="wallet-bal-line">红宝余额 <strong>{{ balanceText }}</strong></view>
      <view class="profile-meta-line" v-if="frozenText">冻结金额：{{ frozenText }}</view>
      <view class="profile-meta-line">累计流水：{{ turnoverText }}</view>
      <view class="profile-meta-line" v-if="turnHint">{{ turnHint }}</view>
    </view>

    <view class="profile-quick-sheet">
      <view class="profile-quick-item" @click="go('recharge')">
        <view class="profile-quick-ico profile-quick-ico-gold">充</view>
        <text class="profile-quick-label">充值</text>
      </view>
      <view class="profile-quick-item" @click="go('withdraw')">
        <view class="profile-quick-ico profile-quick-ico-gold">提</view>
        <text class="profile-quick-label">提现</text>
      </view>
      <view class="profile-quick-item" @click="go('ledger')">
        <view class="profile-quick-ico">流</view>
        <text class="profile-quick-label">流水</text>
      </view>
      <view class="profile-quick-item" @click="go('payee')">
        <view class="profile-quick-ico">址</view>
        <text class="profile-quick-label">地址</text>
      </view>
    </view>

    <view class="profile-section">
      <view class="profile-section-label">资产服务</view>
      <view class="profile-menu-sheet">
        <view class="profile-menu-row" @click="go('payee')">
          <view class="profile-menu-ico">卡</view>
          <view class="profile-menu-main">
            <text><text style="font-weight:750;font-size:15px">钱包地址</text></text>
            <text><text style="font-size:12px;color:#8a7a6e">绑定银行卡与数字钱包</text></text>
          </view>
          <text class="profile-menu-arrow">›</text>
        </view>
        <view class="profile-menu-row" @click="go('ledger')">
          <view class="profile-menu-ico">单</view>
          <view class="profile-menu-main">
            <text><text style="font-weight:750;font-size:15px">资金流水</text></text>
            <text><text style="font-size:12px;color:#8a7a6e">红宝与股份变动明细</text></text>
          </view>
          <text class="profile-menu-arrow">›</text>
        </view>
      </view>
    </view>

    <view class="profile-meta-line" v-if="loading" style="text-align:center;padding:20px">加载中…</view>
    <view class="wallet-warn" v-else-if="error" style="text-align:center">{{ error }}</view>
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { getToken } from '../../utils/auth.js'
import { loadWalletBootstrap, money, turnoverHint } from '../../utils/wallet.js'

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

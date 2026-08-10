<template>
  <view class="hb-page profile-sub-page">
    <TopBar />
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
            <AppGlyph name="recharge" />
          </view>
          <text class="profile-quick-label">充值</text>
        </view>
        <view class="profile-quick-item" @click="go('withdraw')">
          <view class="profile-quick-ico profile-quick-ico-gold">
            <AppGlyph name="withdraw" />
          </view>
          <text class="profile-quick-label">提现</text>
        </view>
        <view class="profile-quick-item" @click="go('ledger')">
          <view class="profile-quick-ico">
            <AppGlyph name="ledger" />
          </view>
          <text class="profile-quick-label">流水</text>
        </view>
        <view class="profile-quick-item" @click="go('payee')">
          <view class="profile-quick-ico">
            <AppGlyph name="payee" />
          </view>
          <text class="profile-quick-label">地址</text>
        </view>
      </view>

      <view class="profile-section">
        <view class="profile-section-label">资产服务</view>
        <view class="profile-menu-sheet">
          <view class="profile-menu-row" @click="go('payee')">
            <view class="profile-menu-ico">
              <AppGlyph name="payee" size="sm" />
            </view>
            <view class="profile-menu-main">
              <text class="profile-menu-title">钱包地址</text>
              <text class="profile-menu-sub">绑定数字钱包地址</text>
            </view>
            <text class="profile-menu-arrow">›</text>
          </view>
          <view class="profile-menu-row" @click="go('ledger')">
            <view class="profile-menu-ico">
              <AppGlyph name="ledger" size="sm" />
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
import AppGlyph from '../../components/AppGlyph.vue'
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

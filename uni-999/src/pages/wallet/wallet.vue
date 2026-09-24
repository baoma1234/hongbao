<template>
  <ProfileSubPage title="钱包">
      <view class="match-card" style="margin-bottom:14px">
        <view class="wallet-bal-line">红宝余额 <strong>{{ balanceText }}</strong></view>
        <view class="profile-meta-line">出款所需流水为：{{ turnoverNeedText }}</view>
        <view class="profile-meta-line" v-if="frozenText">冻结金额：{{ frozenText }}</view>
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
          <view class="profile-menu-row" v-if="realnameRequired" @click="go('realname')">
            <view class="profile-menu-ico">
              <AppGlyph name="payee" size="sm" />
            </view>
            <view class="profile-menu-main">
              <text class="profile-menu-title">真实姓名</text>
              <text class="profile-menu-sub">{{ payoutRealName ? ('已绑定 ' + payoutRealName) : '非 USDT 出款需绑定' }}</text>
            </view>
            <text class="profile-menu-arrow">›</text>
          </view>
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
  </ProfileSubPage>
</template>

<script setup>
import { computed, ref } from 'vue'
import ProfileSubPage from '../../components/ProfileSubPage.vue'
import AppGlyph from '../../components/AppGlyph.vue'
import { onShow } from '@dcloudio/uni-app'
import { getToken } from '../../utils/auth.js'
import { loadWalletBootstrap, money } from '../../utils/wallet.js'
import '../../styles/hb.css'

const info = ref(null)
const loading = ref(false)
const error = ref('')

const turnoverNeed = computed(() => {
  const n = Number((info.value && info.value.turnover) || 0)
  if (!isFinite(n) || n <= 0) return 0
  return Math.round(n * 100) / 100
})
const balanceText = computed(() => {
  if (turnoverNeed.value > 0) return money(0)
  const i = info.value || {}
  const n = i.hongbao != null ? i.hongbao : i.balance
  return n != null ? money(n) : '—'
})
const frozenText = computed(() => {
  const i = info.value || {}
  const n = Math.max(0, Number(i.hongbao_frozen) || 0)
  return n > 0.00001 ? money(n) : ''
})
const turnoverNeedText = computed(() => money(turnoverNeed.value))
const realnameRequired = computed(() => !!(info.value && info.value.withdraw_realname_bind_enabled))
const payoutRealName = computed(() => String((info.value && info.value.payout_real_name) || '').trim())

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

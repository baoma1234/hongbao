<template>
  <view class="hb-page" :key="locale">
    <TopBar />
    <view class="profile-vip-hero">
      <view class="profile-vip-hero-shine" />
      <text class="profile-vip-watermark">{{ t('brand_name') }}</text>
      <view class="profile-vip-identity">
        <image
          v-if="avatar"
          class="profile-avatar-img"
          :src="avatar"
          mode="aspectFill"
        />
        <view v-else class="profile-avatar-fallback">{{ avatarLetter }}</view>
        <view class="profile-vip-text">
          <view class="profile-vip-name-row">
            <text class="profile-summary-name">{{ displayName }}</text>
            <text class="profile-vip-badge">{{ t('profile_vip_badge') || '官方会员' }}</text>
          </view>
          <view class="profile-meta-line">
            <text>{{ t('profile_user_id_label') || '会员ID' }}</text>
            <text><text style="font-weight:800;color:#fff">{{ userId }}</text></text>
            <text class="profile-copy-uid-btn" @click="copyUid">{{ t('profile_uid_copy_btn') || '复制' }}</text>
          </view>
          <view class="profile-meta-line">
            <text>{{ t('profile_mobile_label') || '绑定手机' }}</text>
            <text><text style="font-weight:800;color:#fff">{{ mobile }}</text></text>
          </view>
        </view>
      </view>
    </view>

    <view class="profile-quick-sheet">
      <view class="profile-quick-item" @click="go('/pages/wallet/recharge')">
        <view class="profile-quick-ico profile-quick-ico-gold">充</view>
        <text class="profile-quick-label">{{ t('profile_quick_recharge') || '充值' }}</text>
      </view>
      <view class="profile-quick-item" @click="go('/pages/wallet/withdraw')">
        <view class="profile-quick-ico profile-quick-ico-gold">提</view>
        <text class="profile-quick-label">{{ t('profile_quick_withdraw') || '提现' }}</text>
      </view>
      <view class="profile-quick-item" @click="go('/pages/wallet/ledger')">
        <view class="profile-quick-ico">流</view>
        <text class="profile-quick-label">{{ t('profile_menu_ledger') || '流水' }}</text>
      </view>
      <view class="profile-quick-item" @click="go('/pages/wallet/payee')">
        <view class="profile-quick-ico">址</view>
        <text class="profile-quick-label">{{ t('profile_menu_payee') || '地址' }}</text>
      </view>
    </view>

    <view class="profile-section">
      <view class="profile-section-label">{{ t('profile_section_asset') || '资产服务' }}</view>
      <view class="profile-menu-sheet">
        <view class="profile-menu-row" @click="go('/pages/wallet/payee')">
          <view class="profile-menu-ico">卡</view>
          <view class="profile-menu-main">
            <text><text style="font-weight:750;font-size:15px">{{ t('profile_menu_payee') || '钱包地址' }}</text></text>
            <text><text style="font-size:12px;color:#8a7a6e">{{ t('profile_menu_payee_sub') || '绑定银行卡与数字钱包' }}</text></text>
          </view>
          <text class="profile-menu-arrow">›</text>
        </view>
        <view class="profile-menu-row" @click="go('/pages/wallet/ledger')">
          <view class="profile-menu-ico">单</view>
          <view class="profile-menu-main">
            <text><text style="font-weight:750;font-size:15px">{{ t('profile_menu_ledger') || '资金流水' }}</text></text>
            <text><text style="font-size:12px;color:#8a7a6e">{{ t('profile_menu_ledger_sub') || '红宝与股份变动明细' }}</text></text>
          </view>
          <text class="profile-menu-arrow">›</text>
        </view>
        <view class="profile-menu-row" @click="go('/pages/wallet/wallet')">
          <view class="profile-menu-ico">¥</view>
          <view class="profile-menu-main">
            <text><text style="font-weight:750;font-size:15px">我的钱包</text></text>
            <text><text style="font-size:12px;color:#8a7a6e">余额 {{ balanceText }} · 流水 {{ turnoverText }}</text></text>
          </view>
          <text class="profile-menu-arrow">›</text>
        </view>
      </view>
    </view>

    <view class="profile-section">
      <view class="profile-section-label">{{ t('profile_section_security') || '账号与安全' }}</view>
      <view class="profile-menu-sheet">
        <view class="profile-menu-row">
          <view class="profile-menu-ico">密</view>
          <view class="profile-menu-main">
            <text><text style="font-weight:750;font-size:15px">{{ t('profile_menu_pay_password') || '支付密码' }}</text></text>
            <text><text style="font-size:12px;color:#8a7a6e">{{ t('profile_menu_pay_password_sub') || '提现与绑定地址校验（在提现时设置）' }}</text></text>
          </view>
          <text class="profile-menu-arrow">›</text>
        </view>
      </view>
    </view>

    <button class="profile-logout-btn" @click="onLogout">{{ t('profile_logout_btn') || '退出登录' }}</button>
    <view class="profile-foot-note">{{ t('profile_foot_note') || '红宝官方 · 会员中心' }}</view>
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import { fetchProfile, getToken, logoutLocal } from '../../utils/auth.js'
import { localeState, t } from '../../utils/i18n.js'
import { imDisconnect } from '../../utils/im.js'
import { loadWalletBootstrap, money } from '../../utils/wallet.js'

const locale = localeState()
const profile = ref(null)
const info = ref({})

const displayName = computed(() => {
  const p = profile.value || {}
  return p.nickname || p.username || p.mobile || '会员'
})
const userId = computed(() => (profile.value && (profile.value.user_id || profile.value.id)) || '-')
const mobile = computed(() => (profile.value && (profile.value.mobile || profile.value.user?.mobile)) || '-')
const avatar = computed(() => {
  const p = profile.value || {}
  return p.avatar || p.avatar_url || ''
})
const avatarLetter = computed(() => String(displayName.value || '?').charAt(0))
const balanceText = computed(() => {
  const i = info.value || {}
  const n = i.hongbao != null ? i.hongbao : i.balance
  return money(n || 0)
})
const turnoverText = computed(() => money((info.value && info.value.turnover) || 0))

function go(url) {
  uni.navigateTo({ url })
}

function copyUid() {
  uni.setClipboardData({
    data: String(userId.value),
    success: () => uni.showToast({ title: t('profile_uid_copied') || '已复制', icon: 'none' }),
  })
}

function onLogout() {
  imDisconnect()
  logoutLocal()
  uni.reLaunch({ url: '/pages/login/login' })
}

onShow(async () => {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  try {
    profile.value = await fetchProfile()
  } catch (e) {}
  try {
    const bundle = await loadWalletBootstrap(false)
    info.value = (bundle && bundle.info) || {}
  } catch (e) {}
})
</script>

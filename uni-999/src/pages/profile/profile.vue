<template>
  <view class="hb-page" :key="locale">
    <TopBar />
    <view class="profile-vip-hero">
      <view class="profile-vip-hero-shine" />
      <text class="profile-vip-watermark">{{ t('brand_name') }}</text>
      <view class="profile-vip-identity">
        <image
          class="profile-avatar-img"
          :src="avatarSrc(avatar)"
          mode="aspectFill"
        />
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
            <text><text style="font-weight:800;color:#fff">{{ mobileMask }}</text></text>
          </view>
        </view>
      </view>
    </view>

    <view class="profile-quick-sheet">
      <view class="profile-quick-item" @click="go('/pages/profile/qr')">
        <view class="profile-quick-ico">
          <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
            <path fill="currentColor" d="M3 3h8v8H3V3zm2 2v4h4V5H5zm8-2h8v8h-8V3zm2 2v4h4V5h-4zM3 13h8v8H3v-8zm2 2v4h4v-4H5zm13-2h-3v2h2v2h-2v2h3v-2h2v-4h-2v-2zm-3 6h2v2h-2v-2z" />
          </svg>
        </view>
        <text class="profile-quick-label">{{ t('profile_quick_qr') || '二维码' }}</text>
      </view>
      <view class="profile-quick-item" @click="onScan">
        <view class="profile-quick-ico">
          <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
            <path fill="currentColor" d="M4 4h4v2H6v2H4V4zm12 0h4v4h-2V6h-2V4zM4 16h2v2h2v2H4v-4zm14 2h-2v2h4v-4h-2v2zM8 8h8v8H8V8zm2 2v4h4v-4h-4z" />
          </svg>
        </view>
        <text class="profile-quick-label">{{ t('profile_quick_scan') || '扫一扫' }}</text>
      </view>
      <view class="profile-quick-item" @click="go('/pages/wallet/recharge')">
        <view class="profile-quick-ico profile-quick-ico-gold">
          <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
            <path fill="currentColor" d="M12 2a10 10 0 100 20 10 10 0 000-20zm1 5v4h3l-4 6v-4H9l4-6z" />
          </svg>
        </view>
        <text class="profile-quick-label">{{ t('profile_quick_recharge') || '充值' }}</text>
      </view>
      <view class="profile-quick-item" @click="go('/pages/wallet/withdraw')">
        <view class="profile-quick-ico profile-quick-ico-gold">
          <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
            <path fill="currentColor" d="M5 4h14a1 1 0 011 1v3H4V5a1 1 0 011-1zm-1 6h16v9a1 1 0 01-1 1H5a1 1 0 01-1-1v-9zm4 3v2h8v-2H8z" />
          </svg>
        </view>
        <text class="profile-quick-label">{{ t('profile_quick_withdraw') || '提现' }}</text>
      </view>
    </view>

    <view class="profile-section">
      <view class="profile-section-label">{{ t('profile_section_asset') || '资产服务' }}</view>
      <view class="profile-menu-sheet">
        <view class="profile-menu-row" @click="go('/pages/wallet/payee')">
          <view class="profile-menu-ico">
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
              <path fill="currentColor" d="M3 6h18v3H3V6zm0 5h18v7a2 2 0 01-2 2H5a2 2 0 01-2-2v-7zm3 2v2h4v-2H6z" />
            </svg>
          </view>
          <view class="profile-menu-main">
            <text class="profile-menu-title">{{ t('profile_menu_payee') || '钱包地址' }}</text>
            <text class="profile-menu-sub">{{ t('profile_menu_payee_sub') || '绑定银行卡与数字钱包' }}</text>
          </view>
          <text class="profile-menu-arrow">›</text>
        </view>
        <view class="profile-menu-row" @click="go('/pages/wallet/ledger')">
          <view class="profile-menu-ico">
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
              <path fill="currentColor" d="M7 3h10a2 2 0 012 2v14a2 2 0 01-2 2H7a2 2 0 01-2-2V5a2 2 0 012-2zm1 4v2h8V7H8zm0 4v2h8v-2H8zm0 4v2h5v-2H8z" />
            </svg>
          </view>
          <view class="profile-menu-main">
            <text class="profile-menu-title">{{ t('profile_menu_ledger') || '资金流水' }}</text>
            <text class="profile-menu-sub">{{ t('profile_menu_ledger_sub') || '红宝与股份变动明细' }}</text>
          </view>
          <text class="profile-menu-arrow">›</text>
        </view>
      </view>
    </view>

    <view class="profile-section">
      <view class="profile-section-label">{{ t('profile_section_security') || '账号与安全' }}</view>
      <view class="profile-menu-sheet">
        <view class="profile-menu-row" @click="go('/pages/profile/info')">
          <view class="profile-menu-ico">
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
              <path fill="currentColor" d="M12 12a4.5 4.5 0 100-9 4.5 4.5 0 000 9zm0 2c-4.4 0-8 2.2-8 5v1h16v-1c0-2.8-3.6-5-8-5z" />
            </svg>
          </view>
          <view class="profile-menu-main">
            <text class="profile-menu-title">{{ t('profile_menu_info') || '头像与昵称' }}</text>
            <text class="profile-menu-sub">{{ t('profile_menu_info_sub') || '修改头像、昵称' }}</text>
          </view>
          <text class="profile-menu-arrow">›</text>
        </view>
        <view class="profile-menu-row" @click="go('/pages/profile/password')">
          <view class="profile-menu-ico">
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
              <path fill="currentColor" d="M12 2a5 5 0 015 5v2h1a2 2 0 012 2v9a2 2 0 01-2 2H6a2 2 0 01-2-2v-9a2 2 0 012-2h1V7a5 5 0 015-5zm0 2a3 3 0 00-3 3v2h6V7a3 3 0 00-3-3zm0 9a1.75 1.75 0 100 3.5A1.75 1.75 0 0012 13z" />
            </svg>
          </view>
          <view class="profile-menu-main">
            <text class="profile-menu-title">{{ t('profile_menu_password') || '修改密码' }}</text>
            <text class="profile-menu-sub">{{ t('profile_menu_password_sub') || '旧密码或短信验证' }}</text>
          </view>
          <text class="profile-menu-arrow">›</text>
        </view>
        <view class="profile-menu-row" @click="go('/pages/profile/paypassword')">
          <view class="profile-menu-ico">
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
              <path fill="currentColor" d="M12 1a5 5 0 015 5v2h1.5A1.5 1.5 0 0120 9.5v11A1.5 1.5 0 0118.5 22h-13A1.5 1.5 0 014 20.5v-11A1.5 1.5 0 015.5 8H7V6a5 5 0 015-5zm0 2a3 3 0 00-3 3v2h6V6a3 3 0 00-3-3zm0 9.25a1.75 1.75 0 100 3.5 1.75 1.75 0 000-3.5z" />
            </svg>
          </view>
          <view class="profile-menu-main">
            <text class="profile-menu-title">{{ t('profile_menu_pay_password') || '支付密码' }}</text>
            <text class="profile-menu-sub">{{ t('profile_menu_pay_password_sub') || '提现与绑定地址校验' }}</text>
          </view>
          <text class="profile-menu-arrow">›</text>
        </view>
      </view>
    </view>

    <button class="profile-logout-btn" @click="onLogout">{{ t('profile_logout_btn') || '退出登录' }}</button>
    <view class="profile-foot-note">{{ t('profile_foot_note') || '红宝官方 · 会员中心' }}</view>
    <FriendScanSheet />
    <BottomTabBar active="profile" />
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import BottomTabBar from '../../components/BottomTabBar.vue'
import FriendScanSheet from '../../components/FriendScanSheet.vue'
import { fetchProfile, getToken, logoutLocal, logoutRemote } from '../../utils/auth.js'
import { avatarSrc } from '../../utils/chat.js'
import { localeState, t } from '../../utils/i18n.js'
import { openFriendScanSheet } from '../../utils/friend-scan.js'
import { imDisconnect } from '../../utils/im.js'

const locale = localeState()
const profile = ref(null)

const displayName = computed(() => {
  const p = profile.value || {}
  return p.nickname || p.username || p.mobile_mask || p.mobile || '会员'
})
const userId = computed(() => (profile.value && (profile.value.user_id || profile.value.id)) || '-')
const mobileMask = computed(() => {
  const p = profile.value || {}
  return p.mobile_mask || maskMobile(p.mobile || (p.user && p.user.mobile)) || '-'
})
const avatar = computed(() => {
  const p = profile.value || {}
  return p.avatar_url || p.avatar || ''
})

function maskMobile(m) {
  const s = String(m || '').replace(/\D+/g, '')
  if (s.length < 7) return s || ''
  return s.slice(0, 3) + '****' + s.slice(-4)
}

function go(url) {
  uni.navigateTo({ url })
}

function copyUid() {
  const text = String(userId.value || '').trim()
  if (!text || text === '-') {
    uni.showToast({ title: t('profile_uid_copy_empty') || '暂无会员ID', icon: 'none' })
    return
  }
  uni.setClipboardData({
    data: text,
    success: () => uni.showToast({ title: t('profile_uid_copied') || '已复制', icon: 'none' }),
  })
}

function onScan() {
  openFriendScanSheet({
    selfUserId: userId.value,
    onManual: () => go('/pages/friend/add'),
  })
}

function onLogout() {
  uni.showModal({
    title: t('profile_logout_btn') || '退出登录',
    content: t('profile_logout_confirm') || '确定退出当前账号？',
    success: async (r) => {
      if (!r.confirm) return
      await logoutRemote()
      imDisconnect()
      logoutLocal()
      uni.showToast({ title: t('alert_logout_ok') || '已退出', icon: 'none' })
      setTimeout(() => {
        uni.reLaunch({ url: '/pages/login/login' })
      }, 300)
    },
  })
}

onShow(async () => {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  try {
    profile.value = await fetchProfile()
  } catch (e) {}
})
</script>

<style scoped>
.profile-menu-title {
  font-weight: 750;
  font-size: 15px;
  color: #3d2e22;
  display: block;
}
.profile-menu-sub {
  font-size: 12px;
  color: #8a7a6e;
  display: block;
  margin-top: 2px;
}
</style>

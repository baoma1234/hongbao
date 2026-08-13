<template>
  <view class="hb-page" :key="pageKey">
    <TopBar />
    <view class="profile-vip-hero">
      <view class="profile-vip-hero-shine" />
      <text class="profile-vip-watermark">{{ t('brand_name') }}</text>
      <view class="profile-vip-identity">
          <image
          class="profile-avatar-img"
          :key="'av-' + avatarSrc(avatar)"
          :src="avatarSrc(avatar)"
          mode="aspectFill"
          @click="go('/pages/profile/info')"
        />
        <view class="profile-vip-text">
          <view class="profile-vip-name-row">
            <text class="profile-summary-name">{{ displayName }}</text>
            <text class="profile-vip-badge">{{ tt('profile_vip_badge', '官方会员') }}</text>
          </view>
          <view class="profile-meta-line">
            <text>{{ tt('profile_user_id_label', '会员ID') }}</text>
            <text><text style="font-weight:800;color:#fff">{{ userId }}</text></text>
            <text class="profile-copy-uid-btn" @click="copyUid">{{ tt('profile_uid_copy_btn', '复制') }}</text>
          </view>
          <view class="profile-meta-line">
            <text>{{ tt('profile_mobile_label', '绑定手机') }}</text>
            <text><text style="font-weight:800;color:#fff">{{ mobileMask }}</text></text>
          </view>
        </view>
      </view>
    </view>

    <view class="profile-quick-sheet">
      <view class="profile-quick-item" @click="go('/pages/profile/qr')">
        <view class="profile-quick-ico">
          <AppGlyph name="qr" />
        </view>
        <text class="profile-quick-label">{{ tt('profile_quick_qr', '二维码') }}</text>
      </view>
      <view class="profile-quick-item" @click="onScan">
        <view class="profile-quick-ico">
          <AppGlyph name="scan" />
        </view>
        <text class="profile-quick-label">{{ tt('profile_quick_scan', '扫一扫') }}</text>
      </view>
      <view class="profile-quick-item" @click="go('/pages/wallet/recharge')">
        <view class="profile-quick-ico profile-quick-ico-gold">
          <AppGlyph name="recharge" />
        </view>
        <text class="profile-quick-label">{{ tt('profile_quick_recharge', '充值') }}</text>
      </view>
      <view class="profile-quick-item" @click="go('/pages/wallet/withdraw')">
        <view class="profile-quick-ico profile-quick-ico-gold">
          <AppGlyph name="withdraw" />
        </view>
        <text class="profile-quick-label">{{ tt('profile_quick_withdraw', '提现') }}</text>
      </view>
    </view>

    <view class="profile-section">
      <view class="profile-section-label">{{ tt('profile_section_asset', '资产服务') }}</view>
      <view class="profile-menu-sheet">
        <view class="profile-menu-row" @click="go('/pages/wallet/payee')">
          <view class="profile-menu-ico">
            <AppGlyph name="payee" size="sm" />
          </view>
          <view class="profile-menu-main">
            <text class="profile-menu-title">{{ tt('profile_menu_payee', '钱包地址') }}</text>
            <text class="profile-menu-sub">{{ tt('profile_menu_payee_sub', '绑定数字钱包地址') }}</text>
          </view>
          <text class="profile-menu-arrow">›</text>
        </view>
        <view class="profile-menu-row" @click="go('/pages/wallet/ledger')">
          <view class="profile-menu-ico">
            <AppGlyph name="ledger" size="sm" />
          </view>
          <view class="profile-menu-main">
            <text class="profile-menu-title">{{ tt('profile_menu_ledger', '资金流水') }}</text>
            <text class="profile-menu-sub">{{ tt('profile_menu_ledger_sub', '红宝与股份变动明细') }}</text>
          </view>
          <text class="profile-menu-arrow">›</text>
        </view>
      </view>
    </view>

    <view class="profile-section">
      <view class="profile-section-label">{{ tt('profile_section_security', '账号与安全') }}</view>
      <view class="profile-menu-sheet">
        <view class="profile-menu-row" @click="go('/pages/profile/info')">
          <view class="profile-menu-ico">
            <AppGlyph name="info" size="sm" />
          </view>
          <view class="profile-menu-main">
            <text class="profile-menu-title">{{ tt('profile_menu_info', '头像与昵称') }}</text>
            <text class="profile-menu-sub">{{ tt('profile_menu_info_sub', '修改头像、昵称') }}</text>
          </view>
          <text class="profile-menu-arrow">›</text>
        </view>
        <view class="profile-menu-row" @click="go('/pages/profile/paypassword')">
          <view class="profile-menu-ico">
            <AppGlyph name="paypassword" size="sm" />
          </view>
          <view class="profile-menu-main">
            <text class="profile-menu-title">{{ tt('profile_menu_pay_password', '修改支付密码') }}</text>
            <text class="profile-menu-sub">{{ tt('profile_menu_pay_password_sub', '提现与绑定地址校验') }}</text>
          </view>
          <text class="profile-menu-arrow">›</text>
        </view>
      </view>
    </view>

    <button class="profile-logout-btn" @click="onLogout">{{ tt('profile_logout_btn', '退出登录') }}</button>
    <view class="profile-foot-note">{{ tt('profile_foot_note', '红宝官方 · 会员中心') }}</view>
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
import AppGlyph from '../../components/AppGlyph.vue'
import { fetchProfile, getToken, logoutLocal, logoutRemote } from '../../utils/auth.js'
import { avatarSrc } from '../../utils/chat.js'
import { copyState, ensureLocaleLoaded, getLocale, localeState, t, tt } from '../../utils/i18n.js'
import { openFriendScanSheet } from '../../utils/friend-scan.js'
import { imDisconnect } from '../../utils/im.js'

const locale = localeState()
const copyTick = copyState()
const pageKey = computed(() => String(locale.value || '') + '-' + String(copyTick.value || 0))
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
    uni.showToast({ title: tt('profile_uid_copy_empty', '暂无会员ID'), icon: 'none' })
    return
  }
  uni.setClipboardData({
    data: text,
    success: () => uni.showToast({ title: tt('profile_uid_copied', '已复制'), icon: 'none' }),
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
    title: tt('profile_logout_btn', '退出登录'),
    content: tt('profile_logout_confirm', '确定退出当前账号？'),
    success: async (r) => {
      if (!r.confirm) return
      await logoutRemote()
      imDisconnect()
      logoutLocal()
      uni.showToast({ title: tt('alert_logout_ok', '已退出'), icon: 'none' })
      setTimeout(() => {
        uni.reLaunch({ url: '/pages/login/login' })
      }, 300)
    },
  })
}

onShow(async () => {
  // 进入「我的」时确保语言包已加载，避免仍显示 profile_quick_qr 等 key
  try {
    await ensureLocaleLoaded(getLocale())
  } catch (e) {}
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  try {
    profile.value = await fetchProfile()
  } catch (e) {
    profile.value = null
  }
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

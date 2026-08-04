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
            <text><text style="font-weight:800;color:#fff">{{ mobileMask }}</text></text>
          </view>
        </view>
      </view>
    </view>

    <view class="profile-quick-sheet">
      <view class="profile-quick-item" @click="go('/pages/profile/qr')">
        <view class="profile-quick-ico">码</view>
        <text class="profile-quick-label">{{ t('profile_quick_qr') || '二维码' }}</text>
      </view>
      <view class="profile-quick-item" @click="onScan">
        <view class="profile-quick-ico">扫</view>
        <text class="profile-quick-label">{{ t('profile_quick_scan') || '扫一扫' }}</text>
      </view>
      <view class="profile-quick-item" @click="go('/pages/wallet/recharge')">
        <view class="profile-quick-ico profile-quick-ico-gold">充</view>
        <text class="profile-quick-label">{{ t('profile_quick_recharge') || '充值' }}</text>
      </view>
      <view class="profile-quick-item" @click="go('/pages/wallet/withdraw')">
        <view class="profile-quick-ico profile-quick-ico-gold">提</view>
        <text class="profile-quick-label">{{ t('profile_quick_withdraw') || '提现' }}</text>
      </view>
    </view>

    <view class="profile-section">
      <view class="profile-section-label">{{ t('profile_section_asset') || '资产服务' }}</view>
      <view class="profile-menu-sheet">
        <view class="profile-menu-row" @click="go('/pages/wallet/payee')">
          <view class="profile-menu-ico">卡</view>
          <view class="profile-menu-main">
            <text class="profile-menu-title">{{ t('profile_menu_payee') || '钱包地址' }}</text>
            <text class="profile-menu-sub">{{ t('profile_menu_payee_sub') || '绑定银行卡与数字钱包' }}</text>
          </view>
          <text class="profile-menu-arrow">›</text>
        </view>
        <view class="profile-menu-row" @click="go('/pages/wallet/ledger')">
          <view class="profile-menu-ico">单</view>
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
          <view class="profile-menu-ico">资</view>
          <view class="profile-menu-main">
            <text class="profile-menu-title">{{ t('profile_menu_info') || '头像与昵称' }}</text>
            <text class="profile-menu-sub">{{ t('profile_menu_info_sub') || '修改头像、昵称' }}</text>
          </view>
          <text class="profile-menu-arrow">›</text>
        </view>
        <view class="profile-menu-row" @click="go('/pages/profile/password')">
          <view class="profile-menu-ico">密</view>
          <view class="profile-menu-main">
            <text class="profile-menu-title">{{ t('profile_menu_password') || '修改密码' }}</text>
            <text class="profile-menu-sub">{{ t('profile_menu_password_sub') || '旧密码或短信验证' }}</text>
          </view>
          <text class="profile-menu-arrow">›</text>
        </view>
        <view class="profile-menu-row" @click="go('/pages/profile/paypassword')">
          <view class="profile-menu-ico">付</view>
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
    <BottomTabBar active="profile" />
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import BottomTabBar from '../../components/BottomTabBar.vue'
import { fetchProfile, getToken, logoutLocal, logoutRemote } from '../../utils/auth.js'
import { assetBase, localeState, t } from '../../utils/i18n.js'
import { friendLookup, friendRequest, imConnect, imDisconnect } from '../../utils/im.js'

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
const avatarLetter = computed(() => String(displayName.value || '?').charAt(0))

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

function parseFriendPayload(text) {
  const raw = String(text || '').trim()
  if (!raw) return ''
  let m = raw.match(/FANSHUB_FRIEND[:：]\s*(\d{8})/i)
  if (m) return m[1]
  m = raw.match(/(?:friend|uid|user_id)[=:/]\s*(\d{8})/i)
  if (m) return m[1]
  m = raw.match(/^\d{8}$/)
  if (m) return m[0]
  m = raw.replace(/\D+/g, '').match(/(\d{8})/)
  return m ? m[1] : ''
}

async function addFriendById(memberId) {
  const id = String(memberId || '').replace(/\D+/g, '')
  if (!/^\d{8}$/.test(id)) {
    uni.showToast({ title: '无效的会员二维码', icon: 'none' })
    return
  }
  const self = String(userId.value || '').replace(/\D+/g, '')
  if (self && self === id) {
    uni.showToast({ title: '不能添加自己为好友', icon: 'none' })
    return
  }
  try {
    await imConnect()
    const packet = await friendLookup({ user_id: id })
    const data = (packet && packet.data) || {}
    if (!data.found) {
      uni.showToast({ title: '未找到该用户', icon: 'none' })
      return
    }
    const u = data.user || {}
    const name = u.nickname || ('ID' + (u.user_id || id))
    const ok = await new Promise((resolve) => {
      uni.showModal({
        title: '确认添加',
        content: '向「' + name + '」发送好友申请？',
        success: (r) => resolve(!!r.confirm),
      })
    })
    if (!ok) return
    await friendRequest({ user_id: u.user_id || id, message: '' })
    uni.showToast({ title: '已发送好友申请', icon: 'none' })
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '添加好友失败', icon: 'none' })
  }
}

function loadScriptOnce(src) {
  return new Promise((resolve, reject) => {
    // #ifdef H5
    if (typeof document === 'undefined') {
      reject(new Error('no document'))
      return
    }
    const exist = document.querySelector('script[data-src="' + src + '"]')
    if (exist) {
      resolve()
      return
    }
    const s = document.createElement('script')
    s.src = src
    s.async = true
    s.setAttribute('data-src', src)
    s.onload = () => resolve()
    s.onerror = () => reject(new Error('load fail'))
    document.head.appendChild(s)
    // #endif
    // #ifndef H5
    reject(new Error('H5 only'))
    // #endif
  })
}

async function decodeQrFromPath(filePath) {
  // #ifdef H5
  await loadScriptOnce(assetBase() + 'static/vendor/jsQR.js')
  const jsQRFn = typeof window !== 'undefined' ? window.jsQR : null
  if (!jsQRFn) throw new Error('扫码库未加载')
  const img = await new Promise((resolve, reject) => {
    const el = new Image()
    el.onload = () => resolve(el)
    el.onerror = () => reject(new Error('图片加载失败'))
    el.src = filePath
  })
  const canvas = document.createElement('canvas')
  canvas.width = img.naturalWidth || img.width
  canvas.height = img.naturalHeight || img.height
  const ctx = canvas.getContext('2d')
  ctx.drawImage(img, 0, 0)
  const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height)
  const code = jsQRFn(imageData.data, imageData.width, imageData.height)
  if (!code || !code.data) throw new Error('未识别到二维码')
  return String(code.data)
  // #endif
  // #ifndef H5
  throw new Error('请使用扫一扫')
  // #endif
}

function onScan() {
  uni.showActionSheet({
    itemList: ['扫一扫', '从相册识别', '手动输入会员ID'],
    success: async (res) => {
      const idx = res.tapIndex | 0
      if (idx === 2) {
        go('/pages/friend/add')
        return
      }
      if (idx === 0) {
        try {
          const r = await new Promise((resolve, reject) => {
            uni.scanCode({
              onlyFromCamera: true,
              success: resolve,
              fail: reject,
            })
          })
          const id = parseFriendPayload(r && r.result)
          if (id) await addFriendById(id)
          else uni.showToast({ title: '无效的会员二维码', icon: 'none' })
        } catch (e) {
          uni.showToast({ title: (e && e.errMsg) || '扫码取消', icon: 'none' })
        }
        return
      }
      try {
        const pick = await new Promise((resolve, reject) => {
          uni.chooseImage({
            count: 1,
            sizeType: ['compressed'],
            sourceType: ['album'],
            success: resolve,
            fail: reject,
          })
        })
        const path = pick.tempFilePaths && pick.tempFilePaths[0]
        if (!path) return
        const raw = await decodeQrFromPath(path)
        const id = parseFriendPayload(raw)
        if (id) await addFriendById(id)
        else uni.showToast({ title: '无效的会员二维码', icon: 'none' })
      } catch (e) {
        uni.showToast({ title: (e && e.message) || '识别失败', icon: 'none' })
      }
    },
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

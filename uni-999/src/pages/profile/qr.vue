<template>
  <ProfileSubPage :page-key="locale" :title="tt('profile_qr_title', '我的二维码')">
      <view class="match-card profile-card profile-qr-card">
        <view class="profile-qr-box">
          <image v-if="qrDataUrl" class="profile-qr-img" :src="qrDataUrl" mode="aspectFit" />
          <view v-else class="profile-qr-placeholder">{{ tip || '…' }}</view>
        </view>
        <view class="profile-qr-uid-line">
          <text>{{ tt('profile_qr_uid_prefix', '会员ID：') }}</text>
          <text class="strong">{{ memberId || '-' }}</text>
        </view>
        <text class="profile-qr-tip">{{ tip }}</text>
        <button class="btn-uid-submit" @click="copyId">{{ tt('profile_qr_copy_btn', '复制会员ID') }}</button>
      </view>
  </ProfileSubPage>
</template>

<script setup>
import { safeNavigateBack, HOME_TAB } from '../../utils/nav.js'
import { ref } from 'vue'
import ProfileSubPage from '../../components/ProfileSubPage.vue'
import { onShow } from '@dcloudio/uni-app'
import { fetchProfile, getToken } from '../../utils/auth.js'
import { assetBase, localeState, tt } from '../../utils/i18n.js'

const locale = localeState()
const memberId = ref('')
const tip = ref('')
const qrDataUrl = ref('')
const size = 220

function goBack() {
  safeNavigateBack(HOME_TAB)
}

function normalizeId(raw) {
  const id = String(raw || '').replace(/\D+/g, '')
  return /^\d{8}$/.test(id) ? id : ''
}

function payload() {
  return 'FANSHUB_FRIEND:' + String(memberId.value || '').replace(/\D+/g, '')
}

function loadQrLib() {
  return new Promise((resolve, reject) => {
    // #ifdef H5
    if (typeof window !== 'undefined' && window.QRCode) {
      resolve(window.QRCode)
      return
    }
    const src = assetBase() + 'static/vendor/qrcode.min.js'
    const exist = typeof document !== 'undefined'
      ? document.querySelector('script[data-qrcode-lib]')
      : null
    if (exist) {
      exist.addEventListener('load', () => resolve(window.QRCode))
      exist.addEventListener('error', () => reject(new Error('QR库加载失败')))
      return
    }
    const s = document.createElement('script')
    s.src = src
    s.async = true
    s.setAttribute('data-qrcode-lib', '1')
    s.onload = () => resolve(window.QRCode)
    s.onerror = () => reject(new Error('QR库加载失败'))
    document.head.appendChild(s)
    // #endif
    // #ifndef H5
    reject(new Error('H5 only'))
    // #endif
  })
}

async function renderQr() {
  const id = normalizeId(memberId.value)
  if (!id) {
    tip.value = '登录后可生成个人二维码'
    qrDataUrl.value = ''
    return
  }
  tip.value = tt('profile_qr_tip', '好友扫一扫即可添加你')
  try {
    // #ifdef H5
    const QRCode = await loadQrLib()
    if (QRCode && typeof QRCode.toDataURL === 'function') {
      qrDataUrl.value = await QRCode.toDataURL(payload(), {
        width: size,
        margin: 2,
        color: { dark: '#1a1a1a', light: '#ffffff' },
      })
      return
    }
    // #endif
    throw new Error('QRCode.toDataURL missing')
  } catch (e) {
    qrDataUrl.value =
      'https://api.qrserver.com/v1/create-qr-code/?size=' +
      size +
      'x' +
      size +
      '&data=' +
      encodeURIComponent(payload())
  }
}

function copyId() {
  const text = String(memberId.value || '').trim()
  if (!text) {
    uni.showToast({ title: tt('profile_uid_copy_empty', '暂无会员ID'), icon: 'none' })
    return
  }
  uni.setClipboardData({
    data: text,
    success: () => uni.showToast({ title: tt('profile_uid_copied', '已复制'), icon: 'none' }),
  })
}

async function load() {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  try {
    const p = await fetchProfile()
    memberId.value = normalizeId(p.user_id || p.id || '') || String(p.user_id || p.id || '')
  } catch (e) {
    memberId.value = ''
  }
  await renderQr()
}

onShow(load)
</script>

<style scoped>
.profile-qr-card {
  text-align: center;
}
.profile-qr-box {
  position: relative;
  width: 220px;
  height: 220px;
  margin: 8px auto 14px;
  background: #fff;
  border-radius: 16px;
  overflow: hidden;
  box-shadow: 0 8px 20px rgba(176, 120, 50, 0.12);
  display: flex;
  align-items: center;
  justify-content: center;
}
.profile-qr-img {
  width: 220px;
  height: 220px;
  display: block;
}
.profile-qr-placeholder {
  font-size: 13px;
  color: #8a7a6e;
  padding: 12px;
}
.profile-qr-uid-line {
  font-size: 14px;
  color: #8a7a6e;
}
.profile-qr-uid-line .strong {
  font-weight: 800;
  color: #3d2e22;
  margin-left: 4px;
}
.profile-qr-tip {
  display: block;
  margin: 10px 0 4px;
  font-size: 13px;
  color: #8a7a6e;
}
</style>

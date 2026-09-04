<template>
  <view class="chat-share-poster-page">
    <TopBar title="分享推广赚佣金" />
    <view class="chat-hero-hd chat-hero-hd--bar-actions">
      <view class="chat-hero-back" @click="goBack">‹</view>
      <view class="chat-hero-spacer" />
    </view>
    <view class="chat-share-poster-main">
      <view class="chat-share-poster-card" id="chatSharePosterCard">
        <view class="chat-share-poster-brand">红宝</view>
        <view class="chat-share-poster-title">邀请好友 · 共享收益</view>
        <view class="chat-share-poster-uid">
          我的邀请码 <text class="strong">{{ inviteCode || '—' }}</text>
        </view>
        <view class="chat-share-poster-qr-wrap">
          <image v-if="qrDataUrl" class="chat-share-poster-qr-img" :src="qrDataUrl" mode="aspectFit" />
          <text v-else>{{ loading ? '加载中…' : '—' }}</text>
        </view>
        <view class="chat-share-poster-hint">扫码或打开链接，自动带上你的邀请码</view>
        <view class="chat-share-poster-link">{{ shareLink || '—' }}</view>
      </view>
      <button type="button" class="chat-share-poster-btn primary" @click="copyLink">
        📋 一键复制分享链接
      </button>
      <button type="button" class="chat-share-poster-btn" @click="savePoster">
        💾 保存海报到相册
      </button>
    </view>
  </view>
</template>

<script setup>
import { safeNavigateBack, HOME_TAB } from '../../utils/nav.js'
import { ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import { apiRequest, getToken } from '../../utils/auth.js'
import { assetBase, localeState, t } from '../../utils/i18n.js'
import '../../styles/share-poster.css'

const locale = localeState()
const inviteCode = ref('')
const shareLink = ref('')
const qrDataUrl = ref('')
const loading = ref(false)

function goBack() {
  safeNavigateBack(HOME_TAB)
}

function buildInviteDownloadLink(link, code) {
  const L = String(link || '').trim()
  if (L) return L
  const c = String(code || '').trim()
  let origin = ''
  try {
    origin = typeof location !== 'undefined' ? location.origin : ''
  } catch (e) {
    origin = ''
  }
  if (c && origin) {
    return origin + '/999/?code=' + encodeURIComponent(c)
  }
  return origin ? origin + '/999/' : ''
}

function loadQrLib() {
  return new Promise((resolve, reject) => {
    // #ifdef H5
    if (typeof window !== 'undefined' && window.QRCode) {
      resolve(window.QRCode)
      return
    }
    const src = assetBase() + 'static/vendor/qrcode.min.js'
    const exist = typeof document !== 'undefined' ? document.querySelector('script[data-qrcode-lib]') : null
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

async function renderQr(link) {
  if (!link) {
    qrDataUrl.value = ''
    return
  }
  try {
    // #ifdef H5
    const QRCode = await loadQrLib()
    if (QRCode && typeof QRCode.toDataURL === 'function') {
      qrDataUrl.value = await QRCode.toDataURL(link, {
        width: 200,
        margin: 1,
        color: { dark: '#1a1a1a', light: '#ffffff' },
      })
      return
    }
    // #endif
    throw new Error('QRCode missing')
  } catch (e) {
    qrDataUrl.value =
      'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(link)
  }
}

async function load() {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  loading.value = true
  inviteCode.value = ''
  shareLink.value = '加载中…'
  qrDataUrl.value = ''
  try {
    const data = (await apiRequest('commission', 'GET', {})) || {}
    const code = String(data.invite_code || '')
    const link = buildInviteDownloadLink(data.share_link, code)
    inviteCode.value = code
    shareLink.value = link || '—'
    await renderQr(link)
  } catch (e) {
    shareLink.value = '—'
    uni.showToast({ title: (e && e.message) || '加载分享信息失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

function copyLink() {
  const text = String(shareLink.value || '').trim()
  if (!text || text === '—' || text === '加载中…') {
    uni.showToast({ title: '分享链接未就绪', icon: 'none' })
    return
  }
  uni.setClipboardData({
    data: text,
    success: () => uni.showToast({ title: '分享链接已复制', icon: 'none' }),
    fail: () => uni.showToast({ title: '复制失败', icon: 'none' }),
  })
}

function savePoster() {
  // #ifdef H5
  try {
    if (!qrDataUrl.value) {
      uni.showToast({ title: '海报未就绪', icon: 'none' })
      return
    }
    const w = 720
    const h = 1080
    const out = document.createElement('canvas')
    out.width = w
    out.height = h
    const ctx = out.getContext('2d')
    const grd = ctx.createLinearGradient(0, 0, 0, h)
    grd.addColorStop(0, '#fff7f0')
    grd.addColorStop(0.45, '#ffe8d0')
    grd.addColorStop(1, '#f5d5b0')
    ctx.fillStyle = grd
    ctx.fillRect(0, 0, w, h)

    ctx.fillStyle = '#C61114'
    ctx.font = 'bold 54px "PingFang SC","Microsoft YaHei",sans-serif'
    ctx.textAlign = 'center'
    ctx.fillText('红宝', w / 2, 120)

    ctx.fillStyle = '#1f1714'
    ctx.font = 'bold 36px "PingFang SC","Microsoft YaHei",sans-serif'
    ctx.fillText('邀请好友 · 共享收益', w / 2, 190)

    ctx.fillStyle = '#8a7a6e'
    ctx.font = '24px "PingFang SC","Microsoft YaHei",sans-serif'
    ctx.fillText('我的邀请码 ' + (inviteCode.value || ''), w / 2, 250)

    const img = new Image()
    img.crossOrigin = 'anonymous'
    img.onload = () => {
      const qrSize = 360
      const qx = (w - qrSize) / 2
      const qy = 300
      ctx.fillStyle = '#fff'
      roundRect(ctx, qx - 20, qy - 20, qrSize + 40, qrSize + 40, 24)
      ctx.fill()
      ctx.drawImage(img, qx, qy, qrSize, qrSize)

      ctx.fillStyle = '#8a7a6e'
      ctx.font = '22px "PingFang SC","Microsoft YaHei",sans-serif'
      ctx.fillText('扫码加入，自动绑定邀请码', w / 2, qy + qrSize + 70)

      ctx.fillStyle = '#3d2e22'
      ctx.font = '18px ui-monospace,Consolas,monospace'
      wrapText(ctx, shareLink.value || '', w / 2, qy + qrSize + 120, w - 80, 26)

      out.toBlob((blob) => {
        if (!blob) {
          uni.showToast({ title: '生成海报失败', icon: 'none' })
          return
        }
        const url = URL.createObjectURL(blob)
        const a = document.createElement('a')
        a.href = url
        a.download = 'hongbao-share-' + (inviteCode.value || 'poster') + '.png'
        document.body.appendChild(a)
        a.click()
        document.body.removeChild(a)
        setTimeout(() => URL.revokeObjectURL(url), 2000)
        uni.showToast({ title: '海报已保存，可分享到相册/会话', icon: 'none' })
      }, 'image/png')
    }
    img.onerror = () => uni.showToast({ title: '二维码加载失败', icon: 'none' })
    img.src = qrDataUrl.value
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '保存失败', icon: 'none' })
  }
  // #endif
  // #ifndef H5
  uni.showToast({ title: t('share_save_h5_only') || '请在浏览器中保存海报，或先复制链接', icon: 'none' })
  // #endif
}

function roundRect(ctx, x, y, w, h, r) {
  ctx.beginPath()
  ctx.moveTo(x + r, y)
  ctx.arcTo(x + w, y, x + w, y + h, r)
  ctx.arcTo(x + w, y + h, x, y + h, r)
  ctx.arcTo(x, y + h, x, y, r)
  ctx.arcTo(x, y, x + w, y, r)
  ctx.closePath()
}

function wrapText(ctx, text, x, y, maxWidth, lineHeight) {
  text = String(text || '')
  if (!text) return
  let line = ''
  for (let i = 0; i < text.length; i++) {
    const test = line + text.charAt(i)
    if (ctx.measureText(test).width > maxWidth && line) {
      ctx.fillText(line, x, y)
      line = text.charAt(i)
      y += lineHeight
    } else {
      line = test
    }
  }
  if (line) ctx.fillText(line, x, y)
}

onShow(() => {
  load()
})
</script>

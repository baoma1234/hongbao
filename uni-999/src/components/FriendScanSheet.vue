<template>
  <view v-if="open" class="fs-sheet" aria-hidden="false">
    <view class="fs-sheet-mask" @click="close" />
    <view class="fs-sheet-panel" @click.stop>
      <view class="fs-sheet-title">扫一扫加好友</view>
      <button type="button" class="fs-sheet-item" @click="onPick(0)">扫一扫</button>
      <button type="button" class="fs-sheet-item" @click="onPick(1)">从相册识别</button>
      <button type="button" class="fs-sheet-item" @click="onPick(2)">手动输入会员ID</button>
      <button type="button" class="fs-sheet-item fs-cancel" @click="close">取消</button>
    </view>
  </view>

  <!-- H5 摄像头扫码层 -->
  <view v-if="cameraOpen" class="fs-cam" aria-hidden="false">
    <view class="fs-cam-hd">
      <text class="fs-cam-back" @click="closeCamera">‹</text>
      <text class="fs-cam-title">扫一扫</text>
      <text class="fs-cam-spacer" />
    </view>
    <view class="fs-cam-body">
      <!-- #ifdef H5 -->
      <view id="fsCamHost" class="fs-cam-video-host" />
      <!-- #endif -->
      <view class="fs-cam-frame" />
      <text class="fs-cam-tip">{{ camTip }}</text>
      <button type="button" class="fs-cam-album" @click="pickAlbumFromCam">从相册识别</button>
    </view>
  </view>
</template>

<script setup>
import { nextTick, onMounted, onUnmounted, ref } from 'vue'
import {
  addFriendByMemberId,
  decodeQrFromAlbum,
  parseFriendPayload,
  registerFriendScanOpener,
} from '../utils/friend-scan.js'
import { startLiveQrScan } from '../utils/live-qr-scan.js'

const open = ref(false)
const cameraOpen = ref(false)
const camTip = ref('将二维码放入框内即可自动识别')
let pending = null
let liveCtl = null

function close() {
  open.value = false
  pending = null
}

function stopLive() {
  if (liveCtl && typeof liveCtl.stop === 'function') {
    try {
      liveCtl.stop()
    } catch (e) {}
  }
  liveCtl = null
}

function openSheet(opts) {
  pending = opts || {}
  open.value = true
}

async function handleMemberId(raw) {
  const opts = pending || {}
  const id = parseFriendPayload(raw)
  if (!id) {
    uni.showToast({ title: '无效的会员二维码', icon: 'none' })
    return
  }
  await addFriendByMemberId(id, opts.selfUserId)
}

async function openLiveCamera() {
  // #ifdef H5
  cameraOpen.value = true
  camTip.value = '正在打开摄像头…'
  await nextTick()
  try {
    const host = typeof document !== 'undefined' ? document.getElementById('fsCamHost') : null
    if (!host) throw new Error('扫码组件缺失')
    host.innerHTML = ''
    const video = document.createElement('video')
    video.className = 'fs-cam-video-el'
    video.setAttribute('playsinline', 'true')
    video.setAttribute('webkit-playsinline', 'true')
    video.muted = true
    video.autoplay = true
    host.appendChild(video)
    liveCtl = await startLiveQrScan({
      video,
      onCode: async (raw) => {
        closeCamera()
        try {
          await handleMemberId(raw)
        } catch (e) {
          uni.showToast({ title: (e && e.message) || '添加失败', icon: 'none' })
        }
      },
    })
    camTip.value = '将二维码放入框内即可自动识别'
  } catch (e) {
    camTip.value = (e && e.message) || '无法打开摄像头'
    uni.showToast({ title: camTip.value, icon: 'none' })
  }
  return
  // #endif
  // #ifndef H5
  try {
    const r = await new Promise((resolve, reject) => {
      uni.scanCode({ onlyFromCamera: true, success: resolve, fail: reject })
    })
    await handleMemberId(r && r.result)
  } catch (e) {
    uni.showToast({ title: (e && e.errMsg) || '扫码取消', icon: 'none' })
  }
  // #endif
}

function closeCamera() {
  stopLive()
  // #ifdef H5
  try {
    const host = typeof document !== 'undefined' ? document.getElementById('fsCamHost') : null
    if (host) host.innerHTML = ''
  } catch (e) {}
  // #endif
  cameraOpen.value = false
  camTip.value = '将二维码放入框内即可自动识别'
}

async function pickAlbumFromCam() {
  closeCamera()
  try {
    const id = await decodeQrFromAlbum()
    if (id) await addFriendByMemberId(id, (pending || {}).selfUserId)
    else uni.showToast({ title: '无效的会员二维码', icon: 'none' })
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '识别失败', icon: 'none' })
  }
}

async function onPick(idx) {
  const opts = pending || {}
  const onManual =
    opts.onManual ||
    (() => {
      uni.navigateTo({ url: '/pages/friend/add' })
    })
  close()
  if (idx === 2) {
    onManual()
    return
  }
  if (idx === 0) {
    await openLiveCamera()
    return
  }
  try {
    const id = await decodeQrFromAlbum()
    if (id) await addFriendByMemberId(id, opts.selfUserId)
    else uni.showToast({ title: '无效的会员二维码', icon: 'none' })
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '识别失败', icon: 'none' })
  }
}

onMounted(() => registerFriendScanOpener(openSheet))
onUnmounted(() => {
  registerFriendScanOpener(null)
  stopLive()
})
</script>

<style scoped>
.fs-sheet {
  position: fixed;
  inset: 0;
  z-index: 12000;
}
.fs-sheet-mask {
  position: absolute;
  inset: 0;
  background: rgba(40, 20, 10, 0.45);
}
.fs-sheet-panel {
  position: absolute;
  left: 10px;
  right: 10px;
  bottom: calc(88px + env(safe-area-inset-bottom, 0px));
  background: #fff;
  border-radius: 16px;
  overflow: hidden;
  box-shadow: 0 8px 28px rgba(0, 0, 0, 0.18);
  padding-bottom: 10px;
}
.fs-sheet-title {
  padding: 18px 16px 12px;
  text-align: center;
  font-size: 14px;
  color: #8a7a6e;
}
.fs-sheet-item {
  width: 100%;
  min-height: 56px;
  border: none;
  border-top: 1px solid #f0e6da;
  background: #fff;
  font-size: 16px;
  font-weight: 700;
  color: #1f1714;
  margin: 0;
  padding: 16px 12px;
  line-height: 1.35;
  box-sizing: border-box;
}
.fs-sheet-item::after {
  border: none;
  display: none;
}
.fs-sheet-item.fs-cancel {
  margin: 8px 10px 0;
  width: calc(100% - 20px);
  border-radius: 14px;
  border-top: none;
  background: #f6f1ea;
  color: #657786;
}

.fs-cam {
  position: fixed;
  inset: 0;
  z-index: 13000;
  background: #0b0b0b;
  display: flex;
  flex-direction: column;
}
.fs-cam-hd {
  flex: 0 0 auto;
  display: flex;
  align-items: center;
  padding: calc(10px + env(safe-area-inset-top, 0px)) 12px 10px;
  color: #fff;
}
.fs-cam-back,
.fs-cam-spacer {
  width: 40px;
  height: 40px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 28px;
}
.fs-cam-title {
  flex: 1;
  text-align: center;
  font-size: 17px;
  font-weight: 700;
}
.fs-cam-body {
  flex: 1;
  position: relative;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
}
.fs-cam-video-host {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  overflow: hidden;
  background: #000;
  z-index: 1;
}
.fs-cam-video {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  background: #000;
}
.fs-cam-frame {
  position: relative;
  z-index: 2;
  width: 220px;
  height: 220px;
  border: 2px solid rgba(255, 255, 255, 0.85);
  border-radius: 16px;
  box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.35);
}
.fs-cam-tip {
  position: relative;
  z-index: 2;
  margin-top: 18px;
  color: #fff;
  font-size: 13px;
  text-align: center;
  padding: 0 20px;
}
.fs-cam-album {
  position: relative;
  z-index: 2;
  margin-top: 18px;
  min-width: 160px;
  height: 44px;
  line-height: 44px;
  border: none;
  border-radius: 22px;
  background: rgba(255, 255, 255, 0.92);
  color: #1f1714;
  font-size: 15px;
  font-weight: 700;
}
.fs-cam-album::after {
  border: none;
  display: none;
}
</style>

<style>
/* 注入的原生 video 不受 scoped 影响 */
.fs-cam-video-el {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  background: #000;
}
</style>

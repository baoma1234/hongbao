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
</template>

<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import {
  addFriendByMemberId,
  decodeQrFromAlbum,
  parseFriendPayload,
  registerFriendScanOpener,
} from '../utils/friend-scan.js'

const open = ref(false)
let pending = null

function close() {
  open.value = false
  pending = null
}

function openSheet(opts) {
  pending = opts || {}
  open.value = true
}

async function onPick(idx) {
  const opts = pending || {}
  const selfUserId = opts.selfUserId
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
    try {
      const r = await new Promise((resolve, reject) => {
        uni.scanCode({ onlyFromCamera: true, success: resolve, fail: reject })
      })
      const id = parseFriendPayload(r && r.result)
      if (id) await addFriendByMemberId(id, selfUserId)
      else uni.showToast({ title: '无效的会员二维码', icon: 'none' })
    } catch (e) {
      uni.showToast({ title: (e && e.errMsg) || '扫码取消', icon: 'none' })
    }
    return
  }
  try {
    const id = await decodeQrFromAlbum()
    if (id) await addFriendByMemberId(id, selfUserId)
    else uni.showToast({ title: '无效的会员二维码', icon: 'none' })
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '识别失败', icon: 'none' })
  }
}

onMounted(() => registerFriendScanOpener(openSheet))
onUnmounted(() => registerFriendScanOpener(null))
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
  /* 抬高过底部自定义 tabbar，避免取消被盖住 */
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
</style>

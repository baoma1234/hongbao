<template>
  <view class="hb-page webview-page">
    <TopBar :no-spacer="true" />
    <view class="profile-sub-hd">
      <text class="profile-back-btn" @click="goBack">‹</text>
      <text class="profile-sub-title">{{ title }}</text>
      <text class="profile-sub-spacer" />
    </view>
    <!-- #ifdef H5 -->
    <view v-if="iframeSrc" class="webview-frame-wrap">
      <iframe class="webview-frame" :src="iframeSrc" title="webview" />
    </view>
    <view v-else class="webview-empty">无法打开页面</view>
    <!-- #endif -->
    <!-- #ifndef H5 -->
    <web-view v-if="iframeSrc" :src="iframeSrc" />
    <view v-else class="webview-empty">无法打开页面</view>
    <!-- #endif -->
  </view>
</template>

<script setup>
import { safeNavigateBack, HOME_TAB } from '../../utils/nav.js'
import { ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import { getApiBase } from '../../utils/config.js'
import '../../styles/hb.css'

const title = ref('详情')
const iframeSrc = ref('')

function goBack() {
  safeNavigateBack(HOME_TAB)
}

function resolveUrl(raw) {
  let u = String(raw || '').trim()
  if (!u) return ''
  if (/^https?:\/\//i.test(u)) return u
  const base = String(getApiBase() || '').replace(/\/$/, '')
  if (u.charAt(0) === '/') return (base || '') + u
  return (base || '') + '/' + u
}

onLoad((q) => {
  const raw = decodeURIComponent(String((q && q.url) || ''))
  const t = decodeURIComponent(String((q && q.title) || ''))
  if (t) title.value = t
  iframeSrc.value = resolveUrl(raw)
})
</script>

<style scoped>
.webview-page {
  display: flex;
  flex-direction: column;
  height: 100vh;
  height: 100dvh;
  background: #f5f5f5;
}
.webview-frame-wrap {
  flex: 1;
  min-height: 0;
  width: 100%;
}
.webview-frame {
  width: 100%;
  height: 100%;
  border: 0;
  display: block;
  background: #fff;
}
.webview-empty {
  padding: 40px 16px;
  text-align: center;
  color: #999;
}
</style>

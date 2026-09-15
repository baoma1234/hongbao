<template>
  <ProfileSubPage title="我的帖子" back-fallback="/pages/profile/profile">
    <view class="my-posts">
      <view v-if="loading && !list.length" class="my-posts-empty">加载中…</view>
      <view
        v-for="n in list"
        :key="n.id"
        class="my-post-card"
        @click="openDetail(n)"
      >
        <view class="my-post-hd">
          <text class="my-post-tag" v-if="tagOf(n)">【{{ tagOf(n) }}】</text>
          <text class="my-post-status" :class="n.status">{{ statusText(n.status) }}</text>
          <text class="my-post-views">{{ (n.views_count | 0) }} 浏览</text>
        </view>
        <text class="my-post-body">{{ n.content || '' }}</text>
        <view v-if="imgsOf(n).length" class="my-post-thumbs">
          <image
            v-for="(src, i) in imgsOf(n).slice(0, 3)"
            :key="i"
            class="my-post-thumb"
            :src="avatarSrc(src)"
            mode="aspectFill"
          />
        </view>
        <text class="my-post-time">{{ formatTime(n) }}</text>
      </view>
      <view v-if="!loading && !list.length" class="my-posts-empty">暂无发帖</view>
      <view v-if="hasMore" class="my-posts-more" @click="loadMore">加载更多</view>
    </view>
  </ProfileSubPage>
</template>

<script setup>
import { ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import ProfileSubPage from '../../components/ProfileSubPage.vue'
import { apiRequest, getToken } from '../../utils/auth.js'
import { avatarSrc } from '../../utils/chat.js'
import '../../styles/hb.css'

const list = ref([])
const page = ref(1)
const hasMore = ref(false)
const loading = ref(false)

function tagOf(n) {
  return String((n && (n.tag_label || n.theme_title || n.category_label)) || '').trim()
}

function imgsOf(n) {
  const imgs = n && n.images
  return Array.isArray(imgs) ? imgs.filter(Boolean) : []
}

function statusText(s) {
  if (s === 'published') return '已展示'
  if (s === 'pending') return '待审核'
  if (s === 'rejected') return '已拒绝'
  if (s === 'paused') return '已暂停'
  if (s === 'draft') return '草稿'
  return s || ''
}

function formatTime(n) {
  let ts = (n && (n.publishtime || n.createtime)) | 0
  if (!ts) return ''
  if (ts > 1e12) ts = Math.floor(ts / 1000)
  const d = new Date(ts * 1000)
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  const hh = String(d.getHours()).padStart(2, '0')
  const mm = String(d.getMinutes()).padStart(2, '0')
  return d.getFullYear() + '-' + m + '-' + day + ' ' + hh + ':' + mm
}

function openDetail(n) {
  const id = (n && n.id) | 0
  if (!id) return
  uni.navigateTo({ url: '/pages/notice/detail?id=' + id })
}

async function fetchPage(p, append) {
  loading.value = true
  try {
    const data = await apiRequest('noticemylist', 'GET', { page: p, limit: 20 })
    const rows = (data && data.list) || []
    if (append) list.value = list.value.concat(rows)
    else list.value = rows
    page.value = p
    hasMore.value = !!(data && data.has_more)
  } catch (e) {
    if (!append) list.value = []
    uni.showToast({ title: (e && e.message) || '加载失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

function loadMore() {
  if (loading.value || !hasMore.value) return
  fetchPage(page.value + 1, true)
}

onShow(() => {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  fetchPage(1, false)
})
</script>

<style scoped>
.my-posts {
  padding: 8px 12px 28px;
  box-sizing: border-box;
}
.my-post-card {
  background: #fff;
  border-radius: 10px;
  padding: 12px 14px;
  margin-bottom: 10px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
}
.my-post-hd {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 6px;
}
.my-post-tag {
  font-size: 12px;
  color: #12b7f5;
}
.my-post-status {
  font-size: 11px;
  padding: 1px 6px;
  border-radius: 4px;
  background: #f0f0f0;
  color: #666;
}
.my-post-status.pending {
  background: #fff6e5;
  color: #c47a00;
}
.my-post-status.published {
  background: #e8f8ef;
  color: #1e9e57;
}
.my-post-status.rejected {
  background: #fdecea;
  color: #c0392b;
}
.my-post-views {
  margin-left: auto;
  font-size: 11px;
  color: #999;
}
.my-post-body {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 3;
  overflow: hidden;
  font-size: 14px;
  line-height: 1.55;
  color: #222;
  white-space: pre-wrap;
  word-break: break-word;
}
.my-post-thumbs {
  display: flex;
  gap: 6px;
  margin-top: 8px;
}
.my-post-thumb {
  width: 64px;
  height: 64px;
  border-radius: 6px;
  background: #eee;
}
.my-post-time {
  display: block;
  margin-top: 8px;
  font-size: 12px;
  color: #999;
}
.my-posts-empty {
  padding: 48px 16px;
  text-align: center;
  color: #999;
  font-size: 14px;
}
.my-posts-more {
  text-align: center;
  padding: 12px;
  color: #2ecc71;
  font-size: 14px;
}
</style>

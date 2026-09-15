<template>
  <ProfileSubPage :title="pageTitle" back-fallback="/pages/notice/notice">
    <view v-if="loading" class="notice-detail-empty">加载中…</view>
    <view v-else-if="!notice" class="notice-detail-empty">帖子不存在或未通过审核</view>
    <view v-else class="notice-detail">
      <view class="chat-notice-hd">
        <image
          class="chat-notice-avatar"
          :src="avatarSrc(notice.author_avatar || '')"
          mode="aspectFill"
        />
        <view class="chat-notice-meta">
          <text class="chat-notice-views">{{ viewsText }} 浏览</text>
          <view class="chat-notice-name-row">
            <text class="chat-notice-name">{{ notice.author_name || '红宝官方公告' }}</text>
            <text class="chat-notice-day">{{ relativeDay }}</text>
            <text v-if="tagLabel" class="chat-notice-tag">【{{ tagLabel }}】</text>
            <text v-if="notice.status === 'pending'" class="chat-notice-status pending">待审核</text>
            <text v-else-if="notice.status === 'rejected'" class="chat-notice-status rejected">已拒绝</text>
          </view>
        </view>
        <view class="chat-notice-time">{{ clock }}</view>
      </view>
      <view class="chat-notice-body">{{ notice.content || '' }}</view>
      <view v-if="video" class="chat-notice-media">
        <video class="chat-notice-video" :src="video" controls object-fit="contain" />
      </view>
      <view v-if="images.length" class="chat-notice-media">
        <view
          class="chat-notice-imgs"
          :class="['imgs-' + Math.min(9, images.length), { 'imgs-full': images.length === 1 }]"
        >
          <view
            v-for="(src, ii) in images.slice(0, 9)"
            :key="ii"
            class="chat-notice-img-wrap"
            @click="preview(ii)"
          >
            <image
              class="chat-notice-img"
              :src="avatarSrc(src)"
              :mode="images.length === 1 ? 'widthFix' : 'aspectFill'"
            />
          </view>
        </view>
      </view>
    </view>
  </ProfileSubPage>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import ProfileSubPage from '../../components/ProfileSubPage.vue'
import { apiRequest } from '../../utils/auth.js'
import { avatarSrc } from '../../utils/chat.js'
import '../../styles/hb.css'
import '../../styles/chat-messages-list.css'

const noticeId = ref(0)
const notice = ref(null)
const loading = ref(true)
const viewsLocal = ref(0)

const pageTitle = computed(() => (notice.value && notice.value.author_name) || '帖子详情')
const tagLabel = computed(() => {
  const n = notice.value
  if (!n) return ''
  return String(n.tag_label || n.theme_title || n.category_label || '').trim()
})
const images = computed(() => {
  const imgs = notice.value && notice.value.images
  return Array.isArray(imgs) ? imgs.filter(Boolean) : []
})
const video = computed(() => String((notice.value && notice.value.video) || '').trim())
const viewsText = computed(() => {
  const v = viewsLocal.value || Number((notice.value && notice.value.views_count) || 0) || 0
  if (v >= 10000) return (Math.floor(v / 1000) / 10) + '万'
  return String(v)
})

function noticeTs() {
  const n = notice.value
  let ts = (n && (n.publishtime || n.createtime || n.updatetime)) | 0
  if (ts > 1e12) ts = Math.floor(ts / 1000)
  return ts
}

const relativeDay = computed(() => {
  let ts = noticeTs()
  if (!ts) return ''
  const d = new Date(ts * 1000)
  const now = new Date()
  const startToday = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime()
  const startThat = new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()
  const diff = Math.round((startToday - startThat) / 86400000)
  if (diff === 0) return '今天'
  if (diff === 1) return '昨天'
  if (diff > 1 && diff < 7) return diff + '天前'
  return (d.getMonth() + 1) + '月' + d.getDate() + '日'
})

const clock = computed(() => {
  let ts = noticeTs()
  if (!ts) return ''
  const d = new Date(ts * 1000)
  return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0')
})

function preview(index) {
  const urls = images.value.map((u) => avatarSrc(u)).filter(Boolean)
  if (!urls.length) return
  uni.previewImage({ urls, current: urls[index | 0] || urls[0] })
}

async function bumpViews(id) {
  try {
    const data = await apiRequest('noticeview', 'POST', { id })
    if (data && data.views_count != null) {
      viewsLocal.value = data.views_count | 0
      if (notice.value) notice.value.views_count = viewsLocal.value
    }
  } catch (e) {}
}

async function loadDetail(id) {
  loading.value = true
  try {
    const data = await apiRequest('noticedetail', 'GET', { id })
    notice.value = data || null
    viewsLocal.value = (data && data.views_count) | 0
    if (data && String(data.status) === 'published') {
      bumpViews(id)
    }
  } catch (e) {
    notice.value = null
    uni.showToast({ title: (e && e.message) || '加载失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

onLoad((q) => {
  const id = (q && q.id) | 0
  noticeId.value = id
  if (!id) {
    loading.value = false
    notice.value = null
    return
  }
  loadDetail(id)
})
</script>

<style scoped>
.notice-detail {
  padding: 14px 16px 28px;
  background: #fff;
  min-height: 40vh;
  box-sizing: border-box;
}
.notice-detail-empty {
  padding: 48px 16px;
  text-align: center;
  color: #999;
  font-size: 14px;
}
.chat-notice-status {
  font-size: 11px;
  padding: 1px 6px;
  border-radius: 4px;
}
.chat-notice-status.pending {
  color: #c47a00;
  background: #fff6e5;
}
.chat-notice-status.rejected {
  color: #c0392b;
  background: #fdecea;
}
.chat-notice-views {
  display: block;
  font-size: 11px;
  color: #9a9a9a;
  margin-bottom: 2px;
}
</style>

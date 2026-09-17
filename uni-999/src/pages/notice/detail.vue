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
          <view class="chat-notice-name-row">
            <text class="chat-notice-name">{{ notice.author_name || '红宝官方公告' }}</text>
            <text class="chat-notice-day">{{ relativeDay }}</text>
            <text v-if="tagLabel" class="chat-notice-tag">【{{ tagLabel }}】</text>
            <text class="chat-notice-views">{{ viewsText }} 浏览</text>
            <text v-if="notice.status === 'pending'" class="chat-notice-status pending">待审核</text>
            <text v-else-if="notice.status === 'rejected'" class="chat-notice-status rejected">已拒绝</text>
          </view>
        </view>
        <view class="chat-notice-time">{{ clock }}</view>
      </view>
      <LinkifiedText class="chat-notice-body" :text="notice.content || ''" />
      <view v-if="video" class="chat-notice-media">
        <video
          class="chat-notice-video"
          :src="video"
          :poster="videoCover || undefined"
          controls
          object-fit="contain"
        />
      </view>
      <view
        v-if="images.length === 1"
        class="chat-notice-one"
        :class="{ compact: !imagesFull }"
        @click="preview(0)"
      >
        <image
          class="chat-notice-one-img"
          :src="avatarSrc(images[0])"
          mode="widthFix"
          :style="{ width: '100%' }"
        />
      </view>
      <view v-else-if="images.length > 1" class="chat-notice-media">
        <view
          class="chat-notice-imgs"
          :class="[
            'imgs-' + Math.min(9, images.length),
            { 'imgs-full': imagesFull },
          ]"
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
              :mode="imagesFull ? 'widthFix' : 'aspectFill'"
              :style="imagesFull ? imageStyle : null"
            />
          </view>
        </view>
      </view>
      <view v-if="canManage" class="chat-notice-ft" style="margin-top: 16px">
        <view class="chat-notice-del-btn" @click="softDelete">删除</view>
      </view>
    </view>
  </ProfileSubPage>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import ProfileSubPage from '../../components/ProfileSubPage.vue'
import LinkifiedText from '../../components/LinkifiedText.vue'
import { apiRequest, fetchConfig, fetchProfile } from '../../utils/auth.js'
import { avatarSrc, captureVideoFirstFrame, publicUrl } from '../../utils/chat.js'
import '../../styles/hb.css'
import '../../styles/chat-messages-list.css'
import '../../styles/chat-uni-adapter.css'

const noticeId = ref(0)
const notice = ref(null)
const loading = ref(true)
const viewsLocal = ref(0)
const myUserId = ref(0)
const noticeManagerIds = ref([88888888, 55555555, 44444444, 77777777, 22222222, 58904307])
const autoVideoCover = ref('')
let noticeDeleting = false

const pageTitle = computed(() => (notice.value && notice.value.author_name) || '帖子详情')
const tagLabel = computed(() => {
  const n = notice.value
  if (!n) return ''
  return String(n.tag_label || n.theme_title || n.category_label || '').trim()
})
const images = computed(() => {
  let imgs = notice.value && notice.value.images
  if (typeof imgs === 'string' && imgs) {
    try {
      const parsed = JSON.parse(imgs)
      imgs = Array.isArray(parsed) ? parsed : imgs.split(/[\r\n,]+/)
    } catch (e) {
      imgs = imgs.split(/[\r\n,]+/)
    }
  }
  if (!Array.isArray(imgs)) return []
  // 本站上传会把图存成 /uploads/*.js，按图片展示
  return imgs.map((u) => String(u || '').trim()).filter(Boolean)
})
const imagesFull = computed(() => {
  const c = String((notice.value && notice.value.category) || '')
  return c === 'latest' || c === 'promote'
})
const imageStyle = computed(() => ({ width: '100%' }))
const video = computed(() => String((notice.value && notice.value.video) || '').trim())
const videoCover = computed(() => {
  const c = String((notice.value && notice.value.video_cover) || '').trim()
  if (c) return avatarSrc(c)
  const auto = String(autoVideoCover.value || '').trim()
  return auto ? avatarSrc(auto) : ''
})

async function fillAutoVideoCover(src) {
  autoVideoCover.value = ''
  const url = publicUrl(src) || String(src || '').trim()
  if (!url) return
  try {
    const snap = await captureVideoFirstFrame(url)
    if (snap) autoVideoCover.value = snap
  } catch (e) {}
}
const canManage = computed(() => {
  const uid = myUserId.value | 0
  if (!uid || !notice.value) return false
  if (noticeManagerIds.value.indexOf(uid) < 0) return false
  const c = String(notice.value.category || '')
  return c === 'ads' || c === 'rules'
})
const viewsText = computed(() => {
  const v = viewsLocal.value || Number((notice.value && notice.value.views_count) || 0) || 0
  if (v >= 10000) {
    const w = Math.round(v / 1000) / 10
    return (Number.isInteger(w) ? String(w) : w.toFixed(1)) + '万'
  }
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
  autoVideoCover.value = ''
  try {
    const data = await apiRequest('noticedetail', 'GET', { id })
    notice.value = data || null
    viewsLocal.value = (data && data.views_count) | 0
    if (data && String(data.status) === 'published') {
      bumpViews(id)
    }
    const cover = String((data && data.video_cover) || '').trim()
    const vid = String((data && data.video) || '').trim()
    if (vid && !cover) fillAutoVideoCover(vid)
  } catch (e) {
    notice.value = null
    uni.showToast({ title: (e && e.message) || '加载失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

async function softDelete() {
  if (!canManage.value || noticeDeleting) return
  const id = noticeId.value | 0
  if (!id) return
  const ok = await new Promise((resolve) => {
    uni.showModal({
      title: '下架帖子',
      content: '下架后前台不再显示（数据保留，可在后台恢复）',
      confirmText: '下架',
      cancelText: '取消',
      success: (res) => resolve(!!(res && res.confirm)),
      fail: () => resolve(false),
    })
  })
  if (!ok) return
  noticeDeleting = true
  try {
    await apiRequest('noticepause', 'POST', { id })
    uni.showToast({ title: '已下架', icon: 'success' })
    setTimeout(() => {
      uni.navigateBack({ fail: () => uni.redirectTo({ url: '/pages/notice/notice' }) })
    }, 400)
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '下架失败', icon: 'none' })
  } finally {
    noticeDeleting = false
  }
}

onLoad(async (q) => {
  const id = (q && q.id) | 0
  noticeId.value = id
  try {
    const p = await fetchProfile()
    myUserId.value = (p && (p.id || p.user_id)) | 0
  } catch (e) {}
  try {
    const cfg = await fetchConfig()
    const ids = (cfg && cfg.notice_manager_user_ids) || []
    if (Array.isArray(ids) && ids.length) {
      noticeManagerIds.value = ids.map((x) => x | 0).filter((x) => x > 0)
    }
  } catch (e2) {}
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
  display: inline;
  font-size: 11px;
  color: #9a9a9a;
  margin-top: 0;
}

.chat-notice-one {
  margin-top: 12px;
  width: 100%;
  max-width: 100%;
  box-sizing: border-box;
}
.chat-notice-one.compact {
  max-width: 280px;
}
.chat-notice-one-img {
  width: 100% !important;
  max-width: 100% !important;
  display: block !important;
  border-radius: 10px;
  overflow: hidden;
}
</style>

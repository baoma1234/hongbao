<template>
  <ProfileSubPage title="发帖" back-fallback="/pages/notice/notice">
    <view class="notice-compose">
      <view class="compose-sec">
        <text class="compose-lab">发布到</text>
        <scroll-view scroll-x class="compose-themes" :show-scrollbar="false">
          <view class="compose-themes-inner">
            <view
              v-for="c in categories"
              :key="c.code"
              class="compose-theme-chip"
              :class="{ active: category === c.code }"
              @click="setCategory(c.code)"
            >{{ c.title }}</view>
          </view>
        </scroll-view>
      </view>

      <view class="compose-sec">
        <text class="compose-lab">主题</text>
        <scroll-view scroll-x class="compose-themes" :show-scrollbar="false">
          <view class="compose-themes-inner">
            <view
              v-for="t in themes"
              :key="t.id"
              class="compose-theme-chip"
              :class="{ active: themeId === t.id }"
              @click="themeId = t.id"
            >{{ t.title }}</view>
          </view>
        </scroll-view>
        <text v-if="!themes.length" class="compose-empty-tip">该模块暂无可用主题</text>
      </view>

      <view class="compose-sec">
        <text class="compose-lab">正文</text>
        <textarea
          class="compose-textarea"
          v-model="content"
          maxlength="5000"
          :auto-height="true"
          :placeholder="contentPlaceholder"
        />
      </view>

      <view class="compose-sec">
        <text class="compose-lab">图片（最多9张）</text>
        <view class="compose-imgs">
          <view v-for="(img, i) in images" :key="i" class="compose-img-item">
            <image class="compose-img" :src="avatarSrc(img.preview || img.url)" mode="aspectFill" />
            <text class="compose-img-del" @click="removeImage(i)">×</text>
          </view>
          <view v-if="images.length < 9" class="compose-img-add" @click="pickImages">
            <text class="compose-img-add-plus">+</text>
          </view>
        </view>
      </view>

      <text class="compose-tip">{{ campaignTip }}</text>
      <button class="compose-submit" :disabled="busy || !canPost" @click="submit">
        {{ busy ? '提交中…' : '发布' }}
      </button>
    </view>
  </ProfileSubPage>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onLoad, onShow } from '@dcloudio/uni-app'
import ProfileSubPage from '../../components/ProfileSubPage.vue'
import { apiRequest, fetchConfig, getToken, uploadCommonFile } from '../../utils/auth.js'
import { avatarSrc } from '../../utils/chat.js'
import '../../styles/hb.css'

const categories = [
  { code: 'ads', title: '彩金白嫖' },
  { code: 'rules', title: '红宝•海外圈内事' },
]
/** 暂不开放用户发帖的大模块 */
const BLOCKED_USER_POST_CATS = ['latest', 'promote']

const category = ref('ads')
const themes = ref([])
const themeId = ref(0)
const content = ref('')
const images = ref([])
const busy = ref(false)
const rules = ref(null)

function normalizeUserPostCategory(code) {
  const c = String(code || '').trim()
  if (categories.some((x) => x.code === c)) return c
  return 'ads'
}

const categoryTitle = computed(() => {
  const hit = categories.find((c) => c.code === category.value)
  return (hit && hit.title) || '彩金白嫖'
})

const contentPlaceholder = computed(() => {
  if (category.value === 'rules') return '分享海外快讯、生活故事、求助或吐槽…'
  return '分享彩金、白嫖、广告或曝光内容…'
})

const canPost = computed(() => {
  const r = rules.value
  if (!r) return true
  if (r.exempt) return true
  return r.can_post !== false
})

const campaignTip = computed(() => {
  const r = rules.value
  const board = categoryTitle.value
  if (!r || r.enabled === false) {
    return '发帖归类「' + board + '」，审核通过后他人可见；审核前仅自己可见。'
  }
  const first = Number(r.reward_first)
  const after = Number(r.reward_after)
  const tier = Number(r.reward_tier) || 10
  const daily = Number(r.daily_limit) || 10
  const lines = []
  if (r.need_rp) {
    lines.push(r.has_sent_rp ? '已满足：娱乐发过红宝' : '需先在娱乐群发过红宝才能发帖')
  }
  lines.push('每天限' + daily + '帖（今日剩余' + (r.remain_today != null ? r.remain_today : daily) + '）')
  const fTxt = Number.isFinite(first) ? first : 1
  const aTxt = Number.isFinite(after) ? after : 2
  lines.push('审核通过：前' + tier + '帖各奖' + fTxt + '元红宝，之后每帖' + aTxt + '元')
  lines.push('归类「' + board + '」，审核通过后他人可见。')
  return lines.join('\n')
})

async function loadRules() {
  try {
    const data = await apiRequest('noticepostrules', 'GET')
    if (data && typeof data === 'object') {
      rules.value = data
      return
    }
  } catch (e) {}
  try {
    const cfg = await fetchConfig()
    if (cfg && cfg.notice_post_campaign) {
      rules.value = Object.assign({ can_post: true, has_sent_rp: false, remain_today: 10 }, cfg.notice_post_campaign)
    }
  } catch (e2) {}
}

async function loadThemes() {
  try {
    const data = await apiRequest('noticethemes', 'GET', { category: category.value })
    const list = (data && data.list) || []
    themes.value = Array.isArray(list) ? list : []
    const still = themes.value.some((t) => (t.id | 0) === (themeId.value | 0))
    if (!still) {
      themeId.value = themes.value.length ? themes.value[0].id | 0 : 0
    }
  } catch (e) {
    themes.value = []
    themeId.value = 0
  }
}

function setCategory(code) {
  const next = normalizeUserPostCategory(code)
  if (BLOCKED_USER_POST_CATS.indexOf(String(code || '')) >= 0) {
    uni.showToast({ title: '该模块暂不开放发帖', icon: 'none' })
  }
  if (category.value === next) return
  category.value = next
  themeId.value = 0
  loadThemes()
}

function removeImage(i) {
  images.value.splice(i, 1)
}

function pickImages() {
  const left = 9 - images.value.length
  if (left <= 0) return
  uni.chooseImage({
    count: left,
    sizeType: ['compressed'],
    success: async (res) => {
      const paths = (res && res.tempFilePaths) || []
      for (let i = 0; i < paths.length; i++) {
        const path = paths[i]
        if (!path) continue
        busy.value = true
        try {
          const data = await uploadCommonFile(path)
          const url = normalizeUploadPath(data)
          if (!url) {
            uni.showToast({ title: '上传失败', icon: 'none' })
            continue
          }
          images.value.push({
            url,
            preview: data.fullurl || avatarSrc(url) || path,
          })
        } catch (e) {
          uni.showToast({ title: (e && e.message) || '上传失败', icon: 'none' })
        } finally {
          busy.value = false
        }
      }
    },
  })
}

function normalizeUploadPath(data) {
  if (!data) return ''
  let path = String(data.url || data.path || '').trim()
  const full = String(data.fullurl || '').trim()
  if ((!path || path.indexOf('/uploads/') !== 0) && full) {
    const m = full.match(/(\/uploads\/[^?#]+)/i)
    if (m) path = m[1]
  }
  if (path && path.charAt(0) !== '/' && path.indexOf('http') !== 0) {
    path = '/' + path.replace(/^\/+/, '')
  }
  return path || full
}

async function submit() {
  if (busy.value) return
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  const text = String(content.value || '').trim()
  if (!text) {
    uni.showToast({ title: '请填写正文', icon: 'none' })
    return
  }
  if (!(themeId.value | 0)) {
    uni.showToast({ title: '请选择主题', icon: 'none' })
    return
  }
  if (!canPost.value) {
    uni.showToast({ title: campaignTip.value.split('\n')[0] || '暂不能发帖', icon: 'none' })
    return
  }
  const postCat = normalizeUserPostCategory(category.value)
  if (BLOCKED_USER_POST_CATS.indexOf(postCat) >= 0) {
    uni.showToast({ title: '该模块暂不开放发帖', icon: 'none' })
    return
  }
  busy.value = true
  try {
    await apiRequest('noticecreate', 'POST', {
      content: text,
      category: postCat,
      theme_id: themeId.value | 0,
      images: images.value.map((x) => x.url).filter(Boolean),
    })
    uni.showToast({ title: '已提交审核', icon: 'success' })
    setTimeout(() => {
      uni.redirectTo({
        url: '/pages/notice/my-posts',
        fail: () => uni.navigateBack({ fail: () => uni.switchTab({ url: '/pages/notice/notice' }) }),
      })
    }, 500)
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '发帖失败', icon: 'none' })
  } finally {
    busy.value = false
  }
}

onLoad((q) => {
  const cat = String((q && (q.category || q.cat)) || '').trim()
  if (BLOCKED_USER_POST_CATS.indexOf(cat) >= 0) {
    category.value = 'ads'
    // 从最新/推广点发帖时落到可发模块，避免误发
    setTimeout(() => {
      uni.showToast({ title: '该模块暂不开放发帖，已切换到彩金白嫖', icon: 'none' })
    }, 300)
  } else {
    category.value = normalizeUserPostCategory(cat || 'ads')
  }
})

onShow(() => {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  loadThemes()
  loadRules()
})
</script>

<style scoped>
.notice-compose {
  padding: 12px 16px 28px;
  box-sizing: border-box;
}
.compose-sec {
  margin-bottom: 16px;
}
.compose-lab {
  display: block;
  font-size: 13px;
  color: #888;
  margin-bottom: 8px;
}
.compose-themes {
  width: 100%;
  white-space: nowrap;
}
.compose-themes-inner {
  display: inline-flex;
  gap: 8px;
  padding-bottom: 2px;
}
.compose-theme-chip {
  display: inline-flex;
  align-items: center;
  height: 32px;
  padding: 0 14px;
  border-radius: 16px;
  background: #f2f2f2;
  color: #555;
  font-size: 13px;
  flex-shrink: 0;
}
.compose-theme-chip.active {
  background: #2ecc71;
  color: #fff;
  font-weight: 600;
}
.compose-empty-tip {
  display: block;
  margin-top: 8px;
  font-size: 12px;
  color: #999;
}
.compose-textarea {
  width: 100%;
  min-height: 140px;
  padding: 12px;
  font-size: 15px;
  line-height: 1.55;
  color: #222;
  background: #fff;
  border-radius: 10px;
  border: 0.5px solid #e8e8e8;
  box-sizing: border-box;
}
.compose-imgs {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.compose-img-item {
  position: relative;
  width: 72px;
  height: 72px;
}
.compose-img {
  width: 72px;
  height: 72px;
  border-radius: 8px;
  background: #f5f5f5;
}
.compose-img-del {
  position: absolute;
  top: -6px;
  right: -6px;
  width: 20px;
  height: 20px;
  line-height: 20px;
  text-align: center;
  border-radius: 10px;
  background: rgba(0, 0, 0, 0.55);
  color: #fff;
  font-size: 14px;
}
.compose-img-add {
  width: 72px;
  height: 72px;
  border-radius: 8px;
  border: 1px dashed #ccc;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #fafafa;
}
.compose-img-add-plus {
  font-size: 28px;
  color: #bbb;
  line-height: 1;
}
.compose-tip {
  display: block;
  font-size: 12px;
  color: #999;
  line-height: 1.5;
  white-space: pre-wrap;
  margin: 4px 0 14px;
}
.compose-submit {
  width: 100%;
  height: 44px;
  line-height: 44px;
  border-radius: 22px;
  background: #2ecc71;
  color: #fff;
  font-size: 16px;
  font-weight: 600;
  border: none;
}
.compose-submit[disabled] {
  opacity: 0.55;
}
</style>

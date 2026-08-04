<template>
  <view class="page">
    <view class="nav">
      <view class="nav-ico-btn nav-back" @click="goBack">
        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
          <path fill="currentColor" d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6 6 6z" />
        </svg>
      </view>
      <text class="nav-title">{{ title }}</text>
      <view class="nav-ico-btn nav-more" @click="openMore">
        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
          <path fill="currentColor" d="M7 10a2 2 0 110 4 2 2 0 010-4zm5 0a2 2 0 110 4 2 2 0 010-4zm5 0a2 2 0 110 4 2 2 0 010-4z" />
        </svg>
      </view>
    </view>

    <scroll-view
      scroll-y
      class="msgs"
      :scroll-into-view="scrollInto"
      scroll-with-animation
      @click="showEmoji = false; showSticker = false"
    >
      <view
        v-for="m in messages"
        :id="'m' + msgId(m)"
        :key="msgId(m)"
        class="row"
        :class="{ mine: isMine(m), system: isSysRow(m) }"
      >
        <view v-if="isSysRow(m)" class="sys">{{ sysText(m) }}</view>
        <view v-else class="msg-wrap">
          <text v-if="showSender(m)" class="sender">{{ senderName(m) }}</text>
          <view
            v-if="isRp(m)"
            class="rp-card"
            :class="{ faded: rpFaded(m), grabbed: rpGrabbed(m) }"
            @click="onRpTap(m)"
            @longpress="onMsgLongPress(m)"
          >
            <view class="rp-ico">红</view>
            <view class="rp-main">
              <text class="rp-bless">{{ rpBlessing(m) }}</text>
              <text class="rp-sub">{{ rpSub(m) }}</text>
            </view>
          </view>
          <view v-else-if="isSticker(m)" class="bubble sticker" @longpress="onMsgLongPress(m)">
            <image class="sticker-img" :src="stickerUrl(m)" mode="widthFix" />
            <text class="meta-time">{{ msgTime(m) }}</text>
          </view>
          <view v-else-if="isImage(m)" class="bubble media" @longpress="onMsgLongPress(m)">
            <image class="media-img" :src="mediaUrl(m)" mode="widthFix" @click.stop="previewImageMsg(m)" />
            <text class="meta-time">{{ msgTime(m) }}</text>
          </view>
          <view v-else-if="isVideo(m)" class="bubble media" @longpress="onMsgLongPress(m)">
            <video class="media-video" :src="mediaUrl(m)" controls playsinline />
            <text class="meta-time">{{ msgTime(m) }}</text>
          </view>
          <view v-else-if="isFile(m)" class="bubble media file" @longpress="onMsgLongPress(m)" @click="openFileMsg(m)">
            <text class="file-name">{{ fileName(m) }}</text>
            <text class="file-ext">{{ fileMeta(m) }}</text>
            <text class="meta-time">{{ msgTime(m) }}</text>
          </view>
          <view v-else class="bubble" @longpress="onMsgLongPress(m)">
            <text class="content">{{ msgText(m) }}</text>
            <text class="meta-time">{{ msgTime(m) }}</text>
          </view>
        </view>
      </view>
    </scroll-view>

    <view class="emoji-panel" v-if="showEmoji">
      <scroll-view scroll-y class="emoji-scroll">
        <view class="emoji-grid">
          <text
            v-for="(em, idx) in emojis"
            :key="idx"
            class="emoji-item"
            @click="insertEmoji(em)"
          >{{ em }}</text>
        </view>
      </scroll-view>
    </view>
    <view class="emoji-panel" v-if="showSticker">
      <scroll-view scroll-y class="emoji-scroll">
        <view class="sticker-grid">
          <view
            v-for="(st, idx) in stickerItems"
            :key="st.code + '-' + idx"
            class="sticker-item"
            @click="sendSticker(st)"
          >
            <image class="sticker-pick" :src="st.url" mode="aspectFit" />
            <text class="sticker-code">{{ st.code }}</text>
          </view>
        </view>
        <view v-if="!stickerItems.length" class="empty">暂无表情包</view>
      </scroll-view>
    </view>

    <view class="composer">
      <button class="tool icon" size="mini" @click="toggleEmoji"><text>😊</text></button>
      <button class="tool icon" size="mini" @click="toggleSticker"><text>😀</text></button>
      <button class="tool icon" size="mini" @click="pickImage"><text>🖼️</text></button>
      <button class="tool icon" size="mini" @click="pickVideo"><text>🎬</text></button>
      <button class="tool icon" size="mini" @click="pickFile"><text>📎</text></button>
      <button class="tool icon" size="mini" @click="showRp = true; showEmoji = false; showSticker = false"><text>🧧</text></button>
      <input
        class="input"
        v-model="text"
        confirm-type="send"
        @confirm="sendText"
        @focus="showEmoji = false; showSticker = false"
        placeholder="输入消息"
      />
      <button class="send" size="mini" @click="sendText">发送</button>
    </view>

    <!-- 发包 -->
    <view class="mask" v-if="showRp" @click="showRp = false">
      <view class="sheet" @click.stop>
        <view class="sheet-title">发红包</view>
        <view class="field" v-if="!isPrivate">
          <text class="lab">类型</text>
          <view class="tabs">
            <text
              v-for="t in packetTypes"
              :key="t.v"
              class="tab"
              :class="{ on: rpForm.packet_type === t.v }"
              @click="rpForm.packet_type = t.v"
            >{{ t.n }}</text>
          </view>
        </view>
        <view class="field">
          <text class="lab">金额（红宝）</text>
          <input class="hb-input" type="digit" v-model="rpForm.total_amount" placeholder="金额" />
        </view>
        <view class="field" v-if="!isPrivate">
          <text class="lab">个数</text>
          <input class="hb-input" type="number" v-model="rpForm.total_count" placeholder="个数" />
        </view>
        <view class="field" v-if="!isPrivate && rpForm.packet_type === 3">
          <text class="lab">雷号 0-9</text>
          <input class="hb-input" type="number" v-model="rpForm.mine_digit" placeholder="0-9" />
        </view>
        <view class="field">
          <text class="lab">祝福语</text>
          <input class="hb-input" v-model="rpForm.blessing" placeholder="恭喜发财" />
        </view>
        <button class="btn-uid-submit" :disabled="rpSending" @click="sendRp">
          {{ rpSending ? '发送中…' : '塞进红包' }}
        </button>
        <button class="cancel" @click="showRp = false">取消</button>
      </view>
    </view>

    <GrabSlider ref="grabSliderRef" />

    <!-- 红包详情 -->
    <view class="mask" v-if="detailVisible" @click="detailVisible = false">
      <view class="sheet detail" @click.stop>
        <view class="sheet-title">红包详情</view>
        <view class="detail-head" v-if="detail">
          <text class="d-bless">{{ (detail.packet && detail.packet.blessing) || '恭喜发财' }}</text>
          <text class="d-amt" v-if="myGrabAmount">抢到 {{ myGrabAmount }} 红宝</text>
          <text class="d-meta">
            {{ packetTypeLabel((detail.packet && detail.packet.packet_type) || 1) }}
            · {{ (detail.packet && detail.packet.total_amount) || '-' }} 红宝
            / {{ (detail.packet && detail.packet.total_count) || '-' }} 个
          </text>
          <text class="d-fair-tip" v-if="detailFairTip">{{ detailFairTip }}</text>
          <button
            v-if="canFairVerify"
            class="d-fair-btn"
            @click="openFairVerify"
          >查询验证</button>
        </view>
        <scroll-view scroll-y class="detail-list">
          <view v-for="r in detailRecords" :key="r.id || r.user_id" class="d-row">
            <image
              v-if="r.avatar"
              class="d-av"
              :src="r.avatar"
              mode="aspectFill"
            />
            <view v-else class="d-av d-av-fb">{{ (r.nickname || '?').charAt(0) }}</view>
            <view class="d-main">
              <text class="d-nick">{{ r.nickname || ('用户' + (r.user_id || '')) }}</text>
              <text class="d-time" v-if="r.createtime">{{ formatRpTime(r.createtime) }}</text>
            </view>
            <text class="d-right">{{ formatAmt(r.amount) }}</text>
          </view>
          <view v-if="!detailRecords.length" class="empty">{{ claimsEmptyTip }}</view>
        </scroll-view>
        <button
          v-if="canGrabDetail"
          class="btn-uid-submit"
          :disabled="grabbing"
          @click="grabFromDetail"
        >
          {{ grabbing ? '领取中…' : '开红包' }}
        </button>
        <button class="cancel" @click="detailVisible = false">关闭</button>
      </view>
    </view>

    <!-- 私聊更多 -->
    <view class="mask" v-if="moreVisible" @click="moreVisible = false">
      <view class="sheet more" @click.stop>
        <view class="sheet-title">{{ peerNickname || title }}</view>
        <view class="more-sub">会员ID {{ meta.peer }}{{ remark ? ' · 备注 ' + remark : '' }}</view>
        <button class="btn-uid-submit" @click="editRemark">{{ remark ? '修改备注' : '设置备注' }}</button>
        <button class="cancel" @click="moreVisible = false">关闭</button>
      </view>
    </view>
  </view>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { onLoad, onUnload } from '@dcloudio/uni-app'
import GrabSlider from '../../components/GrabSlider.vue'
import { apiRequest, fetchProfile, getToken } from '../../utils/auth.js'
import { getApiBase } from '../../utils/config.js'
import {
  isRecalled,
  isSystemMsg,
  msgExtra,
  msgType,
  packetTypeLabel,
  recallTip,
} from '../../utils/chat.js'
import { clearActiveChat, getActiveChat, saveActiveChat } from '../../utils/chat-route.js'
import { COMMON_EMOJIS } from '../../utils/emoji.js'
import {
  grabRedPacket,
  imConnect,
  imSend,
  loadHistory,
  markConversationRead,
  onImEvent,
  recallMessage,
  redPacketDetail,
  sendRedPacket,
  setPeerRemark,
} from '../../utils/im.js'

const title = ref('聊天')
const peerNickname = ref('')
const remark = ref('')
const text = ref('')
const messages = ref([])
const scrollInto = ref('')
const meta = ref({ type: 1, peer: 0, group: 0, conversationId: '' })
const showRp = ref(false)
const showEmoji = ref(false)
const showSticker = ref(false)
const rpSending = ref(false)
const mediaSending = ref(false)
const grabbing = ref(false)
const detailVisible = ref(false)
const detail = ref(null)
const moreVisible = ref(false)
const myGrabAmount = ref('')
const canGrabDetail = ref(false)
const grabSliderRef = ref(null)
const emojis = COMMON_EMOJIS
const stickerItems = ref([])
let myId = 0
let off = null
let activePacketId = 0

const isPrivate = computed(() => (meta.value.type | 0) === 1)
const packetTypes = [
  { v: 2, n: '拼手气' },
  { v: 3, n: '埋雷' },
  { v: 1, n: '普通' },
]
const rpForm = reactive({
  packet_type: 2,
  total_amount: '',
  total_count: '5',
  mine_digit: '7',
  blessing: '恭喜发财',
})

const detailRecords = computed(() => {
  const d = detail.value
  if (!d) return []
  return d.records || d.list || []
})

const claimsEmptyTip = computed(() => {
  const d = detail.value
  if (!d) return '暂无领取记录'
  if (d.claims_visible === false) return '领取后可查看领取详情'
  return '暂无领取记录'
})

const canFairVerify = computed(() => {
  const d = detail.value
  if (!d) return false
  const p = d.packet || {}
  const ptype = (p.packet_type | 0)
  if (ptype !== 2 && ptype !== 3 && ptype !== 5) return false
  const remain = p.remain_count != null ? (p.remain_count | 0) : 1
  const st = (p.status | 0)
  const finished = remain <= 0 || st === 2 || st === 3 || st === 4 || st === 5
  const grabbed = !!(d.mine || (d.records || []).some((r) => (r.user_id | 0) === myId))
  return grabbed && finished
})

const detailFairTip = computed(() => {
  const d = detail.value
  if (!d) return ''
  const p = d.packet || {}
  const ptype = (p.packet_type | 0)
  if (ptype !== 2 && ptype !== 3 && ptype !== 5) return ''
  if (canFairVerify.value) return ''
  const grabbed = !!(d.mine || (d.records || []).some((r) => (r.user_id | 0) === myId))
  const remain = p.remain_count != null ? (p.remain_count | 0) : 1
  const st = (p.status | 0)
  const finished = remain <= 0 || st === 2 || st === 3 || st === 4 || st === 5
  if (grabbed && !finished) return '红包领完后可查询验证'
  return '领取后且红包领完才可查询验证'
})

function formatRpTime(ts) {
  const n = Number(ts) || 0
  if (!n) return ''
  const d = new Date(n < 1e12 ? n * 1000 : n)
  const p = (x) => (x < 10 ? '0' + x : '' + x)
  return p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes())
}

function openFairVerify() {
  const p = (detail.value && detail.value.packet) || {}
  const no = String(p.packet_no || '').trim()
  if (!no) {
    uni.showToast({ title: '缺少红包单号', icon: 'none' })
    return
  }
  const base = getApiBase() || ''
  // #ifdef H5
  if (typeof window !== 'undefined') {
    const url = (base.replace(/\/$/, '') || '') + '/888/fair-verify.html?packet_no=' + encodeURIComponent(no)
    window.open(url, '_blank')
    return
  }
  // #endif
  uni.navigateTo({
    url: '/pages/common/webview?url=' + encodeURIComponent('/888/fair-verify.html?packet_no=' + encodeURIComponent(no)),
    fail: () => uni.showToast({ title: '请到网页版查询验证', icon: 'none' }),
  })
}

function msgId(m) {
  return m.msg_id || m.id || m.createtime || Math.random()
}
function isMine(m) {
  return (m.from_user_id | 0) === (myId | 0)
}
function isRp(m) {
  return msgType(m) === 2
}
function isImage(m) {
  return msgType(m) === 4
}
function isVideo(m) {
  return msgType(m) === 5
}
function isFile(m) {
  return msgType(m) === 7
}
function isSticker(m) {
  const t = msgType(m)
  if (t === 6) return true
  if (t === 1 && /^\[[^\]]+\]$/.test(String(m && m.content ? m.content : ''))) return true
  return false
}
function isSysRow(m) {
  return isRecalled(m) || isSystemMsg(m)
}
function sysText(m) {
  if (isRecalled(m)) return recallTip(m, myId, isPrivate.value)
  return m.content || '[系统消息]'
}
function rpBlessing(m) {
  return msgExtra(m).blessing || '恭喜发财'
}
function rpSub(m) {
  const ex = msgExtra(m)
  const label = packetTypeLabel(ex.packet_type || 1)
  if (ex.cover_grabbed || ex.cover_faded) return label + ' · 已领过'
  if (ex.cover_expired) return label + ' · 已过期'
  return label + ' · 点击拆开'
}
function rpFaded(m) {
  const ex = msgExtra(m)
  return !!(ex.cover_faded || ex.cover_expired)
}
function rpGrabbed(m) {
  return !!msgExtra(m).cover_grabbed
}
function msgTime(m) {
  const raw = m && (m.createtime || m.create_time || m.timestamp || 0)
  const ts = Number(raw || 0)
  if (!ts) return ''
  const d = new Date(ts < 1e12 ? ts * 1000 : ts)
  const pad = (n) => (n < 10 ? '0' + n : '' + n)
  return pad(d.getHours()) + ':' + pad(d.getMinutes())
}
function msgText(m) {
  return (m && (m.content || m.text)) || '[消息]'
}
function showSender(m) {
  return (meta.value.type | 0) === 2 && !isMine(m)
}
function senderName(m) {
  return String((m && (m.nickname || m.from_nickname)) || ('ID' + ((m && m.from_user_id) | 0)))
}
function mediaUrl(m) {
  const ex = msgExtra(m)
  const raw = (ex && (ex.url || ex.fullurl)) || ''
  return normalizeStickerUrl(raw)
}
function fileName(m) {
  const ex = msgExtra(m)
  return String((ex && ex.name) || '文件')
}
function fileMeta(m) {
  const ex = msgExtra(m)
  const ext = String((ex && ex.ext) || '').trim()
  const size = Number(ex && ex.size)
  const text = []
  if (ext) text.push(ext)
  if (size > 0) {
    if (size >= 1024 * 1024) text.push((size / (1024 * 1024)).toFixed(1) + 'MB')
    else if (size >= 1024) text.push(Math.round(size / 1024) + 'KB')
    else text.push(size + 'B')
  }
  return text.join(' · ')
}
function normalizeStickerUrl(url) {
  const s = String(url || '').trim()
  if (!s) return ''
  if (/^https?:\/\//i.test(s)) return s
  if (s.startsWith('/')) return s
  if (s.startsWith('stickers/')) return '/888/' + s
  return '/888/' + s.replace(/^\/+/, '')
}
function stickerUrl(m) {
  const ex = msgExtra(m)
  const raw = (ex && (ex.url || ex.fullurl)) || ''
  if (raw) return normalizeStickerUrl(raw)
  return '/888/stickers/wechat/face/微笑.png'
}
function formatAmt(n) {
  const x = Number(n)
  if (!isFinite(x)) return '-'
  return x.toFixed(2)
}

function sameRoom(msg) {
  if (!msg) return false
  const type = msg.conversation_type | 0
  if (type !== (meta.value.type | 0)) return false
  if (type === 2) {
    return (msg.group_id | 0) === (meta.value.group | 0) ||
      String(msg.conversation_id || '') === String(meta.value.conversationId || meta.value.group)
  }
  return String(msg.conversation_id || '') === String(meta.value.conversationId) ||
    (msg.from_user_id | 0) === (meta.value.peer | 0) ||
    (msg.to_user_id | 0) === (meta.value.peer | 0)
}

function applyRecalled(msg) {
  if (!msg || !sameRoom(msg)) return
  const id = String(msg.id || msg.msg_id || '')
  if (!id) return
  const list = messages.value.slice()
  const idx = list.findIndex((x) => String(x.id || x.msg_id) === id)
  if (idx >= 0) {
    list[idx] = Object.assign({}, list[idx], msg, { status: 2 })
    messages.value = list
  }
}

function canRecallLocal(m) {
  if (!m || !isMine(m) || isRecalled(m) || isSystemMsg(m)) return false
  if (isPrivate.value) return true
  const ts = (m.createtime | 0) || 0
  const t = ts < 1e12 ? ts : Math.floor(ts / 1000)
  return t > 0 && Date.now() / 1000 - t <= 120
}

function onMsgLongPress(m) {
  if (!canRecallLocal(m)) return
  const label = isPrivate.value ? '删除消息' : '撤回消息'
  uni.showActionSheet({
    itemList: [label],
    success: async (res) => {
      if (res.tapIndex !== 0) return
      try {
        const packet = await recallMessage(m.id || m.msg_id)
        const body = (packet && packet.data) || {}
        const msg = body.message || Object.assign({}, m, { status: 2 })
        applyRecalled(msg)
        uni.showToast({ title: isPrivate.value ? '已删除' : '已撤回', icon: 'none' })
      } catch (e) {
        uni.showToast({ title: (e && e.message) || '操作失败', icon: 'none' })
      }
    },
  })
}

function toggleEmoji() {
  showEmoji.value = !showEmoji.value
  if (showEmoji.value) {
    showSticker.value = false
    showRp.value = false
  }
}

function insertEmoji(em) {
  text.value = String(text.value || '') + em
}

async function toggleSticker() {
  const next = !showSticker.value
  showSticker.value = next
  if (next) {
    showEmoji.value = false
    showRp.value = false
    if (!stickerItems.value.length) await loadStickers()
  }
}

async function loadStickers() {
  try {
    const data = await apiRequest('stickerlist', 'GET', {})
    const list = Array.isArray(data && data.list) ? data.list : []
    if (list.length) {
      stickerItems.value = list
        .map((it) => ({
          code: String(it.name || it.code || '').trim(),
          pack: String(it.pack || 'custom'),
          url: normalizeStickerUrl(it.url || it.fullurl || ''),
        }))
        .filter((it) => it.code && it.url)
        .slice(0, 64)
      if (stickerItems.value.length) return
    }
  } catch (e) {}
  try {
    const res = await new Promise((resolve, reject) => {
      uni.request({
        url: '/888/data/stickers.json',
        method: 'GET',
        success: (r) => resolve((r && r.data) || {}),
        fail: reject,
      })
    })
    const packs = Array.isArray(res && res.packs) ? res.packs : []
    const out = []
    packs.forEach((p) => {
      const pid = String((p && p.id) || 'wechat')
      const cats = Array.isArray(p && p.categories) ? p.categories : []
      cats.forEach((c) => {
        const items = Array.isArray(c && c.items) ? c.items : []
        items.forEach((it) => {
          if (out.length >= 64) return
          const code = String((it && it.code) || '').trim()
          const url = normalizeStickerUrl((it && it.url) || '')
          if (code && url) out.push({ code, url, pack: pid })
        })
      })
    })
    stickerItems.value = out
  } catch (e2) {
    stickerItems.value = []
  }
}

async function sendSticker(st) {
  if (!st || !st.url) return
  const code = String(st.code || '表情')
  const payload = {
    msg_type: 6,
    content: '[' + code + ']',
    extra: {
      pack: String(st.pack || 'wechat'),
      code,
      url: st.url,
      fullurl: st.url,
    },
  }
  try {
    if (meta.value.type == 2) {
      await imSend('group.send', Object.assign({ group_id: meta.value.group | 0 }, payload), true)
    } else {
      await imSend('private.send', Object.assign({ to_user_id: meta.value.peer | 0 }, payload), true)
    }
    showSticker.value = false
    await fetchHistory()
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '发送失败', icon: 'none' })
  }
}

async function uploadCommonFile(filePath) {
  const base = getApiBase() || ''
  const token = getToken()
  const up = await new Promise((resolve, reject) => {
    uni.uploadFile({
      url: base + '/api/common/upload',
      filePath,
      name: 'file',
      header: token ? { token } : {},
      success: (res) => {
        try {
          const body = JSON.parse((res && res.data) || '{}')
          if ((body && body.code) !== 1) {
            reject(new Error(body.msg || body.message || '上传失败'))
            return
          }
          resolve(body.data || {})
        } catch (e) {
          reject(new Error('上传失败'))
        }
      },
      fail: (err) => reject(new Error((err && err.errMsg) || '上传失败')),
    })
  })
  return up
}

async function sendMediaMessage(msgType, extra, label) {
  if (meta.value.type == 2) {
    await imSend(
      'group.send',
      { group_id: meta.value.group | 0, msg_type: msgType, content: label, extra: extra || {} },
      true
    )
  } else {
    await imSend(
      'private.send',
      { to_user_id: meta.value.peer | 0, msg_type: msgType, content: label, extra: extra || {} },
      true
    )
  }
}

async function pickImage() {
  if (mediaSending.value) return
  try {
    const chosen = await new Promise((resolve, reject) => {
      uni.chooseImage({
        count: 1,
        sizeType: ['compressed'],
        sourceType: ['album', 'camera'],
        success: resolve,
        fail: reject,
      })
    })
    const filePath = String((chosen && chosen.tempFilePaths && chosen.tempFilePaths[0]) || '')
    if (!filePath) return
    mediaSending.value = true
    uni.showLoading({ title: '上传中…', mask: true })
    const up = await uploadCommonFile(filePath)
    const url = normalizeStickerUrl(up.url || up.fullurl || '')
    if (!url) throw new Error('上传失败')
    await sendMediaMessage(
      4,
      { url, fullurl: up.fullurl || url, name: up.name || '' },
      '[图片]'
    )
    await fetchHistory()
  } catch (e) {
    const msg = (e && e.message) || ''
    if (!/cancel|deny|fail chooseImage/i.test(msg)) {
      uni.showToast({ title: msg || '发送失败', icon: 'none' })
    }
  } finally {
    uni.hideLoading()
    mediaSending.value = false
  }
}

async function pickVideo() {
  if (mediaSending.value) return
  try {
    const chosen = await new Promise((resolve, reject) => {
      uni.chooseVideo({
        sourceType: ['album', 'camera'],
        maxDuration: 60,
        compressed: true,
        success: resolve,
        fail: reject,
      })
    })
    const filePath = String((chosen && chosen.tempFilePath) || '')
    if (!filePath) return
    mediaSending.value = true
    uni.showLoading({ title: '上传中…', mask: true })
    const up = await uploadCommonFile(filePath)
    const url = normalizeStickerUrl(up.url || up.fullurl || '')
    if (!url) throw new Error('上传失败')
    await sendMediaMessage(
      5,
      { url, fullurl: up.fullurl || url, name: up.name || '' },
      '[视频]'
    )
    await fetchHistory()
  } catch (e) {
    const msg = (e && e.message) || ''
    if (!/cancel|deny|fail chooseVideo/i.test(msg)) {
      uni.showToast({ title: msg || '发送失败', icon: 'none' })
    }
  } finally {
    uni.hideLoading()
    mediaSending.value = false
  }
}

async function pickFile() {
  if (mediaSending.value) return
  try {
    const chosen = await new Promise((resolve, reject) => {
      // #ifdef H5
      if (typeof uni.chooseMessageFile === 'function') {
        uni.chooseMessageFile({
          count: 1,
          type: 'file',
          success: resolve,
          fail: reject,
        })
        return
      }
      // #endif
      reject(new Error('当前端不支持文件选择'))
    })
    const item = (chosen && chosen.tempFiles && chosen.tempFiles[0]) || {}
    const filePath = String(item.path || '')
    if (!filePath) return
    mediaSending.value = true
    uni.showLoading({ title: '上传中…', mask: true })
    const up = await uploadCommonFile(filePath)
    const url = normalizeStickerUrl(up.url || up.fullurl || '')
    if (!url) throw new Error('上传失败')
    const rawName = String(item.name || up.name || 'file')
    const dot = rawName.lastIndexOf('.')
    const ext = dot >= 0 ? rawName.slice(dot + 1).toLowerCase() : ''
    await sendMediaMessage(
      7,
      { url, fullurl: up.fullurl || url, name: rawName, size: Number(item.size || 0), ext },
      '[文件]' + rawName
    )
    await fetchHistory()
  } catch (e) {
    const msg = (e && e.message) || ''
    if (!/cancel|deny|fail/i.test(msg)) {
      uni.showToast({ title: msg || '发送失败', icon: 'none' })
    }
  } finally {
    uni.hideLoading()
    mediaSending.value = false
  }
}

function previewImageMsg(m) {
  const cur = mediaUrl(m)
  if (!cur) return
  uni.previewImage({ current: cur, urls: [cur] })
}

function openFileMsg(m) {
  const url = mediaUrl(m)
  if (!url) {
    uni.showToast({ title: '文件地址无效', icon: 'none' })
    return
  }
  // #ifdef H5
  if (typeof window !== 'undefined') {
    window.open(url, '_blank')
    return
  }
  // #endif
  uni.setClipboardData({
    data: url,
    success: () => uni.showToast({ title: '文件链接已复制', icon: 'none' }),
  })
}

function goBack() {
  clearActiveChat()
  uni.navigateBack({ fail: () => uni.switchTab({ url: '/pages/messages/messages' }) })
}

function openMore() {
  if (isPrivate.value) {
    moreVisible.value = true
    return
  }
  uni.navigateTo({
    url: '/pages/group/settings?group=' + encodeURIComponent(meta.value.group || meta.value.conversationId),
  })
}

async function ensureUser() {
  try {
    const p = await fetchProfile()
    myId = (p && (p.user_id || p.id)) | 0
  } catch (e) {}
}

async function markRead() {
  const list = messages.value
  if (!list.length) return
  const last = list[list.length - 1]
  const lastId = (last.msg_id || last.id) | 0
  const cid =
    meta.value.type === 2
      ? String(meta.value.group || meta.value.conversationId)
      : String(meta.value.conversationId)
  if (!cid) return
  await markConversationRead(meta.value.type, cid, lastId)
}

async function fetchHistory() {
  const data = {
    conversation_type: meta.value.type | 0,
    limit: 40,
  }
  if (meta.value.type == 2) data.group_id = meta.value.group | 0
  else {
    data.to_user_id = meta.value.peer | 0
    if (meta.value.conversationId) data.conversation_id = meta.value.conversationId
  }
  const packet = await loadHistory(data)
  const body = (packet && packet.data) || {}
  const list = body.list || body.messages || []
  messages.value = list.slice().reverse()
  const last = messages.value[messages.value.length - 1]
  if (last) scrollInto.value = 'm' + msgId(last)
  await markRead()
}

async function sendText() {
  const content = String(text.value || '').trim()
  if (!content) return
  try {
    if (meta.value.type == 2) {
      await imSend('group.send', { group_id: meta.value.group | 0, content, msg_type: 1 }, true)
    } else {
      await imSend('private.send', { to_user_id: meta.value.peer | 0, content, msg_type: 1 }, true)
    }
    text.value = ''
    showEmoji.value = false
    showSticker.value = false
    await fetchHistory()
  } catch (e) {
    uni.showToast({ title: e.message || '发送失败', icon: 'none' })
  }
}

async function sendRp() {
  const amount = Number(rpForm.total_amount)
  if (!(amount > 0)) {
    uni.showToast({ title: '请输入金额', icon: 'none' })
    return
  }
  const payload = {
    blessing: String(rpForm.blessing || '恭喜发财').trim() || '恭喜发财',
    total_amount: amount,
  }
  if (isPrivate.value) {
    payload.scope_type = 1
    payload.to_user_id = meta.value.peer | 0
    payload.packet_type = 1
    payload.total_count = 1
  } else {
    payload.scope_type = 2
    payload.group_id = meta.value.group | 0
    payload.packet_type = rpForm.packet_type | 0 || 2
    payload.total_count = Math.max(1, parseInt(rpForm.total_count, 10) || 1)
    if (payload.packet_type === 3) {
      const dig = parseInt(rpForm.mine_digit, 10)
      if (!(dig >= 0 && dig <= 9)) {
        uni.showToast({ title: '雷号需 0-9', icon: 'none' })
        return
      }
      payload.mine_digit = dig
      if ([5, 7, 9].indexOf(payload.total_count) < 0) {
        uni.showToast({ title: '埋雷个数请选 5/7/9', icon: 'none' })
        return
      }
    }
  }
  rpSending.value = true
  try {
    await sendRedPacket(payload)
    showRp.value = false
    uni.showToast({ title: '红包已发出', icon: 'none' })
    await fetchHistory()
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '发包失败', icon: 'none' })
  } finally {
    rpSending.value = false
  }
}

async function onRpTap(m) {
  const ex = msgExtra(m)
  const pid = (ex.packet_id || 0) | 0
  if (!pid) {
    uni.showToast({ title: '红包信息缺失', icon: 'none' })
    return
  }
  activePacketId = pid
  if (!ex.cover_grabbed && !ex.cover_expired && !isMine(m)) {
    await tryGrab(pid)
  }
  await openDetail(pid)
}

async function tryGrab(packetId, sliderPayload = null) {
  grabbing.value = true
  try {
    const packet = await grabRedPacket(packetId, sliderPayload || {})
    const data = (packet && packet.data) || packet || {}
    if (data.code === 'slider_required' || (packet && packet.type === 'redpacket.challenge')) {
      grabbing.value = false
      if (!(grabSliderRef.value && typeof grabSliderRef.value.challenge === 'function')) {
        uni.showToast({ title: data.message || '需要滑动验证', icon: 'none' })
        return null
      }
      try {
        const payload = await grabSliderRef.value.challenge()
        return await tryGrab(packetId, payload || {})
      } catch (ce) {
        if (!/cancel/i.test((ce && ce.message) || '')) {
          uni.showToast({ title: (ce && ce.message) || '验证取消', icon: 'none' })
        }
        return null
      }
    }
    if (data.amount != null) {
      myGrabAmount.value = formatAmt(data.amount)
      uni.showToast({ title: '抢到 ' + myGrabAmount.value, icon: 'none' })
    }
    return data
  } catch (e) {
    const msg = (e && e.message) || '领取失败'
    if (/already|已领|expired|过期|finished|抢完/i.test(msg)) {
      // open detail
    } else if (/slider/i.test(msg)) {
      uni.showToast({ title: '需要滑动验证', icon: 'none' })
    } else {
      uni.showToast({ title: msg, icon: 'none' })
    }
    return null
  } finally {
    grabbing.value = false
  }
}

async function openDetail(packetId) {
  try {
    const packet = await redPacketDetail(packetId)
    const data = (packet && packet.data) || packet || {}
    detail.value = data
    const p = data.packet || {}
    const remain = p.remain_count != null ? p.remain_count : p.remain
    const grabbed = !!(data.mine || (data.records || []).some((r) => (r.user_id | 0) === myId))
    canGrabDetail.value = !!(remain > 0 && !grabbed)
    const mine = data.mine || (data.records || []).find((r) => (r.user_id | 0) === myId)
    if (mine && mine.amount != null) myGrabAmount.value = formatAmt(mine.amount)
    detailVisible.value = true
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '详情失败', icon: 'none' })
  }
}

async function grabFromDetail() {
  if (!activePacketId) return
  await tryGrab(activePacketId)
  await openDetail(activePacketId)
  await fetchHistory()
}

function editRemark() {
  moreVisible.value = false
  const cur = remark.value || ''
  // #ifdef H5
  if (typeof window !== 'undefined' && typeof window.prompt === 'function') {
    const nextRaw = window.prompt('设置备注（最多32字，留空清除）', cur)
    if (nextRaw === null) return
    saveRemark(String(nextRaw || '').trim().slice(0, 32))
    return
  }
  // #endif
  uni.showModal({
    title: remark.value ? '修改备注' : '设置备注',
    editable: true,
    placeholderText: '最多32字，留空清除',
    content: cur,
    success: (res) => {
      if (!res.confirm) return
      saveRemark(String(res.content || '').trim().slice(0, 32))
    },
  })
}

async function saveRemark(next) {
  try {
    const packet = await setPeerRemark(meta.value.peer, next)
    const data = (packet && packet.data) || packet || {}
    remark.value = String(data.remark || '')
    if (data.peer_nickname) peerNickname.value = String(data.peer_nickname)
    title.value = String(data.title || remark.value || peerNickname.value || title.value)
    uni.showToast({ title: remark.value ? '备注已保存' : '备注已清除', icon: 'none' })
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '备注失败', icon: 'none' })
  }
}

onLoad(async (query) => {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  let q = query || {}
  // 刷新后若 query 丢失，用本地快照补全
  if (!(q.id || q.peer || q.group)) {
    const saved = getActiveChat()
    if (saved) {
      q = {
        type: String(saved.type || 1),
        id: saved.id || '',
        peer: String(saved.peer || 0),
        group: String(saved.group || 0),
        title: saved.title || '',
        nickname: saved.nickname || '',
        remark: saved.remark || '',
      }
    }
  }
  meta.value = {
    type: parseInt(q.type || '1', 10) || 1,
    peer: parseInt(q.peer || '0', 10) || 0,
    group: parseInt(q.group || '0', 10) || 0,
    conversationId: decodeURIComponent(q.id || ''),
  }
  title.value = decodeURIComponent(q.title || '聊天')
  peerNickname.value = decodeURIComponent(q.nickname || '')
  remark.value = decodeURIComponent(q.remark || '')
  if (isPrivate.value) rpForm.packet_type = 1

  saveActiveChat({
    type: meta.value.type,
    id: meta.value.conversationId,
    peer: meta.value.peer,
    group: meta.value.group,
    title: title.value,
    nickname: peerNickname.value,
    remark: remark.value,
  })

  await ensureUser()
  off = onImEvent((type, data) => {
    if (type === 'private.message' || type === 'group.message') {
      const msg = (data && data.message) || data
      if (msg && sameRoom(msg)) {
        const id = msgId(msg)
        if (!messages.value.some((x) => String(msgId(x)) === String(id))) {
          messages.value.push(msg)
          scrollInto.value = 'm' + id
          markRead()
        }
      }
    }
    if (type === 'message.recalled') {
      const msg = (data && data.message) || data
      applyRecalled(msg)
    }
    if (type === 'redpacket.update') {
      fetchHistory().catch(() => {})
    }
  })
  try {
    await imConnect()
    await fetchHistory()
  } catch (e) {
    uni.showToast({ title: e.message || '连接失败', icon: 'none' })
  }
})

onUnload(() => {
  if (off) off()
})
</script>

<style scoped>
.page {
  display: flex;
  flex-direction: column;
  height: 100vh;
  background: #f6f1ea;
}
.nav {
  display: flex;
  align-items: center;
  gap: 12rpx;
  padding: 18rpx 20rpx;
  background: linear-gradient(to right, #e63022, #c61114);
  color: #fff;
}
.nav-back {
  width: 52rpx;
}
.nav-ico-btn {
  width: 52rpx;
  height: 52rpx;
  border-radius: 12rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  color: rgba(255, 255, 255, 0.95);
}
.nav-ico-btn:active {
  background: rgba(255, 255, 255, 0.16);
}
.nav-title {
  flex: 1;
  font-size: 32rpx;
  font-weight: 800;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.nav-more {
  width: 52rpx;
}
.msgs { flex: 1; padding: 20rpx 24rpx; box-sizing: border-box; }
.row { display: flex; margin: 14rpx 0; }
.row.mine { justify-content: flex-end; }
.row.system { justify-content: center; }
.msg-wrap {
  max-width: 78%;
}
.row.mine .msg-wrap {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
}
.sender {
  display: block;
  font-size: 20rpx;
  color: #9a8574;
  margin: 0 8rpx 6rpx;
}
.sys {
  max-width: 90%;
  font-size: 22rpx;
  color: #9a8574;
  background: rgba(255, 255, 255, 0.65);
  padding: 8rpx 18rpx;
  border-radius: 999rpx;
}
.bubble {
  max-width: 75%;
  padding: 16rpx 20rpx;
  background: #fff;
  border-radius: 16rpx;
  font-size: 28rpx;
  color: #2a1f18;
  box-shadow: 0 4rpx 12rpx rgba(40, 20, 10, 0.04);
}
.row.mine .bubble {
  background: #ffe8e6;
  color: #c61114;
}
.bubble.sticker {
  background: transparent;
  box-shadow: none;
  padding: 0;
  max-width: 260rpx;
}
.bubble.media {
  background: #fff;
}
.media-img {
  width: 340rpx;
  max-height: 420rpx;
  border-radius: 12rpx;
}
.media-video {
  width: 360rpx;
  height: 220rpx;
  border-radius: 12rpx;
  background: #000;
}
.bubble.file {
  min-width: 280rpx;
  border: 1px solid rgba(180, 140, 100, 0.22);
}
.file-name {
  display: block;
  font-size: 26rpx;
  color: #2a1f18;
  font-weight: 700;
}
.file-ext {
  display: block;
  margin-top: 6rpx;
  font-size: 20rpx;
  color: #8a7a6e;
}
.sticker-img {
  width: 220rpx;
  max-height: 220rpx;
  border-radius: 14rpx;
  background: #fff;
}
.meta-time {
  display: block;
  margin-top: 8rpx;
  font-size: 20rpx;
  color: #9a8574;
  text-align: right;
}
.row.mine .meta-time {
  color: #b35a60;
}
.rp-card {
  display: flex;
  gap: 16rpx;
  width: 420rpx;
  padding: 20rpx;
  border-radius: 16rpx;
  background: linear-gradient(135deg, #e94b3c 0%, #c61114 58%, #a50f12 100%);
  color: #fff;
  box-shadow: 0 8rpx 18rpx rgba(198, 17, 20, 0.22);
}
.rp-card.faded, .rp-card.grabbed {
  opacity: 0.72;
  filter: grayscale(0.2);
}
.rp-ico {
  width: 64rpx;
  height: 64rpx;
  border-radius: 14rpx;
  background: rgba(255, 255, 255, 0.2);
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 800;
}
.rp-main { flex: 1; min-width: 0; }
.rp-bless { display: block; font-size: 28rpx; font-weight: 800; }
.rp-sub { display: block; margin-top: 6rpx; font-size: 22rpx; opacity: 0.9; }
.emoji-panel {
  height: 360rpx;
  background: #fffaf5;
  border-top: 1px solid #eee;
}
.emoji-scroll { height: 100%; }
.emoji-grid {
  display: flex;
  flex-wrap: wrap;
  padding: 12rpx 8rpx 24rpx;
}
.sticker-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12rpx;
  padding: 12rpx 12rpx 22rpx;
}
.sticker-item {
  background: #fff;
  border-radius: 12rpx;
  padding: 10rpx 8rpx;
}
.sticker-pick {
  width: 100%;
  height: 100rpx;
}
.sticker-code {
  display: block;
  margin-top: 4rpx;
  font-size: 20rpx;
  color: #8a7a6e;
  text-align: center;
}
.emoji-item {
  width: 12.5%;
  text-align: center;
  font-size: 40rpx;
  line-height: 72rpx;
}
.composer {
  display: flex;
  gap: 12rpx;
  padding: 16rpx;
  background: #fff;
  border-top: 1px solid #eee;
  align-items: center;
}
.tool {
  margin: 0;
  background: #fff8f0;
  color: #c45a1a;
  border: 1px solid #f0b04a;
}
.tool.icon {
  width: 58rpx;
  min-width: 58rpx;
  padding: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 30rpx;
}
.input {
  flex: 1;
  height: 72rpx;
  background: #f7f2ec;
  border-radius: 12rpx;
  padding: 0 20rpx;
}
.send { margin: 0; background: #c61114; color: #fff; }
.mask {
  position: fixed;
  inset: 0;
  z-index: 100;
  background: rgba(20, 12, 8, 0.45);
  display: flex;
  align-items: flex-end;
  justify-content: center;
}
.sheet {
  width: 100%;
  max-width: 720rpx;
  background: #fffaf5;
  border-radius: 24rpx 24rpx 0 0;
  padding: 28rpx 28rpx 40rpx;
  box-sizing: border-box;
}
.sheet.detail { max-height: 80vh; }
.sheet-title {
  font-size: 32rpx;
  font-weight: 800;
  text-align: center;
  margin-bottom: 20rpx;
}
.field { margin-bottom: 18rpx; }
.lab {
  display: block;
  font-size: 24rpx;
  color: #8a7a6e;
  margin-bottom: 8rpx;
  font-weight: 700;
}
.hb-input {
  width: 100%;
  box-sizing: border-box;
  background: #fff;
  border: 1.5px solid #f0b04a;
  border-radius: 12rpx;
  padding: 16rpx 20rpx;
  font-size: 28rpx;
}
.tabs { display: flex; gap: 12rpx; }
.tab {
  flex: 1;
  text-align: center;
  padding: 14rpx 0;
  border-radius: 12rpx;
  background: #fff8f0;
  border: 1.5px solid #f0b04a;
  color: #8a4f1f;
  font-size: 24rpx;
  font-weight: 700;
}
.tab.on {
  color: #c61114;
  border-color: #c61114;
  background: #fff;
}
.btn-uid-submit {
  width: 100%;
  margin-top: 8rpx;
  background: linear-gradient(#fff, #fff) padding-box,
    linear-gradient(145deg, #ffe9b0, #f0b04a, #e07a22) border-box;
  border: 1.5px solid transparent;
  color: #3d2e22;
  font-weight: 800;
  border-radius: 14rpx;
  padding: 18rpx;
}
.cancel {
  margin-top: 16rpx;
  background: #fff;
  color: #6a5648;
  border: 1px solid rgba(180, 140, 100, 0.35);
  border-radius: 12rpx;
}
.detail-head { text-align: center; margin-bottom: 16rpx; }
.d-bless { display: block; font-size: 30rpx; font-weight: 800; }
.d-amt { display: block; margin-top: 8rpx; color: #c61114; font-size: 36rpx; font-weight: 800; }
.d-meta { display: block; margin-top: 8rpx; color: #9a8574; font-size: 22rpx; }
.d-fair-tip { display: block; margin-top: 10rpx; color: #b08a60; font-size: 22rpx; }
.d-fair-btn {
  margin-top: 14rpx;
  background: linear-gradient(135deg, #ffe082, #ffb300);
  color: #8a4b00;
  font-weight: 800;
  font-size: 26rpx;
  border-radius: 999rpx;
  padding: 12rpx 28rpx;
  border: none;
  line-height: 1.3;
}
.detail-list { max-height: 420rpx; margin-bottom: 12rpx; }
.d-row {
  display: flex;
  align-items: center;
  gap: 16rpx;
  padding: 16rpx 4rpx;
  border-bottom: 1px solid rgba(224, 122, 34, 0.12);
  font-size: 26rpx;
}
.d-av {
  width: 64rpx;
  height: 64rpx;
  border-radius: 50%;
  flex-shrink: 0;
  background: #f3e6d8;
}
.d-av-fb {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #8a4b00;
  font-weight: 800;
  background: linear-gradient(135deg, #ffe082, #ffb300);
}
.d-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4rpx; }
.d-nick { font-weight: 700; color: #3d2e22; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.d-time { font-size: 20rpx; color: #9a8574; }
.d-right { font-weight: 700; color: #c61114; flex-shrink: 0; }
.empty { text-align: center; color: #9a8574; padding: 24rpx; font-size: 24rpx; }
.more-sub {
  text-align: center;
  color: #8a7a6e;
  font-size: 24rpx;
  margin-bottom: 20rpx;
}
</style>

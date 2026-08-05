<template>
  <view class="chat-room-page">
    <view class="chat-room-pane open">
      <view class="chat-hero-hd">
        <view class="chat-hero-back" @click="goBack">
          <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true">
            <path fill="currentColor" d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6 6 6 1.41-1.41z" />
          </svg>
        </view>
        <view class="chat-hero-title chat-room-title">{{ title }}</view>
        <view class="chat-hero-more" @click="openMore">
          <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
            <circle cx="6" cy="12" r="1.8" fill="currentColor" />
            <circle cx="12" cy="12" r="1.8" fill="currentColor" />
            <circle cx="18" cy="12" r="1.8" fill="currentColor" />
          </svg>
        </view>
      </view>

      <view class="chat-room-main">
        <scroll-view
          scroll-y
          class="chat-msg-scroll"
          :scroll-into-view="scrollInto"
          :scroll-top="scrollTop"
          scroll-with-animation
          @click="closePanels"
        >
          <view
            v-for="m in messages"
            :id="'m' + msgId(m)"
            :key="msgId(m)"
            class="chat-msg-row"
            :class="{ me: isMine(m), system: isSysRow(m), 'group-msg': showSender(m) }"
          >
            <view v-if="isSysRow(m)" class="sys-notice">
              <view class="notice-inner">{{ sysText(m) }}</view>
            </view>
            <template v-else>
              <view class="chat-msg-avatar locked">
                <image :src="msgAvatar(m)" mode="aspectFill" />
              </view>
              <view class="chat-msg-main">
                <view v-if="showSender(m)" class="chat-msg-nick locked">{{ senderName(m) }}</view>

                <!-- 红宝：对齐 888 bubble-rp 结构 -->
                <view
                  v-if="isRp(m)"
                  class="chat-rp-card bubble-rp"
                  :class="rpCardClass(m)"
                  @click="onRpTap(m)"
                  @longpress="onMsgLongPress(m)"
                >
                  <view class="rp-top">
                    <view class="rp-icon-box">
                      <svg class="rp-env-svg" viewBox="0 0 40 46" aria-hidden="true">
                        <rect x="4" y="12" width="32" height="30" rx="3.2" fill="#F8E2A8" />
                        <path d="M4 15.2L20 27.2L36 15.2V13.2c0-1.9-1.4-3.2-3.2-3.2H7.2C5.4 10 4 11.3 4 13.2v2z" fill="#E3B268" />
                        <path d="M4 15.2L20 27.2L36 15.2" stroke="rgba(138,100,39,.35)" stroke-width="1" fill="none" />
                        <circle cx="20" cy="25" r="8.2" fill="#C61114" />
                        <circle cx="20" cy="25" r="8.2" fill="none" stroke="rgba(253,228,179,.85)" stroke-width="1.2" />
                        <text x="20" y="28.2" text-anchor="middle" font-size="9.5" font-weight="800" fill="#FDE4B3">開</text>
                      </svg>
                    </view>
                    <view class="rp-info">
                      <view class="rp-title">{{ rpTitle(m) }}</view>
                      <view class="rp-desc">
                        <text class="rp-amt"><text class="rp-yen">¥</text>{{ rpAmount(m) }}</text>
                        <text v-if="rpCount(m)" class="rp-cnt">{{ rpCount(m) }}个</text>
                      </view>
                    </view>
                    <view v-if="rpMineDigit(m) != null" class="rp-mine-badge">
                      <text class="rp-mine-badge-lab">雷</text>
                      <text class="rp-mine-badge-num">{{ rpMineDigit(m) }}</text>
                    </view>
                  </view>
                  <view class="rp-ribbon" />
                  <view class="rp-bottom">
                    <text class="rp-bottom-lab">{{ rpBottomLab(m) }}</text>
                    <text class="rp-time">{{ msgTime(m) }}</text>
                  </view>
                </view>

                <view v-else-if="isSticker(m)" class="chat-bubble sticker" @longpress="onMsgLongPress(m)">
                  <image class="chat-sticker-img" :src="stickerUrl(m)" mode="widthFix" />
                  <text class="meta">{{ msgTime(m) }}</text>
                </view>
                <view v-else-if="isImage(m)" class="chat-bubble media" @longpress="onMsgLongPress(m)">
                  <image class="chat-media-img" :src="mediaUrl(m)" mode="widthFix" @click.stop="previewImageMsg(m)" />
                  <text class="meta">{{ msgTime(m) }}</text>
                </view>
                <view v-else-if="isVideo(m)" class="chat-bubble media" @longpress="onMsgLongPress(m)">
                  <video class="chat-media-video" :src="mediaUrl(m)" controls playsinline />
                  <text class="meta">{{ msgTime(m) }}</text>
                </view>
                <view v-else-if="isFile(m)" class="chat-bubble media file" @longpress="onMsgLongPress(m)" @click="openFileMsg(m)">
                  <text class="file-name">{{ fileName(m) }}</text>
                  <text class="file-ext">{{ fileMeta(m) }}</text>
                  <text class="meta">{{ msgTime(m) }}</text>
                </view>
                <view v-else class="chat-bubble text-msg" @longpress="onMsgLongPress(m)">
                  <text class="content">{{ msgText(m) }}</text>
                  <text class="meta">{{ msgTime(m) }}</text>
                </view>
              </view>
            </template>
          </view>
        </scroll-view>

        <view class="chat-composer-wrap">
          <view class="chat-emoji-panel" :class="{ open: showEmoji || showSticker }">
            <view class="chat-expr-mode-tabs">
              <view class="chat-expr-mode-btn" :class="{ active: showEmoji && !showSticker }" @click="openEmojiOnly">表情</view>
              <view class="chat-expr-mode-btn" :class="{ active: showSticker }" @click="openStickerPanel">表情包</view>
            </view>
            <scroll-view v-if="showEmoji && !showSticker" scroll-y class="emoji-scroll">
              <view class="emoji-grid">
                <text
                  v-for="(em, idx) in emojis"
                  :key="'em' + idx"
                  class="emoji-item"
                  @click="insertEmoji(em)"
                >{{ em }}</text>
              </view>
            </scroll-view>
            <scroll-view v-else scroll-y class="emoji-scroll">
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
              <view v-if="!stickerItems.length" class="empty" style="padding:16px;text-align:center;color:#999">暂无表情包</view>
            </scroll-view>
          </view>

          <view class="chat-attach-panel" :class="{ open: showAttach }">
            <view class="chat-attach-item" @click="onAttachPick('image')">
              <text class="chat-attach-icon">🖼️</text>
              <text>图片</text>
            </view>
            <view class="chat-attach-item" @click="onAttachPick('video')">
              <text class="chat-attach-icon">🎬</text>
              <text>视频</text>
            </view>
            <view class="chat-attach-item" @click="onAttachPick('rp')">
              <text class="chat-attach-icon">🧧</text>
              <text>红包</text>
            </view>
          </view>

          <view class="chat-composer chat-footer">
            <view class="chat-tool-icon" @click="toggleEmoji">
              <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8">
                <circle cx="12" cy="12" r="10" />
                <path d="M8 14s1.5 2 4 2 4-2 4-2" />
                <line x1="9" y1="9" x2="9.01" y2="9" />
                <line x1="15" y1="9" x2="15.01" y2="9" />
              </svg>
            </view>
            <input
              class="input-box"
              v-model="text"
              confirm-type="send"
              maxlength="2000"
              placeholder="输入消息…"
              @confirm="sendText"
              @focus="onInputFocus"
            />
            <view class="btn-plus" :class="{ active: showAttach }" @click="toggleAttach">
              <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
                <path fill="currentColor" d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" />
              </svg>
            </view>
            <view class="chat-send-btn" @click="sendText">发送</view>
          </view>
        </view>
      </view>
    </view>

    <!-- 发红宝：完全照搬 888 chatRpSendPane -->
    <view class="chat-rp-send-pane" :class="{ open: showRp }" :aria-hidden="showRp ? 'false' : 'true'">
      <view class="chat-hero-hd">
        <view id="chatRpCancelBtn" class="chat-hero-back chat-rp-cancel" @click="closeRpSend">{{ rpCancelLabel }}</view>
        <view class="chat-hero-title">{{ rpTitleLabel }}</view>
        <view class="chat-hero-spacer" />
      </view>
      <view class="chat-rp-send-main">
        <scroll-view scroll-y class="chat-rp-send-body">
          <view class="chat-rp-preview">
            <view class="chat-rp-preview-seal">红</view>
            <view class="chat-rp-preview-bless">{{ rpPreviewBless }}</view>
            <view class="chat-rp-preview-sub">{{ rpPreviewSub }}</view>
          </view>

          <view class="chat-rp-balance-hint">
            <text>{{ rpBalanceHintLabel }}</text>
            <text class="chat-rp-bal-strong">￥{{ money(walletBalance) }}</text>
            <text v-if="walletFrozen > 0.00001" class="chat-rp-frozen-hint">
              · {{ rpFrozenHintLabel }} <text class="chat-rp-bal-strong">￥{{ money(walletFrozen) }}</text>
            </text>
          </view>

          <view class="chat-rp-form">
            <view class="chat-rp-field chat-rp-field--amount">
              <text class="chat-rp-lab">{{ rpAmountLabel }}</text>
              <view class="chat-rp-amount-row">
                <text class="chat-rp-yuan">￥</text>
                <input
                  class="chat-rp-amount-input"
                  type="digit"
                  v-model="rpForm.total_amount"
                  :placeholder="rpAmountPh"
                />
              </view>
            </view>

            <view v-if="!isPrivate" class="chat-rp-field" id="chatRpCountWrap">
              <text class="chat-rp-lab">{{ rpCountLabel }}</text>
              <view v-if="rpForm.packet_type === 3" class="chat-rp-count-tabs">
                <view
                  v-for="n in mineCountOptions"
                  :key="'c' + n"
                  class="chat-rp-count-btn"
                  :class="{ active: Number(rpForm.total_count) === n }"
                  @click="rpForm.total_count = String(n)"
                >{{ n }}</view>
              </view>
              <view v-else class="chat-rp-inline-ctrl">
                <input class="chat-rp-count-input" type="digit" v-model="rpForm.total_count" placeholder="5-10" />
                <text class="chat-rp-unit">个</text>
              </view>
              <view class="chat-rp-field-hint">{{ rpCountHintLabel }}</view>
            </view>

            <view v-if="!isPrivate" class="chat-rp-field chat-rp-field--type">
              <text class="chat-rp-lab">{{ rpTypeLabel }}</text>
              <view class="chat-rp-type-tabs">
                <view
                  v-for="tItem in packetTypes"
                  :key="'t' + tItem.v"
                  class="chat-rp-type-btn"
                  :class="{ active: rpForm.packet_type === tItem.v }"
                  @click="setRpType(tItem.v)"
                >{{ tItem.n }}</view>
              </view>
              <view v-if="rpTypeDesc" class="chat-rp-field-hint chat-rp-type-desc">{{ rpTypeDesc }}</view>
            </view>

            <view v-if="!isPrivate && rpForm.packet_type === 3" class="chat-rp-field chat-rp-mine-card">
              <view class="chat-rp-mine-title">{{ rpMineDigitLabel }}</view>
              <view class="chat-rp-mine-digits">
                <view
                  v-for="d in 10"
                  :key="'d' + (d - 1)"
                  class="chat-rp-mine-digit-btn"
                  :class="{ active: Number(rpForm.mine_digit) === d - 1 }"
                  @click="rpForm.mine_digit = String(d - 1)"
                >{{ d - 1 }}</view>
              </view>
              <view class="chat-rp-field-hint">{{ rpMineHintLabel }}</view>
            </view>

            <view class="chat-rp-field">
              <text class="chat-rp-lab">{{ rpBlessingLabel }}</text>
              <input
                class="chat-rp-bless-input"
                v-model="rpForm.blessing"
                maxlength="40"
                :placeholder="rpBlessingPh"
              />
            </view>
          </view>
        </scroll-view>
        <view class="chat-rp-send-ft">
          <view class="chat-rp-submit-btn" :class="{ disabled: rpSending }" @click="sendRp">
            {{ rpSending ? rpSendingLabel : rpSubmitLabel }}
          </view>
        </view>
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
          <button v-if="canFairVerify" class="d-fair-btn" @click="openFairVerify">查询验证</button>
        </view>
        <scroll-view scroll-y class="detail-list">
          <view v-for="r in detailRecords" :key="r.id || r.user_id" class="d-row">
            <image class="d-av" :src="avatarSrc(r.avatar)" mode="aspectFill" />
            <view class="d-main">
              <text class="d-nick">{{ r.nickname || ('用户' + (r.user_id || '')) }}</text>
              <text class="d-time" v-if="r.createtime">{{ formatRpTime(r.createtime) }}</text>
            </view>
            <text class="d-right">{{ formatAmt(r.amount) }}</text>
          </view>
          <view v-if="!detailRecords.length" class="empty">{{ claimsEmptyTip }}</view>
        </scroll-view>
        <button v-if="canGrabDetail" class="btn-uid-submit" :disabled="grabbing" @click="grabFromDetail">
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
import { computed, nextTick, reactive, ref } from 'vue'
import { onLoad, onUnload } from '@dcloudio/uni-app'
import GrabSlider from '../../components/GrabSlider.vue'
import '../../styles/chat.bundle.css'
import '../../styles/chat-room-uni-adapter.css'
import '../../styles/chat-rp-send-uni-adapter.css'
import { apiRequest, fetchProfile, getToken } from '../../utils/auth.js'
import { getApiBase } from '../../utils/config.js'
import { assetBase, applyServerCopy, copyState, localeState, tt } from '../../utils/i18n.js'
import {
  avatarSrc,
  isRecalled,
  isSystemMsg,
  msgExtra,
  msgType,
  packetTypeLabel,
  recallTip,
} from '../../utils/chat.js'
import { clearActiveChat, getActiveChat, saveActiveChat } from '../../utils/chat-route.js'
import { COMMON_EMOJIS } from '../../utils/emoji.js'
import { loadWalletBootstrap, money } from '../../utils/wallet.js'
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
const scrollTop = ref(0)
const meta = ref({ type: 1, peer: 0, group: 0, conversationId: '' })
const myAvatar = ref('')
const myUserId = ref(0)
const showRp = ref(false)
const showEmoji = ref(false)
const showSticker = ref(false)
const showAttach = ref(false)
const walletBalance = ref(0)
const walletFrozen = ref(0)
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
const locale = localeState()
const copyTick = copyState()

function rpT(key, fallback) {
  void locale.value
  void copyTick.value
  return tt(key, fallback)
}

const rpCancelLabel = computed(() => rpT('chat_cancel', '取消'))
const rpTitleLabel = computed(() => rpT('chat_rp_title', '发红宝'))
const rpBalanceHintLabel = computed(() => rpT('chat_rp_balance_hint', '可用红宝：'))
const rpFrozenHintLabel = computed(() => rpT('chat_rp_frozen_hint', '冻结'))
const rpAmountLabel = computed(() => rpT('chat_rp_amount_label', '金额'))
const rpAmountPh = computed(() => rpT('chat_rp_amount_ph', '0.00'))
const rpCountLabel = computed(() => rpT('chat_rp_count_label', '个数'))
const rpCountHintLabel = computed(() => rpT('chat_rp_count_hint', '按本群配置显示个数'))
const rpTypeLabel = computed(() => rpT('chat_rp_type_label', '类型'))
const rpMineDigitLabel = computed(() => rpT('chat_rp_mine_digit', '埋雷数字（0～9）'))
const rpMineHintLabel = computed(() =>
  rpT(
    'chat_rp_mine_hint',
    '手填雷号；开奖后匹配哈希末位相同的波场区块作证明。金额尾数等于雷号即中雷，可多人同时中雷。'
  )
)
const rpBlessingLabel = computed(() => rpT('chat_rp_blessing_label', '祝福语'))
const rpBlessingPh = computed(() => rpT('chat_rp_blessing_ph', '恭喜发财，大吉大利'))
const rpBlessingDefault = computed(() => rpT('chat_rp_blessing_default', '恭喜发财'))
const rpSubmitLabel = computed(() => rpT('chat_rp_submit', '塞钱进红包'))
const rpSendingLabel = computed(() => rpT('chat_rp_sending', '发送中…'))

const packetTypes = computed(() => [
  { v: 2, n: rpT('chat_rp_type_lucky', '拼手气') },
  { v: 5, n: rpT('chat_rp_type_relay', '接龙') },
  { v: 3, n: rpT('chat_rp_type_mine', '埋雷') },
  { v: 1, n: rpT('chat_rp_type_avg', '普通红宝') },
  { v: 4, n: rpT('chat_rp_type_random', '随机红宝') },
])
const mineCountOptions = [5, 7, 9]
const rpForm = reactive({
  packet_type: 2,
  total_amount: '',
  total_count: '5',
  mine_digit: '0',
  blessing: '恭喜发财',
})

const rpPreviewBless = computed(() => String(rpForm.blessing || '').trim() || rpBlessingDefault.value)
const rpPreviewSub = computed(() => {
  if (isPrivate.value) return rpT('chat_rp_type_avg', '普通红宝')
  const map = {
    1: rpT('chat_rp_type_avg', '普通红宝'),
    2: rpT('chat_rp_lucky_sub', '拼手气红宝'),
    3: rpT('chat_rp_type_mine', '埋雷') + '红包',
    4: rpT('chat_rp_type_random', '随机红宝'),
    5: rpT('chat_rp_type_relay', '接龙') + '红包',
  }
  return map[rpForm.packet_type | 0] || '红包'
})
/** 对齐 888：群聊仅拼手气/接龙展示多语言简介 */
const rpTypeDesc = computed(() => {
  if (isPrivate.value) return ''
  const type = rpForm.packet_type | 0
  if (type === 2) {
    return rpT(
      'chat_rp_type_lucky_desc',
      '拼手气：发包人可自领；领完后金额最少者赔付该包总额（同额取最晚）；发包人最少不赔。'
    )
  }
  if (type === 5) {
    return rpT(
      'chat_rp_type_relay_desc',
      '接龙：抢到金额直接进可用余额；抢光后最少者扣整包金额自动发下一包；30分钟未抢完则已领保留、未领退回。'
    )
  }
  return ''
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
  const uid = (myUserId.value | 0) || (myId | 0)
  if (uid && m && (m.from_user_id | 0) === uid) return true
  return !!(m && m.is_mine)
}

function msgAvatar(m) {
  if (isMine(m)) return avatarSrc(myAvatar.value)
  const fu = (m && m.from_user) || {}
  return avatarSrc((m && (m.from_avatar || fu.avatar)) || '')
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
function rpTitle(m) {
  const ex = msgExtra(m)
  const ptype = ex.packet_type != null ? (ex.packet_type | 0) : 2
  const fixed = ({ 2: '红宝拼手气', 3: '红宝扫雷', 5: '红宝接龙' })[ptype] || ''
  return fixed || ex.blessing || (m && m.content) || '恭喜发财，大吉大利'
}
function rpAmount(m) {
  const ex = msgExtra(m)
  const amt = ex.total_amount != null ? parseFloat(ex.total_amount) : NaN
  if (!isNaN(amt) && amt > 0) return amt.toFixed(2)
  return '红包'
}
function rpCount(m) {
  const ex = msgExtra(m)
  const cnt = ex.total_count != null ? (ex.total_count | 0) : 0
  const amt = ex.total_amount != null ? parseFloat(ex.total_amount) : NaN
  if (!isNaN(amt) && amt > 0 && cnt > 0) return cnt
  return 0
}
function rpMineDigit(m) {
  const ex = msgExtra(m)
  const ptype = ex.packet_type != null ? (ex.packet_type | 0) : 0
  if (ptype !== 3) return null
  const raw = ex.mine_digit
  if (raw == null || raw === '') return null
  let mine = parseInt(raw, 10)
  if (!isFinite(mine) || mine < 0 || mine > 9) mine = 0
  return mine
}
function rpExpired(m) {
  const ex = msgExtra(m)
  if (ex.cover_expired) return true
  const exp = ex.expiretime | 0
  if (exp > 0) return Math.floor(Date.now() / 1000) >= exp
  return false
}
function rpGrabbed(m) {
  return !!msgExtra(m).cover_grabbed
}
function rpFaded(m) {
  const ex = msgExtra(m)
  return !!(ex.cover_faded || ex.cover_expired || ex.cover_grabbed || rpExpired(m))
}
function rpBottomLab(m) {
  const ex = msgExtra(m)
  const ptype = ex.packet_type != null ? (ex.packet_type | 0) : 2
  const pending = !!ex.mine_pending
  if (rpGrabbed(m)) return '已领取'
  if (rpExpired(m)) return '已过期'
  if (ptype === 3) return pending ? '红宝扫雷 · 匹配中' : '红宝扫雷'
  if (ptype === 5) return '红宝接龙'
  if (ptype === 2) return '红宝拼手气'
  if (ptype === 4) return '随机红宝'
  if (ptype === 1) return '普通红宝'
  if (ex.mode_label) return String(ex.mode_label)
  return '红包福利'
}
function rpCardClass(m) {
  const ex = msgExtra(m)
  const ptype = ex.packet_type != null ? (ex.packet_type | 0) : 2
  return {
    'is-mine': ptype === 3,
    'is-faded': rpFaded(m),
    'is-grabbed': rpGrabbed(m),
    'is-expired': rpExpired(m),
  }
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
  if (s.startsWith('/999/') || s.startsWith('/888/')) return s
  if (s.startsWith('/')) return s
  if (s.startsWith('static/')) return assetBase() + s
  if (s.startsWith('stickers/')) return assetBase() + 'static/' + s
  return assetBase() + 'static/' + s.replace(/^\/+/, '')
}
function stickerUrl(m) {
  const ex = msgExtra(m)
  const raw = (ex && (ex.url || ex.fullurl)) || ''
  if (raw) return normalizeStickerUrl(raw)
  return assetBase() + 'static/stickers/wechat/face/微笑.png'
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

function closePanels() {
  showEmoji.value = false
  showSticker.value = false
  showAttach.value = false
}

function onInputFocus() {
  showEmoji.value = false
  showSticker.value = false
  showAttach.value = false
}

function toggleEmoji() {
  const next = !(showEmoji.value && !showSticker.value)
  showEmoji.value = next
  showSticker.value = false
  if (next) {
    showAttach.value = false
    showRp.value = false
  }
}

function openEmojiOnly() {
  showEmoji.value = true
  showSticker.value = false
  showAttach.value = false
}

function toggleAttach() {
  const next = !showAttach.value
  showAttach.value = next
  if (next) {
    showEmoji.value = false
    showSticker.value = false
  }
}

function onAttachPick(kind) {
  showAttach.value = false
  if (kind === 'image') pickImage()
  else if (kind === 'video') pickVideo()
  else if (kind === 'rp') openRpSend()
}

function setRpType(v) {
  rpForm.packet_type = v | 0
  if (rpForm.packet_type === 3) {
    const cur = Number(rpForm.total_count)
    if (mineCountOptions.indexOf(cur) < 0) rpForm.total_count = '5'
  }
}

function closeRpSend() {
  showRp.value = false
}

async function openRpSend() {
  showAttach.value = false
  showEmoji.value = false
  showSticker.value = false
  if (isPrivate.value) {
    rpForm.packet_type = 1
    rpForm.total_count = '1'
  } else if (!rpForm.packet_type) {
    rpForm.packet_type = 2
  }
  if (!String(rpForm.blessing || '').trim()) rpForm.blessing = rpBlessingDefault.value
  try {
    const cfg = await apiRequest('config', 'GET')
    if (cfg && cfg.copy) applyServerCopy(cfg.copy)
  } catch (e) {}
  await refreshWallet()
  showRp.value = true
}

function openRpSheet() {
  openRpSend()
}

async function openStickerPanel() {
  showAttach.value = false
  showEmoji.value = true
  showSticker.value = true
  if (!stickerItems.value.length) await loadStickers()
}

function insertEmoji(em) {
  text.value = String(text.value || '') + em
}

async function toggleSticker() {
  await openStickerPanel()
}

async function refreshWallet() {
  try {
    const boot = await loadWalletBootstrap(true)
    const info = (boot && boot.info) || boot || {}
    walletBalance.value = Number(info.hongbao != null ? info.hongbao : info.balance) || 0
    walletFrozen.value = Number(info.hongbao_frozen) || 0
  } catch (e) {
    try {
      const p = await fetchProfile()
      walletBalance.value = Number(p && (p.hongbao != null ? p.hongbao : p.money)) || 0
      walletFrozen.value = Number(p && p.hongbao_frozen) || 0
    } catch (e2) {}
  }
}

function scrollToLatest() {
  const last = messages.value[messages.value.length - 1]
  if (!last) return
  const id = 'm' + msgId(last)
  scrollInto.value = ''
  scrollTop.value = scrollTop.value === 999999 ? 999998 : 999999
  nextTick(() => {
    scrollInto.value = id
    setTimeout(() => {
      scrollInto.value = id
      scrollTop.value = scrollTop.value === 999999 ? 999998 : 999999
    }, 60)
    setTimeout(() => {
      scrollInto.value = id
    }, 200)
  })
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
        url: assetBase() + 'static/data/stickers.json',
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
    myUserId.value = myId
    myAvatar.value = (p && (p.avatar_url || p.avatar)) || ''
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
    limit: 50,
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
  await markRead()
  scrollToLatest()
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
    showAttach.value = false
    await fetchHistory()
  } catch (e) {
    uni.showToast({ title: e.message || '发送失败', icon: 'none' })
  }
}

async function sendRp() {
  if (rpSending.value) return
  const amount = Number(rpForm.total_amount)
  if (!(amount > 0)) {
    uni.showToast({ title: '请输入红包金额', icon: 'none' })
    return
  }
  if (amount < 10) {
    uni.showToast({ title: '金额最低 10 元', icon: 'none' })
    return
  }
  if (walletBalance.value > 0 && amount > walletBalance.value + 0.0001) {
    uni.showToast({ title: '红宝不足', icon: 'none' })
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
        uni.showToast({ title: '请选择埋雷数字 0～9', icon: 'none' })
        return
      }
      payload.mine_digit = dig
      if (mineCountOptions.indexOf(payload.total_count) < 0) {
        uni.showToast({ title: '扫雷红包个数仅可选 5 / 7 / 9', icon: 'none' })
        return
      }
    }
  }
  rpSending.value = true
  try {
    await sendRedPacket(payload)
    showRp.value = false
    uni.showToast({ title: '红包已发送', icon: 'success' })
    await refreshWallet()
    await fetchHistory()
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '发红宝失败', icon: 'none' })
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
  if (isPrivate.value) {
    rpForm.packet_type = 1
    rpForm.total_count = '1'
  } else {
    rpForm.packet_type = 2
    rpForm.total_count = '5'
  }

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
          scrollToLatest()
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
/* 房间布局/气泡/输入栏由 chat.bundle + chat-room-uni-adapter 负责；此处仅弹层 */
.mask {
  position: fixed;
  inset: 0;
  z-index: 20000;
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

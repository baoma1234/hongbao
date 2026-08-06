<template>
  <view class="chat-room-page">
    <TopBar :no-spacer="true" />
    <view class="chat-room-pane open">
      <view class="chat-hero-hd">
        <view class="chat-hero-back" hover-class="chat-hero-back--active" @click="goBack">
          <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" class="chat-hero-ico">
            <path fill="currentColor" d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6 6 6 1.41-1.41z" />
          </svg>
        </view>
        <view class="chat-hero-title chat-room-title">{{ title }}</view>
        <view class="chat-hero-more" hover-class="chat-hero-back--active" @click="openMore">
          <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" class="chat-hero-ico">
            <circle cx="6" cy="12" r="1.8" fill="currentColor" />
            <circle cx="12" cy="12" r="1.8" fill="currentColor" />
            <circle cx="18" cy="12" r="1.8" fill="currentColor" />
          </svg>
        </view>
      </view>

      <view class="chat-room-main">
        <view
          v-if="noticePinVisible"
          class="chat-notice-pin"
          :class="{ 'is-expanded': noticePinExpanded }"
          @click="toggleNoticePinExpand"
        >
          <text class="chat-notice-pin-icon">📢</text>
          <view class="chat-notice-pin-body">
            <view class="chat-notice-pin-label">群公告</view>
            <view v-if="noticePinText" class="chat-notice-pin-text">{{ noticePinText }}</view>
            <view v-if="noticePinImages.length" class="chat-notice-pin-imgs">
              <view
                v-for="(src, idx) in noticePinImages"
                :key="'np' + idx"
                class="chat-notice-pin-img"
                @click.stop="previewNoticeImage(src)"
              >
                <image :src="src" mode="aspectFill" />
              </view>
            </view>
          </view>
          <view class="chat-notice-pin-close" @click.stop="dismissNoticePin">×</view>
        </view>
        <scroll-view
          scroll-y
          class="chat-msg-scroll"
          :scroll-into-view="scrollInto"
          :scroll-top="scrollTop"
          :scroll-with-animation="false"
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

                <view
                  v-else-if="isTransfer(m)"
                  class="chat-transfer-card"
                  :class="{ me: isMine(m) }"
                  @longpress="onMsgLongPress(m)"
                >
                  <view class="tf-top">
                    <view class="tf-icon" aria-hidden="true">💸</view>
                    <view class="tf-info">
                      <view class="tf-amt"><text class="tf-yen">¥</text>{{ transferAmount(m) }}</view>
                      <view class="tf-title">{{ transferTitle(m) }}</view>
                    </view>
                  </view>
                  <view class="tf-bottom">
                    <text class="tf-lab">转账</text>
                    <text class="tf-time">{{ msgTime(m) }}</text>
                  </view>
                </view>

                <view v-else-if="isSticker(m)" class="chat-bubble sticker" @longpress="onMsgLongPress(m)">
                  <image class="chat-sticker-img" :src="stickerUrl(m)" mode="aspectFit" />
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
          <view id="chat-bottom-anchor" class="chat-bottom-anchor" />
        </scroll-view>

        <view
          class="chat-composer-wrap"
          :class="{ 'is-muted': composerLocked, 'is-extras-locked': extrasLocked }"
        >
          <view class="chat-emoji-panel" :class="{ open: showEmoji || showSticker }">
            <view class="chat-expr-mode-tabs">
              <view class="chat-expr-mode-btn" :class="{ active: showEmoji && !showSticker }" @click="openEmojiOnly">表情</view>
              <view class="chat-expr-mode-btn" :class="{ active: showSticker }" @click="openStickerPanel">表情包</view>
            </view>
            <scroll-view v-if="showEmoji && !showSticker" scroll-y class="emoji-scroll">
              <scroll-view v-if="emojiGroups.length > 1" scroll-x class="emoji-tabs-row">
                <view
                  v-for="(g, gi) in emojiGroups"
                  :key="g.id"
                  class="emoji-tab-chip"
                  :class="{ active: emojiGroupIdx === gi }"
                  @click="emojiGroupIdx = gi"
                >{{ g.name }}</view>
              </scroll-view>
              <view class="emoji-grid">
                <text
                  v-for="(em, idx) in activeEmojis"
                  :key="'em' + idx"
                  class="emoji-item"
                  @click="insertEmoji(em)"
                >{{ em }}</text>
              </view>
            </scroll-view>
            <scroll-view v-else scroll-y class="emoji-scroll">
              <view class="sticker-upload-bar">
                <button type="button" class="sticker-upload-btn" :disabled="stickerUploading" @click="uploadCustomSticker">
                  {{ stickerUploading ? '上传中…' : '＋ 上传表情' }}
                </button>
                <text class="sticker-quota">{{ stickerQuotaText }}</text>
              </view>
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
            <view v-if="canCap('image')" class="chat-attach-item" @click="onAttachPick('image')">
              <text class="chat-attach-icon">🖼️</text>
              <text>图片</text>
            </view>
            <view v-if="canCap('video')" class="chat-attach-item" @click="onAttachPick('video')">
              <text class="chat-attach-icon">🎬</text>
              <text>视频</text>
            </view>
            <view v-if="canCap('file')" class="chat-attach-item" @click="onAttachPick('file')">
              <text class="chat-attach-icon">📎</text>
              <text>文件</text>
            </view>
            <view v-if="canCap('rp')" class="chat-attach-item" @click="onAttachPick('rp')">
              <text class="chat-attach-icon">🧧</text>
              <text>红包</text>
            </view>
            <view v-if="isPrivate" class="chat-attach-item" @click="onAttachPick('transfer')">
              <text class="chat-attach-icon">💸</text>
              <text>转账</text>
            </view>
          </view>

          <view class="chat-composer chat-footer">
            <view v-if="canCap('emoji')" id="chatEmojiBtn" class="chat-tool-icon" @click="toggleEmoji">
              <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8">
                <circle cx="12" cy="12" r="10" />
                <path d="M8 14s1.5 2 4 2 4-2 4-2" />
                <line x1="9" y1="9" x2="9.01" y2="9" />
                <line x1="15" y1="9" x2="15.01" y2="9" />
              </svg>
            </view>
            <input
              id="chatInput"
              class="input-box"
              v-model="text"
              confirm-type="send"
              maxlength="2000"
              :disabled="composerLocked || !canCap('text')"
              :placeholder="composerPlaceholder"
              @confirm="sendText"
              @focus="onInputFocus"
            />
            <view
              v-if="attachAllowed"
              id="chatAttachBtn"
              class="btn-plus"
              :class="{ active: showAttach }"
              @click="toggleAttach"
            >
              <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
                <path fill="currentColor" d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" />
              </svg>
            </view>
            <view
              id="chatSendBtn"
              class="chat-send-btn"
              :class="{ disabled: composerLocked || !canCap('text') }"
              @click="sendText"
            >发送</view>
          </view>
        </view>
      </view>
    </view>

    <!-- 发红宝：关闭时用 v-if 卸掉，避免固定层挡点击 -->
    <view v-if="showRp" class="chat-rp-send-pane open" aria-hidden="false">
      <view class="chat-hero-hd">
        <view class="chat-rp-cancel" hover-class="chat-rp-cancel--active" @click="closeRpSend">{{ rpCancelLabel }}</view>
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
                  :disabled="amountReadonly"
                />
              </view>
            </view>

            <view v-if="!isPrivate" class="chat-rp-field" id="chatRpCountWrap">
              <text class="chat-rp-lab">{{ rpCountLabel }}</text>
              <view
                v-if="rpForm.packet_type === 3"
                class="chat-rp-count-tabs"
                :class="{ 'is-single': mineCountOptions.length === 1 }"
              >
                <view
                  v-for="n in mineCountOptions"
                  :key="'c' + n"
                  class="chat-rp-count-btn"
                  :class="{ active: Number(rpForm.total_count) === n }"
                  @click="rpForm.total_count = String(n)"
                >{{ n }}</view>
              </view>
              <view
                v-else-if="rpCountOptions.length <= 8"
                class="chat-rp-count-tabs"
                :class="{ 'is-single': rpCountOptions.length === 1 }"
              >
                <view
                  v-for="n in rpCountOptions"
                  :key="'rc' + n"
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

    <!-- 私聊转账：对齐 888 #chatTransferSendPane -->
    <view v-if="showTransfer" class="chat-rp-send-pane chat-transfer-send-pane open" aria-hidden="false">
      <view class="chat-hero-hd">
        <view class="chat-rp-cancel" hover-class="chat-rp-cancel--active" @click="closeTransferSend">取消</view>
        <view class="chat-hero-title">转账</view>
        <view class="chat-hero-spacer" />
      </view>
      <view class="chat-rp-send-main">
        <scroll-view scroll-y class="chat-rp-send-body">
          <view class="chat-transfer-preview">
            <view class="chat-transfer-preview-icon">💸</view>
            <view class="chat-transfer-preview-lab">转账给对方</view>
            <view class="chat-transfer-preview-amt">￥{{ transferPreviewAmt }}</view>
          </view>
          <view class="chat-rp-balance-hint">
            <text>可用红宝：</text>
            <text class="chat-rp-bal-strong">￥{{ money(walletBalance) }}</text>
          </view>
          <view class="chat-rp-form">
            <view class="chat-rp-field chat-rp-field--amount">
              <text class="chat-rp-lab">金额</text>
              <view class="chat-rp-amount-row">
                <text class="chat-rp-yuan">￥</text>
                <input
                  class="chat-rp-amount-input"
                  type="digit"
                  v-model="transferForm.amount"
                  placeholder="0.00"
                />
              </view>
            </view>
            <view class="chat-rp-field">
              <text class="chat-rp-lab">备注</text>
              <input
                class="chat-rp-bless-input"
                v-model="transferForm.remark"
                maxlength="40"
                placeholder="可选填写"
              />
            </view>
          </view>
        </scroll-view>
        <view class="chat-rp-send-ft">
          <view
            class="chat-rp-submit-btn chat-transfer-submit-btn"
            :class="{ disabled: transferSending }"
            @click="submitTransfer"
          >{{ transferSending ? '转账中…' : '确认转账' }}</view>
        </view>
      </view>
    </view>

    <GrabSlider ref="grabSliderRef" />

    <!-- 红包详情：对齐 888 #chatRpDetailPane -->
    <view v-if="detailVisible" id="chatRpDetailPane" class="chat-sub-pane open" aria-hidden="false">
      <view class="chat-hero-hd">
        <view class="chat-hero-back" hover-class="chat-hero-back--active" @click="detailVisible = false">
          <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" class="chat-hero-ico">
            <path fill="currentColor" d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6 6 6 1.41-1.41z" />
          </svg>
        </view>
        <view class="chat-hero-title">红包详情</view>
        <view class="chat-hero-spacer" />
      </view>
      <view class="chat-sub-main">
        <view id="chatRpDetailBody">
          <view class="chat-rp-detail-head" v-if="detail">
            <view class="chat-rp-detail-bless">{{ detailBlessTitle }}</view>
            <view class="chat-rp-detail-meta">
              共 {{ (detail.packet && detail.packet.total_count) || 0 }} 个 · ￥{{ detailTotalAmt }}
            </view>
            <view
              v-if="detail.packet && detail.packet.createtime"
              class="chat-rp-detail-send-time"
            >发送时间 {{ formatRpTime(detail.packet.createtime) }}</view>
            <view v-if="detailMyAmtText" class="chat-rp-detail-myamt">
              你领取了 <text style="font-size:22px;font-weight:800">￥{{ detailMyAmtText }}</text>
            </view>
            <view v-if="detailMineDigitLine" class="chat-rp-detail-meta">
              埋雷数字：<text style="font-weight:800">{{ detailMineDigit }}</text>{{ detailMineDigitLine }}
            </view>
            <template v-if="canFairVerify && (detailFairHash || detailTronBlock)">
              <view class="chat-rp-fair-hash">
                <text class="chat-rp-fair-label">{{ detailFairLabel }}</text>
                <text style="display:block;font-size:10px;line-height:1.45;word-break:break-all;color:#333">
                  {{ detailFairHash || '开奖后公开' }}
                </text>
              </view>
              <view v-if="detailTronHref" class="chat-rp-tron-btn" @click="openTronVerify">
                {{ detailTronBlock ? '前往波场验证' : '查看锁定区块' }}
              </view>
              <button type="button" class="chat-rp-fair-link" @click="openFairVerify">本站验证详情</button>
            </template>
            <view v-else-if="detailFairTip" class="chat-rp-fair-sub">{{ detailFairTip }}</view>
            <view v-if="detailLocked" class="chat-rp-privacy-tip locked">🔒 隐私群：不可点击查看对方资料</view>
            <view
              v-if="mineSettleTip"
              class="chat-rp-mine-summary"
              :class="{ 'is-safe': mineSettleSafe }"
            >{{ mineSettleTip }}</view>
          </view>
          <scroll-view
            scroll-y
            class="chat-rp-detail-list"
            :class="{ 'is-open': !detailLocked, 'is-private': detailLocked }"
          >
            <view
              v-for="r in detailRecords"
              :key="r.id || r.user_id"
              class="chat-rp-record-item"
              :class="{ 'is-locked': recordGray(r) }"
            >
              <view
                class="chat-rp-record-avatar"
                :class="{ locked: detailLocked, 'is-gray': recordGray(r) }"
              >
                <image v-if="!recordGray(r)" :src="avatarSrc(r.avatar)" mode="aspectFill" />
                <text v-else>{{ (r.nickname || 'U').charAt(0) }}</text>
              </view>
              <view class="chat-rp-record-main">
                <view
                  class="chat-rp-record-name"
                  :class="{ locked: detailLocked, 'is-masked': recordGray(r) }"
                >
                  <text v-if="recordGray(r)" class="chat-rp-lock">🔒</text>
                  {{ r.nickname || ('用户' + (r.user_id || '')) }}
                </view>
                <view class="chat-rp-record-amt">
                  ￥{{ formatAmt(r.amount) }}
                  <text v-if="recordShowBest(r)"> 手气最佳</text>
                  <text v-if="recordShowWorst(r)"> 手气最差</text>
                  <text v-if="recordMineHit(r)" class="is-mine-hit"> 中雷</text>
                  <text v-else-if="recordMineSafe(r)" class="is-mine-safe"> 未中雷</text>
                  <text v-if="recordTail(r) != null"> · 尾{{ recordTail(r) }}</text>
                </view>
                <view v-if="r.createtime" class="chat-rp-record-time">
                  领取时间 {{ formatRpTime(r.createtime) }}
                </view>
              </view>
            </view>
            <view v-if="!detailRecords.length" class="chat-empty chat-rp-claims-hidden">{{ claimsEmptyTip }}</view>
            <view v-else-if="othersHiddenTip" class="chat-empty chat-rp-claims-hidden">{{ othersHiddenTip }}</view>
          </scroll-view>
          <button
            v-if="canGrabDetail"
            type="button"
            class="chat-rp-detail-grab-btn"
            :disabled="grabbing"
            @click="grabFromDetail"
          >{{ grabbing ? '领取中…' : '开红包' }}</button>
        </view>
      </view>
    </view>

    <!-- 私聊更多：对齐 888 action-sheet -->
    <view class="chat-action-sheet" :class="{ open: moreVisible }" v-if="moreVisible" aria-hidden="false">
      <view class="chat-action-sheet-mask" @click="moreVisible = false" />
      <view class="chat-action-sheet-panel" @click.stop>
        <view class="chat-action-sheet-title">{{ peerNickname || title }}</view>
        <view class="chat-setting-hint" style="text-align:center;margin-bottom:8px">
          会员ID {{ meta.peer }}{{ remark ? ' · 备注 ' + remark : '' }}
        </view>
        <button type="button" class="chat-action-item" @click="editRemark">
          {{ remark ? '修改备注' : '设置备注' }}
        </button>
        <button type="button" class="chat-action-item cancel" @click="moreVisible = false">关闭</button>
      </view>
    </view>
  </view>
</template>

<script setup>
import { computed, nextTick, reactive, ref } from 'vue'
import { onLoad, onShow, onUnload } from '@dcloudio/uni-app'
import GrabSlider from '../../components/GrabSlider.vue'
import TopBar from '../../components/TopBar.vue'
import '../../styles/chat.bundle.css'
import '../../styles/chat-room-uni-adapter.css'
import '../../styles/chat-rp-send-uni-adapter.css'
import '../../styles/chat-888-parity.css'
import { apiRequest, fetchProfile, getToken, uploadSticker } from '../../utils/auth.js'
import { getApiBase, getImgBase } from '../../utils/config.js'
import { assetBase, applyServerCopy, copyState, localeState, tt } from '../../utils/i18n.js'
import {
  avatarSrc,
  isRecalled,
  isSystemMsg,
  msgExtra,
  msgType,
  publicUrl,
  recallTip,
} from '../../utils/chat.js'
import { clearActiveChat, getActiveChat, saveActiveChat } from '../../utils/chat-route.js'
import { COMMON_EMOJIS, loadEmojiTree } from '../../utils/emoji.js'
import { setInboxMyId, noteConversationRead } from '../../utils/im-inbox.js'
import { playOpenRedPacketSound } from '../../utils/notify-sound.js'
import { loadWalletBootstrap, money } from '../../utils/wallet.js'
import {
  bindForegroundResume,
  fetchGroupInfo,
  grabRedPacket,
  imConnect,
  imSend,
  loadHistory,
  markConversationRead,
  onImEvent,
  recallMessage,
  redPacketDetail,
  resumeFromBackground,
  sendRedPacket,
  sendTransfer,
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
const showTransfer = ref(false)
const showEmoji = ref(false)
const showSticker = ref(false)
const showAttach = ref(false)
const walletBalance = ref(0)
const walletFrozen = ref(0)
const rpSending = ref(false)
const transferSending = ref(false)
const transferForm = reactive({ amount: '', remark: '' })
const mediaSending = ref(false)
const grabbing = ref(false)
const detailVisible = ref(false)
const detail = ref(null)
const moreVisible = ref(false)
const myGrabAmount = ref('')
const canGrabDetail = ref(false)
const grabSliderRef = ref(null)
const emojiGroups = ref([{ id: 'common', name: '常用', chars: COMMON_EMOJIS.slice() }])
const emojiGroupIdx = ref(0)
const activeEmojis = computed(() => {
  const g = emojiGroups.value[emojiGroupIdx.value] || emojiGroups.value[0]
  return (g && g.chars) || COMMON_EMOJIS
})
const stickerItems = ref([])
const stickerQuota = ref({ count: 0, limit: 50, is_admin: false })
const stickerUploading = ref(false)
const stickerQuotaText = computed(() => {
  const q = stickerQuota.value || {}
  if (q.is_admin) return '管理员不限量'
  const lim = q.limit | 0
  if (lim <= 0) return ''
  return '已上传 ' + (q.count | 0) + ' / ' + lim
})
const groupMeta = ref(null)
const noticePinClosed = ref(false)
const noticePinExpanded = ref(false)
const noticeDismissedText = ref('')
let myId = 0
let off = null
let activePacketId = 0
let roomAlive = false
let bootDoneAt = 0
let scrollTimers = []

const isPrivate = computed(() => (meta.value.type | 0) === 1)
const transferPreviewAmt = computed(() => {
  const n = parseFloat(transferForm.amount) || 0
  return n.toFixed(2)
})
const locale = localeState()
const copyTick = copyState()

function rpT(key, fallback) {
  void locale.value
  void copyTick.value
  return tt(key, fallback)
}

function resolveGroupNotice(g) {
  g = g || {}
  const base = String(g.notice || '').trim()
  let map = g.notice_i18n
  if (typeof map === 'string') {
    try {
      map = JSON.parse(map)
    } catch (e) {
      map = null
    }
  }
  if (!map || typeof map !== 'object') return base
  const loc = locale.value || 'zh-CN'
  const local = String(map[loc] || '').trim()
  return local || base
}

function resolveNoticeImages(g) {
  g = g || {}
  const raw = g.notice_images
  if (Array.isArray(raw)) {
    return raw.map((s) => String(s || '').trim()).filter(Boolean)
  }
  if (typeof raw === 'string') {
    const t = raw.trim()
    if (!t) return []
    if (t.charAt(0) === '[') {
      try {
        const arr = JSON.parse(t)
        if (Array.isArray(arr)) {
          return arr.map((s) => String(s || '').trim()).filter(Boolean)
        }
      } catch (e2) {}
    }
    return t.split(/[\r\n,]+/).map((s) => s.trim()).filter(Boolean)
  }
  return []
}

function publicAssetUrl(src) {
  const s = String(src || '').trim()
  if (!s) return ''
  if (/^https?:\/\//i.test(s) || s.indexOf('//') === 0) return s
  if (s.charAt(0) === '/') return s
  const base = getApiBase() || ''
  return (base.replace(/\/+$/, '') + '/' + s.replace(/^\/+/, ''))
}

const noticePinText = computed(() => {
  if (isPrivate.value) return ''
  const g = (groupMeta.value && groupMeta.value.group) || {}
  const notice = resolveGroupNotice(g)
  if (!notice) return ''
  if (noticeDismissedText.value && noticeDismissedText.value === notice) return ''
  return notice
})

const noticePinImages = computed(() => {
  if (isPrivate.value) return []
  const g = (groupMeta.value && groupMeta.value.group) || {}
  return resolveNoticeImages(g).map(publicAssetUrl).filter(Boolean)
})

const noticePinVisible = computed(() => {
  if (isPrivate.value || noticePinClosed.value) return false
  return !!(noticePinText.value || noticePinImages.value.length)
})

function toggleNoticePinExpand() {
  noticePinExpanded.value = !noticePinExpanded.value
}

function dismissNoticePin() {
  const g = (groupMeta.value && groupMeta.value.group) || {}
  const notice = resolveGroupNotice(g)
  if (notice) noticeDismissedText.value = notice
  noticePinClosed.value = true
  noticePinExpanded.value = false
}

function previewNoticeImage(src) {
  const urls = noticePinImages.value
  if (!urls.length) return
  uni.previewImage({ urls, current: src })
}

const groupPolicy = computed(() => {
  const m = groupMeta.value || {}
  return m.policy || {}
})

const forbidModes = computed(() => {
  const m = groupMeta.value || {}
  return m.forbid_modes || (groupPolicy.value && groupPolicy.value.forbid_modes) || {}
})

function canCap(cap) {
  if (isPrivate.value) return true
  const pol = groupPolicy.value || {}
  const key = 'can_send_' + cap
  if (pol[key] != null) return !!pol[key]
  const role = (groupMeta.value && groupMeta.value.my_role) | 0
  if (role >= 2) {
    if (cap === 'rp' && (pol.can_send_rp === false || pol.rp_robot_only === true)) return false
    return true
  }
  const fm = forbidModes.value || {}
  if (fm[cap]) return false
  if (groupMeta.value && groupMeta.value.can_speak === false) {
    if (cap === 'text' || cap === 'emoji' || cap === 'image' || cap === 'video' || cap === 'file') return false
  }
  if (cap === 'rp') {
    if (pol.can_send_rp === false || pol.rp_robot_only === true) return false
  }
  return true
}

const composerLocked = computed(() => {
  if (isPrivate.value) return false
  if (groupMeta.value && groupMeta.value.can_speak === false) return true
  if (groupMeta.value && groupMeta.value.mute_all && ((groupMeta.value.my_role | 0) < 2)) return true
  return !canCap('text')
})

const extrasLocked = computed(() => {
  if (isPrivate.value) return false
  if (groupMeta.value && groupMeta.value.can_speak === false && canCap('text')) return true
  if (!composerLocked.value) return false
  return !(canCap('image') || canCap('video') || canCap('emoji') || canCap('file') || canCap('rp'))
})

function buildForbidSpeakPlaceholder() {
  const pol = groupPolicy.value || {}
  const g = (groupMeta.value && groupMeta.value.group) || {}
  const custom = String(pol.forbid_speak_hint || g.forbid_speak_hint || '').trim()
  if (custom) return custom
  const parts = []
  if (canCap('rp')) parts.push('发/抢红包')
  if (canCap('image')) parts.push('发图片')
  if (canCap('video')) parts.push('发视频')
  if (canCap('emoji')) parts.push('发表情')
  if (!parts.length) return '本群禁止发言，仅管理员可发言'
  return '仅可' + parts.join('、') + '操作'
}

const composerPlaceholder = computed(() => {
  if (isPrivate.value) return '输入消息…'
  if (groupMeta.value && groupMeta.value.can_speak === false && canCap('text')) {
    return '你已被禁言，暂时无法发言'
  }
  if (composerLocked.value || !canCap('text')) return buildForbidSpeakPlaceholder()
  return '输入消息…'
})

const attachAllowed = computed(() => {
  return canCap('image') || canCap('video') || canCap('file') || canCap('rp')
})

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

const allPacketTypes = [
  { v: 2, nKey: 'chat_rp_type_lucky', nFb: '拼手气' },
  { v: 5, nKey: 'chat_rp_type_relay', nFb: '接龙' },
  { v: 3, nKey: 'chat_rp_type_mine', nFb: '埋雷' },
  { v: 1, nKey: 'chat_rp_type_avg', nFb: '普通红宝' },
  { v: 4, nKey: 'chat_rp_type_random', nFb: '随机红宝' },
]

function enabledRpTypeIds() {
  if (isPrivate.value) return [1]
  const pol = groupPolicy.value || {}
  let raw = String(pol.rp_enabled_types || '')
  if (!raw && groupMeta.value && groupMeta.value.group) {
    raw = String(groupMeta.value.group.rp_enabled_types || '')
  }
  if (!raw) raw = '1,3,4,5'
  const list = raw
    .split(',')
    .map((x) => parseInt(x, 10))
    .filter((n) => n >= 1 && n <= 5)
  return list.length ? list : [1, 3, 4, 5]
}

function groupRpFixedAmount() {
  if (isPrivate.value) return 0
  const pol = groupPolicy.value || {}
  let fixed = parseFloat(pol.rp_fixed_amount) || 0
  if (!(fixed > 0) && groupMeta.value && groupMeta.value.group) {
    fixed = parseFloat(groupMeta.value.group.rp_fixed_amount) || 0
  }
  return fixed > 0 ? Math.round(fixed * 100) / 100 : 0
}

function groupRpAmountLimits() {
  if (isPrivate.value) return { min: 10, max: 0 }
  const pol = groupPolicy.value || {}
  const g = (groupMeta.value && groupMeta.value.group) || {}
  let min = parseFloat(pol.rp_min_amount) || 0
  let max = parseFloat(pol.rp_max_amount) || 0
  if (!(min > 0)) min = parseFloat(g.rp_min_amount) || 0
  if (!(max > 0)) max = parseFloat(g.rp_max_amount) || 0
  if (!(min > 0)) min = 10
  return {
    min: Math.round(min * 100) / 100,
    max: max > 0 ? Math.round(max * 100) / 100 : 0,
  }
}

function groupRpCountRange() {
  const pol = groupPolicy.value || {}
  let min = parseInt(pol.rp_min_count, 10) || 0
  let max = parseInt(pol.rp_max_count, 10) || 0
  const g = (groupMeta.value && groupMeta.value.group) || {}
  if (!(min > 0)) min = parseInt(g.rp_min_count, 10) || 0
  if (!(max > 0)) max = parseInt(g.rp_max_count, 10) || 0
  if (min <= 0) min = 5
  if (max <= 0) max = 10
  if (max < min) max = min
  return { min, max, fixed: min === max }
}

/** 普通/随机红宝：个数范围对齐 888 userRpCountRange */
function userRpCountRange() {
  return { min: 1, max: 500, fixed: false }
}

function rpCountRangeForType(packetType) {
  const t = packetType | 0
  if (t === 1 || t === 4) return userRpCountRange()
  return groupRpCountRange()
}

const packetTypes = computed(() => {
  const enabled = enabledRpTypeIds()
  const role = (groupMeta.value && groupMeta.value.my_role) | 0
  const relayAdminOnly = !!(groupPolicy.value && groupPolicy.value.rp_relay_admin_only)
  return allPacketTypes
    .filter((t) => enabled.indexOf(t.v) >= 0)
    .filter((t) => !(t.v === 5 && relayAdminOnly && role < 2))
    .map((t) => ({ v: t.v, n: rpT(t.nKey, t.nFb) }))
})

const mineCountOptions = computed(() => {
  const range = groupRpCountRange()
  const base = [5, 7, 9].filter((n) => n >= range.min && n <= range.max)
  return base.length ? base : [range.min]
})

const rpCountOptions = computed(() => {
  const range = rpCountRangeForType(rpForm.packet_type | 0)
  if (range.fixed) return [range.min]
  const out = []
  const cap = Math.min(range.max, range.min + 11)
  for (let i = range.min; i <= cap; i++) out.push(i)
  return out.length ? out : [range.min]
})

const amountReadonly = computed(() => groupRpFixedAmount() > 0)

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
    3: rpT('chat_rp_type_mine', '埋雷') + '红宝',
    4: rpT('chat_rp_type_random', '随机红宝'),
    5: rpT('chat_rp_type_relay', '接龙') + '红宝',
  }
  return map[rpForm.packet_type | 0] || '红宝'
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

function pktFinished(d) {
  if (!d) return false
  if (d.finished === true || d.others_visible === true || d.verify_visible === true) return true
  const p = d.packet || {}
  const remain = p.remain_count != null ? (p.remain_count | 0) : 1
  const st = p.status | 0
  return remain <= 0 || st === 2 || st === 3 || st === 4 || st === 5
}

const detailRecords = computed(() => {
  const d = detail.value
  if (!d) return []
  if (d.claims_visible === false) return []
  let rows = d.records || d.list || []
  const othersVisible = d.others_visible != null ? !!d.others_visible : pktFinished(d)
  if (!othersVisible) {
    rows = rows.filter((r) => (r.user_id | 0) === (myId | 0))
  }
  return rows
})

const othersVisibleDone = computed(() => {
  const d = detail.value
  if (!d) return false
  if (d.others_visible != null) return !!d.others_visible
  return pktFinished(d)
})

const othersHiddenTip = computed(() => {
  const d = detail.value
  if (!d || othersVisibleDone.value) return ''
  if (!(d.mine || detailRecords.value.length)) return ''
  return '红包领完或过期后可查看其他人领取记录'
})

const claimsEmptyTip = computed(() => {
  const d = detail.value
  if (!d) return '暂无领取记录'
  if (d.claims_visible === false) return '领取后可查看自己的领取金额'
  return '暂无领取记录'
})

const canFairVerify = computed(() => {
  const d = detail.value
  if (!d) return false
  const p = d.packet || {}
  const ptype = p.packet_type | 0
  if (ptype !== 2 && ptype !== 3 && ptype !== 5) return false
  if (d.verify_visible != null) return !!d.verify_visible
  return pktFinished(d)
})

const detailFairTip = computed(() => {
  const d = detail.value
  if (!d) return ''
  const p = d.packet || {}
  const ptype = p.packet_type | 0
  if (ptype !== 2 && ptype !== 3 && ptype !== 5) return ''
  if (canFairVerify.value) return ''
  return '红包领完或过期后可查询验证'
})

const mineSettleHitN = computed(() => {
  const d = detail.value
  if (!d) return 0
  const p = d.packet || {}
  if ((p.packet_type | 0) !== 3 || (p.status | 0) !== 5) return -1
  return (d.records || []).filter((r) => (r.is_mine_hit | 0) === 1).length
})

const mineSettleTip = computed(() => {
  if (mineSettleHitN.value < 0) return ''
  return mineSettleHitN.value > 0 ? '本局中雷 ' + mineSettleHitN.value + ' 人' : '本局无人中雷'
})

const mineSettleSafe = computed(() => mineSettleHitN.value === 0)

const detailBlessTitle = computed(() => {
  const d = detail.value
  const p = (d && d.packet) || {}
  const map = { 2: '红宝拼手气', 3: '红宝扫雷', 5: '红宝接龙' }
  return map[p.packet_type | 0] || p.blessing || '恭喜发财'
})

const detailTotalAmt = computed(() => {
  const p = (detail.value && detail.value.packet) || {}
  return (parseFloat(p.total_amount || 0) || 0).toFixed(2)
})

const detailMyAmtText = computed(() => {
  const d = detail.value
  if (!d || !d.mine || d.mine.amount == null) return ''
  return (parseFloat(d.mine.amount) || 0).toFixed(2)
})

const detailMineDigit = computed(() => {
  const p = (detail.value && detail.value.packet) || {}
  if ((p.packet_type | 0) !== 3) return null
  return p.mine_digit | 0
})

const detailMineDigitLine = computed(() => {
  if (detailMineDigit.value == null) return ''
  const p = (detail.value && detail.value.packet) || {}
  return p.mine_pending ? '（匹配波场哈希末位中）' : '（已匹配波场哈希末位）'
})

const detailLocked = computed(() => {
  const d = detail.value
  if (!d) return false
  let privacyMode = String(d.privacy_mode || (d.policy && d.policy.privacy_mode) || '')
  const locked =
    d.rp_detail_locked === true ||
    privacyMode === 'private' ||
    (privacyMode !== 'open' && d.member_list_hidden === true)
  return !!locked
})

const detailFairHash = computed(() => {
  const p = (detail.value && detail.value.packet) || {}
  return String(p.tron_block_id || p.fair_hash || '')
})

const detailTronBlock = computed(() => {
  const p = (detail.value && detail.value.packet) || {}
  return p.tron_block_num || p.targetBlockNum || 0
})

const detailFairLabel = computed(() => {
  return detailTronBlock.value ? 'TRON #' + detailTronBlock.value : 'TRON'
})

const detailTronHref = computed(() => {
  const target = detailTronBlock.value
    ? String(detailTronBlock.value)
    : String(detailFairHash.value || '')
  if (!target) return ''
  return 'https://tronscan.org/#/block/' + encodeURIComponent(target)
})

function recordGray(r) {
  if (!r) return false
  if ((r.user_id | 0) === (myId | 0)) return false
  return !!(r.name_masked || r.avatar_gray)
}

function recordShowBest(r) {
  return !!(othersVisibleDone.value && r && r.is_best)
}

function recordShowWorst(r) {
  return !!(othersVisibleDone.value && r && r.is_worst)
}

function recordMineHit(r) {
  const p = (detail.value && detail.value.packet) || {}
  if ((p.packet_type | 0) !== 3 || !r) return false
  const hit = (r.is_mine_hit | 0) === 1
  return hit && ((p.status | 0) === 5 || hit)
}

function recordMineSafe(r) {
  const p = (detail.value && detail.value.packet) || {}
  if ((p.packet_type | 0) !== 3 || !r) return false
  if ((r.is_mine_hit | 0) === 1) return false
  return (p.status | 0) === 5
}

function recordTail(r) {
  if (!othersVisibleDone.value || !r || r.tail_digit == null) return null
  const p = (detail.value && detail.value.packet) || {}
  if ((p.packet_type | 0) !== 3) return null
  return r.tail_digit | 0
}

function formatRpTime(ts) {
  const n = Number(ts) || 0
  if (!n) return ''
  const d = new Date(n < 1e12 ? n * 1000 : n)
  const p = (x) => (x < 10 ? '0' + x : '' + x)
  return p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes())
}

function openTronVerify() {
  const href = detailTronHref.value
  if (!href) return
  // #ifdef H5
  if (typeof window !== 'undefined') {
    window.open(href, '_blank')
    return
  }
  // #endif
  uni.setClipboardData({
    data: href,
    success: () => uni.showToast({ title: '波场链接已复制', icon: 'none' }),
  })
}

function openFairVerify() {
  const p = (detail.value && detail.value.packet) || {}
  const no = String(p.packet_no || '').trim()
  const pid = (p.id | 0) || (p.packet_id | 0) || 0
  if (!no) {
    uni.showToast({ title: '缺少红包单号', icon: 'none' })
    return
  }
  let url = '/pages/common/fair-verify?packet_no=' + encodeURIComponent(no)
  if (pid > 0) url += '&packet_id=' + encodeURIComponent(String(pid))
  uni.navigateTo({
    url,
    fail: () => uni.showToast({ title: '无法打开验证页', icon: 'none' }),
  })
}

function msgId(m) {
  if (!m) return '0'
  const id = m.msg_id != null && m.msg_id !== '' ? m.msg_id : m.id
  if (id != null && id !== '') return String(id)
  const t = (m.createtime | 0) || 0
  const from = (m.from_user_id | 0) || 0
  const typ = (m.msg_type | 0) || (m.type | 0) || 0
  const c = String(m.content || m.text || m.body || '').slice(0, 32)
  return 'tmp_' + t + '_' + from + '_' + typ + '_' + c.length
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
function isTransfer(m) {
  return msgType(m) === 8
}
function transferAmount(m) {
  const ex = msgExtra(m)
  const amt = ex.amount != null ? parseFloat(ex.amount) : NaN
  if (!isNaN(amt) && amt > 0) return amt.toFixed(2)
  return '0.00'
}
function transferTitle(m) {
  const ex = msgExtra(m)
  const remark = String(ex.remark || '').trim()
  if (remark) return remark
  return isMine(m) ? '转账给对方' : '收到转账'
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
  return pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds())
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
  // 优先 fullurl（绝对地址）；相对 /uploads 用 api/img 基址拼接，勿再裁成同站路径
  const raw = (ex && (ex.fullurl || ex.url)) || ''
  return publicUrl(raw)
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
/** 编码路径中文段，避免 sticker 中文文件名发出去后 img 空白 */
function encodeUriPath(url) {
  const raw = String(url || '')
  if (!raw) return ''
  if (/^https?:\/\//i.test(raw)) {
    try {
      const u = new URL(raw)
      u.pathname = u.pathname.split('/').map((seg, i) => {
        if (i === 0 || seg === '') return seg
        try {
          return encodeURIComponent(decodeURIComponent(seg))
        } catch (e) {
          return encodeURIComponent(seg)
        }
      }).join('/')
      return u.toString()
    } catch (e) {
      return raw
    }
  }
  return raw.split('/').map((seg, i) => {
    if (i === 0 || seg === '') return seg
    try {
      return encodeURIComponent(decodeURIComponent(seg))
    } catch (e) {
      return encodeURIComponent(seg)
    }
  }).join('/')
}

/** 展示用：统一到可访问的 /888/stickers 或 /999/static/stickers，并编码 */
function normalizeStickerUrl(url) {
  let s = String(url || '').trim()
  if (!s) return ''
  if (/^https?:\/\//i.test(s)) {
    try {
      // #ifdef H5
      s = new URL(s, typeof location !== 'undefined' ? location.origin : undefined).pathname || s
      // #endif
      // #ifndef H5
      const m = s.match(/^https?:\/\/[^/]+(\/.*)$/i)
      if (m) s = m[1]
      // #endif
    } catch (e) {}
  }
  // 999 发来的 fullurl 可能是 /999/static/stickers；展示优先改成同站 /888/stickers（两边文件都在）
  if (s.indexOf('/999/static/stickers/') === 0) {
    s = '/888/stickers/' + s.slice('/999/static/stickers/'.length)
  } else if (s.indexOf('/888/static/stickers/') === 0) {
    s = '/888/stickers/' + s.slice('/888/static/stickers/'.length)
  } else if (s.indexOf('static/stickers/') === 0) {
    s = '/888/stickers/' + s.slice('static/stickers/'.length)
  } else if (s.indexOf('stickers/') === 0) {
    s = '/888/' + s
  } else if (s.startsWith('/')) {
    // keep
  } else if (s.startsWith('static/')) {
    s = assetBase() + s
  } else {
    s = assetBase() + 'static/' + s.replace(/^\/+/, '')
  }
  return encodeUriPath(s)
}

/** 发给 IM 的 sticker url：必须命中服务端 allowlist（未编码的真实路径） */
function stickerSendUrl(url) {
  let s = String(url || '').trim()
  if (!s) return ''
  if (/^https?:\/\//i.test(s)) {
    try {
      // #ifdef H5
      s = new URL(s, typeof location !== 'undefined' ? location.origin : undefined).pathname || s
      // #endif
      // #ifndef H5
      const m = s.match(/^https?:\/\/[^/]+(\/.*)$/i)
      if (m) s = m[1]
      // #endif
    } catch (e) {}
  }
  try {
    s = decodeURIComponent(s)
  } catch (e) {}
  if (s.indexOf('/999/static/stickers/') === 0) {
    return '/888/stickers/' + s.slice('/999/static/stickers/'.length)
  }
  if (s.indexOf('/888/static/stickers/') === 0) {
    return '/888/stickers/' + s.slice('/888/static/stickers/'.length)
  }
  if (s.indexOf('static/stickers/') === 0) {
    return '/888/stickers/' + s.slice('static/stickers/'.length)
  }
  if (s.indexOf('stickers/') === 0) {
    return '/888/' + s
  }
  if (
    s.indexOf('/888/stickers/') === 0 ||
    s.indexOf('/stickers/') === 0 ||
    s.indexOf('/uploads/stickers/') === 0 ||
    s.indexOf('/999/static/stickers/') === 0
  ) {
    return s
  }
  return s
}
function stickerUrl(m) {
  const ex = msgExtra(m)
  // 优先 canonical url（/888/stickers），避免 fullurl 指向 /999 导致空白
  const raw = (ex && (ex.url || ex.fullurl)) || ''
  if (raw) return normalizeStickerUrl(raw)
  return encodeUriPath(assetBase() + 'static/stickers/wechat/face/微笑.png')
}
function formatAmt(n) {
  const x = Number(n)
  if (!isFinite(x)) return '-'
  return x.toFixed(2)
}

function sameRoom(msg) {
  if (!msg) return false
  let type = msg.conversation_type | 0
  if (!type) {
    if ((msg.group_id | 0) > 0) type = 2
    else type = 1
  }
  if (type !== (meta.value.type | 0)) return false
  if (type === 2) {
    return (msg.group_id | 0) === (meta.value.group | 0) ||
      String(msg.conversation_id || '') === String(meta.value.conversationId || meta.value.group)
  }
  return String(msg.conversation_id || '') === String(meta.value.conversationId) ||
    (msg.from_user_id | 0) === (meta.value.peer | 0) ||
    (msg.to_user_id | 0) === (meta.value.peer | 0)
}

function privateCid() {
  if (meta.value.conversationId) return String(meta.value.conversationId)
  const a = myId | 0
  const b = meta.value.peer | 0
  if (a > 0 && b > 0) {
    return a < b ? a + '_' + b : b + '_' + a
  }
  return ''
}

function roomConversationId() {
  if (meta.value.type === 2) return String(meta.value.group || meta.value.conversationId || '')
  return privateCid()
}

function appendLocalMessage(raw) {
  if (!raw) return false
  let msg = raw
  if (!(msg.conversation_type | 0)) {
    msg = Object.assign({}, msg, {
      conversation_type: meta.value.type | 0,
      group_id: meta.value.type === 2 ? meta.value.group | 0 : msg.group_id | 0,
      conversation_id: msg.conversation_id || roomConversationId(),
      to_user_id: msg.to_user_id || (meta.value.type === 1 ? meta.value.peer | 0 : 0),
    })
  }
  if (!sameRoom(msg)) return false
  const id = String(msgId(msg))
  if (id && messages.value.some((x) => String(msgId(x)) === id)) return false
  messages.value = messages.value.concat([msg])
  if (msg.conversation_id && !meta.value.conversationId) {
    meta.value = Object.assign({}, meta.value, { conversationId: String(msg.conversation_id) })
    saveActiveChat({
      type: meta.value.type,
      id: meta.value.conversationId,
      peer: meta.value.peer,
      group: meta.value.group,
      title: title.value,
      nickname: peerNickname.value,
      remark: remark.value,
    })
  }
  scrollToLatest()
  return true
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

function openEmojiOnly() {
  if (!canCap('emoji') && !canCap('text')) {
    uni.showToast({ title: '表情已禁止', icon: 'none' })
    return
  }
  showEmoji.value = true
  showSticker.value = false
  showAttach.value = false
  ensureEmojisLoaded()
}

function toggleEmoji() {
  if (!canCap('emoji') && !canCap('text')) {
    uni.showToast({ title: '表情已禁止', icon: 'none' })
    return
  }
  const next = !(showEmoji.value && !showSticker.value)
  showEmoji.value = next
  showSticker.value = false
  if (next) {
    showAttach.value = false
    showRp.value = false
    ensureEmojisLoaded()
  }
}

async function ensureEmojisLoaded() {
  try {
    const data = await loadEmojiTree()
    if (data && Array.isArray(data.groups) && data.groups.length) {
      emojiGroups.value = data.groups
      if (emojiGroupIdx.value >= data.groups.length) emojiGroupIdx.value = 0
    }
  } catch (e) {}
}

function toggleAttach() {
  if (!attachAllowed.value) {
    uni.showToast({ title: '附件已禁止', icon: 'none' })
    return
  }
  const next = !showAttach.value
  showAttach.value = next
  if (next) {
    showEmoji.value = false
    showSticker.value = false
  }
}

function onAttachPick(kind) {
  showAttach.value = false
  if (kind === 'image') {
    if (!canCap('image')) {
      uni.showToast({ title: '图片消息已禁止', icon: 'none' })
      return
    }
    pickImage()
  } else if (kind === 'video') {
    if (!canCap('video')) {
      uni.showToast({ title: '视频消息已禁止', icon: 'none' })
      return
    }
    pickVideo()
  } else if (kind === 'file') {
    if (!canCap('file')) {
      uni.showToast({ title: '文件消息已禁止', icon: 'none' })
      return
    }
    pickFile()
  } else if (kind === 'rp') {
    if (!canCap('rp')) {
      uni.showToast({ title: '本群禁止发红宝', icon: 'none' })
      return
    }
    openRpSend()
  } else if (kind === 'transfer') {
    openTransferSend()
  }
}

function applyRpFormDefaults() {
  if (isPrivate.value) {
    rpForm.packet_type = 1
    rpForm.total_count = '1'
    return
  }
  const enabled = enabledRpTypeIds()
  const types = packetTypes.value
  if (!types.some((t) => t.v === (rpForm.packet_type | 0))) {
    rpForm.packet_type = (types[0] && types[0].v) || enabled[0] || 2
  }
  const fixed = groupRpFixedAmount()
  if (fixed > 0) rpForm.total_amount = String(fixed)
  const range = rpCountRangeForType(rpForm.packet_type | 0)
  const cur = parseInt(rpForm.total_count, 10) || 0
  if (cur < range.min || cur > range.max) {
    rpForm.total_count = String(range.min)
  }
  if ((rpForm.packet_type | 0) === 3) {
    const opts = mineCountOptions.value
    if (opts.indexOf(parseInt(rpForm.total_count, 10)) < 0) {
      rpForm.total_count = String(opts[0] || range.min)
    }
  }
}

function setRpType(v) {
  rpForm.packet_type = v | 0
  applyRpFormDefaults()
}

function closeRpSend() {
  showRp.value = false
}

async function openRpSend() {
  showAttach.value = false
  showEmoji.value = false
  showSticker.value = false
  if (!canCap('rp')) {
    uni.showToast({ title: '本群禁止发红宝', icon: 'none' })
    return
  }
  if (!isPrivate.value) await loadGroupMeta()
  applyRpFormDefaults()
  if (!String(rpForm.blessing || '').trim()) rpForm.blessing = rpBlessingDefault.value
  try {
    const cfg = await apiRequest('config', 'GET')
    if (cfg && cfg.copy) applyServerCopy(cfg.copy)
  } catch (e) {}
  await refreshWallet()
  showRp.value = true
}

function closeTransferSend() {
  showTransfer.value = false
  transferSending.value = false
}

async function openTransferSend() {
  showAttach.value = false
  showEmoji.value = false
  showSticker.value = false
  if (!isPrivate.value) {
    uni.showToast({ title: '仅私聊可转账', icon: 'none' })
    return
  }
  transferForm.amount = ''
  transferForm.remark = ''
  await refreshWallet()
  showTransfer.value = true
}

async function submitTransfer() {
  if (transferSending.value) return
  if (!isPrivate.value) {
    uni.showToast({ title: '仅私聊可转账', icon: 'none' })
    return
  }
  const amount = parseFloat(transferForm.amount) || 0
  const remark = String(transferForm.remark || '').trim()
  if (amount < 0.01) {
    uni.showToast({ title: '请输入转账金额', icon: 'none' })
    return
  }
  if (walletBalance.value > 0 && amount > walletBalance.value + 0.0001) {
    uni.showToast({ title: '红宝不足', icon: 'none' })
    return
  }
  transferSending.value = true
  try {
    const packet = await sendTransfer({
      to_user_id: meta.value.peer | 0,
      amount,
      remark,
    })
    const data = (packet && packet.data) || {}
    if (data.balance != null) {
      walletBalance.value = Number(data.balance) || 0
    } else if (data.hongbao != null) {
      walletBalance.value = Number(data.hongbao) || 0
    }
    const msg = data.message
    if (msg) appendLocalMessage(msg)
    else await fetchHistory()
    closeTransferSend()
    uni.showToast({ title: '转账成功', icon: 'success' })
    markRead().catch(() => {})
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '转账失败', icon: 'none' })
  } finally {
    transferSending.value = false
  }
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
  const id = last ? 'm' + msgId(last) : 'chat-bottom-anchor'
  scrollInto.value = ''
  scrollTop.value = scrollTop.value === 999999 ? 999998 : 999999
  if (scrollTimers.length) {
    scrollTimers.forEach((t) => clearTimeout(t))
    scrollTimers = []
  }
  const bump = (target) => {
    scrollInto.value = ''
    nextTick(() => {
      scrollInto.value = target
      scrollTop.value = scrollTop.value === 999999 ? 999998 : 999999
      // #ifdef H5
      try {
        if (typeof document !== 'undefined') {
          const roots = document.querySelectorAll('.chat-room-page .chat-msg-scroll, .chat-msg-scroll')
          roots.forEach((node) => {
            const cands = [
              node,
              node.querySelector && node.querySelector('.uni-scroll-view'),
              node.parentElement &&
                node.parentElement.querySelector &&
                node.parentElement.querySelector('.uni-scroll-view'),
            ]
            cands.forEach((el) => {
              if (!el) return
              try {
                el.scrollTop = el.scrollHeight
              } catch (e) {}
            })
          })
        }
      } catch (e) {}
      // #endif
    })
  }
  nextTick(() => {
    bump(id)
    ;[60, 160, 320, 500, 800, 1200].forEach((ms, i) => {
      scrollTimers.push(
        setTimeout(() => bump(i % 2 === 0 ? 'chat-bottom-anchor' : id), ms)
      )
    })
  })
}

async function loadStickers() {
  const baseItems = []
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
    packs.forEach((p) => {
      const pid = String((p && p.id) || 'wechat')
      const cats = Array.isArray(p && p.categories) ? p.categories : []
      cats.forEach((c) => {
        const items = Array.isArray(c && c.items) ? c.items : []
        items.forEach((it) => {
          if (baseItems.length >= 120) return
          const code = String((it && it.code) || '').trim()
          const raw = String((it && it.url) || '').trim()
          const url = normalizeStickerUrl(raw)
          const sendUrl = stickerSendUrl(raw || url)
          if (code && url) baseItems.push({ code, url, sendUrl, pack: pid })
        })
      })
    })
  } catch (e) {}

  let customItems = []
  try {
    const data = await apiRequest('stickerlist', 'GET', {})
    stickerQuota.value = {
      count: (data && data.count) | 0,
      limit: (data && data.limit) | 0,
      is_admin: !!(data && data.is_admin),
    }
    const list = Array.isArray(data && data.items)
      ? data.items
      : Array.isArray(data && data.list)
        ? data.list
        : []
    customItems = list
      .map((it) => {
        const raw = String(it.url || it.fullurl || '').trim()
        return {
          id: it.id,
          code: String(it.name || it.code || '').trim(),
          pack: String(it.pack || 'custom'),
          url: normalizeStickerUrl(raw),
          sendUrl: stickerSendUrl(raw),
        }
      })
      .filter((it) => it.code && it.url)
  } catch (e) {}

  // 自定义表情优先展示
  const seen = {}
  const merged = []
  customItems.concat(baseItems).forEach((it) => {
    const key = it.pack + ':' + it.code + ':' + it.url
    if (seen[key]) return
    seen[key] = 1
    merged.push(it)
  })
  stickerItems.value = merged.slice(0, 160)
}

async function uploadCustomSticker() {
  if (stickerUploading.value) return
  const q = stickerQuota.value || {}
  if (!q.is_admin && (q.limit | 0) > 0 && (q.count | 0) >= (q.limit | 0)) {
    uni.showToast({ title: '最多上传 ' + q.limit + ' 个自定义表情', icon: 'none' })
    return
  }
  try {
    const pick = await new Promise((resolve, reject) => {
      uni.chooseImage({
        count: 1,
        sizeType: ['compressed'],
        sourceType: ['album'],
        success: resolve,
        fail: reject,
      })
    })
    const path = pick.tempFilePaths && pick.tempFilePaths[0]
    if (!path) return
    stickerUploading.value = true
    uni.showToast({ title: '上传中…', icon: 'none' })
    const data = await uploadSticker(path)
    stickerQuota.value = {
      count: (data && data.count) | 0,
      limit: (data && data.limit) | 0,
      is_admin: !!(data && data.is_admin),
    }
    await loadStickers()
    uni.showToast({ title: '上传成功', icon: 'success' })
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '上传失败', icon: 'none' })
  } finally {
    stickerUploading.value = false
  }
}

async function sendSticker(st) {
  if (!st || !(st.url || st.sendUrl)) return
  const code = String(st.code || '表情')
  const sendUrl = stickerSendUrl(st.sendUrl || st.url || '')
  const displayUrl = normalizeStickerUrl(sendUrl || st.url || '')
  if (!code || !sendUrl) {
    uni.showToast({ title: '表情无效', icon: 'none' })
    return
  }
  const payload = {
    msg_type: 6,
    content: '[' + code + ']',
    extra: {
      pack: String(st.pack || 'wechat'),
      code,
      // url / fullurl 都走 canonical /888/stickers，避免对端偏好 fullurl 时 404 空白
      url: sendUrl,
      fullurl: displayUrl || sendUrl,
    },
  }
  try {
    let packet
    if (meta.value.type == 2) {
      packet = await imSend('group.send', Object.assign({ group_id: meta.value.group | 0 }, payload), true)
    } else {
      packet = await imSend('private.send', Object.assign({ to_user_id: meta.value.peer | 0 }, payload), true)
    }
    showSticker.value = false
    const msg = packet && packet.data && packet.data.message
    if (msg) appendLocalMessage(msg)
    else await fetchHistory()
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '发送失败', icon: 'none' })
  }
}

async function uploadCommonFile(filePath) {
  // 上传必须打 API；imgUri 可能是 CDN，没有 /api/common/upload
  const base = getApiBase() || getImgBase() || ''
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

/** 上传结果 → IM 允许的 /uploads 相对路径 + 可展示的绝对 fullurl（优先 OSS） */
function mediaPathsFromUpload(up) {
  let path = String((up && up.url) || '').trim()
  const fullRaw = String((up && up.fullurl) || '').trim()
  if ((!path || path.indexOf('/uploads/') !== 0) && fullRaw) {
    try {
      const u = new URL(fullRaw, getApiBase() || (typeof location !== 'undefined' ? location.origin : undefined))
      path = u.pathname || path
    } catch (e) {
      if (fullRaw.indexOf('/uploads/') >= 0) {
        path = fullRaw.slice(fullRaw.indexOf('/uploads/'))
      }
    }
  }
  if (!path || path.indexOf('/uploads/') !== 0) {
    throw new Error('上传失败')
  }
  // 展示地址一律走 publicUrl（OSS upload_cdn），勿盲信 API 返回的本站 fullurl
  const full = publicUrl(path) || fullRaw || path
  return { path, full }
}

async function sendMediaMessage(msgType, extra, label) {
  let packet
  if (meta.value.type == 2) {
    packet = await imSend(
      'group.send',
      { group_id: meta.value.group | 0, msg_type: msgType, content: label, extra: extra || {} },
      true
    )
  } else {
    packet = await imSend(
      'private.send',
      { to_user_id: meta.value.peer | 0, msg_type: msgType, content: label, extra: extra || {} },
      true
    )
  }
  const msg = packet && packet.data && packet.data.message
  if (msg) appendLocalMessage(msg)
  return packet
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
    const { path, full } = mediaPathsFromUpload(up)
    await sendMediaMessage(
      4,
      { url: path, fullurl: full, name: up.name || '' },
      '[图片]'
    )
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
    const { path, full } = mediaPathsFromUpload(up)
    await sendMediaMessage(
      5,
      { url: path, fullurl: full, name: up.name || '' },
      '[视频]'
    )
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
    const { path, full } = mediaPathsFromUpload(up)
    const rawName = String(item.name || up.name || 'file')
    const dot = rawName.lastIndexOf('.')
    const ext = dot >= 0 ? rawName.slice(dot + 1).toLowerCase() : ''
    await sendMediaMessage(
      7,
      { url: path, fullurl: full, name: rawName, size: Number(item.size || 0), ext },
      '[文件]' + rawName
    )
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

async function goBack() {
  // 先落已读水位，再清 activeChat，避免返回瞬间延迟推送又把未读加回
  try {
    await markRead()
  } catch (e) {}
  clearActiveChat()
  // 聊天页常被 reLaunch 打开，navigateBack 可能无历史；直接回红宝 Tab 最稳
  uni.switchTab({
    url: '/pages/messages/messages',
    fail() {
      uni.reLaunch({ url: '/pages/messages/messages' })
    },
  })
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
    setInboxMyId(myId)
  } catch (e) {}
}

async function markRead() {
  const cid = roomConversationId()
  if (!cid) return
  let lastId = 0
  const list = messages.value || []
  for (let i = 0; i < list.length; i++) {
    const m = list[i]
    const mid = (m && ((m.id | 0) || (m.msg_id | 0))) || 0
    if (mid > lastId) lastId = mid
  }
  // 本地立刻清未读（即使历史还没拉到也要清角标）
  noteConversationRead(meta.value.type, cid, lastId)
  if (meta.value.type === 2 && meta.value.group) {
    noteConversationRead(2, String(meta.value.group), lastId)
  }
  // 勿用 lastId=0 上报：会在库里插入游标 0，Redis 失效后 SQL 把整段历史算成未读
  if (lastId > 0) {
    await markConversationRead(meta.value.type, cid, lastId)
  }
}

async function fetchHistory() {
  const data = {
    conversation_type: meta.value.type | 0,
    limit: 50,
  }
  if (meta.value.type == 2) data.group_id = meta.value.group | 0
  else {
    data.to_user_id = meta.value.peer | 0
    const cid = privateCid()
    if (cid) data.conversation_id = cid
  }
  const packet = await loadHistory(data)
  const body = (packet && packet.data) || {}
  // 服务端 history 已按 id ASC（旧→新），勿再 reverse
  const incoming = Array.isArray(body.list)
    ? body.list.slice()
    : Array.isArray(body.messages)
      ? body.messages.slice()
      : []
  // 合并本地已有气泡，避免发红宝/发消息后立刻全量替换把刚插入的冲掉
  const map = new Map()
  messages.value.forEach((m) => {
    map.set(String(msgId(m)), m)
  })
  incoming.forEach((m) => {
    map.set(String(msgId(m)), m)
  })
  const merged = Array.from(map.values()).sort((a, b) => {
    const ai = (a.id | 0) || 0
    const bi = (b.id | 0) || 0
    if (ai && bi && ai !== bi) return ai - bi
    return ((a.createtime | 0) || 0) - ((b.createtime | 0) || 0)
  })
  messages.value = merged
  if (!meta.value.conversationId) {
    const cid =
      meta.value.type === 2
        ? String(meta.value.group || '')
        : privateCid() || (incoming[incoming.length - 1] && incoming[incoming.length - 1].conversation_id) || ''
    if (cid) {
      meta.value = Object.assign({}, meta.value, { conversationId: String(cid) })
      saveActiveChat({
        type: meta.value.type,
        id: meta.value.conversationId,
        peer: meta.value.peer,
        group: meta.value.group,
        title: title.value,
        nickname: peerNickname.value,
        remark: remark.value,
      })
    }
  }
  await markRead()
  scrollToLatest()
}

async function sendText() {
  if (composerLocked.value || !canCap('text')) {
    uni.showToast({ title: composerPlaceholder.value || '暂不可发言', icon: 'none' })
    return
  }
  const content = String(text.value || '').trim()
  if (!content) return
  try {
    let packet
    if (meta.value.type == 2) {
      packet = await imSend('group.send', { group_id: meta.value.group | 0, content, msg_type: 1 }, true)
    } else {
      packet = await imSend('private.send', { to_user_id: meta.value.peer | 0, content, msg_type: 1 }, true)
    }
    const msg = (packet && packet.data && packet.data.message) || null
    text.value = ''
    showEmoji.value = false
    showSticker.value = false
    showAttach.value = false
    if (msg) appendLocalMessage(msg)
    else await fetchHistory()
    markRead().catch(() => {})
  } catch (e) {
    uni.showToast({ title: e.message || '发送失败', icon: 'none' })
  }
}

async function sendRp() {
  if (rpSending.value) return
  if (!canCap('rp')) {
    uni.showToast({ title: '本群禁止发红宝', icon: 'none' })
    return
  }
  applyRpFormDefaults()
  const fixed = groupRpFixedAmount()
  let amount = Number(rpForm.total_amount)
  if (fixed > 0) amount = fixed
  if (!(amount > 0)) {
    uni.showToast({ title: '请输入红包金额', icon: 'none' })
    return
  }
  const lim = groupRpAmountLimits()
  if (fixed <= 0 && amount < lim.min) {
    uni.showToast({ title: '金额最低 ' + lim.min + ' 元', icon: 'none' })
    return
  }
  if (fixed <= 0 && lim.max > 0 && amount > lim.max + 0.0001) {
    uni.showToast({ title: '金额最高 ' + lim.max + ' 元', icon: 'none' })
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
    const range = rpCountRangeForType(payload.packet_type)
    let count = Math.max(1, parseInt(rpForm.total_count, 10) || 1)
    if (count < range.min) count = range.min
    if (count > range.max) count = range.max
    payload.total_count = count
    const enabled = enabledRpTypeIds()
    if (enabled.indexOf(payload.packet_type) < 0) {
      uni.showToast({ title: '本群未开放该红宝玩法', icon: 'none' })
      return
    }
    if (payload.packet_type === 5) {
      const pol = groupPolicy.value || {}
      const role = (groupMeta.value && groupMeta.value.my_role) | 0
      if (pol.rp_relay_admin_only && role < 2) {
        uni.showToast({ title: '接龙红宝仅管理员可发', icon: 'none' })
        return
      }
    }
    if (payload.packet_type === 3) {
      const dig = parseInt(rpForm.mine_digit, 10)
      if (!(dig >= 0 && dig <= 9)) {
        uni.showToast({ title: '请选择埋雷数字 0～9', icon: 'none' })
        return
      }
      payload.mine_digit = dig
      const opts = mineCountOptions.value
      if (opts.indexOf(payload.total_count) < 0) {
        uni.showToast({ title: '扫雷红包个数不在允许范围', icon: 'none' })
        return
      }
    }
  }
  rpSending.value = true
  try {
    const packet = await sendRedPacket(payload)
    const msg = (packet && packet.data && packet.data.message) || null
    showRp.value = false
    uni.showToast({ title: '红包已发送', icon: 'success' })
    if (msg) appendLocalMessage(msg)
    await refreshWallet()
    setTimeout(() => {
      fetchHistory().catch(() => {})
    }, 600)
    markRead().catch(() => {})
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
      playOpenRedPacketSound()
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

function mergeGroupMeta(data) {
  if (!data || typeof data !== 'object') return
  const prev = groupMeta.value || {}
  const prevNotice = resolveGroupNotice(prev.group || {})
  const next = Object.assign({}, prev, data)
  if (data.group) next.group = Object.assign({}, prev.group || {}, data.group)
  if (data.policy) next.policy = Object.assign({}, prev.policy || {}, data.policy)
  if (data.forbid_modes) next.forbid_modes = data.forbid_modes
  else if (data.policy && data.policy.forbid_modes) next.forbid_modes = data.policy.forbid_modes
  if (next.policy && next.policy.forbid_modes) next.forbid_modes = next.policy.forbid_modes
  if (data.can_speak != null) next.can_speak = !!data.can_speak
  if (data.mute_all != null) next.mute_all = !!data.mute_all
  if (data.my_role != null) next.my_role = data.my_role | 0
  groupMeta.value = next
  const nextNotice = resolveGroupNotice(next.group || {})
  if (nextNotice && nextNotice !== prevNotice) {
    noticePinClosed.value = false
    noticeDismissedText.value = ''
    noticePinExpanded.value = false
  }
}

async function loadGroupMeta() {
  if (isPrivate.value || !(meta.value.group | 0)) {
    groupMeta.value = null
    return
  }
  try {
    const packet = await fetchGroupInfo(meta.value.group | 0)
    const data = (packet && packet.data) || packet || {}
    mergeGroupMeta(data)
    applyRpFormDefaults()
  } catch (e) {}
}

async function softRefreshHistory() {
  if (!roomAlive) return
  try {
    await fetchHistory()
  } catch (e) {}
}

function leaveRoomToList(tip) {
  if (tip) uni.showToast({ title: tip, icon: 'none' })
  roomAlive = false
  markRead()
    .catch(() => {})
    .finally(() => {
      clearActiveChat()
      setTimeout(() => {
        uni.switchTab({ url: '/pages/messages/messages' })
      }, 400)
    })
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
  noticePinClosed.value = false
  noticePinExpanded.value = false
  noticeDismissedText.value = ''
  groupMeta.value = null
  title.value = decodeURIComponent(q.title || '聊天')
  peerNickname.value = decodeURIComponent(q.nickname || '')
  remark.value = decodeURIComponent(q.remark || '')
  if (isPrivate.value) {
    rpForm.packet_type = 1
    rpForm.total_count = '1'
  } else {
    const enabled = enabledRpTypeIds()
    rpForm.packet_type = enabled.indexOf(2) >= 0 ? 2 : enabled[0] || 1
    rpForm.total_count = String(rpCountRangeForType(rpForm.packet_type).min)
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
  // 进房立刻清本地未读，避免返回列表时角标还在
  markRead().catch(() => {})

  roomAlive = true
  bindForegroundResume()
  await ensureUser()
  off = onImEvent((type, data) => {
    if (type === 'im.resume') {
      softRefreshHistory()
      if (!isPrivate.value) loadGroupMeta().catch(() => {})
      return
    }
    if (type === 'private.message' || type === 'group.message') {
      const msg = (data && data.message) || data
      if (msg && sameRoom(msg)) {
        if (appendLocalMessage(msg)) markRead().catch(() => {})
      }
      return
    }
    if (type === 'redpacket.relay_next') {
      const msg = (data && data.message) || null
      if (msg && sameRoom(msg)) {
        if (appendLocalMessage(msg)) markRead().catch(() => {})
      } else if (!msg) {
        const gid = (data && data.group_id) | 0
        if (!isPrivate.value && gid && gid === (meta.value.group | 0)) {
          uni.showToast({ title: '🧧 接龙下一包已发出', icon: 'none' })
          softRefreshHistory()
        } else if (isPrivate.value || !gid) {
          uni.showToast({ title: '🧧 接龙下一包已发出', icon: 'none' })
        }
      }
      return
    }
    if (type === 'message.recalled') {
      const msg = (data && data.message) || data
      applyRecalled(msg)
      return
    }
    if (type === 'redpacket.update') {
      softRefreshHistory()
      return
    }
    if (type === 'group.kicked') {
      const gid = (data && data.group_id) | 0
      if (!isPrivate.value && gid && gid === (meta.value.group | 0)) {
        leaveRoomToList('你已被移出群组')
      }
      return
    }
    if (type === 'group.mute_all_changed' || type === 'group.forbid_changed' || type === 'group.updated') {
      const gid = (data && (data.group_id || (data.group && data.group.id))) | 0
      if (!isPrivate.value && gid && gid === (meta.value.group | 0)) {
        if (type === 'group.forbid_changed' && data) mergeGroupMeta(data)
        else loadGroupMeta().catch(() => {})
      }
    }
  })
  try {
    await imConnect()
    await Promise.all([fetchHistory(), loadGroupMeta()])
    // 大厅「一键复制密令」跳转客服后：自动把密令发出去
    if (isPrivate.value && (meta.value.peer | 0) === 88888888) {
      try {
        const pending = String(uni.getStorageSync('fans_hub_pending_cs_secret') || '').trim()
        if (pending) {
          uni.removeStorageSync('fans_hub_pending_cs_secret')
          text.value = pending
          await sendText()
        }
      } catch (_) {}
    }
  } catch (e) {
    uni.showToast({ title: e.message || '连接失败', icon: 'none' })
  } finally {
    bootDoneAt = Date.now()
    scrollToLatest()
  }
})

onShow(() => {
  if (!getToken() || !roomAlive) return
  bindForegroundResume()
  resumeFromBackground('chat-onShow')
  // 刚进房时 onShow 会跟 onLoad 抢历史，容易滚不到底；短窗口只补滚
  if (bootDoneAt && Date.now() - bootDoneAt < 1800) {
    scrollToLatest()
  } else {
    softRefreshHistory()
  }
  if (!isPrivate.value) loadGroupMeta().catch(() => {})
})

onUnload(() => {
  markRead()
    .catch(() => {})
    .finally(() => {
      clearActiveChat()
    })
  roomAlive = false
  if (scrollTimers.length) {
    scrollTimers.forEach((t) => clearTimeout(t))
    scrollTimers = []
  }
  if (off) off()
})
</script>

<style scoped>
/* 详情/会话样式走 chat.bundle + chat-888-parity；此处仅房间页微补 */
.chat-rp-detail-myamt text {
  font-size: 22px;
  font-weight: 800;
}
.chat-rp-record-avatar image {
  width: 100%;
  height: 100%;
}
</style>

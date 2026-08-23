<template>
  <view class="chat-room-page" :style="roomSafeStyle">
    <TopBar />
    <view class="chat-room-pane open">
      <view class="chat-hero-hd">
        <view class="chat-hero-back" hover-class="chat-hero-back--active" @click="goBack">
          <text class="chat-hero-back-char">‹</text>
        </view>
        <view class="chat-hero-title chat-room-title">{{ title }}</view>
        <view class="chat-hero-more" hover-class="chat-hero-back--active" @click="openMore">
          <text class="chat-hero-back-char">···</text>
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
            <view class="chat-notice-pin-text">{{ noticePinDisplay }}</view>
            <view v-if="noticePinExpanded && noticePinImages.length" class="chat-notice-pin-imgs">
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
          @scroll="onChatScroll"
          @click="closePanels"
        >
          <view
            v-if="hasOlderMessages"
            class="chat-load-older"
            @click.stop="revealOlderMessages"
          >
            <text class="chat-load-older-txt">{{ historyLoadingOlder ? '加载中…' : '查看更早消息' }}</text>
          </view>
          <view
            v-for="m in visibleMessages"
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
                <image :src="msgAvatar(m)" mode="aspectFill" lazy-load />
              </view>
              <view class="chat-msg-main" :class="{ 'has-niuniu': isNiuniu(m) || isFissionShare(m) }">
                <view v-if="showSender(m)" class="chat-msg-nick locked">{{ senderName(m) }}</view>

                <!-- 红宝：对齐 888 bubble-rp；信封用 CSS 代替内联 SVG，减轻列表主线程 -->
                <view
                  v-if="isRp(m)"
                  class="chat-rp-card bubble-rp"
                  :class="rpCardClass(m)"
                  @click="onRpTap(m)"
                  @longpress="onMsgLongPress(m)"
                >
                  <view class="rp-top">
                    <view class="rp-icon-box rp-icon-css" aria-hidden="true">
                      <text class="rp-icon-kai">開</text>
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

                <!-- 红宝尾数牛牛：倒计时在子组件内 tick，避免整表每秒 invalidate -->
                <ChatNiuniuCard
                  v-else-if="isNiuniu(m)"
                  :message="m"
                  :time-text="msgTime(m)"
                  @tap="onNiuniuTap(m)"
                  @longpress="onMsgLongPress(m)"
                  @expired="onNiuniuCardExpired"
                />

                <view
                  v-else-if="isFissionShare(m)"
                  class="chat-fission-share-card"
                  :class="{ me: isMine(m) }"
                  @longpress="onMsgLongPress(m)"
                >
                  <view class="chat-fission-share-hd">
                    <text class="chat-fission-share-tag">官方活动</text>
                    <text class="chat-fission-share-time">{{ msgTime(m) }}</text>
                  </view>
                  <view class="chat-fission-share-env" @click.stop="openFissionFromMsg(m)">
                    <text class="chat-fission-share-title">裂变红宝</text>
                    <text class="chat-fission-share-pool">¥ {{ fissionSharePool(m) }} 奖金池</text>
                    <text class="chat-fission-share-progress">当前 {{ fissionShareQuals(m) }} / {{ fissionShareCap(m) }} 份资格</text>
                    <view class="chat-fission-share-cta" :class="{ disabled: fissionShareEnded(m) }">
                      {{ fissionShareEnded(m) ? '活动已结束' : '点击拆开红包' }}
                    </view>
                    <text class="chat-fission-share-risk">72小时未集齐资格，红包池作废</text>
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
                  <image class="chat-sticker-img" :src="stickerUrl(m)" mode="aspectFit" lazy-load />
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
                <view
                  v-else
                  class="chat-bubble text-msg"
                  @touchstart="onTextMsgHoldStart(m, $event)"
                  @touchmove="onTextMsgHoldMove($event)"
                  @touchend="onTextMsgHoldEnd"
                  @touchcancel="onTextMsgHoldEnd"
                  @mousedown="onTextMsgHoldStart(m, $event)"
                  @mouseup="onTextMsgHoldEnd"
                  @mouseleave="onTextMsgHoldEnd"
                >
                  <text class="content">{{ msgText(m) }}</text>
                  <text class="meta">{{ msgTime(m) }}</text>
                </view>
              </view>
            </template>
          </view>
          <view id="chat-bottom-anchor" class="chat-bottom-anchor" />
        </scroll-view>

        <view
          v-if="showJumpLatest"
          class="chat-jump-latest"
          hover-class="chat-jump-latest--active"
          :style="jumpLatestStyle"
          @click.stop="jumpToLatest"
        >
          <text class="chat-jump-latest-ico chat-tool-glyph">↓</text>
        </view>

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
                <view
                  v-for="(em, idx) in activeEmojis"
                  :key="'em' + idx"
                  class="emoji-item"
                  @click="insertEmoji(em)"
                >
                  <image
                    v-if="!emojiImgFailed[em]"
                    class="emoji-item-img"
                    :src="emojiImgUrl(em)"
                    mode="aspectFit"
                    lazy-load
                    @error="onEmojiImgError(em)"
                  />
                  <text v-else class="emoji-item-fallback">{{ em }}</text>
                </view>
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
            <view
              v-else-if="!hasRecharged && !isOfficialGroup"
              class="chat-attach-item chat-attach-item-muted"
              @click="onAttachPick('rp')"
            >
              <text class="chat-attach-icon">🧧</text>
              <text>红包</text>
            </view>
            <!-- 普通多包模式前台暂隐；入口统一为原「单结果」，展示名「尾数牛牛」 -->
            <view v-if="canStartNiuniu" class="chat-attach-item" @click="onAttachPick('niuniu_single')">
              <text class="chat-attach-icon">🐂</text>
              <text>{{ niuniuLooping && niuniuLoopMode === 2 ? '继续连开' : '尾数牛牛' }}</text>
            </view>
            <view v-if="canStopNiuniu" class="chat-attach-item" @click="onAttachPick('niuniu_stop')">
              <text class="chat-attach-icon">⏹</text>
              <text>关闭牛牛</text>
            </view>
            <view v-if="canEnterYxx" class="chat-attach-item" @click="onAttachPick('yxx')">
              <text class="chat-attach-icon">🎲</text>
              <text>鱼虾蟹</text>
            </view>
            <view v-if="canStopYxx" class="chat-attach-item" @click="onAttachPick('yxx_stop')">
              <text class="chat-attach-icon">⏹</text>
              <text>关闭鱼虾蟹</text>
            </view>
            <view v-if="isPrivate && hasRecharged" class="chat-attach-item" @click="onAttachPick('transfer')">
              <text class="chat-attach-icon">💸</text>
              <text>转账</text>
            </view>
            <view
              v-else-if="isPrivate"
              class="chat-attach-item chat-attach-item-muted"
              @click="onAttachPick('transfer')"
            >
              <text class="chat-attach-icon">💸</text>
              <text>转账</text>
            </view>
          </view>

          <view class="chat-composer chat-footer">
            <view v-if="canCap('emoji')" id="chatEmojiBtn" class="chat-tool-icon" @click="toggleEmoji">
              <text class="chat-tool-glyph" aria-hidden="true">☺</text>
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
              <text class="chat-tool-glyph chat-tool-glyph--plus" aria-hidden="true">＋</text>
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
                >{{ mineCountBtnLabel(n) }}</view>
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
              <view class="chat-rp-field-hint">{{ rpCountFieldHint }}</view>
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

    <!-- 尾数牛牛购入 -->
    <view v-if="showNiuniu" class="chat-rp-send-pane open" aria-hidden="false">
      <view class="chat-hero-hd">
        <view class="chat-rp-cancel" @click="showNiuniu = false">取消</view>
        <view class="chat-hero-title">尾数牛牛购入</view>
        <view class="chat-hero-spacer" />
      </view>
      <view class="chat-rp-send-main">
        <view class="chat-rp-send-body" style="padding:16px">
          <view class="chat-rp-balance-hint">
            <text>可用红宝：</text>
            <text class="chat-rp-bal-strong">￥{{ money(walletBalance) }}</text>
          </view>
          <view class="nn-sheet-tip">
            每份 {{ (niuniuSheet && niuniuSheet.round && niuniuSheet.round.share_price) || 100 }} 进入奖池；开出尾数即红包金额入账（如 42=0.42 元），结算后再按牛型分奖金。
          </view>
          <view class="chat-rp-field">
            <text class="chat-rp-lab">购入包数</text>
            <input class="chat-rp-bless-input" type="number" v-model="niuniuBuyCount" />
          </view>
        </view>
        <view class="chat-rp-send-ft">
          <view class="chat-rp-submit-btn" :class="{ disabled: niuniuBusy }" @click="submitNiuniuBuy">
            {{ niuniuBusy ? '购入中…' : '确认购入' }}
          </view>
        </view>
      </view>
    </view>

    <GrabSlider ref="grabSliderRef" />

    <!-- 牛牛领取：立体描边红包框（无背景图/无领取按钮图） -->
    <view v-if="showNiuniuCover" class="nn-cover-mask" @click="closeNiuniuCover">
      <view class="nn-cover-card" @click.stop>
        <view class="nn-cover-frame">
          <view class="nn-cover-close" @click.stop="closeNiuniuCover">×</view>
          <text class="nn-cover-title">尾数牛牛红宝</text>
          <view class="nn-cover-center">
            <text class="nn-cover-l1">#{{ niuniuCoverInfo.id || '--' }} 入场:{{ niuniuCoverInfo.price }}</text>
            <text class="nn-cover-l2">包数:{{ niuniuCoverInfo.packs }} 官方手续费:{{ niuniuCoverInfo.feePct }}%</text>
            <text class="nn-cover-l3">奖池(扣除{{ niuniuCoverInfo.feePct }}%后)</text>
            <text class="nn-cover-l4">{{ niuniuCoverInfo.pool }}</text>
          </view>
          <text class="nn-cover-sub">{{ niuniuCoverSubText }}</text>
          <view class="nn-cover-open" :class="{ busy: niuniuBusy }" @click="confirmNiuniuCoverOpen">
            {{ niuniuBusy ? '…' : '開' }}
          </view>
        </view>
      </view>
    </view>

    <!-- 单包开启结果：尾数金额入账（如 02→0.02） -->
    <view v-if="showNiuniuPackResult" class="nn-pack-result-mask" @click="dismissNiuniuPackResult">
      <view class="nn-pack-result-card" @click.stop>
        <text class="nn-pack-result-title">{{ (niuniuPackResult && (niuniuPackResult.share_count|0) > 1) ? '本局结果' : '本包结果' }}</text>
        <text class="nn-pack-result-amt">{{ formatNiuniuPacketAmount(niuniuPackResult) }}</text>
        <text class="nn-pack-result-line">{{ formatNiuniuPackResultLine(niuniuPackResult) }}</text>
        <text class="nn-pack-result-hint">尾数金额已入账</text>
        <text v-if="niuniuPackRemain > 0" class="nn-pack-result-remain">还剩 {{ niuniuPackRemain }} 包，需再开 {{ niuniuPackRemain }} 次</text>
        <view class="nn-pack-result-btn" :class="{ busy: niuniuBusy }" @click="onNiuniuPackResultContinue">
          {{ niuniuBusy ? '…' : (niuniuPackRemain > 0 ? '继续开下一包' : '查看明细') }}
        </view>
      </view>
    </view>

    <!-- 牛牛领取明细：与 hash/尾数排序一致，仅高亮自己 -->
    <view
      v-if="showNiuniuDetail"
      class="chat-sub-pane open chat-detail-overlay"
      aria-hidden="false"
      :style="appSubPaneStyle"
    >
      <view class="chat-hero-hd">
        <view class="chat-hero-back" hover-class="chat-hero-back--active" @click="closeNiuniuDetail">
          <text class="chat-hero-back-char">‹</text>
        </view>
        <view class="chat-hero-title">领取明细</view>
        <view class="chat-hero-spacer" />
      </view>
      <view class="chat-sub-main nn-detail-main">
        <view class="nn-detail-summary" v-if="niuniuDetailRound">
          <text>奖池 {{ niuniuDetailPoolText }}</text>
          <text
            v-if="niuniuVerifyLabel(niuniuDetailRound)"
            class="nn-detail-verify"
            @click.stop="openNiuniuVerify"
          >波场验证 ›</text>
        </view>
        <view class="nn-detail-frame">
          <view class="nn-detail-head">
            <text class="nn-dh-av">头像</text>
            <text class="nn-dh-name">昵称</text>
            <text class="nn-dh-amt">金额/份</text>
            <text class="nn-dh-res">结果</text>
            <text class="nn-dh-win">奖金</text>
          </view>
          <scroll-view scroll-y class="nn-detail-scroll" :show-scrollbar="true" :enable-flex="true">
            <view
              v-for="(row, idx) in niuniuDetailRows"
              :key="row._key || row.id || idx"
              class="nn-detail-person"
              :class="{ mine: row.is_mine }"
            >
              <view class="nn-dh-av">
                <image class="nn-user-av" :src="avatarSrc(row.avatar)" mode="aspectFill" />
              </view>
              <text class="nn-dh-name">{{ row.is_mine ? '我' : (row.nickname || ('用户' + row.user_id)) }}</text>
              <text class="nn-dh-amt">{{ formatNiuniuDetailAmountShares(row) }}</text>
              <view class="nn-dh-res-wrap">
                <text class="nn-dh-res">{{ formatNiuniuDetailResultShort(row) }}</text>
                <text v-if="row.claimed_at" class="nn-dh-time">{{ formatRpTime(row.claimed_at) }}</text>
                <text v-else class="nn-dh-time">未领取</text>
              </view>
              <text class="nn-dh-win" :class="{ win: Number(row.win_amount) > 0 }">{{ formatNiuniuBonus(row.win_amount) }}</text>
            </view>
            <view v-if="!niuniuDetailRows.length" class="nn-detail-empty">暂无明细</view>
          </scroll-view>
        </view>
      </view>
    </view>

    <!-- 红包详情：对齐 888 #chatRpDetailPane -->
    <view
      v-if="detailVisible"
      id="chatRpDetailPane"
      class="chat-sub-pane open chat-detail-overlay"
      aria-hidden="false"
      :style="appSubPaneStyle"
    >
      <view class="chat-hero-hd">
        <view class="chat-hero-back" hover-class="chat-hero-back--active" @click="closeRpDetail">
          <text class="chat-hero-back-char">‹</text>
        </view>
        <view class="chat-hero-title">红包详情</view>
        <view class="chat-hero-spacer" />
      </view>
      <view class="chat-sub-main">
        <view id="chatRpDetailBody">
          <view
            v-if="grabErrorTip"
            class="chat-rp-grab-error"
            @click="grabErrorTip = ''"
          >
            <text class="chat-rp-grab-error-title">无法领取</text>
            <text class="chat-rp-grab-error-msg">{{ grabErrorTip }}</text>
            <text class="chat-rp-grab-error-close">关闭</text>
          </view>
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
            <view v-if="detailFairTip" class="chat-rp-fair-sub">{{ detailFairTip }}</view>
            <view
              v-if="mineSettleTip"
              class="chat-rp-mine-summary"
              :class="{ 'is-safe': mineSettleSafe }"
            >{{ mineSettleTip }}</view>
          </view>
          <view
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
          </view>
          <!-- 领完/过期后：验证区与隐私提示放在领取列表下方 -->
          <view v-if="detail" class="chat-rp-detail-foot">
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
            <view v-if="detailLocked" class="chat-rp-privacy-tip locked">🔒 隐私群：不可点击查看对方资料</view>
          </view>
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
import ChatNiuniuCard from '../../components/ChatNiuniuCard.vue'
import '../../styles/chat.bundle.css'
import '../../styles/chat-room-uni-adapter.css'
import '../../styles/chat-rp-send-uni-adapter.css'
import '../../styles/chat-888-parity.css'
import { apiRequest, fetchProfile, getToken, goLoginIfUnauthorized, uploadSticker } from '../../utils/auth.js'
import { getApiBase, getImgBase, learnUploadCdnFromUrl, ensureAbsoluteHttpUrl, packagedStaticUrl, resolveStaticRequestUrl } from '../../utils/config.js'
import { assetBase, applyServerCopy, copyState, localeState, tt } from '../../utils/i18n.js'
import {
  avatarSrc,
  isRecalled,
  isSystemMsg,
  msgExtra,
  msgType,
  normalizeMessage,
  publicUrl,
  recallTip,
} from '../../utils/chat.js'
import {
  clearActiveChat,
  getActiveChat,
  saveActiveChat,
} from '../../utils/chat-route.js'
import { safeNavigateBack, HOME_TAB } from '../../utils/nav.js'
import {
  applySafeAreaCssVars,
  getSafeAreaInsets,
  measureChatOverlayTop,
} from '../../utils/safe-area.js'
import { COMMON_EMOJIS, loadEmojiTree, emojiTwemojiUrl } from '../../utils/emoji.js'
import { setInboxMyId, noteConversationRead } from '../../utils/im-inbox.js'
import { playOpenRedPacketSound } from '../../utils/notify-sound.js'
import { setGroupNotifyMuted } from '../../utils/group-notify-mute.js'
import { loadWalletBootstrap, money } from '../../utils/wallet.js'
import stickerAsciiAlias from '../../static/data/sticker-ascii-alias.json'
import {
  bindForegroundResume,
  fetchGroupInfo,
  grabRedPacket,
  imConnect,
  imSend,
  loadHistory,
  markConversationRead,
  niuniuBuy,
  niuniuClaim,
  niuniuDetail,
  niuniuStart,
  niuniuStop,
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
/** 渲染窗口：默认只画最近 N 条；点「查看更早」先扩本地，并继续 before_id 拉库 */
const MSG_RENDER_CAP = 40
const MSG_OLDER_PAGE = 40
const msgRevealCount = ref(MSG_RENDER_CAP)
/** 仅在「带 before_id 的请求」确认没有更多时才为 true；首屏绝不能据此关掉按钮 */
const historyExhausted = ref(false)
const historyLoadingOlder = ref(false)
const visibleMessages = computed(() => {
  const all = messages.value || []
  const n = Math.max(MSG_RENDER_CAP, msgRevealCount.value | 0)
  if (all.length <= n) return all
  return all.slice(all.length - n)
})
const hasOlderMessages = computed(() => {
  if (historyLoadingOlder.value) return true
  const all = messages.value || []
  const n = Math.max(MSG_RENDER_CAP, msgRevealCount.value | 0)
  if (all.length > n) return true
  return !historyExhausted.value
})
async function revealOlderMessages() {
  if (historyLoadingOlder.value) return
  const all = messages.value || []
  const n = Math.max(MSG_RENDER_CAP, msgRevealCount.value | 0)
  // 本地还有未展示：先扩窗口（立刻看到更多）
  if (all.length > n) {
    msgRevealCount.value = n + MSG_OLDER_PAGE
  }
  // 本地已全部展示：只要服务端未确认耗尽，就必须继续拉更早
  if (historyExhausted.value) return

  const oldest = (messages.value || [])[0]
  const beforeId = oldest ? ((oldest.id | 0) || 0) : 0
  if (beforeId <= 0) {
    // 没有可分页 id：若本地也已展完，才关掉按钮
    if ((messages.value || []).length <= Math.max(MSG_RENDER_CAP, msgRevealCount.value | 0)) {
      historyExhausted.value = true
    }
    return
  }

  historyLoadingOlder.value = true
  try {
    const data = {
      conversation_type: meta.value.type | 0,
      limit: MSG_OLDER_PAGE,
      before_id: beforeId,
    }
    if ((meta.value.type | 0) === 2) data.group_id = meta.value.group | 0
    else {
      data.to_user_id = meta.value.peer | 0
      const cid = privateCid()
      if (cid) data.conversation_id = cid
    }
    const packet = await loadHistory(data)
    const body = (packet && packet.data) || {}
    const incoming = Array.isArray(body.list)
      ? body.list.slice()
      : Array.isArray(body.messages)
        ? body.messages.slice()
        : []
    if (!incoming.length) {
      historyExhausted.value = true
      return
    }
    const map = new Map()
    incoming.forEach((m) => {
      map.set(String(msgId(m)), normalizeMessage(m))
    })
    ;(messages.value || []).forEach((m) => {
      map.set(String(msgId(m)), m)
    })
    const merged = Array.from(map.values()).sort((a, b) => {
      const ai = (a.id | 0) || 0
      const bi = (b.id | 0) || 0
      if (ai && bi && ai !== bi) return ai - bi
      return ((a.createtime | 0) || 0) - ((b.createtime | 0) || 0)
    })
    const prevLen = (messages.value || []).length
    messages.value = merged
    const added = Math.max(0, merged.length - prevLen)
    // 扩到能盖住新拉到的更早消息
    msgRevealCount.value = Math.max(
      MSG_RENDER_CAP,
      (msgRevealCount.value | 0) + Math.max(MSG_OLDER_PAGE, added)
    )
    if (incoming.length < MSG_OLDER_PAGE) {
      historyExhausted.value = true
    }
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '加载失败', icon: 'none' })
  } finally {
    historyLoadingOlder.value = false
  }
}
const scrollInto = ref('')
const scrollTop = ref(0)
const meta = ref({ type: 1, peer: 0, group: 0, conversationId: '' })
const myAvatar = ref('')
const myUserId = ref(0)
const showRp = ref(false)
const showTransfer = ref(false)
const showNiuniu = ref(false)
const niuniuBusy = ref(false)
const niuniuSheet = ref(null)
const niuniuBuyCount = ref('1')
const showNiuniuDetail = ref(false)
const showNiuniuCover = ref(false)
const niuniuCoverRoundId = ref(0)
const niuniuCoverRemain = ref(0)
const niuniuCoverGameMode = ref(0)
const niuniuCoverRound = ref(null)
const showNiuniuPackResult = ref(false)
const niuniuPackResult = ref(null)
const niuniuPackRemain = ref(0)
const niuniuPackRoundId = ref(0)
const niuniuDetailRound = ref(null)
const niuniuDetailRows = ref([])
/** 房间页 CSS 变量：保证 App 浮层能继承到真实顶栏高度 */
const roomSafeStyle = ref({})
/** App 详情浮层内联 top（H5 为空，走 CSS，避免网页多垫） */
const appSubPaneStyle = ref({})
/** App 回到底部按钮用像素 bottom（env(safe-area) 在 APK 常为 0） */
const jumpLatestStyle = ref({})
function refreshChatSafeLayout() {
  const r = applySafeAreaCssVars()
  const overlayTop = (r && r.overlayTop) || measureChatOverlayTop()
  const insetTop = (r && r.top != null ? r.top : getSafeAreaInsets().top) || 0
  const insetBottom = (r && r.bottom != null ? r.bottom : getSafeAreaInsets().bottom) || 0
  roomSafeStyle.value = {
    '--chat-overlay-top': overlayTop + 'px',
    '--safe-area-inset-top': insetTop + 'px',
    '--safe-area-inset-bottom': insetBottom + 'px',
  }
  // #ifdef APP-PLUS
  appSubPaneStyle.value = { top: overlayTop + 'px' }
  jumpLatestStyle.value = {
    bottom: 78 + insetBottom + 'px',
  }
  // #endif
  // #ifndef APP-PLUS
  jumpLatestStyle.value = {}
  // #endif
}
const niuniuDetailPoolText = computed(() => {
  const r = niuniuDetailRound.value
  if (!r) return '0'
  const n = Number(r.distributable != null ? r.distributable : r.pool_amount)
  return (isNaN(n) ? 0 : n).toFixed(2)
})
const niuniuCoverInfo = computed(() => {
  const r = niuniuCoverRound.value || {}
  const id = (r.id | 0) || (niuniuCoverRoundId.value | 0) || 0
  const priceN = Number(r.share_price != null ? r.share_price : 100)
  const price = isNaN(priceN) ? 100 : Math.round(priceN)
  const packs = (r.share_count | 0) || 0
  const rate = Number(r.fee_rate)
  const feePct = !isNaN(rate) && rate > 0 ? Math.round(rate * 1000) / 10 : 3
  let poolN = Number(r.distributable)
  if (isNaN(poolN) || poolN <= 0) {
    const pool = Number(r.pool_amount != null ? r.pool_amount : 0)
    const fee = !isNaN(rate) && rate > 0 ? rate : 0.03
    poolN = isNaN(pool) ? 0 : pool * (1 - fee)
  }
  return {
    id,
    price,
    packs,
    feePct,
    pool: (isNaN(poolN) ? 0 : poolN).toFixed(2),
  }
})
const niuniuCoverSubText = computed(() => {
  if ((niuniuCoverGameMode.value | 0) === 2) {
    return '无论购入多少包，开启一次即可查看结果'
  }
  const n = niuniuCoverRemain.value | 0
  if (n > 1) return '点一次开一包，还剩 ' + n + ' 包'
  if (n === 1) return '最后一包，开启后查看开奖明细'
  return '点一次开一包，全部开完后查看明细'
})
/** 牛牛倒计时归零补拉防抖（实际 tick 在 ChatNiuniuCard） */
let niuniuZeroRefreshAt = 0
const showEmoji = ref(false)
const showSticker = ref(false)
const showAttach = ref(false)
const walletBalance = ref(0)
const walletFrozen = ref(0)
/** 曾成功充值：解锁私聊/非官方群发抢与转账；未充值仍可在官方群发/抢（默认 false，等 profile 回填） */
const hasRecharged = ref(false)
const rpSending = ref(false)
const transferSending = ref(false)
const transferForm = reactive({ amount: '', remark: '' })
const mediaSending = ref(false)
const grabbing = ref(false)
const detailVisible = ref(false)
const detail = ref(null)
const grabErrorTip = ref('')
const moreVisible = ref(false)
const myGrabAmount = ref('')
const canGrabDetail = ref(false)
const grabSliderRef = ref(null)
const emojiGroups = ref([{ id: 'common', name: '常用', chars: COMMON_EMOJIS.slice() }])
const emojiGroupIdx = ref(0)
const emojiImgFailed = ref({})
const activeEmojis = computed(() => {
  const g = emojiGroups.value[emojiGroupIdx.value] || emojiGroups.value[0]
  return (g && g.chars) || COMMON_EMOJIS
})
function emojiImgUrl(em) {
  return emojiTwemojiUrl(em)
}
function onEmojiImgError(em) {
  if (!em || emojiImgFailed.value[em]) return
  emojiImgFailed.value = Object.assign({}, emojiImgFailed.value, { [em]: 1 })
}
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
/** 是否贴底：用户上翻看历史时为 false，避免软刷新/牛牛更新把列表拽回底部 */
let stickToBottom = true
const showJumpLatest = ref(false)
let chatScrollViewport = 0

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

/** 折叠只展示一行预览（去换行+截断），展开才出全文；勿依赖 CSS 对 uni-text 省略 */
const noticePinDisplay = computed(() => {
  const raw = String(noticePinText.value || '').trim()
  if (!raw) return '群公告'
  if (noticePinExpanded.value) return '群公告: ' + raw
  const one = raw.replace(/[\r\n\t]+/g, ' ').replace(/\s{2,}/g, ' ').trim()
  const max = 22
  const preview = one.length > max ? one.slice(0, max) + '.....' : one
  return '群公告: ' + preview
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

const canStartNiuniu = computed(() => {
  if (isPrivate.value) return false
  const pol = groupPolicy.value || {}
  return !!(pol.can_start_niuniu || (pol.niuniu_enabled && ((groupMeta.value && groupMeta.value.my_role) | 0) >= 2))
})

const niuniuLooping = computed(() => {
  const pol = groupPolicy.value || {}
  return !!(pol.niuniu_looping)
})

const niuniuLoopMode = computed(() => {
  const pol = groupPolicy.value || {}
  const m = (pol.niuniu_loop_mode | 0) || 1
  return m === 2 ? 2 : 1
})

const canStopNiuniu = computed(() => {
  if (isPrivate.value) return false
  const pol = groupPolicy.value || {}
  if (pol.can_stop_niuniu) return true
  return !!(niuniuLooping.value && ((groupMeta.value && groupMeta.value.my_role) | 0) >= 2)
})

const canEnterYxx = computed(() => {
  if (isPrivate.value) return false
  const pol = groupPolicy.value || {}
  if (pol.can_start_yxx) return true
  return !!(pol.yxx_enabled && pol.yxx_table_open)
})
const canStartYxx = computed(() => {
  if (isPrivate.value) return false
  const pol = groupPolicy.value || {}
  return !!pol.can_start_yxx
})
const canStopYxx = computed(() => {
  if (isPrivate.value) return false
  const pol = groupPolicy.value || {}
  return !!pol.can_stop_yxx
})

const forbidModes = computed(() => {
  const m = groupMeta.value || {}
  return m.forbid_modes || (groupPolicy.value && groupPolicy.value.forbid_modes) || {}
})

const isOfficialGroup = computed(() => {
  if (isPrivate.value) return false
  const pol = groupPolicy.value || {}
  if (pol.is_official === true || pol.is_official === 1) return true
  const g = (groupMeta.value && groupMeta.value.group) || {}
  return (g.is_recommend | 0) === 1
})

function rechargeGateTip(kind) {
  if (kind === 'transfer') return '未充值账号不能转账，请先充值'
  if (kind === 'grab') return '未充值账号仅可在官方群领取红包'
  return '未充值账号仅可在官方群发红包，请先充值'
}

/** 未充值：官方群可发红包；私聊/非官方群不可发 */
function canSendRpByRecharge() {
  return !!hasRecharged.value || !!isOfficialGroup.value
}

function canCap(cap) {
  if (cap === 'rp' && !canSendRpByRecharge()) return false
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

function canGrabInCurrentScene(packet) {
  if (hasRecharged.value) return true
  const scope = packet ? (packet.scope_type | 0) : (isPrivate.value ? 1 : 2)
  if (scope === 1) return false
  if (packet && (packet.group_id | 0) > 0) {
    // 详情里若无群标记，回退当前会话官方判定
    return isOfficialGroup.value
  }
  return isOfficialGroup.value
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

/** 扫雷赔付倍率：5→1.5 / 7→1.2 / 9→1.0 */
function mineCompensateRates() {
  return { 5: 1.5, 7: 1.2, 9: 1.0 }
}

function formatMineRate(rate) {
  const n = Number(rate)
  if (!isFinite(n) || n <= 0) return '1'
  return String(n).replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1')
}

function mineCountBtnLabel(count) {
  const rates = mineCompensateRates()
  const c = Number(count) || 0
  const rate = rates[c] != null ? rates[c] : 1
  return c + '/' + formatMineRate(rate) + '倍'
}

const mineCountOptions = computed(() => {
  const range = groupRpCountRange()
  const base = [5, 7, 9].filter((n) => n >= range.min && n <= range.max)
  return base.length ? base : [range.min]
})

const rpCountFieldHint = computed(() => {
  if ((rpForm.packet_type | 0) === 3) {
    const opts = mineCountOptions.value
    if (opts.length) return '扫雷固定 ' + opts.join('/')
    return '扫雷固定 5/7/9'
  }
  return rpCountHintLabel.value
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
  return (
    p(d.getMonth() + 1) +
    '-' +
    p(d.getDate()) +
    ' ' +
    p(d.getHours()) +
    ':' +
    p(d.getMinutes()) +
    ':' +
    p(d.getSeconds())
  )
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
function isNiuniu(m) {
  if (msgType(m) === 10) return true
  const ex = msgExtra(m)
  return !!(ex && ex.niuniu)
}
function isFissionShare(m) {
  if (msgType(m) === 11) return true
  const ex = msgExtra(m)
  return !!(ex && (ex.fission || ex.fission_share))
}
function fissionShareExtra(m) {
  return msgExtra(m) || {}
}
function fissionSharePool(m) {
  const p = Number(fissionShareExtra(m).pool)
  return isNaN(p) ? '0.00' : p.toFixed(2)
}
function fissionShareQuals(m) {
  return (fissionShareExtra(m).quals | 0) || 0
}
function fissionShareCap(m) {
  return (fissionShareExtra(m).cap | 0) || 100
}
function fissionShareEnded(m) {
  return !!(fissionShareExtra(m).ended | 0)
}
function openFissionFromMsg(m) {
  if (fissionShareEnded(m)) {
    uni.showToast({ title: '活动已结束', icon: 'none' })
    return
  }
  uni.switchTab({
    url: '/pages/fission/detail',
    fail: () => uni.navigateTo({ url: '/pages/fission/detail' }),
  })
}
function niuniuRound(m) {
  const ex = msgExtra(m)
  return (ex && ex.round) || {}
}
function niuniuRawPhase(m) {
  const ex = msgExtra(m)
  return String((ex && ex.phase) || niuniuRound(m).card_phase || 'buying')
}
/** 结合截止时间推算展示阶段（按需用 Date.now，不再依赖全局 1s tick） */
function niuniuPhase(m) {
  const raw = niuniuRawPhase(m)
  if (raw === 'result' || raw === 'void' || raw === 'refund') return raw
  const r = niuniuRound(m)
  const now = Math.floor(Date.now() / 1000)
  const buyEnd = r.buy_end_at | 0
  const claimEnd = r.claim_end_at | 0
  if (raw === 'buying') {
    if (buyEnd > 0 && buyEnd <= now) {
      if (claimEnd > now) return 'claim'
      if (claimEnd > 0 && claimEnd <= now) return 'result'
      return 'result'
    }
    return 'buying'
  }
  if (raw === 'claim') {
    if (claimEnd > 0 && claimEnd <= now) return 'result'
    return 'claim'
  }
  return raw
}
function niuniuDrand(m) {
  const r = niuniuRound(m)
  return r.drand_label || ('drand-#' + (r.drand_round || ''))
}
function niuniuRemainSec(m) {
  const phase = niuniuPhase(m)
  if (phase !== 'buying' && phase !== 'claim') return 0
  const r = niuniuRound(m)
  const endAt = phase === 'claim' ? (r.claim_end_at | 0) : (r.buy_end_at | 0)
  const now = Math.floor(Date.now() / 1000)
  if (endAt > 0) return Math.max(0, endAt - now)
  const fallback = phase === 'claim' ? (r.remain_claim | 0) : (r.remain_buy | 0)
  return Math.max(0, fallback)
}
function niuniuShowCountdown(m) {
  const phase = niuniuPhase(m)
  return (phase === 'buying' || phase === 'claim') && niuniuRemainSec(m) > 0
}
/** 倒计时归零：子组件上报后补拉（防抖，不每秒扫全表） */
function onNiuniuCardExpired() {
  const now = Math.floor(Date.now() / 1000)
  if (now - niuniuZeroRefreshAt < 2) return
  niuniuZeroRefreshAt = now
  softRefreshHistory()
}
function stopNiuniuTick() {
  // 兼容旧调用点；全局 tick 已移除，倒计时在 ChatNiuniuCard 内
}
function niuniuRemainText(m) {
  const sec = niuniuRemainSec(m)
  const mm = String(Math.floor(sec / 60)).padStart(2, '0')
  const ss = String(sec % 60).padStart(2, '0')
  return mm + ':' + ss
}
/** 用 niuniu.update / 购入回包就地刷新卡片，不必整页拉 history */
function applyNiuniuUpdateLocal(payload) {
  if (!payload || !roomAlive) return false
  const gid = (payload.group_id | 0) || (meta.value.group | 0)
  if (!isPrivate.value && gid && gid !== (meta.value.group | 0)) return false
  const message = payload.message && typeof payload.message === 'object' ? payload.message : null
  const extraRaw = payload.extra && typeof payload.extra === 'object' ? payload.extra : null
  const extra = extraRaw || (message ? msgExtra(message) : payload)
  const round = (extra && extra.round) || payload.round || (message && msgExtra(message).round) || null
  const roundId =
    ((extra && extra.round_id) | 0) ||
    ((round && round.id) | 0) ||
    ((payload.round_id) | 0) ||
    ((message && msgExtra(message).round_id) | 0) ||
    0
  const targetMsgId = (payload.message_id | 0) || ((message && message.id) | 0) || 0
  if (!roundId && !targetMsgId) return false
  const phase = String((extra && extra.phase) || (round && round.card_phase) || '')
  let patched = 0
  const rows = messages.value
  for (let i = 0; i < rows.length; i++) {
    const m = rows[i]
    if (!isNiuniu(m)) continue
    const ex = msgExtra(m)
    const rid = (ex.round_id | 0) || ((ex.round && ex.round.id) | 0) || 0
    const mid = msgId(m) | 0
    const hit = (roundId > 0 && rid === roundId) || (targetMsgId > 0 && mid === targetMsgId)
    if (!hit) continue
    const nextEx = Object.assign({}, ex, extra || {}, {
      niuniu: 1,
      round_id: roundId || rid,
      phase: phase || ex.phase,
      round: Object.assign({}, ex.round || {}, round || {}),
    })
    const next = Object.assign({}, m, message || {}, {
      extra: nextEx,
      content: (payload.content != null ? payload.content : (message && message.content)) || m.content,
    })
    rows[i] = next
    patched++
  }
  if (patched) {
    // 就地替换命中行，避免整表 slice 全量 invalidate
  }
  return patched > 0
}
function niuniuTitle(m) {
  const r = niuniuRound(m)
  if ((r.game_mode | 0) === 2) return '尾数牛牛'
  if (r.game_mode_label) {
    const lab = String(r.game_mode_label)
    if (lab.indexOf('单结果') >= 0) return '尾数牛牛'
    return lab
  }
  return '尾数牛牛'
}
function niuniuSummary(m) {
  const r = niuniuRound(m)
  const phase = niuniuPhase(m)
  const modeTip = ''
  if (phase === 'claim') {
    return '👥总参与 ' + (r.share_count || 0) + ' 份｜💰奖池 ' + (r.pool_amount || 0) + modeTip + '｜点击领取查看尾数'
  }
  if (phase === 'result') {
    return '🎊开奖完成｜可发放 ' + (r.distributable || 0) + modeTip + '｜点击查看明细'
  }
  if (phase === 'void') return '本局作废（0 份参与）'
  if (phase === 'refund') return '流局：扣手续费后已退回'
  return '👥 ' + (r.share_count || 0) + ' 份｜💰奖池 ' + (r.pool_amount || 0) + modeTip + '｜点击购入'
}
function niuniuCta(m) {
  const phase = niuniuPhase(m)
  if (phase === 'buying') return '点击购入参与'
  if (phase === 'claim') return '👉 点击领取本局红包'
  if (phase === 'result' || phase === 'refund') return '查看开奖明细'
  return '查看'
}
function niuniuPoolText(m) {
  const r = niuniuRound(m)
  const n = Number(r.pool_amount != null ? r.pool_amount : 0)
  return (isNaN(n) ? 0 : n).toFixed(2)
}
function niuniuFeePct(m) {
  const r = niuniuRound(m)
  const rate = Number(r.fee_rate)
  if (!isNaN(rate) && rate > 0) return Math.round(rate * 1000) / 10
  return 3
}
function niuniuShareCount(m) {
  return (niuniuRound(m).share_count | 0) || 0
}
function niuniuDistributableText(m) {
  const r = niuniuRound(m)
  let n = Number(r.distributable)
  if (isNaN(n) || n <= 0) {
    const pool = Number(r.pool_amount != null ? r.pool_amount : 0)
    const rate = Number(r.fee_rate)
    const fee = !isNaN(rate) && rate > 0 ? rate : 0.03
    n = isNaN(pool) ? 0 : pool * (1 - fee)
  }
  return (isNaN(n) ? 0 : n).toFixed(2)
}
function niuniuSharePrice(m) {
  const r = niuniuRound(m)
  const n = Number(r.share_price != null ? r.share_price : 100)
  return isNaN(n) ? 100 : Math.round(n)
}
function niuniuRoundId(m) {
  const r = niuniuRound(m)
  const ex = msgExtra(m)
  return (r.id | 0) || (ex.round_id | 0) || 0
}
function niuniuNeedsClaim(m) {
  const phase = niuniuPhase(m)
  if (phase !== 'claim' && phase !== 'result') return false
  const r = niuniuRound(m)
  if ((r.my_share_count | 0) <= 0) return false
  return !r.my_claimed
}
function niuniuBtnSrc(m) {
  if (niuniuNeedsClaim(m)) return '/static/niuniu/claim-hongbao.png'
  const phase = niuniuPhase(m)
  if (phase === 'buying') return '/static/niuniu/btn-buy.png'
  if (phase === 'claim') return '/static/niuniu/btn-claim.png'
  // 开奖明细按钮（ASCII 文件名，兼容部分 WebView 中文路径）
  return '/static/niuniu/btn-detail.png'
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

/** 历史消息里的中文贴纸文件名 → 拼音 ASCII，避免打包禁中文后旧消息空白 */
function remapStickerAsciiPath(url) {
  let s = String(url || '')
  if (!s || !stickerAsciiAlias) return s
  return s.replace(/([^/]+\.(?:png|jpg|jpeg|gif|webp))(?=$|[?#])/gi, (file) => {
    let key = file
    try {
      key = decodeURIComponent(file)
    } catch (e) {}
    const hit = stickerAsciiAlias[key] || stickerAsciiAlias[file]
    return hit || file
  })
}

/** 展示用：内置表情包优先走 999/打包 static；历史 /888 路径仅 remap 进 999，不再回写 888 */
function normalizeStickerUrl(url) {
  let s = remapStickerAsciiPath(String(url || '').trim())
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

  // 统一抽出 stickers/ 相对段（含历史 /888 消息）
  let rel = ''
  if (s.indexOf('/999/static/stickers/') === 0) {
    rel = s.slice('/999/static/'.length)
  } else if (s.indexOf('/888/static/stickers/') === 0) {
    rel = s.slice('/888/static/'.length)
  } else if (s.indexOf('/888/stickers/') === 0) {
    rel = 'stickers/' + s.slice('/888/stickers/'.length)
  } else if (s.indexOf('/stickers/') === 0) {
    rel = 'stickers/' + s.slice('/stickers/'.length)
  } else if (s.indexOf('static/stickers/') === 0) {
    rel = s.slice('static/'.length)
  } else if (s.indexOf('stickers/') === 0) {
    rel = s
  }

  // 内置 wechat 表情包：App 用打包本地 /static；H5 用 /999/static
  if (rel && rel.indexOf('stickers/') === 0 && rel.indexOf('stickers/uploads/') !== 0) {
    return packagedStaticUrl(rel)
  }

  if (s.startsWith('/uploads/')) {
    s = encodeUriPath(remapStickerAsciiPath(s))
    if (s.charAt(0) === '/') {
      s = ensureAbsoluteHttpUrl(s, getImgBase() || getApiBase()) || s
    }
    return s
  }
  if (s.startsWith('/')) {
    s = encodeUriPath(remapStickerAsciiPath(s))
    if (s.charAt(0) === '/') {
      s = ensureAbsoluteHttpUrl(s, getApiBase()) || s
    }
    return s
  }
  if (s.startsWith('static/')) {
    return packagedStaticUrl(s)
  }
  return packagedStaticUrl('static/' + s.replace(/^\/+/, ''))
}

/** 发给 IM 的 sticker url：只允许 /999/static/stickers（历史 /888 入站 remap 到 999） */
function stickerSendUrl(url) {
  let s = remapStickerAsciiPath(String(url || '').trim())
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
  // 打包本地 /static/stickers → 服务端 allow 的 /999/static/stickers
  if (s.indexOf('/static/stickers/') === 0) {
    return '/999' + s
  }
  if (s.indexOf('/999/static/stickers/') === 0) {
    return s
  }
  if (s.indexOf('/888/stickers/') === 0) {
    return '/999/static/stickers/' + s.slice('/888/stickers/'.length)
  }
  if (s.indexOf('/888/static/stickers/') === 0) {
    return '/999/static/stickers/' + s.slice('/888/static/stickers/'.length)
  }
  if (s.indexOf('static/stickers/') === 0) {
    return '/999/' + s
  }
  if (s.indexOf('stickers/') === 0) {
    return '/999/static/' + s
  }
  if (
    s.indexOf('/stickers/') === 0 ||
    s.indexOf('/uploads/stickers/') === 0
  ) {
    return s
  }
  return ''
}

function stickerUrl(m) {
  const ex = msgExtra(m)
  const raw = (ex && (ex.url || ex.fullurl)) || ''
  if (raw) return normalizeStickerUrl(raw)
  return packagedStaticUrl('stickers/wechat/face/weixiao.png')
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
  let msg = normalizeMessage(Object.assign({}, raw))
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
  // 自己发的消息强制贴底；他人消息仅在已贴底时跟随
  scrollToLatest(isMine(msg))
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

function isPlainTextMsg(m) {
  if (!m || isRecalled(m) || isSystemMsg(m)) return false
  if (msgType(m) !== 1 || isSticker(m)) return false
  if (isRp(m) || isNiuniu(m) || isFissionShare(m) || isTransfer(m)) return false
  return true
}

/** 仅纯文字消息可复制；长按需满 2 秒 */
const MSG_COPY_HOLD_MS = 2000
let textMsgHoldTimer = null
let textMsgHoldMsg = null
let textMsgHoldPoint = null

function clearTextMsgHold() {
  if (textMsgHoldTimer) {
    clearTimeout(textMsgHoldTimer)
    textMsgHoldTimer = null
  }
  textMsgHoldMsg = null
  textMsgHoldPoint = null
}

function msgCopyText(m) {
  if (!isPlainTextMsg(m)) return ''
  return String(m.content || m.text || '').trim()
}

function canCopyMsg(m) {
  return !!msgCopyText(m)
}

function copyMsgContent(m) {
  const text = msgCopyText(m)
  if (!text) {
    uni.showToast({ title: rpT('chat_msg_copy_fail', '复制失败'), icon: 'none' })
    return
  }
  uni.setClipboardData({
    data: text,
    success: () => uni.showToast({ title: rpT('chat_msg_copy_ok', '已复制'), icon: 'none' }),
    fail: () => uni.showToast({ title: rpT('chat_msg_copy_fail', '复制失败'), icon: 'none' }),
  })
}

function eventPoint(e) {
  const t = (e && e.touches && e.touches[0]) || (e && e.changedTouches && e.changedTouches[0])
  if (t) return { x: t.clientX || t.pageX || 0, y: t.clientY || t.pageY || 0 }
  if (e && typeof e.clientX === 'number') return { x: e.clientX, y: e.clientY }
  return null
}

function onTextMsgHoldStart(m, e) {
  clearTextMsgHold()
  if (!isPlainTextMsg(m)) return
  // 触摸与鼠标并存时：忽略随后的 mousedown，避免计时重置
  if (e && e.type === 'mousedown' && e.sourceCapabilities && e.sourceCapabilities.firesTouchEvents) return
  if (e && e.type === 'mousedown' && typeof e.button === 'number' && e.button !== 0) return
  textMsgHoldMsg = m
  textMsgHoldPoint = eventPoint(e)
  textMsgHoldTimer = setTimeout(() => {
    textMsgHoldTimer = null
    const target = textMsgHoldMsg
    textMsgHoldMsg = null
    textMsgHoldPoint = null
    if (!target) return
    onMsgLongPress(target)
  }, MSG_COPY_HOLD_MS)
}

function onTextMsgHoldMove(e) {
  if (!textMsgHoldTimer || !textMsgHoldPoint) return
  const p = eventPoint(e)
  if (!p) return
  if (Math.abs(p.x - textMsgHoldPoint.x) > 12 || Math.abs(p.y - textMsgHoldPoint.y) > 12) {
    clearTextMsgHold()
  }
}

function onTextMsgHoldEnd() {
  clearTextMsgHold()
}

function onMsgLongPress(m) {
  if (!m || isRecalled(m) || isSystemMsg(m)) return
  // 仅文字可复制；其它类型长按只用于撤回/删除
  const copyable = canCopyMsg(m)
  const recallable = canRecallLocal(m)
  if (!copyable && !recallable) return
  if (copyable && !recallable) {
    copyMsgContent(m)
    return
  }
  const items = []
  const actions = []
  if (copyable) {
    items.push(rpT('chat_msg_copy', '复制'))
    actions.push('copy')
  }
  if (recallable) {
    items.push(isPrivate.value ? rpT('chat_msg_delete', '删除消息') : rpT('chat_msg_recall', '撤回消息'))
    actions.push('recall')
  }
  uni.showActionSheet({
    itemList: items,
    success: async (res) => {
      const action = actions[res.tapIndex]
      if (action === 'copy') {
        copyMsgContent(m)
        return
      }
      if (action !== 'recall') return
      try {
        const packet = await recallMessage(m.id || m.msg_id)
        const body = (packet && packet.data) || {}
        const msg = body.message || Object.assign({}, m, { status: 2 })
        applyRecalled(msg)
        uni.showToast({
          title: isPrivate.value ? rpT('chat_msg_delete_ok', '已删除') : rpT('chat_msg_recall_ok', '已撤回'),
          icon: 'none',
        })
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
    if (!canSendRpByRecharge()) {
      uni.showToast({ title: rechargeGateTip('rp'), icon: 'none' })
      return
    }
    if (!canCap('rp')) {
      uni.showToast({ title: '本群禁止发红宝', icon: 'none' })
      return
    }
    openRpSend()
  } else if (kind === 'niuniu') {
    startNiuniuRound(1)
  } else if (kind === 'niuniu_single') {
    startNiuniuRound(2)
  } else if (kind === 'niuniu_stop') {
    stopNiuniuLoop()
  } else if (kind === 'yxx') {
    openYxxTable()
  } else if (kind === 'yxx_stop') {
    stopYxxTable()
  } else if (kind === 'transfer') {
    if (!hasRecharged.value) {
      uni.showToast({ title: rechargeGateTip('transfer'), icon: 'none' })
      return
    }
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
  if (!canSendRpByRecharge()) {
    uni.showToast({ title: rechargeGateTip('rp'), icon: 'none' })
    return
  }
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
  if (!hasRecharged.value) {
    uni.showToast({ title: rechargeGateTip('transfer'), icon: 'none' })
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
  if (!hasRecharged.value) {
    uni.showToast({ title: rechargeGateTip('transfer'), icon: 'none' })
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
    else await fetchHistory({ forceScroll: true })
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
    if (info && info.has_recharged != null) {
      hasRecharged.value = !!info.has_recharged
    }
  } catch (e) {
    try {
      const p = await fetchProfile()
      walletBalance.value = Number(p && (p.hongbao != null ? p.hongbao : p.money)) || 0
      walletFrozen.value = Number(p && p.hongbao_frozen) || 0
      if (p && p.has_recharged != null) {
        hasRecharged.value = !!p.has_recharged
      }
    } catch (e2) {}
  }
}

function cancelScrollToLatest() {
  if (scrollTimers.length) {
    scrollTimers.forEach((t) => clearTimeout(t))
    scrollTimers = []
  }
}

function resolveChatScrollViewport() {
  // #ifdef H5
  try {
    if (typeof document !== 'undefined') {
      const roots = document.querySelectorAll('.chat-room-page .chat-msg-scroll, .chat-msg-scroll')
      for (let i = 0; i < roots.length; i++) {
        const node = roots[i]
        const el =
          (node.querySelector && node.querySelector('.uni-scroll-view')) ||
          node
        const h = (el && el.clientHeight) || 0
        if (h > 0) {
          chatScrollViewport = h
          return h
        }
      }
    }
  } catch (e) {}
  // #endif
  return chatScrollViewport || 480
}

function onChatScroll(e) {
  const d = (e && e.detail) || {}
  const top = Number(d.scrollTop)
  const sh = Number(d.scrollHeight)
  if (!isFinite(top) || !isFinite(sh) || sh <= 0) return
  const vh = resolveChatScrollViewport()
  const dist = sh - top - vh
  const near = dist < 140
  if (stickToBottom && !near) {
    cancelScrollToLatest()
    // 清掉 into-view，避免消息列表重绘时再次把视口钉回底部
    scrollInto.value = ''
  }
  stickToBottom = near
  showJumpLatest.value = !near
}

function jumpToLatest() {
  showJumpLatest.value = false
  scrollToLatest(true)
}

function scrollToLatest(force) {
  if (!force && !stickToBottom) return
  if (force) {
    stickToBottom = true
    showJumpLatest.value = false
  }
  const last = messages.value[messages.value.length - 1]
  const id = last ? 'm' + msgId(last) : 'chat-bottom-anchor'
  scrollInto.value = ''
  cancelScrollToLatest()
  const bump = (target) => {
    if (!force && !stickToBottom) return
    scrollInto.value = ''
    nextTick(() => {
      if (!force && !stickToBottom) return
      // App / H5 / Safari：into-view 与 scroll-top 只取其一，避免双通道连撞
      // #ifdef APP-PLUS
      scrollInto.value = target
      // #endif
      // #ifndef APP-PLUS
      scrollInto.value = target
      scrollTop.value = scrollTop.value === 999999 ? 999998 : 999999
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
    // 合并为 1～2 次：首帧 + 图片/牛牛撑高后再补一次（三端共用，避免旧 6 连撞）
    scrollTimers.push(setTimeout(() => bump('chat-bottom-anchor'), 80))
    scrollTimers.push(setTimeout(() => bump(id), 220))
  })
}

async function loadStickers() {
  const baseItems = []
  try {
    // 表情包清单固定走 ESA 加速域（需 ESA 已配 CORS）
    const stickerUrl = 'https://shd12h.ykjab.com/999/static/data/stickers.json'
    const res = await new Promise((resolve, reject) => {
      uni.request({
        url: stickerUrl,
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
      // url 固定 /999/static/stickers；fullurl 用展示地址（App 可走打包 static）
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
    else await fetchHistory({ forceScroll: true })
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '发送失败', icon: 'none' })
  }
}

async function uploadCommonFile(filePath) {
  // 上传必须打 API；imgUri 可能是 CDN，没有 /api/common/upload
  const url = ensureAbsoluteHttpUrl('/api/common/upload', getApiBase())
  if (!url) throw new Error('接口地址未就绪，请检查网络后重试')
  const token = getToken()
  const up = await new Promise((resolve, reject) => {
    uni.uploadFile({
      url,
      filePath,
      name: 'file',
      header: token ? { token } : {},
      success: (res) => {
        try {
          const body = JSON.parse((res && res.data) || '{}')
          if ((body && body.code) !== 1) {
            const msg = body.msg || body.message || '上传失败'
            goLoginIfUnauthorized(body.code, msg)
            reject(new Error(msg))
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
  if (fullRaw) learnUploadCdnFromUrl(fullRaw)
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
  // 优先接口返回的 OSS fullurl；勿用本站 imgUri 拼出来的地址盖掉阿里云
  let full = ''
  if (/aliyuncs\.com|oss-accelerate/i.test(fullRaw)) {
    full = fullRaw
  } else {
    full = publicUrl(path) || fullRaw || path
  }
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
  // 有历史则返回上一级；刷新后无栈则回首页
  safeNavigateBack(HOME_TAB)
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
    if (p && p.has_recharged != null) {
      hasRecharged.value = !!p.has_recharged
    }
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

async function fetchHistory(opts) {
  const forceScroll = !!(opts && opts.forceScroll)
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
    map.set(String(msgId(m)), normalizeMessage(m))
  })
  const merged = Array.from(map.values()).sort((a, b) => {
    const ai = (a.id | 0) || 0
    const bi = (b.id | 0) || 0
    if (ai && bi && ai !== bi) return ai - bi
    return ((a.createtime | 0) || 0) - ((b.createtime | 0) || 0)
  })
  // 同一牛牛局只保留一张卡（优先较新 id，兼容历史多卡）
  const nnKeep = new Map()
  const deduped = []
  merged.forEach((m) => {
    if (!isNiuniu(m)) {
      deduped.push(m)
      return
    }
    const rid = niuniuRoundId(m)
    if (!rid) {
      deduped.push(m)
      return
    }
    if (nnKeep.has(rid)) {
      const idx = nnKeep.get(rid)
      const prev = deduped[idx]
      if ((msgId(m) | 0) >= (msgId(prev) | 0)) deduped[idx] = m
    } else {
      nnKeep.set(rid, deduped.length)
      deduped.push(m)
    }
  })
  messages.value = deduped
  // 进房/强制贴底：回到末尾窗口；不要用「首屏条数 < limit」误判没有更早消息（Redis recent 常不足一页）
  if (forceScroll || stickToBottom) {
    msgRevealCount.value = MSG_RENDER_CAP
    historyExhausted.value = false
  } else if ((msgRevealCount.value | 0) < MSG_RENDER_CAP) {
    msgRevealCount.value = MSG_RENDER_CAP
  }
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
  // 上翻看历史时不强制回底；进房 / 自己操作可 forceScroll
  if (forceScroll || stickToBottom) scrollToLatest(forceScroll)
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
    else await fetchHistory({ forceScroll: true })
    markRead().catch(() => {})
  } catch (e) {
    uni.showToast({ title: e.message || '发送失败', icon: 'none' })
  }
}

async function openYxxTable() {
  const gid = meta.value.group | 0
  if (!gid || !canEnterYxx.value) return
  if (canStartYxx.value) {
    try {
      await apiRequest('yxxgroupstart', 'POST', { group_id: gid })
    } catch (e) {}
  }
  uni.navigateTo({ url: '/pages/yxx/hall?group_id=' + gid })
}

async function stopYxxTable() {
  const gid = meta.value.group | 0
  if (!gid || !canStopYxx.value) return
  try {
    await apiRequest('yxxgroupstop', 'POST', { group_id: gid })
    uni.showToast({ title: '已关闭本群鱼虾蟹', icon: 'none' })
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '关闭失败', icon: 'none' })
  }
}

async function startNiuniuRound(mode = 1) {
  if (niuniuBusy.value) return
  if (!canStartNiuniu.value) {
    uni.showToast({ title: '无权开启或未启用尾数牛牛', icon: 'none' })
    return
  }
  const gameMode = (mode | 0) === 2 ? 2 : 1
  niuniuBusy.value = true
  try {
    const res = await niuniuStart(meta.value.group | 0, gameMode)
    const data = (res && res.data) || res || {}
    const msg = data.message || null
    const tip = '尾数牛牛连开已开启'
    if (msg) {
      appendLocalMessage(msg)
      uni.showToast({ title: tip, icon: 'success' })
    } else if (data.round && data.round.id) {
      uni.showToast({ title: tip + '，刷新中…', icon: 'none' })
    } else {
      uni.showToast({ title: '开局成功但未收到卡片，请下拉刷新', icon: 'none' })
    }
    setTimeout(() => fetchHistory({ forceScroll: true }).catch(() => {}), 400)
    if (!isPrivate.value) loadGroupMeta().catch(() => {})
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '开启失败', icon: 'none' })
  } finally {
    niuniuBusy.value = false
  }
}

async function stopNiuniuLoop() {
  if (niuniuBusy.value) return
  if (!canStopNiuniu.value) {
    uni.showToast({ title: '无权关闭或未在连开', icon: 'none' })
    return
  }
  niuniuBusy.value = true
  try {
    await niuniuStop(meta.value.group | 0)
    uni.showToast({ title: '已关闭连开', icon: 'success' })
    if (!isPrivate.value) loadGroupMeta().catch(() => {})
    setTimeout(() => fetchHistory({ forceScroll: true }).catch(() => {}), 300)
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '关闭失败', icon: 'none' })
  } finally {
    niuniuBusy.value = false
  }
}

async function onNiuniuTap(m) {
  const ex = msgExtra(m)
  const rid = (ex.round_id || (ex.round && ex.round.id) || 0) | 0
  if (!rid) {
    uni.showToast({ title: '对局信息缺失', icon: 'none' })
    return
  }
  const phase = niuniuPhase(m)
  const raw = niuniuRawPhase(m)
  // 本地判定购入已截止但服务端卡片未切阶段：先刷一轮，避免误开购入窗
  if (raw === 'buying' && phase !== 'buying' && phase !== 'claim') {
    softRefreshHistory()
    uni.showToast({ title: '正在切换阶段…', icon: 'none' })
    try {
      const res = await niuniuDetail(rid)
      openNiuniuDetail((res && res.data) || res || {})
    } catch (e) {}
    return
  }
  if (phase === 'buying') {
    niuniuSheet.value = { round_id: rid, phase, round: ex.round || {} }
    niuniuBuyCount.value = '1'
    await refreshWallet()
    showNiuniu.value = true
    return
  }
  // 本人已购入且未领：弹红宝封面；开启后再看开奖明细
  if (niuniuNeedsClaim(m)) {
    const r = niuniuRound(m)
    const mode = (r.game_mode | 0) || 0
    let remain =
      (r.my_unclaimed_count | 0) ||
      Math.max(0, ((r.my_share_count | 0) || 0) - (r.my_claimed ? (r.my_share_count | 0) : 0)) ||
      (r.my_share_count | 0) ||
      1
    // 单结果：逻辑上只需开一次
    if (mode === 2 && remain > 0) remain = 1
    openNiuniuCover(rid, remain, mode, r)
    return
  }
  if (phase === 'claim' || phase === 'result') {
    niuniuBusy.value = true
    try {
      const res = await niuniuDetail(rid)
      const data = (res && res.data) || res || {}
      const mine = data.mine || []
      const needClaim = mine.length > 0 && mine.some((s) => !s.claimed)
      const mode = ((data.round && data.round.game_mode) | 0) || 0
      if (needClaim) {
        const remainPacks = mine.filter((s) => !s.claimed).length
        markNiuniuShareLocal(rid, data.round || null, false, remainPacks)
        openNiuniuCover(rid, mode === 2 ? 1 : remainPacks, mode, data.round || null)
        return
      }
      if (mine.length) {
        markNiuniuShareLocal(rid, data.round || null, true, 0)
      }
      openNiuniuDetail(data)
    } catch (e) {
      uni.showToast({ title: (e && e.message) || '加载失败', icon: 'none' })
    } finally {
      niuniuBusy.value = false
    }
    return
  }
  try {
    const res = await niuniuDetail(rid)
    const data = (res && res.data) || res || {}
    openNiuniuDetail(data)
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '加载失败', icon: 'none' })
  }
}

function openNiuniuCover(rid, remainHint, gameMode, roundPatch) {
  niuniuCoverRoundId.value = rid | 0
  const hint = remainHint | 0
  if (hint > 0) niuniuCoverRemain.value = hint
  if (gameMode != null) niuniuCoverGameMode.value = gameMode | 0
  if (roundPatch && typeof roundPatch === 'object') {
    niuniuCoverRound.value = Object.assign({}, niuniuCoverRound.value || {}, roundPatch, {
      id: (roundPatch.id | 0) || (rid | 0),
    })
  } else if (!niuniuCoverRound.value || ((niuniuCoverRound.value.id | 0) !== (rid | 0))) {
    // 从消息列表补一轮快照
    const rows = messages.value || []
    let found = null
    for (let i = 0; i < rows.length; i++) {
      const m = rows[i]
      if (!isNiuniu(m)) continue
      const ex = msgExtra(m)
      const id = (ex.round_id | 0) || ((ex.round && ex.round.id) | 0) || 0
      if (id === (rid | 0)) {
        found = ex.round || null
        break
      }
    }
    niuniuCoverRound.value = Object.assign({}, found || {}, { id: rid | 0 })
  }
  showNiuniuCover.value = true
}
function closeNiuniuCover() {
  if (niuniuBusy.value) return
  showNiuniuCover.value = false
  niuniuCoverRoundId.value = 0
}
function parseNiuniuClaimRemain(data) {
  if (!data || typeof data !== 'object') return 0
  const mode = ((data.round && data.round.game_mode) | 0) || 0
  // 单结果服务端一次领完
  if (mode === 2 || data.done === true) return 0
  if (data.remain_unclaimed != null) return Math.max(0, data.remain_unclaimed | 0)
  const r = data.round || {}
  if (r.my_unclaimed_count != null) return Math.max(0, r.my_unclaimed_count | 0)
  return 0
}
async function claimOneNiuniuPack(rid) {
  rid = rid | 0
  if (!rid) return null
  const res = await niuniuClaim(rid)
  const data = (res && res.data) || res || {}
  const mode = ((data.round && data.round.game_mode) | 0) || (niuniuCoverGameMode.value | 0) || 0
  if (mode) niuniuCoverGameMode.value = mode
  const remain = parseNiuniuClaimRemain(data)
  const done = data.done === true || remain <= 0 || mode === 2
  const share = data.share || (data.shares && data.shares[0]) || null
  // 兼容旧 IM：一次领完全部（返回多份且无 remain）→ 当作已开完
  if (
    data.remain_unclaimed == null &&
    Array.isArray(data.shares) &&
    data.shares.length > 1
  ) {
    markNiuniuShareLocal(rid, data.round || null, true, 0)
    return { data, share: data.shares[0], remain: 0, done: true }
  }
  markNiuniuShareLocal(rid, data.round || null, done, remain)
  return { data, share, remain: done ? 0 : remain, done }
}
function showNiuniuPackResultUi(rid, share, remain) {
  niuniuPackResult.value = share
  niuniuPackRemain.value = remain | 0
  niuniuPackRoundId.value = rid | 0
  showNiuniuPackResult.value = true
}
async function confirmNiuniuCoverOpen() {
  if (niuniuBusy.value) return
  const rid = niuniuCoverRoundId.value | 0
  if (!rid) return
  niuniuBusy.value = true
  try {
    const out = await claimOneNiuniuPack(rid)
    if (!out) return
    showNiuniuCover.value = false
    niuniuCoverRoundId.value = 0
    niuniuCoverRemain.value = out.remain | 0
    if (out.data && out.data.round) {
      niuniuCoverRound.value = Object.assign({}, niuniuCoverRound.value || {}, out.data.round)
    }
    showNiuniuPackResultUi(rid, out.share, out.remain)
  } catch (e) {
    const msg = (e && e.message) || '领取失败'
    // 未参与者 / 已领完：直接看明细
    if (/未参与|没有购入|未购入|已领完/.test(msg)) {
      showNiuniuCover.value = false
      niuniuCoverRoundId.value = 0
      try {
        const dres = await niuniuDetail(rid)
        openNiuniuDetail((dres && dres.data) || dres || {})
      } catch (e2) {
        uni.showToast({ title: msg, icon: 'none' })
      }
    } else {
      uni.showToast({ title: msg, icon: 'none' })
    }
  } finally {
    niuniuBusy.value = false
  }
}
/** 点遮罩：有剩余则回到封面再开；已开完则进明细 */
async function dismissNiuniuPackResult() {
  if (niuniuBusy.value) return
  const rid = niuniuPackRoundId.value | 0
  const remain = niuniuPackRemain.value | 0
  showNiuniuPackResult.value = false
  niuniuPackResult.value = null
  if (remain > 0 && rid) {
    openNiuniuCover(rid, remain, niuniuCoverGameMode.value, niuniuCoverRound.value)
    return
  }
  await openNiuniuDetailAfterClaim(rid)
}
/** 继续开下一包 / 查看明细 */
async function onNiuniuPackResultContinue() {
  if (niuniuBusy.value) return
  const rid = niuniuPackRoundId.value | 0
  const remain = niuniuPackRemain.value | 0
  if (remain > 0 && rid) {
    niuniuBusy.value = true
    try {
      const out = await claimOneNiuniuPack(rid)
      if (!out) return
      niuniuCoverRemain.value = out.remain | 0
      showNiuniuPackResultUi(rid, out.share, out.remain)
    } catch (e) {
      const msg = (e && e.message) || '领取失败'
      if (/已领完/.test(msg)) {
        showNiuniuPackResult.value = false
        niuniuPackResult.value = null
        await openNiuniuDetailAfterClaim(rid)
      } else {
        uni.showToast({ title: msg, icon: 'none' })
      }
    } finally {
      niuniuBusy.value = false
    }
    return
  }
  showNiuniuPackResult.value = false
  niuniuPackResult.value = null
  await openNiuniuDetailAfterClaim(rid)
}
async function openNiuniuDetailAfterClaim(rid) {
  rid = rid | 0
  niuniuPackRoundId.value = 0
  if (!rid) return
  niuniuBusy.value = true
  try {
    const dres = await niuniuDetail(rid)
    openNiuniuDetail((dres && dres.data) || dres || {})
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '加载明细失败', icon: 'none' })
  } finally {
    niuniuBusy.value = false
  }
}
function markNiuniuClaimedLocal(rid, roundPatch) {
  markNiuniuShareLocal(rid, roundPatch, true, 0)
}
function markNiuniuShareLocal(rid, roundPatch, claimed, unclaimed) {
  rid = rid | 0
  if (!rid) return
  const rows = messages.value || []
  for (let i = 0; i < rows.length; i++) {
    const m = rows[i]
    if (!isNiuniu(m)) continue
    const ex = msgExtra(m)
    const id = (ex.round_id | 0) || ((ex.round && ex.round.id) | 0) || 0
    if (id !== rid) continue
    const prevCount = ((ex.round && ex.round.my_share_count) | 0) || ((roundPatch && roundPatch.my_share_count) | 0) || 0
    const nextUnclaimed =
      unclaimed != null
        ? Math.max(0, unclaimed | 0)
        : claimed
          ? 0
          : ((roundPatch && roundPatch.my_unclaimed_count) | 0) ||
            ((ex.round && ex.round.my_unclaimed_count) | 0) ||
            prevCount
    const nextRound = Object.assign({}, ex.round || {}, roundPatch || {}, {
      my_claimed: !!claimed,
      my_share_count: Math.max(1, prevCount || 1),
      my_unclaimed_count: nextUnclaimed,
    })
    const nextEx = Object.assign({}, ex, { round: nextRound, round_id: rid })
    const next = Object.assign({}, m, { extra: nextEx })
    rows.splice(i, 1, next)
  }
  messages.value = rows.slice()
}

function formatNiuniuWin(v) {
  const n = Number(v)
  if (isNaN(n) || n <= 0) return '0'
  return '+' + (Math.round(n * 100) / 100)
}
function formatNiuniuBonus(v) {
  const n = Number(v)
  if (isNaN(n) || n <= 0) return '0'
  return String(Math.round(n * 10000) / 10000)
}
function formatNiuniuPacketAmount(row) {
  if (!row) return '--'
  // 未领取不出金额/结果
  if (!row.claimed) return '--'
  if (row.amount != null && row.amount !== '' && !isNaN(Number(row.amount))) {
    return (Math.round(Number(row.amount) * 100) / 100).toFixed(2)
  }
  const tail = row.tail_digits != null && row.tail_digits !== '' ? String(row.tail_digits) : ''
  if (!tail) return '--'
  const n = parseInt(String(tail).replace(/\D/g, '').slice(-2) || '0', 10)
  return (Math.round(n) / 100).toFixed(2)
}
/** 领取明细：金额/份，如 0.25/5 */
function formatNiuniuDetailAmountShares(row) {
  const amt = formatNiuniuPacketAmount(row)
  if (amt === '--') return '--'
  const shares = Math.max(1, (Number(row && (row.share_count || row.weight)) || 0) | 0)
  return amt + '/' + shares
}
function formatNiuniuResultLine(row) {
  if (!row) return '--'
  if (!row.claimed) return '未领取'
  if (row.result) return String(row.result)
  const tail = row.tail_digits != null && row.tail_digits !== '' ? String(row.tail_digits) : '--'
  const niu = row.niu_label || row.category || row.calc || '--'
  return '尾数' + tail + ' ' + niu
}
/** 明细短结果：42 牛2 / 未领取，避免挤出屏幕 */
function formatNiuniuDetailResultShort(row) {
  if (!row) return '--'
  if (!row.claimed) return '未领取'
  const tail = row.tail_digits != null && row.tail_digits !== '' ? String(row.tail_digits) : ''
  const niu = row.niu_label || row.category || ''
  if (tail) return tail + (niu ? ' ' + niu : '')
  if (row.result && String(row.result) !== '未领取') {
    return String(row.result).replace(/^尾数/, '')
  }
  return '--'
}
function formatNiuniuPackResultLine(row) {
  if (!row) return '--'
  return formatNiuniuResultLine(row)
}
function byNiuniuClaimTime(a, b) {
  // 真实领取时间升序；未领排后；同秒按 claim_seq / id
  const ca = a && a.claimed ? 0 : 1
  const cb = b && b.claimed ? 0 : 1
  if (ca !== cb) return ca - cb
  const ta = (a && a.claimed_at) | 0
  const tb = (b && b.claimed_at) | 0
  if (ta !== tb) return ta - tb
  const sa = (a && a.claim_seq) | 0
  const sb = (b && b.claim_seq) | 0
  if (sa !== sb) return sa - sb
  return ((a && a.id) | 0) - ((b && b.id) | 0)
}

function openNiuniuDetail(data) {
  refreshChatSafeLayout()
  const uid = (myUserId.value | 0) || (myId | 0)
  const round = (data && data.round) || null
  let shares =
    data && data.shares && data.shares.length
      ? data.shares.slice()
      : (data && data.mine) || []
  shares = shares.filter(Boolean)

  // 同一用户只展示一行（多份合并奖金）；与资金流水牛牛明细一致
  {
    const map = new Map()
    shares.forEach((s) => {
      const id = (s.user_id | 0) || 0
      if (!map.has(id)) {
        map.set(id, Object.assign({}, s, {
          share_count: (s.share_count | 0) || 1,
          weight: (s.weight | 0) || (s.share_count | 0) || 1,
          win_amount: Number(s.win_amount) || 0,
          claimed_at: (s.claimed_at | 0) || 0,
          claim_seq: (s.claim_seq | 0) || 0,
          category: s.category || s.niu_label || s.niu_type || '',
          niu_label: s.niu_label || s.niu_type || '',
          result: s.result || '',
        }))
        return
      }
      const g = map.get(id)
      g.share_count = ((g.share_count | 0) || 1) + 1
      g.weight = g.share_count
      g.win_amount = (Number(g.win_amount) || 0) + (Number(s.win_amount) || 0)
      const ca = (s.claimed_at | 0) || 0
      if (ca > 0 && (!(g.claimed_at | 0) || ca < (g.claimed_at | 0))) g.claimed_at = ca
      const cs = (s.claim_seq | 0) || 0
      if (cs > 0 && (!(g.claim_seq | 0) || cs < (g.claim_seq | 0))) {
        g.claim_seq = cs
        if (s.claimed && s.tail_digits) {
          g.tail_digits = s.tail_digits
          g.niu_label = s.niu_label || s.niu_type || g.niu_label
          g.category = s.category || s.niu_label || s.niu_type || g.category
          g.result = s.result || g.result
          g.amount = s.amount != null ? s.amount : g.amount
          g.id = (s.id | 0) || g.id
          g.claimed = true
        }
      }
      if (!g.nickname && s.nickname) g.nickname = s.nickname
      if (!g.avatar && s.avatar) g.avatar = s.avatar
      if (s.claimed && (!g.tail_digits || g.tail_digits === '') && s.tail_digits != null && s.tail_digits !== '') {
        g.tail_digits = s.tail_digits
        g.niu_label = s.niu_label || s.niu_type || g.niu_label
        g.category = s.category || s.niu_label || s.niu_type || g.category
        g.result = s.result || g.result
        g.amount = s.amount != null ? s.amount : g.amount
        g.id = (s.id | 0) || g.id
        g.claimed = true
      }
    })
    shares = Array.from(map.values()).map((g) =>
      Object.assign({}, g, {
        weight: (g.share_count | 0) || 1,
        win_amount: Math.round((Number(g.win_amount) || 0) * 10000) / 10000,
      })
    )
  }

  // 强制按真实领取时间排序（未领排后）
  shares = shares.slice().sort(byNiuniuClaimTime)
  niuniuDetailRound.value = round
  niuniuDetailRows.value = shares.map((s) => {
    const id = (s.user_id | 0) || 0
    return Object.assign({}, s, {
      is_mine: id > 0 && id === uid,
      weight: (s.weight | 0) || (s.share_count | 0) || 1,
      category: s.category || s.niu_label || (s.claimed ? '' : '未领取'),
      result: s.result || formatNiuniuResultLine(s),
      _key: (s.id || 0) + '-' + id + '-' + ((s.share_no | 0) || 0) + '-' + ((s.share_count | 0) || 1),
    })
  })
  showNiuniuDetail.value = true
}

function closeNiuniuDetail() {
  showNiuniuDetail.value = false
}

function niuniuVerifyLabel(r) {
  if (!r) return ''
  if (r.proof_type === 'tron' || (r.tron_block_num | 0) > 0 || r.tron_block_id) {
    const n = (r.tron_block_num | 0) || (r.drand_round | 0)
    return n > 0 ? ('tron-#' + n) : 'tron'
  }
  return r.drand_label || (r.drand_round ? ('drand-#' + r.drand_round) : '')
}
function openNiuniuVerify() {
  const r = niuniuDetailRound.value || {}
  const rid = (r.id | 0) || 0
  if (!rid) {
    uni.showToast({ title: '缺少局号', icon: 'none' })
    return
  }
  uni.navigateTo({
    url: '/pages/common/fair-verify?kind=niuniu&round_id=' + encodeURIComponent(String(rid)),
    fail: () => uni.showToast({ title: '无法打开验证页', icon: 'none' }),
  })
}

async function submitNiuniuBuy() {
  if (niuniuBusy.value) return
  const sheet = niuniuSheet.value || {}
  const rid = sheet.round_id | 0
  const count = Math.max(1, Math.min(100, parseInt(niuniuBuyCount.value, 10) || 1))
  if (!rid) return
  niuniuBusy.value = true
  try {
    const res = await niuniuBuy(rid, count)
    const data = (res && res.data) || res || {}
    showNiuniu.value = false
    uni.showToast({ title: '已购入 ' + count + ' 份', icon: 'success' })
    if (data.round) {
      applyNiuniuUpdateLocal({
        group_id: meta.value.group | 0,
        extra: {
          phase: 'buying',
          round_id: rid,
          round: Object.assign({}, data.round, {
            my_share_count: Math.max(1, (data.round.my_share_count | 0) || count),
            my_claimed: false,
          }),
        },
      })
    }
    await refreshWallet()
    // 兜底再拉一次，避免漏掉他端并发买入
    setTimeout(() => softRefreshHistory(), 500)
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '购入失败', icon: 'none' })
  } finally {
    niuniuBusy.value = false
  }
}

async function sendRp() {
  if (rpSending.value) return
  if (!canSendRpByRecharge()) {
    uni.showToast({ title: rechargeGateTip('rp'), icon: 'none' })
    return
  }
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
      fetchHistory({ forceScroll: true }).catch(() => {})
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
  // 只打开上方详情页；底部「开红包」才真正领取（对齐 888）
  await openDetail(pid)
}

function mapGrabError(msg) {
  const s = String(msg || '')
  const mNeed = s.match(/balance_not_enough_for_compensate\s*:\s*([0-9.]+)/i)
  if (mNeed) {
    const n = Number(mNeed[1])
    const amt = isFinite(n) ? n.toFixed(2) : mNeed[1]
    return '红宝不足，需至少 ￥' + amt + ' 才能领取（用于赔付/续发）'
  }
  if (/balance_not_enough_for_compensate/i.test(s)) {
    return '红宝不足，无法覆盖赔付金额，不能领取'
  }
  if (/balance_below_mine_min/i.test(s)) {
    return '红宝须大于本群最低金额限制，才能领取扫雷红包'
  }
  if (/insufficient balance/i.test(s) || /红宝不足/.test(s)) {
    return s.indexOf('红宝') >= 0 ? s : '红宝不足，请先闪兑凑够红宝'
  }
  if (/mine_hash_pending/i.test(s)) {
    return '扫雷哈希确认中，请稍后再领'
  }
  // 服务端已中文化的文案直接展示
  if (/[\u4e00-\u9fff]/.test(s)) return s
  return s || '领取失败'
}

function showNativeGrabTip(tip) {
  try {
    if (typeof document === 'undefined') return
    const id = 'chat-rp-grab-fail-toast'
    let el = document.getElementById(id)
    if (!el) {
      el = document.createElement('div')
      el.id = id
      el.setAttribute('role', 'alert')
      document.body.appendChild(el)
    }
    el.textContent = tip
    el.style.cssText =
      'position:fixed;left:12px;right:12px;top:18%;z-index:2147483646;padding:14px 16px;' +
      'border-radius:12px;background:rgba(180,35,24,.96);color:#fff;font-size:15px;font-weight:700;' +
      'line-height:1.45;box-shadow:0 10px 28px rgba(0,0,0,.28);text-align:center;'
    clearTimeout(el._hideTimer)
    el._hideTimer = setTimeout(() => {
      try {
        if (el && el.parentNode) el.parentNode.removeChild(el)
      } catch (e0) {}
    }, 4500)
  } catch (e1) {}
}

function showGrabFailTip(rawMsg) {
  const tip = mapGrabError(rawMsg)
  grabErrorTip.value = tip
  // 详情全屏层常盖住 uni toast/modal：多重兜底保证一定看得见
  showNativeGrabTip(tip)
  try {
    if (typeof window !== 'undefined' && typeof window.alert === 'function') {
      window.alert(tip)
    }
  } catch (e0) {}
  try {
    uni.showModal({
      title: '无法领取',
      content: tip,
      showCancel: false,
      confirmText: '知道了',
    })
  } catch (e1) {}
  try {
    uni.showToast({ title: tip.slice(0, 40), icon: 'none', duration: 3500 })
  } catch (e2) {}
}

async function tryGrab(packetId, sliderPayload = null) {
  grabbing.value = true
  try {
    if (!canGrabInCurrentScene()) {
      showGrabFailTip(rechargeGateTip('grab'))
      return null
    }
    const packet = await grabRedPacket(packetId, sliderPayload || {})
    const data = (packet && packet.data) || packet || {}
    if (data.code === 'slider_required' || (packet && packet.type === 'redpacket.challenge')) {
      grabbing.value = false
      if (!(grabSliderRef.value && typeof grabSliderRef.value.challenge === 'function')) {
        showGrabFailTip(data.message || '需要滑动验证')
        return null
      }
      try {
        const payload = await grabSliderRef.value.challenge()
        return await tryGrab(packetId, payload || {})
      } catch (ce) {
        if (!/cancel/i.test((ce && ce.message) || '')) {
          showGrabFailTip((ce && ce.message) || '验证取消')
        }
        return null
      }
    }
    grabErrorTip.value = ''
    if (data.amount != null) {
      myGrabAmount.value = formatAmt(data.amount)
      playOpenRedPacketSound()
      const hit = !!data.is_mine_hit
      const pay = Number(data.compensate_amount || 0)
      if (hit && pay > 0) {
        uni.showToast({
          title: '抢到 ' + myGrabAmount.value + ' · 中雷已赔付 ￥' + pay.toFixed(2),
          icon: 'none',
        })
      } else {
        uni.showToast({ title: '抢到 ' + myGrabAmount.value, icon: 'none' })
      }
    }
    return data
  } catch (e) {
    const msg = String((e && e.message) || e || '领取失败')
    // 已领/抢完：静默；余额不足等一律强提示
    const softDone =
      /already|已领|expired|过期|finished|抢完|packet empty|packet closed/i.test(msg) &&
      !/balance|红宝不足|赔付|insufficient|compensate/i.test(msg)
    if (!softDone) {
      showGrabFailTip(msg)
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
    const status = (p.status | 0) || 0
    const grabbed = !!(data.mine || (data.records || []).some((r) => (r.user_id | 0) === myId))
    let canGrab = !!(remain > 0 && !grabbed && (status === 0 || status === 1))
    // 私聊红包仅对方可领
    if ((p.scope_type | 0) === 1 && (p.to_user_id | 0) !== (myId | 0)) {
      canGrab = false
    }
    if (!canGrabInCurrentScene(p)) {
      canGrab = false
    }
    if (p.expiretime && (p.expiretime | 0) > 0 && (p.expiretime | 0) < Math.floor(Date.now() / 1000)) {
      canGrab = false
    }
    canGrabDetail.value = canGrab
    const mine = data.mine || (data.records || []).find((r) => (r.user_id | 0) === myId)
    if (mine && mine.amount != null) myGrabAmount.value = formatAmt(mine.amount)
    refreshChatSafeLayout()
    detailVisible.value = true
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '详情失败', icon: 'none' })
  }
}

async function grabFromDetail() {
  if (!activePacketId) return
  const data = await tryGrab(activePacketId)
  const keptTip = grabErrorTip.value
  // 失败时保留错误条，仍刷新详情以便更新按钮状态
  try {
    await openDetail(activePacketId)
  } catch (e) {}
  if (data) {
    grabErrorTip.value = ''
    await fetchHistory({ forceScroll: true })
  } else if (keptTip) {
    // openDetail 重绘后再次钉住提示，避免被盖住
    grabErrorTip.value = keptTip
    showNativeGrabTip(keptTip)
  }
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
  if (data.notify_mute != null) {
    next.notify_mute = !!(data.notify_mute | 0)
    setGroupNotifyMuted(meta.value.group | 0, next.notify_mute)
  }
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

let softRefreshTimer = null
let softRefreshInflight = null
async function softRefreshHistory() {
  if (!roomAlive) return
  // 单飞 + 短防抖：resume/倒计时归零/失败回退连打时只拉一次，三端行为一致
  if (softRefreshTimer) clearTimeout(softRefreshTimer)
  softRefreshTimer = setTimeout(() => {
    softRefreshTimer = null
    if (!roomAlive) return
    if (softRefreshInflight) return
    softRefreshInflight = fetchHistory()
      .catch(() => {})
      .finally(() => {
        softRefreshInflight = null
      })
  }, 400)
}

/** A1：抢包更新只补封面状态，避免整页拉 history */
function applyRedPacketUpdateLocal(data) {
  if (!data || !roomAlive) return false
  const pid =
    (data.packet_id | 0) ||
    ((data.grab && data.grab.packet_id) | 0) ||
    ((data.packet && data.packet.id) | 0) ||
    0
  if (!pid) return false

  const grab = data.grab || {}
  const packet = grab.packet || data.packet || {}
  const remain =
    grab.remain_count != null
      ? grab.remain_count | 0
      : packet.remain_count != null
        ? packet.remain_count | 0
        : null
  const status = (grab.status | 0) || (packet.status | 0) || 0
  const byUid = (data.by_user_id | 0) || (grab.user_id | 0) || 0
  const finished =
    !!data.settled ||
    !!data.tron_revealed ||
    status === 2 ||
    status === 3 ||
    status === 4 ||
    status === 5 ||
    (remain !== null && remain <= 0)

  let patched = 0
  const rows = messages.value
  for (let i = 0; i < rows.length; i++) {
    const m = rows[i]
    const ex = msgExtra(m)
    const mid = (ex.packet_id | 0) || 0
    if (mid !== pid) continue
    const nextEx = Object.assign({}, ex)
    if (byUid && byUid === (myId | 0)) {
      nextEx.cover_grabbed = true
      nextEx.cover_faded = true
    }
    if (remain !== null && remain <= 0) {
      nextEx.cover_faded = true
    }
    if (status === 3 || status === 4) {
      nextEx.cover_expired = true
      nextEx.cover_faded = true
    }
    if (remain !== null) nextEx.remain_count = remain
    if (status) nextEx.packet_status = status
    const next = Object.assign({}, m, { extra: nextEx })
    rows[i] = next
    patched++
  }
  if (patched) {
    // 就地替换命中行，避免整表 slice
  }

  // 详情页打开时：结算/领完才重拉详情；普通抢包用本地补 remain
  if (detailVisible.value && activePacketId === pid) {
    if (finished) {
      openDetail(pid).catch(() => {})
    } else if (detail.value && detail.value.packet) {
      const d = Object.assign({}, detail.value)
      d.packet = Object.assign({}, d.packet)
      if (remain !== null) d.packet.remain_count = remain
      if (status) d.packet.status = status
      detail.value = d
      if (remain !== null) {
        canGrabDetail.value = !!(remain > 0 && !d.mine && (status === 0 || status === 1))
      }
    }
  }

  return patched > 0 || !finished
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
  refreshChatSafeLayout()
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
        // 同局牛牛卡片已存在：只更新，不追加新气泡
        if (isNiuniu(msg)) {
          const patched = applyNiuniuUpdateLocal({
            group_id: (msg.group_id | 0) || (meta.value.group | 0),
            message: msg,
            extra: msgExtra(msg),
          })
          if (patched) {
            markRead().catch(() => {})
            return
          }
        }
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
      const ok = applyRedPacketUpdateLocal(data)
      // 结算/开奖等立即事件若本地未命中消息，再兜底拉一次
      if (!ok && (data.settled || data.tron_revealed)) {
        softRefreshHistory()
      }
      return
    }
    if (type === 'niuniu.update') {
      // PushBus: { message: { group_id, extra, content, message_id, message: chatMsg } }
      const wrap = (data && data.message) || data || {}
      const chatMsg = wrap.message && typeof wrap.message === 'object' ? wrap.message : null
      const extra = wrap.extra || (chatMsg ? msgExtra(chatMsg) : null)
      const ok = applyNiuniuUpdateLocal({
        group_id: (wrap.group_id | 0) || ((chatMsg && chatMsg.group_id) | 0) || (meta.value.group | 0),
        message_id: (wrap.message_id | 0) || ((chatMsg && chatMsg.id) | 0) || 0,
        content: wrap.content != null ? wrap.content : chatMsg && chatMsg.content,
        extra,
        message: chatMsg,
        round: extra && extra.round,
      })
      if (!ok) softRefreshHistory()
      if (!isPrivate.value) loadGroupMeta().catch(() => {})
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
    stickToBottom = true
    showJumpLatest.value = false
    await Promise.all([fetchHistory({ forceScroll: true }), loadGroupMeta()])
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
    scrollToLatest(true)
  }
})

onShow(() => {
  refreshChatSafeLayout()
  if (!getToken() || !roomAlive) return
  bindForegroundResume()
  resumeFromBackground('chat-onShow')
  // 刚进房时 onShow 会跟 onLoad 抢历史，容易滚不到底；短窗口只补滚（用户已上翻则不抢）
  if (bootDoneAt && Date.now() - bootDoneAt < 1800) {
    if (stickToBottom) scrollToLatest(true)
  } else {
    softRefreshHistory()
  }
  if (!isPrivate.value) loadGroupMeta().catch(() => {})
})

onUnload(() => {
  clearTextMsgHold()
  stopNiuniuTick()
  markRead()
    .catch(() => {})
    .finally(() => {
      clearActiveChat()
    })
  roomAlive = false
  stickToBottom = true
  showJumpLatest.value = false
  cancelScrollToLatest()
  if (off) off()
})
function closeRpDetail() {
  detailVisible.value = false
  grabErrorTip.value = ''
}
</script>

<style>
/* App 专用：详情主体取消 -24px 盖板上叠，避免顶到标题字；H5 不编译此段 */
/* #ifdef APP-PLUS */
.chat-room-page .chat-detail-overlay .chat-sub-main,
.chat-room-page #chatRpDetailPane .chat-sub-main,
.chat-room-page .chat-sub-pane.open .nn-detail-main {
  margin-top: 0 !important;
  border-top-left-radius: 0 !important;
  border-top-right-radius: 0 !important;
}
/* #endif */
</style>

<style scoped>
/* 文字气泡：禁用系统选中/呼出，改由长按 2s 复制 */
.chat-bubble.text-msg {
  -webkit-touch-callout: none;
  -webkit-user-select: none;
  user-select: none;
}
/* 详情/会话样式走 chat.bundle + chat-888-parity；此处仅房间页微补 */
.chat-rp-grab-error {
  margin: 10px 12px 0;
  padding: 12px 14px;
  border-radius: 12px;
  background: linear-gradient(135deg, #fff1f0, #ffe7e3);
  border: 1px solid rgba(230, 48, 34, 0.35);
  color: #b42318;
  display: flex;
  flex-direction: column;
  gap: 4px;
  position: sticky;
  top: 0;
  z-index: 20;
  box-shadow: 0 4px 14px rgba(180, 35, 24, 0.12);
}
.chat-rp-grab-error-title {
  font-size: 14px;
  font-weight: 800;
}
.chat-rp-grab-error-msg {
  font-size: 13px;
  line-height: 1.45;
  font-weight: 600;
}
.chat-rp-grab-error-close {
  align-self: flex-end;
  margin-top: 4px;
  font-size: 12px;
  color: #e63022;
  font-weight: 700;
}
.chat-rp-detail-myamt text {
  font-size: 22px;
  font-weight: 800;
}
.chat-rp-detail-foot {
  padding: 12px 14px 8px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.chat-rp-record-avatar image {
  width: 100%;
  height: 100%;
}
/* 红宝尾数牛牛气泡样式在 ChatNiuniuCard.vue（父页 scoped 无法命中子组件） */
.nn-sheet-tip {
  font-size: 12px;
  color: #888;
  line-height: 1.5;
  margin: 10px 0 14px;
}
.nn-detail-main {
  display: flex;
  flex-direction: column;
  min-height: 0;
  background: #f7f8fa;
  overflow-x: hidden;
  width: 100%;
  box-sizing: border-box;
}
.nn-detail-summary {
  display: flex;
  flex-wrap: wrap;
  gap: 10px 14px;
  padding: 12px 14px;
  background: #fff;
  border-bottom: 1px solid #eef0f3;
  font-size: 13px;
  font-weight: 800;
  color: #c62828;
  flex-shrink: 0;
}
.nn-detail-frame {
  flex: 1;
  min-height: 0;
  display: flex;
  flex-direction: column;
  width: 100%;
  padding: 0 10px;
  box-sizing: border-box;
  overflow-x: hidden;
}
.nn-detail-head,
.nn-detail-person {
  display: grid;
  grid-template-columns: 40px minmax(0, 1fr) 64px minmax(0, 1.25fr) 52px;
  align-items: center;
  column-gap: 6px;
  padding: 10px 6px;
  box-sizing: border-box;
  width: 100%;
  max-width: 100%;
  overflow: hidden;
}
.nn-detail-head {
  flex-shrink: 0;
  font-size: 12px;
  font-weight: 800;
  color: #888;
  background: #fff;
  border-radius: 10px 10px 0 0;
  border-bottom: 1px solid #eef0f3;
  margin-top: 6px;
}
.nn-detail-scroll {
  flex: 1;
  height: 0;
  min-height: 280px;
  width: 100%;
  max-width: 100%;
  overflow-x: hidden;
  box-sizing: border-box;
  padding-bottom: 24px;
}
.nn-detail-person {
  background: #fff;
  border-radius: 10px;
  border: 1px solid #e8ebf0;
  margin-top: 6px;
  font-size: 13px;
  color: #333;
}
.nn-detail-person.mine {
  border-color: #f0c36d;
  background: linear-gradient(180deg, #fff8e8, #fff);
  box-shadow: inset 0 0 0 1px rgba(212, 136, 6, 0.12);
}
.nn-dh-amt,
.nn-dh-win {
  text-align: center;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.nn-dh-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 13px;
  font-weight: 700;
  min-width: 0;
}
.nn-dh-res-wrap {
  min-width: 0;
  max-width: 100%;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 2px;
  overflow: hidden;
}
.nn-dh-res {
  font-size: 13px;
  font-weight: 800;
  color: #333;
  line-height: 1.2;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.nn-dh-time {
  font-size: 11px;
  color: #999;
  font-weight: 500;
  line-height: 1.2;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.nn-dh-av {
  display: flex;
  align-items: center;
  justify-content: center;
}
.nn-user-av {
  width: 30px;
  height: 30px;
  border-radius: 50%;
  background: #eee;
  flex-shrink: 0;
}
.nn-detail-person.mine .nn-dh-name {
  color: #d48806;
  font-weight: 800;
}
.nn-dh-amt {
  font-weight: 800;
  color: #c62828;
}
.nn-dh-win {
  font-weight: 800;
  color: #888;
}
.nn-dh-win.win {
  color: #c62828;
}
.nn-detail-verify {
  color: #1565c0;
  font-weight: 700;
}
.nn-detail-empty {
  text-align: center;
  color: #999;
  padding: 40px 0;
  font-size: 13px;
}
.chat-fission-share-card {
  width: min(78vw, 280px);
  border-radius: 14px;
  overflow: hidden;
  background: linear-gradient(165deg, #0d1b3a 0%, #152a52 100%);
  box-shadow: 0 6px 18px rgba(10, 20, 40, 0.28);
}
.chat-fission-share-hd {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 8px 12px 0;
}
.chat-fission-share-tag {
  font-size: 11px;
  color: #ffd27a;
  font-weight: 700;
}
.chat-fission-share-time {
  font-size: 11px;
  color: #8a9bb5;
}
.chat-fission-share-env {
  margin: 8px 10px 12px;
  padding: 16px 12px 12px;
  border-radius: 12px;
  text-align: center;
  background: linear-gradient(165deg, #ff5a2a 0%, #c62828 70%, #8b0000 100%);
  color: #fff;
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.25);
}
.chat-fission-share-title {
  display: block;
  font-size: 14px;
  letter-spacing: 3px;
  margin-bottom: 6px;
  font-weight: 700;
}
.chat-fission-share-pool {
  display: block;
  font-size: 24px;
  font-weight: 800;
  color: #ffe082;
  margin-bottom: 4px;
}
.chat-fission-share-progress {
  display: block;
  font-size: 12px;
  opacity: 0.95;
  margin-bottom: 2px;
}
.chat-fission-share-cta {
  margin: 10px auto 6px;
  max-width: 200px;
  background: linear-gradient(180deg, #ffe08a, #f0b429);
  color: #4a3200;
  font-weight: 800;
  font-size: 14px;
  line-height: 38px;
  border-radius: 20px;
}
.chat-fission-share-cta.disabled {
  filter: grayscale(0.8);
  opacity: 0.7;
}
.chat-fission-share-risk {
  display: block;
  font-size: 10px;
  opacity: 0.85;
}
.nn-cover-mask {
  position: fixed;
  inset: 0;
  z-index: 16000;
  background: rgba(0, 0, 0, 0.58);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
  box-sizing: border-box;
}
.nn-cover-card {
  width: min(82vw, 300px);
  border-radius: 20px;
  padding: 3px;
  box-sizing: border-box;
  background: linear-gradient(145deg, #ffe9a8 0%, #f0c14b 18%, #c62828 52%, #7a0b14 100%);
  box-shadow:
    0 14px 32px rgba(0, 0, 0, 0.4),
    0 1px 0 rgba(255, 255, 255, 0.45) inset,
    0 -2px 6px rgba(80, 0, 0, 0.35) inset;
}
.nn-cover-frame {
  position: relative;
  border-radius: 17px;
  padding: 28px 16px 22px;
  box-sizing: border-box;
  min-height: 360px;
  display: flex;
  flex-direction: column;
  align-items: center;
  background: linear-gradient(180deg, #e53935 0%, #c62828 48%, #9b1018 100%);
  border: 2px solid rgba(255, 224, 130, 0.85);
  box-shadow:
    0 0 0 2px rgba(139, 20, 30, 0.9),
    0 0 0 4px rgba(255, 214, 120, 0.55),
    inset 0 1px 0 rgba(255, 255, 255, 0.28),
    inset 0 -10px 24px rgba(80, 0, 10, 0.28);
}
.nn-cover-close {
  position: absolute;
  top: 10px;
  right: 10px;
  z-index: 3;
  width: 30px;
  height: 30px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  line-height: 1;
  color: #fff;
  background: rgba(0, 0, 0, 0.28);
  border: 1px solid rgba(255, 255, 255, 0.3);
}
.nn-cover-badge {
  position: absolute;
  top: 12px;
  left: 12px;
  z-index: 3;
  padding: 3px 8px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 800;
  color: #7a1f00;
  background: #ffe082;
  border: 1px solid rgba(255, 255, 255, 0.45);
}
.nn-cover-title {
  margin-top: 6px;
  font-size: 18px;
  font-weight: 900;
  color: #fff8e1;
  letter-spacing: 1px;
  text-shadow: 0 2px 0 rgba(120, 20, 20, 0.35);
}
.nn-cover-center {
  width: 100%;
  margin-top: 18px;
  padding: 12px 10px;
  border-radius: 12px;
  text-align: center;
  background: rgba(90, 10, 16, 0.35);
  border: 1px solid rgba(255, 214, 120, 0.45);
  box-shadow:
    inset 0 1px 0 rgba(255, 255, 255, 0.18),
    0 4px 10px rgba(0, 0, 0, 0.18);
}
.nn-cover-l1,
.nn-cover-l2,
.nn-cover-l3 {
  display: block;
  line-height: 1.35;
}
.nn-cover-l1 {
  font-size: 13px;
  font-weight: 800;
  color: #fff8e1;
}
.nn-cover-l2 {
  margin-top: 4px;
  font-size: 12px;
  font-weight: 700;
  color: #ffe9b0;
}
.nn-cover-l3 {
  margin-top: 8px;
  font-size: 11px;
  color: rgba(255, 236, 200, 0.88);
}
.nn-cover-l4 {
  display: block;
  margin-top: 2px;
  font-size: 26px;
  font-weight: 900;
  color: #ffe082;
  letter-spacing: 0.5px;
}
.nn-cover-sub {
  margin-top: 12px;
  font-size: 12px;
  color: rgba(255, 236, 200, 0.92);
  text-align: center;
  line-height: 1.4;
  padding: 0 4px;
}
.nn-cover-open {
  margin-top: auto;
  width: 76px;
  height: 76px;
  border-radius: 50%;
  background: radial-gradient(circle at 35% 28%, #fff3c4, #f0c14b 55%, #c9a227 100%);
  color: #8a2a00;
  font-size: 30px;
  font-weight: 900;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 2px solid rgba(255, 255, 255, 0.55);
  box-shadow:
    0 8px 18px rgba(0, 0, 0, 0.28),
    inset 0 2px 0 rgba(255, 255, 255, 0.65),
    inset 0 -5px 8px rgba(160, 90, 0, 0.22);
}
.nn-cover-open.busy {
  opacity: 0.7;
}
.nn-pack-result-mask {
  position: fixed;
  inset: 0;
  z-index: 16100;
  background: rgba(0, 0, 0, 0.55);
  display: flex;
  align-items: center;
  justify-content: center;
}
.nn-pack-result-card {
  width: 280px;
  padding: 24px 18px 18px;
  border-radius: 16px;
  background: #fff;
  text-align: center;
  box-shadow: 0 12px 32px rgba(0, 0, 0, 0.28);
}
.nn-pack-result-title {
  display: block;
  font-size: 15px;
  font-weight: 800;
  color: #333;
  margin-bottom: 10px;
}
.nn-pack-result-amt {
  display: block;
  font-size: 36px;
  font-weight: 900;
  color: #c62828;
  line-height: 1.2;
  margin-bottom: 8px;
}
.nn-pack-result-line {
  display: block;
  font-size: 18px;
  font-weight: 900;
  color: #333;
  margin-bottom: 6px;
}
.nn-pack-result-hint {
  display: block;
  font-size: 12px;
  color: #888;
  margin-bottom: 10px;
}
.nn-pack-result-remain {
  display: block;
  font-size: 12px;
  color: #888;
  margin-bottom: 14px;
}
.nn-pack-result-btn {
  height: 40px;
  line-height: 40px;
  border-radius: 20px;
  background: #c62828;
  color: #fff;
  font-size: 15px;
  font-weight: 800;
}
.nn-pack-result-btn.busy {
  opacity: 0.65;
}
.chat-attach-item-muted {
  opacity: 0.45;
}
</style>

<template>
  <view>
    <TopBar :no-spacer="true" />
    <view id="tabMessages" class="tab-page active">
      <view class="chat-shell">
        <view class="chat-list-pane">
          <view class="chat-hero-hd chat-list-hero">
            <view class="chat-hero-title">红宝社区</view>
            <view class="chat-list-actions">
              <view class="chat-hero-icon-btn" @click="toggleSearch">
                <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
                  <circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2" />
                  <path d="M20 20l-3.5-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
              </view>
              <view class="chat-plus-menu-wrap">
                <view class="chat-hero-icon-btn" @click="plusOpen = !plusOpen">
                  <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
                    <path fill="currentColor" d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" />
                  </svg>
                </view>
                <view v-if="plusOpen" class="chat-plus-menu">
                  <view class="chat-plus-menu-item" @click="onPlusAction('scan')">
                    <image class="chat-plus-menu-ico-img" :src="icoScan" mode="aspectFit" />
                    <text>扫一扫</text>
                  </view>
                  <view class="chat-plus-menu-item" @click="onPlusAction('friend')">
                    <image class="chat-plus-menu-ico-img" :src="icoAddFriend" mode="aspectFit" />
                    <text>添加好友</text>
                  </view>
                  <view class="chat-plus-menu-item" @click="onPlusAction('request')">
                    <image class="chat-plus-menu-ico-img" :src="icoFriendReq" mode="aspectFit" />
                    <text>好友申请</text>
                    <text v-if="friendReqPending > 0" class="chat-friend-req-badge">{{ friendReqPending > 99 ? '99+' : friendReqPending }}</text>
                  </view>
                  <view class="chat-plus-menu-item" @click="onPlusAction('share')">
                    <image class="chat-plus-menu-ico-img" :src="fxIcon" mode="aspectFit" />
                    <text>分享推广</text>
                  </view>
                  <view v-if="canCreateGroup" class="chat-plus-menu-item" @click="onPlusAction('createGroup')">
                    <image class="chat-plus-menu-ico-img" :src="icoCreateGroup" mode="aspectFit" />
                    <text>建群</text>
                  </view>
                </view>
              </view>
            </view>
          </view>

          <view class="chat-list-main">
            <view class="chat-conn" :class="connClass">IM · {{ statusText }} · <text class="chat-reconnect" @click="reconnect">重连</text></view>
            <view class="chat-my-id" v-if="myIdText">{{ myIdText }}</view>

            <view class="chat-home-search-area">
              <view v-if="searchOpen" class="chat-home-search-row">
                <view class="chat-home-search-box">
                  <input class="chat-home-search-input" v-model="keyword" placeholder="搜索会话 / 昵称 / 内容" />
                </view>
                <view class="chat-home-search-cancel" @click="clearSearch">取消</view>
              </view>
              <view class="chat-home-tabs-row">
                <view class="chat-home-tab" :class="{ active: homeTab === 'chat' }" @click="switchHomeTab('chat')">聊天</view>
                <view class="chat-home-tab" :class="{ active: homeTab === 'community' }" @click="switchHomeTab('community')">社群</view>
                <view class="chat-home-tab" :class="{ active: homeTab === 'notice' }" @click="switchHomeTab('notice')">公告</view>
                <view class="chat-home-tab" :class="{ active: homeTab === 'commission' }" @click="switchHomeTab('commission')">佣金</view>
              </view>
            </view>

            <!-- 聊天 -->
            <view id="chatHomePanelChat" class="chat-home-panel" :class="{ 'is-hidden': homeTab !== 'chat' }">
              <view class="chat-conv-ptr-host">
                <view class="chat-conv-ptr" :class="{ refreshing: listRefreshing, ready: listRefreshing }">
                  <view class="chat-conv-ptr-inner">
                    <view class="chat-conv-ptr-spinner" />
                    <text class="chat-conv-ptr-text">{{ listRefreshing ? '刷新中…' : '下拉刷新' }}</text>
                  </view>
                </view>
                <scroll-view
                  scroll-y
                  class="chat-conv-scroll"
                  :refresher-enabled="true"
                  :refresher-triggered="listRefreshing"
                  @refresherrefresh="onListRefresh"
                >
                  <view class="chat-conv-list">
                    <view class="chat-empty" v-if="!displayList.length && loaded">暂无会话（登录后通常会有客服）</view>
                    <view
                      v-for="item in displayList"
                      :key="itemKey(item)"
                      class="chat-conv-item"
                      :class="{ 'is-pinned': !!item.pinned, 'is-admin': !!item.is_im_admin }"
                      @click="openChat(item)"
                      @longpress="onLongPress(item)"
                    >
                      <view class="chat-avatar" :class="{ group: (item.conversation_type | 0) === 2, admin: !!item.is_im_admin }">
                        <image :src="avatarSrc(item.avatar)" mode="aspectFill" />
                      </view>
                      <view class="chat-conv-body">
                        <view class="chat-conv-title">
                          <text>
                            <text v-if="item.pinned" class="chat-conv-pin">📌</text>
                            {{ displayTitle(item) }}
                            <text v-if="item.is_im_admin" class="chat-admin-tag">客服</text>
                          </text>
                          <text class="chat-conv-time">{{ itemTime(item) }}</text>
                        </view>
                        <view class="chat-conv-preview">{{ itemPreview(item) }}</view>
                      </view>
                      <view v-if="unreadOf(item) > 0" class="chat-badge">
                        {{ unreadOf(item) > 99 ? '99+' : unreadOf(item) }}
                      </view>
                    </view>
                  </view>
                </scroll-view>
              </view>
            </view>

            <!-- 社群 -->
            <view id="chatHomePanelCommunity" class="chat-home-panel chat-community-glass" :class="{ 'is-hidden': homeTab !== 'community' }">
              <view class="chat-community-seg">
                <view class="chat-community-seg-btn" :class="{ active: communitySub === 'official' }" @click="setCommunitySub('official')">官方社群</view>
                <view class="chat-community-seg-btn" :class="{ active: communitySub === 'mine' }" @click="setCommunitySub('mine')">我的群组</view>
                <view class="chat-community-seg-btn" :class="{ active: communitySub === 'friends' }" @click="setCommunitySub('friends')">好友列表</view>
              </view>

              <view v-if="communitySub === 'official'" class="chat-community-pane active">
                <view class="chat-community-hero-cards">
                  <view
                    v-for="(g, idx) in communityRecs"
                    :key="g.id || g.group_id"
                    class="chat-group-card"
                    @click="openGroup(g)"
                  >
                    <view v-if="!g.is_member && (idx === 0 || g.recommend_tag)" class="tag">{{ g.recommend_tag || '新建社群' }}</view>
                    <view class="icon-box">
                      <image :src="avatarSrc(g.avatar)" mode="aspectFill" />
                    </view>
                    <view class="title">{{ g.name || ('#' + (g.id || g.group_id)) }}</view>
                    <view class="subtitle">{{ groupMembersText(g) }}</view>
                  </view>
                  <view v-if="!communityRecs.length" class="chat-empty chat-empty-glass">暂无推荐社群</view>
                </view>
              </view>

              <view v-else-if="communitySub === 'mine'" class="chat-community-pane active">
                <view class="chat-community-glass-panel chat-community-pane-body">
                  <view class="chat-my-groups-list">
                    <view
                      v-if="canCreateGroup"
                      class="chat-my-group-item chat-my-group-create"
                      @click="openCreateGroupPane({ fromCreateCard: true })"
                    >
                      <view class="chat-my-group-main">
                        <view class="chat-my-group-avatar chat-my-group-avatar-plus">+</view>
                        <view class="chat-my-group-create-text">
                          <text class="chat-my-group-name">+ 创建我的专属保密对战群</text>
                          <text class="chat-my-group-sub">零门槛当群主，躺赚群内 1% 发包管理费津贴</text>
                        </view>
                      </view>
                    </view>
                    <view v-for="g in myGroups" :key="g.id" class="chat-my-group-item" @click="openGroup(g)">
                      <view class="chat-my-group-main">
                        <view class="chat-my-group-avatar">
                          <image :src="avatarSrc(g.avatar)" mode="aspectFill" />
                        </view>
                        <text class="chat-my-group-name">{{ g.name || ('#' + g.id) }}</text>
                      </view>
                      <view class="chat-my-group-count">{{ g.display_member_count || g.member_count || 0 }}<text>人</text></view>
                    </view>
                    <view v-if="!myGroups.length" class="chat-empty chat-empty-glass">暂无已加入社群</view>
                  </view>
                </view>
              </view>

              <view v-else class="chat-community-pane active">
                <view class="chat-community-glass-panel chat-community-pane-body">
                  <view class="chat-friend-feed-list">
                    <view
                      v-for="f in friends"
                      :key="f.peer_user_id || f.user_id"
                      class="chat-feed-item"
                      :class="{ 'is-pinned-cs': !!(f.is_default_cs || f.pinned) }"
                      @click="openFriendChat(f)"
                    >
                      <view class="chat-feed-avatar">
                        <image :src="avatarSrc(f.avatar)" mode="aspectFill" />
                        <view class="chat-feed-online-dot" :class="{ off: !f.online }" />
                      </view>
                      <view class="chat-feed-body">
                        <view class="chat-feed-text">
                          <text v-if="f.is_default_cs || f.pinned" class="chat-feed-pin">📌</text>
                          {{ friendName(f) }}
                        </view>
                        <view class="chat-feed-status" :class="{ on: !!f.online }">{{ f.online ? '刚刚在线' : '暂时离开' }}</view>
                      </view>
                    </view>
                    <view v-if="!friends.length" class="chat-empty chat-empty-glass">暂无好友</view>
                  </view>
                </view>
              </view>
            </view>

            <!-- 公告（对齐 888：四分类 + 推广收益表 + 动态卡片） -->
            <view id="chatHomePanelNotice" class="chat-home-panel chat-notice-feed-panel" :class="{ 'is-hidden': homeTab !== 'notice' }">
              <view class="chat-community-seg chat-notice-seg" id="chatNoticeCats" role="tablist">
                <view
                  class="chat-community-seg-btn"
                  :class="{ active: noticeCat === 'latest' }"
                  @click="setNoticeCat('latest')"
                >最新发布</view>
                <view
                  class="chat-community-seg-btn"
                  :class="{ active: noticeCat === 'promote' }"
                  @click="setNoticeCat('promote')"
                >推广赚钱</view>
                <view
                  class="chat-community-seg-btn"
                  :class="{ active: noticeCat === 'ads' }"
                  @click="setNoticeCat('ads')"
                >广告发布</view>
                <view
                  class="chat-community-seg-btn"
                  :class="{ active: noticeCat === 'rules' }"
                  @click="setNoticeCat('rules')"
                >游戏规则</view>
              </view>
              <scroll-view class="chat-notice-body-scroll" scroll-y :show-scrollbar="false">
                <view
                  v-if="noticeCat === 'promote'"
                  class="chat-promote-earn-wrap"
                  id="chatPromoteEarnWrap"
                >
                  <view class="chat-promote-earn-card">
                    <view class="chat-promote-earn-hd">
                      <text class="chat-promote-earn-title">推广收益数据表</text>
                      <text class="chat-promote-earn-live" @click="refreshPromoteEarnMock">实时更新 ›</text>
                    </view>
                    <view class="chat-promote-earn-table">
                      <view class="chat-promote-earn-thead">
                        <view class="chat-promote-earn-th"><text>用户ID</text></view>
                        <view class="chat-promote-earn-th is-active"><text>收益类型</text></view>
                        <view class="chat-promote-earn-th"><text>广细记录</text></view>
                        <view class="chat-promote-earn-th"><text>到手佣金</text></view>
                      </view>
                      <view class="chat-promote-earn-viewport">
                        <view
                          class="chat-promote-earn-track"
                          :style="promoteEarnTrackStyle"
                        >
                          <view
                            v-for="(row, idx) in promoteEarnDisplayRows"
                            :key="'pe-' + idx"
                            class="chat-promote-earn-row"
                          >
                            <view class="chat-promote-earn-td"><text>{{ row.uidMasked }}</text></view>
                            <view class="chat-promote-earn-td"><text>{{ row.typeLabel }}</text></view>
                            <view class="chat-promote-earn-td is-detail"><text>{{ row.detailLabel }}</text></view>
                            <view class="chat-promote-earn-td is-amt"><text>{{ row.amountText }}</text></view>
                          </view>
                        </view>
                      </view>
                    </view>
                  </view>
                </view>

                <view class="chat-notice-feed" id="chatNoticeFeed">
                  <view
                    v-for="n in notices"
                    :key="n.id || n.publishtime || n.createtime"
                    class="chat-notice-card"
                  >
                    <view class="chat-notice-hd">
                      <image
                        v-if="n.author_avatar"
                        class="chat-notice-avatar"
                        :src="avatarSrc(n.author_avatar)"
                        mode="aspectFill"
                      />
                      <view
                        v-else
                        class="chat-notice-avatar chat-notice-avatar-fallback"
                        aria-hidden="true"
                      >{{ noticeCategoryIcon(n) }}</view>
                      <view class="chat-notice-meta">
                        <view class="chat-notice-name-row">
                          <text class="chat-notice-name">{{ n.author_name || '红宝官方公告' }}</text>
                          <text class="chat-notice-day">{{ noticeRelativeDay(n) }}</text>
                          <text v-if="noticeCatLabel(n)" class="chat-notice-tag">【{{ noticeCatLabel(n) }}】</text>
                        </view>
                      </view>
                      <view class="chat-notice-time">{{ noticeClock(n) }}</view>
                    </view>
                    <view class="chat-notice-body">{{ n.content || n.summary || n.title || '' }}</view>
                    <view v-if="noticeVideo(n)" class="chat-notice-media">
                      <video
                        class="chat-notice-video"
                        :src="noticeVideo(n)"
                        controls
                        object-fit="contain"
                      />
                    </view>
                    <view
                      v-if="noticeImages(n).length"
                      class="chat-notice-media"
                    >
                      <view class="chat-notice-imgs" :class="'imgs-' + Math.min(9, noticeImages(n).length)">
                        <image
                          v-for="(src, ii) in noticeImages(n).slice(0, 9)"
                          :key="ii"
                          class="chat-notice-img"
                          :src="avatarSrc(src)"
                          mode="aspectFill"
                          @click="previewNoticeImages(n, ii)"
                        />
                      </view>
                    </view>
                    <view v-if="noticeActionButtons(n).length" class="chat-notice-actions">
                      <view
                        v-for="(btn, bi) in noticeActionButtons(n)"
                        :key="bi"
                        class="chat-notice-action-btn"
                        :class="btn.cls"
                        @click="handleNoticeAction(btn.action, btn.url, btn.label)"
                      >{{ btn.label }}</view>
                    </view>
                    <view class="chat-notice-ft">
                      <view class="chat-notice-share-btn" @click="shareNoticeToCommunity(n)">分享到社群</view>
                    </view>
                  </view>
                  <view v-if="!notices.length" class="chat-empty chat-empty-glass">暂无公告</view>
                </view>
              </scroll-view>
            </view>

            <!-- 佣金 -->
            <view id="chatHomePanelCommission" class="chat-home-panel chat-commission-panel" :class="{ 'is-hidden': homeTab !== 'commission' }">
              <view class="chat-commission-hero-card">
                <view class="chat-commission-hero-top">
                  <text class="chat-commission-hero-label">累计佣金</text>
                  <view class="chat-commission-withdraw-btn" @click="goCommission">提现</view>
                </view>
                <view class="chat-commission-hero-value">¥ {{ money(commission.total_money) }}</view>
                <view class="chat-commission-hero-stats">
                  <view class="chat-commission-stat">
                    <text class="chat-commission-stat-label">可提现</text>
                    <text class="chat-commission-stat-value">¥ {{ money(commission.withdrawable) }}</text>
                  </view>
                  <view class="chat-commission-stat-divider" />
                  <view class="chat-commission-stat">
                    <text class="chat-commission-stat-label">今日收益</text>
                    <text class="chat-commission-stat-value">¥ {{ money(commission.today_money) }}</text>
                  </view>
                  <view class="chat-commission-stat-divider" />
                  <view class="chat-commission-stat">
                    <text class="chat-commission-stat-label">红包返佣</text>
                    <text class="chat-commission-stat-value">¥ {{ money(commission.rebate_money) }}</text>
                  </view>
                </view>
              </view>

              <view class="chat-commission-nav-grid">
                <view
                  class="chat-commission-nav-item"
                  :class="{ 'is-active': commissionListMode === 'promo' }"
                  @click="goCommissionNav('promo')"
                >
                  <view class="chat-commission-nav-ico">
                    <svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm4 18H6V4h7v5h5v11zm-3.5-5.5l-1.4 1.4L11 13.8l-2.1 2.1-1.4-1.4L9.6 12.4 7.5 10.3l1.4-1.4L11 11l2.1-2.1 1.4 1.4-2.1 2.1 2.1 2.1z"/></svg>
                  </view>
                  <text class="chat-commission-nav-label">推广结算 ›</text>
                </view>
                <view
                  class="chat-commission-nav-item"
                  :class="{ 'is-active': commissionListMode === 'rebate' }"
                  @click="goCommissionNav('rebate')"
                >
                  <view class="chat-commission-nav-ico">
                    <svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm1 15H9v-2h6v2zm0-4H9v-2h6v2zm-3-5V3.5L18.5 9H12z"/></svg>
                  </view>
                  <text class="chat-commission-nav-label">红包返佣 ›</text>
                </view>
                <view
                  class="chat-commission-nav-item"
                  :class="{ 'is-active': commissionListMode === 'ledger' }"
                  @click="goCommissionNav('ledger')"
                >
                  <view class="chat-commission-nav-ico">
                    <svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 14h-2v-2h2v2zm0-4h-2V7h2v5z"/></svg>
                  </view>
                  <text class="chat-commission-nav-label">收益明细 ›</text>
                </view>
                <view
                  class="chat-commission-nav-item"
                  :class="{ 'is-active': commissionListMode === 'withdraw_list' }"
                  @click="goCommissionNav('withdraw_list')"
                >
                  <view class="chat-commission-nav-ico">
                    <svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 14l-5-5 1.41-1.41L11 13.17V7h2v6.17l2.59-2.58L17 12l-5 5z"/></svg>
                  </view>
                  <text class="chat-commission-nav-label">提现记录 ›</text>
                </view>
              </view>

              <view class="chat-commission-recent-card">
                <view class="chat-commission-recent-hd">{{ commissionListTitle }}</view>
                <view class="chat-commission-list">
                  <view
                    class="chat-commission-row"
                    :class="{ 'is-dual-rebate': isDualRebate(row) }"
                    v-for="(row, idx) in commissionRows"
                    :key="row.id || idx"
                  >
                    <view class="chat-commission-row-ico" aria-hidden="true">
                      <svg viewBox="0 0 24 24" width="14" height="14"><path fill="currentColor" d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                    </view>
                    <view class="chat-commission-row-main">
                      <view class="chat-commission-row-title">{{ commissionRowTitle(row) }}</view>
                      <view class="chat-commission-row-time">{{ formatNoticeTime(row) }}</view>
                    </view>
                    <view class="chat-commission-row-amt" :class="{ 'is-out': isAmtOut(row) }">{{ formatCommissionAmt(row) }}</view>
                  </view>
                  <view v-if="!commissionRows.length" class="chat-empty chat-empty-glass">{{ commissionEmptyText }}</view>
                </view>
              </view>
            </view>
          </view>
        </view>
      </view>
    </view>

    <!-- 创建新群聊（对齐 888 chatCreateGroupPane） -->
    <view
      class="chat-create-group-pane"
      :class="{ open: createGroupOpen }"
      :aria-hidden="createGroupOpen ? 'false' : 'true'"
    >
      <view class="chat-cg-header">
        <view class="chat-cg-back" @click="closeCreateGroupPane">
          <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true">
            <path fill="currentColor" d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6 6 6 1.41-1.41z" />
          </svg>
        </view>
        <view class="chat-cg-title">创建新群聊</view>
        <view
          class="chat-cg-next-top"
          :class="{ 'is-disabled': createGroupSubmitting }"
          @click="submitCreateGroup"
        >下一步</view>
      </view>
      <view class="chat-cg-main">
        <view class="chat-cg-input-group">
          <view class="chat-cg-avatar" @click="cycleCreateGroupAvatar">{{ createGroupAvatar }}</view>
          <view class="chat-cg-input-box">
            <input
              class="chat-cg-name-input"
              type="text"
              maxlength="64"
              v-model="createGroupName"
              placeholder="请输入群名称"
              confirm-type="done"
              @confirm="submitCreateGroup"
            />
            <view
              class="chat-cg-next-inner"
              :class="{ 'is-disabled': createGroupSubmitting }"
              @click="submitCreateGroup"
            >下一步</view>
          </view>
        </view>

        <view class="chat-cg-section-title"><text>群类型</text></view>
        <view class="chat-cg-cards">
          <view
            class="chat-cg-card"
            :class="{ active: createGroupPrivacy === 'open' }"
            @click="createGroupPrivacy = 'open'"
          >
            <view class="chat-cg-card-header">
              <text class="chat-cg-radio" />
              <text>开放群</text>
            </view>
            <view class="chat-cg-card-body">
              <text class="chat-cg-dot" />
              <text>可查看成员资料，支持自由加好友</text>
            </view>
          </view>
          <view
            class="chat-cg-card"
            :class="{ active: createGroupPrivacy === 'private' }"
            @click="createGroupPrivacy = 'private'"
          >
            <view class="chat-cg-card-header">
              <text class="chat-cg-radio" />
              <text>隐私群</text>
            </view>
            <view class="chat-cg-card-body">
              <text class="chat-cg-dot" />
              <text>隐藏成员列表，陌生人不可互加</text>
            </view>
          </view>
        </view>

        <view class="chat-cg-section-title"><text>运行模式</text></view>
        <view class="chat-cg-cards">
          <view
            class="chat-cg-card"
            :class="{ 'active-light': createGroupMode === 'chat' }"
            @click="createGroupMode = 'chat'"
          >
            <view class="chat-cg-card-header">
              <text class="chat-cg-radio" />
              <text>聊天模式</text>
            </view>
            <view class="chat-cg-card-body">
              <text class="chat-cg-dot" />
              <text>自由聊天，可发普通/手气/埋雷红包</text>
            </view>
          </view>
          <view
            class="chat-cg-card"
            :class="{ active: createGroupMode === 'grab' }"
            @click="createGroupMode = 'grab'"
          >
            <view class="chat-cg-card-header">
              <text class="chat-cg-radio" />
              <text>红包对战模式</text>
            </view>
            <view class="chat-cg-card-body">
              <text class="chat-cg-dot" />
              <text>仅管理员可发红宝；发言限制请在群设置「禁止模式」配置</text>
            </view>
          </view>
        </view>

        <view class="chat-cg-hint">群主可后续在群设置中修改</view>
      </view>
    </view>

    <!-- 会话长按：对齐 888 #chatConvActionSheet -->
    <view class="chat-action-sheet chat-conv-action-sheet" :class="{ open: !!convSheetItem }" v-if="convSheetItem" aria-hidden="false">
      <view class="chat-action-sheet-mask" @click="closeConvSheet" />
      <view class="chat-action-sheet-panel" @click.stop>
        <view class="chat-action-sheet-title">{{ convSheetTitle }}</view>
        <button type="button" class="chat-action-item" @click="doConvPin">
          {{ convSheetPinned ? '取消置顶' : '置顶聊天' }}
        </button>
        <button type="button" class="chat-action-item danger" @click="doConvDelete">删除聊天</button>
        <button type="button" class="chat-action-item cancel" @click="closeConvSheet">取消</button>
      </view>
    </view>

    <BottomTabBar active="messages" />
  </view>
</template>

<script setup>
import { computed, onUnmounted, ref, watch } from 'vue'
import { onShow, onHide } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import BottomTabBar from '../../components/BottomTabBar.vue'
import '../../styles/chat.bundle.css'
import '../../styles/chat-uni-adapter.css'
import '../../styles/chat-888-parity.css'
import { apiRequest, fetchProfile, getToken } from '../../utils/auth.js'
import {
  avatarLetter,
  avatarSrc,
  convKey,
  displayTitle,
  formatConvTime,
  previewText,
  resolveConvId,
} from '../../utils/chat.js'
import { assetBase } from '../../utils/i18n.js'
import { openFriendScanSheet } from '../../utils/friend-scan.js'
import {
  canCreateGroupFromAuth,
  createGroup,
  friendRequests,
  getImAuthMeta,
  getImStatus,
  hideConversation,
  imConnect,
  joinGroup,
  listFriends,
  listMyGroups,
  listConversations,
  markConversationRead,
  onImEvent,
  pinConversation,
  resumeFromBackground,
  bindForegroundResume,
} from '../../utils/im.js'
import {
  clearInboxUnread,
  setInboxMyId,
  startImInbox,
  syncInboxFromServerList,
} from '../../utils/im-inbox.js'
import { setChatUnreadTotal } from '../../utils/tab-badge.js'

const CREATE_GROUP_AVATARS = ['🐵', '🐼', '🦊', '🐯', '🦁', '🐶', '🐱', '🐰', '🐻', '🐨', '🐸', '🐷']

const list = ref([])
const loaded = ref(false)
const status = ref('disconnected')
const localUnread = ref({})
const fxIcon = assetBase() + 'static/chat/fx.png'
const icoScan = assetBase() + 'static/chat/plus_scan.png'
const icoAddFriend = assetBase() + 'static/chat/plus_add_friend.png'
const icoFriendReq = assetBase() + 'static/chat/plus_friend_req.png'
const icoCreateGroup = assetBase() + 'static/chat/plus_create_group.png'
const homeTab = ref('chat')
const searchOpen = ref(false)
const keyword = ref('')
const plusOpen = ref(false)
const canCreateGroup = ref(false)
const myIdText = ref('')
const createGroupOpen = ref(false)
const createGroupName = ref('')
const createGroupPrivacy = ref('private')
const createGroupMode = ref('chat')
const createGroupAvatar = ref('🐵')
const createGroupSubmitting = ref(false)
const createGroupBindRebate = ref(false)
const communitySub = ref('official')
const communityRecs = ref([])
const myGroups = ref([])
const friends = ref([])
const notices = ref([])
const noticeCat = ref('latest')
const promoteEarnRows = ref([])
const promoteEarnOffset = ref(0)
let promoteEarnTimer = null
const commission = ref({})
const commissionListMode = ref('recent')
const friendReqPending = ref(0)
const listRefreshing = ref(false)
const convSheetItem = ref(null)
let off = null
let loading = false
let pageAlive = false

const displayList = computed(() => {
  const q = String(keyword.value || '').trim().toLowerCase()
  if (!q) return list.value
  return list.value.filter((it) => {
    const title = String(displayTitle(it) || '').toLowerCase()
    const nick = String(it.peer_nickname || '').toLowerCase()
    const prev = String(itemPreview(it) || '').toLowerCase()
    return title.indexOf(q) >= 0 || nick.indexOf(q) >= 0 || prev.indexOf(q) >= 0
  })
})

const convSheetTitle = computed(() => {
  const item = convSheetItem.value
  if (!item) return '会话操作'
  return displayTitle(item) || '会话操作'
})
const convSheetPinned = computed(() => !!(convSheetItem.value && convSheetItem.value.pinned))

const statusText = computed(() => {
  if (status.value === 'online') return '已连接'
  if (status.value === 'connecting') return '连接中…'
  return '未连接'
})

const connClass = computed(() => {
  if (status.value === 'online') return 'ok'
  if (status.value === 'connecting') return ''
  return 'err'
})

function refreshStatus() {
  status.value = getImStatus()
}

function itemKey(item) {
  return convKey(item.conversation_type, resolveConvId(item))
}

function unreadOf(item) {
  const key = itemKey(item)
  const local = localUnread.value[key] | 0
  const server = item.unread_count | 0
  return Math.max(local, server)
}

function toggleSearch() {
  searchOpen.value = !searchOpen.value
  plusOpen.value = false
}

function clearSearch() {
  keyword.value = ''
  searchOpen.value = false
}

function money(v) {
  const n = Number(v || 0)
  return isFinite(n) ? n.toFixed(2) : '0.00'
}

function formatAmt(v) {
  if (v == null || v === '') return '-'
  const n = Number(v)
  if (!isFinite(n)) return String(v)
  return (n >= 0 ? '+' : '') + n.toFixed(2)
}

function isDualRebate(row) {
  const r = row || {}
  return String(r.revenue_type || '') === 'dual' || String(r.type || '') === 'red_packet_dual_rebate_in'
}

function isAmtOut(row) {
  const t = String(formatCommissionAmt(row) || '')
  return t.indexOf('-') === 0
}

function commissionRowTitle(row) {
  row = row || {}
  if (row.type_label) return String(row.type_label)
  if (row.title) return String(row.title)
  if (row.scene_text) return String(row.scene_text)
  if (row.type_text) return String(row.type_text)
  const rt = String(row.revenue_type || '')
  if (rt === 'dual') return '🔥 群主+推荐双重返佣'
  if (rt === 'invite') return '🔗 推荐发包返佣'
  if (rt === 'owner') return '群主返佣'
  const typ = String(row.type || '')
  if (typ === 'red_packet_dual_rebate_in') return '🔥 群主+推荐双重返佣'
  if (typ === 'red_packet_invite_rebate_in' || typ === 'red_packet_rebate') return '🔗 推荐发包返佣'
  if (typ === 'red_packet_agent_rebate_in') return '群主返佣'
  return typ || '结算记录'
}

function formatCommissionAmt(row) {
  row = row || {}
  if (row.amount_text) return String(row.amount_text)
  const h = Number(row.hongbao_change || 0)
  const b = Number(row.balance_change || 0)
  const r = Number(row.rights_change || 0)
  const money = h || b || r || Number(row.amount || 0)
  if (!money) return '¥ 0.00'
  const abs = Math.abs(money).toFixed(2)
  return (money > 0 ? '+¥ ' : '-¥ ') + abs
}

const commissionListTitle = computed(() => {
  const mode = commissionListMode.value
  if (mode === 'promo') return '推广结算'
  if (mode === 'rebate') return '红包返佣'
  if (mode === 'withdraw_list') return '提现记录'
  if (mode === 'ledger') return '收益明细'
  return '最近结算'
})

const commissionEmptyText = computed(() => {
  const mode = commissionListMode.value
  if (mode === 'promo') return '暂无推广结算'
  if (mode === 'rebate') return '暂无红包返佣'
  if (mode === 'withdraw_list') return '暂无提现记录'
  if (mode === 'ledger') return '暂无收益明细'
  return '登录后查看佣金明细'
})

const commissionRows = computed(() => {
  const data = commission.value || {}
  const mode = commissionListMode.value
  let list = []
  if (mode === 'promo') list = data.promo_recent
  else if (mode === 'rebate') list = data.rebate_recent
  else if (mode === 'withdraw_list') list = data.withdraw_recent
  else list = data.recent
  return Array.isArray(list) ? list : []
})

function groupEmoji(name) {
  const n = String(name || '')
  if (/红包|福利/.test(n)) return '🧧'
  if (/保密|私密|元宝|金/.test(n)) return '🪙'
  if (/礼|赠|礼物/.test(n)) return '🎁'
  if (/开放|红宝/.test(n)) return '👑'
  return '🧧'
}

function groupMembersText(g) {
  const n = (g && (g.member_count || g.display_member_count)) | 0
  return n > 0 ? n + '人' : ''
}

function friendName(f) {
  return f.remark || f.peer_nickname || f.nickname || ('ID' + (f.peer_user_id || f.user_id || ''))
}

function noticeCatLabel(n) {
  const label = String((n && n.category_label) || '').trim()
  if (label) return label
  const c = String((n && n.category) || noticeCat.value || '')
  if (c === 'promote') return '推广赚钱'
  if (c === 'ads') return '广告发布'
  if (c === 'rules') return '游戏规则'
  if (c === 'latest') return '最新发布'
  return c
}

function noticeCategoryIcon(n) {
  const c = String((n && (n.category || noticeCat.value)) || '')
  if (c === 'rules' || c.indexOf('规则') >= 0) return '📋'
  if (c === 'promote' || c.indexOf('推广') >= 0) return '💼'
  if (c === 'ads' || c.indexOf('广告') >= 0) return '📣'
  if (c === 'latest' || c.indexOf('最新') >= 0) return '🆕'
  return '📢'
}

function noticeTs(n) {
  return (n && (n.publishtime || n.createtime || n.updatetime || n.time)) | 0
}

function noticeRelativeDay(n) {
  let ts = noticeTs(n)
  if (!ts) return ''
  if (ts > 1e12) ts = Math.floor(ts / 1000)
  const d = new Date(ts * 1000)
  const now = new Date()
  const startToday = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime()
  const startThat = new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()
  const diff = Math.round((startToday - startThat) / 86400000)
  if (diff === 0) return '今天'
  if (diff === 1) return '昨天'
  if (diff > 1 && diff < 7) return diff + '天前'
  return (d.getMonth() + 1) + '月' + d.getDate() + '日'
}

function noticeClock(n) {
  let ts = noticeTs(n)
  if (!ts) return ''
  if (ts > 1e12) ts = Math.floor(ts / 1000)
  const d = new Date(ts * 1000)
  const hh = String(d.getHours()).padStart(2, '0')
  const mm = String(d.getMinutes()).padStart(2, '0')
  return hh + ':' + mm
}

function formatNoticeTime(n) {
  return formatConvTime(noticeTs(n))
}

function noticeVideo(n) {
  return String((n && n.video) || '').trim()
}

function noticeImages(n) {
  const imgs = n && n.images
  if (!Array.isArray(imgs)) return []
  return imgs.filter(Boolean)
}

function noticeActionButtons(n) {
  if (!n) return []
  const type = String(n.action_type || '')
  if (type === 'buttons' && Array.isArray(n.action_buttons) && n.action_buttons.length) {
    return n.action_buttons.map((btn) => ({
      label: String((btn && btn.label) || '').trim(),
      url: String((btn && btn.url) || ''),
      action: 'link',
      cls: 'soft',
    })).filter((b) => b.label)
  }
  const label = String(n.action_label || '').trim()
  if (!label) return []
  const isShare = type === 'share'
  return [{
    label,
    url: String(n.action_url || ''),
    action: isShare ? 'share' : 'link',
    cls: isShare ? 'primary' : 'wide-soft',
  }]
}

function previewNoticeImages(n, index) {
  const urls = noticeImages(n).map((u) => avatarSrc(u)).filter(Boolean)
  if (!urls.length) return
  uni.previewImage({ urls, current: urls[index | 0] || urls[0] })
}

function handleNoticeAction(action, url, label) {
  action = String(action || '')
  url = String(url || '').trim()
  label = String(label || '')
  if (action === 'share' || /邀请|推广|佣金|收益/.test(label)) {
    switchHomeTab('commission')
    return
  }
  if (/红包|接力/.test(label)) {
    switchHomeTab('community')
    return
  }
  if (!url) return
  if (/^https?:\/\//i.test(url)) {
    // #ifdef H5
    if (typeof window !== 'undefined') window.open(url, '_blank')
    // #endif
    // #ifndef H5
    uni.setClipboardData({
      data: url,
      success: () => uni.showToast({ title: '链接已复制', icon: 'none' }),
    })
    // #endif
    return
  }
  if (url.charAt(0) === '/') {
    uni.navigateTo({ url }).catch(() => {
      uni.switchTab({ url }).catch(() => {})
    })
  }
}

async function shareNoticeToCommunity(n) {
  const cat = noticeCatLabel(n)
  const text = String((n && n.content) || '').trim()
  let shareText = (cat ? ('【' + cat + '】') : '') + (text ? text.slice(0, 120) : '红宝官方公告')
  try {
    if (getToken()) {
      const share = await apiRequest('share', 'POST', { copy_only: 1 })
      if (share && share.share_text) shareText += '\n' + share.share_text
      else if (share && share.share_link) shareText += '\n' + share.share_link
    }
  } catch (e) {}
  // #ifdef H5
  try {
    if (typeof navigator !== 'undefined' && navigator.share) {
      await navigator.share({ title: '红宝公告', text: shareText })
      uni.showToast({ title: '已唤起分享', icon: 'none' })
      return
    }
  } catch (e) {}
  // #endif
  uni.setClipboardData({
    data: shareText,
    success: () => uni.showToast({ title: '已复制，可粘贴到社群', icon: 'none' }),
    fail: () => uni.showToast({ title: '分享失败', icon: 'none' }),
  })
}

function promoteEarnMaskUid(uid) {
  uid = String(uid == null ? '' : uid).replace(/\D/g, '')
  if (uid.length <= 4) return '****'
  if (uid.length <= 6) return uid.slice(0, 1) + '****' + uid.slice(-1)
  const head = Math.floor((uid.length - 4) / 2)
  const tail = uid.length - 4 - head
  return uid.slice(0, head) + '****' + uid.slice(uid.length - tail)
}

function promoteEarnTypeLabel(key) {
  return key === 'group' ? '群主红包返佣' : '分享推广'
}

function promoteEarnDetailLabel(key, n) {
  if (key === 'promote_earn_detail_group_fee') return '红包抽成返佣'
  if (key === 'promote_earn_detail_groups_n') return '自建' + n + '群红包返利'
  if (key === 'promote_earn_detail_multi') return '多群互动返现'
  if (key === 'promote_earn_detail_exposure') return '推广曝光成交收益'
  return '分享链接引流' + n + '人'
}

function buildPromoteEarnMockRows(count) {
  count = Math.max(12, Math.min(40, count || 24))
  const shareDetails = [
    'promote_earn_detail_share_n',
    'promote_earn_detail_multi',
    'promote_earn_detail_exposure',
  ]
  const rows = []
  for (let i = 0; i < count; i++) {
    const r = Math.random()
    const typeKey = r < 0.55 ? 'share' : 'group'
    const detailKey = typeKey === 'group'
      ? 'promote_earn_detail_group_fee'
      : shareDetails[Math.floor(Math.random() * shareDetails.length)]
    const n = 3 + Math.floor(Math.random() * 40)
    const uidNum = 10000000 + Math.floor(Math.random() * 90000000)
    let amt = 18 + Math.random() * 160 + (i % 7) * 3.17
    amt = Math.round(amt * 100) / 100
    rows.push({
      uidMasked: promoteEarnMaskUid(String(uidNum)),
      typeLabel: promoteEarnTypeLabel(typeKey),
      detailLabel: promoteEarnDetailLabel(detailKey, n),
      amountText: '¥' + amt.toFixed(2),
    })
  }
  return rows
}

const promoteEarnDisplayRows = computed(() => {
  const rows = promoteEarnRows.value || []
  if (!rows.length) return []
  return rows.concat(rows)
})

const promoteEarnTrackStyle = computed(() => ({
  transform: 'translateY(-' + (promoteEarnOffset.value | 0) + 'px)',
  transition: promoteEarnOffset.value ? 'transform 0.45s ease' : 'none',
}))

function stopPromoteEarnScroll() {
  if (promoteEarnTimer) {
    clearInterval(promoteEarnTimer)
    promoteEarnTimer = null
  }
  promoteEarnOffset.value = 0
}

function startPromoteEarnScroll() {
  stopPromoteEarnScroll()
  if (noticeCat.value !== 'promote') return
  if (!(promoteEarnRows.value && promoteEarnRows.value.length)) return
  const rowH = 36
  promoteEarnTimer = setInterval(() => {
    const half = (promoteEarnRows.value.length | 0) * rowH
    if (half < rowH) return
    promoteEarnOffset.value += rowH
    if (promoteEarnOffset.value >= half) {
      setTimeout(() => {
        promoteEarnOffset.value = 0
      }, 480)
    }
  }, 3000)
}

function syncPromoteEarnPanel() {
  if (noticeCat.value === 'promote') {
    if (!promoteEarnRows.value.length) {
      promoteEarnRows.value = buildPromoteEarnMockRows(24)
    }
    startPromoteEarnScroll()
  } else {
    stopPromoteEarnScroll()
  }
}

function refreshPromoteEarnMock() {
  promoteEarnRows.value = buildPromoteEarnMockRows(24)
  startPromoteEarnScroll()
  uni.showToast({ title: '已刷新收益数据', icon: 'none' })
}

watch(noticeCat, () => {
  syncPromoteEarnPanel()
})

onUnmounted(() => {
  stopPromoteEarnScroll()
})

function itemPreview(item) {
  const prev = previewText(item.last_message)
  if (prev && prev !== '暂无消息') return prev
  return item.is_im_admin ? '点击开始咨询' : '暂无消息'
}

function itemTime(item) {
  const last = item.last_message
  const ts = item.updatetime || (last && last.createtime) || 0
  return formatConvTime(ts)
}

function openChat(item) {
  const type = item.conversation_type | 0
  const id = resolveConvId(item)
  const key = convKey(type, id)
  localUnread.value = Object.assign({}, localUnread.value, { [key]: 0 })
  item.unread_count = 0
  clearInboxUnread(type, id)
  const last = item.last_message
  const lastId = last ? (last.msg_id || last.id) | 0 : 0
  if (id) markConversationRead(type, id, lastId).catch(() => null)
  const q = [
    'type=' + encodeURIComponent(type),
    'id=' + encodeURIComponent(id),
    'peer=' + encodeURIComponent(item.peer_user_id || 0),
    'group=' + encodeURIComponent(item.group_id || (type === 2 ? id : 0)),
    'title=' + encodeURIComponent(displayTitle(item)),
    'nickname=' + encodeURIComponent(item.peer_nickname || ''),
    'remark=' + encodeURIComponent(item.remark || ''),
  ].join('&')
  uni.navigateTo({ url: '/pages/chat/chat?' + q })
}

function bumpUnread(msg) {
  // 未读由全局 im-inbox 维护；此处仅同步会话列表预览，避免双计
  if (!msg) return
  upsertListFromMessage(msg)
}

function upsertListFromMessage(msg) {
  if (!msg) return
  const type = (msg.conversation_type | 0) || ((msg.group_id | 0) > 0 ? 2 : 1)
  let id = ''
  if (type === 2) id = String(msg.group_id || msg.conversation_id || '')
  else id = String(msg.conversation_id || '')
  if (!id) return
  const key = convKey(type, id)
  const rows = list.value.slice()
  let found = null
  for (let i = 0; i < rows.length; i++) {
    if (itemKey(rows[i]) === key) {
      found = rows[i]
      break
    }
  }
  if (!found) {
    found = {
      conversation_type: type,
      conversation_id: id,
      group_id: type === 2 ? (msg.group_id | 0) : 0,
      peer_user_id: type === 1 ? ((msg.from_user_id | 0) === (myIdNum() | 0) ? msg.to_user_id : msg.from_user_id) : 0,
      title: type === 2 ? '群 ' + id : 'ID ' + (msg.from_user_id || ''),
      last_message: msg,
      updatetime: msg.createtime | 0,
      unread_count: localUnread.value[key] | 0,
      pinned: false,
    }
    rows.unshift(found)
  } else {
    found.last_message = msg
    found.updatetime = msg.createtime | 0
    found.unread_count = localUnread.value[key] | 0
  }
  rows.sort((a, b) => {
    const ap = a.pinned ? 1 : 0
    const bp = b.pinned ? 1 : 0
    if (ap !== bp) return bp - ap
    return (b.updatetime | 0) - (a.updatetime | 0)
  })
  list.value = rows
}

function myIdNum() {
  const n = parseInt(String(myIdText.value || '').replace(/\D+/g, ''), 10)
  return n || 0
}

function onLongPress(item) {
  if (!item) return
  plusOpen.value = false
  convSheetItem.value = item
}

function closeConvSheet() {
  convSheetItem.value = null
}

async function doConvPin() {
  const item = convSheetItem.value
  if (!item) return
  const type = item.conversation_type | 0
  const id = resolveConvId(item)
  const pinned = !!item.pinned
  try {
    await pinConversation(type, id, !pinned)
    closeConvSheet()
    await loadList(true)
    uni.showToast({ title: pinned ? '已取消置顶' : '已置顶', icon: 'none' })
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '操作失败', icon: 'none' })
  }
}

function doConvDelete() {
  const item = convSheetItem.value
  if (!item) return
  const type = item.conversation_type | 0
  const id = resolveConvId(item)
  uni.showModal({
    title: '删除会话',
    content: '从列表移除「' + displayTitle(item) + '」？聊天记录不会清空。',
    success: async (r) => {
      if (!r.confirm) return
      try {
        const extra = {}
        if (type === 2) extra.group_id = item.group_id || id
        else if (item.peer_user_id) extra.to_user_id = item.peer_user_id
        await hideConversation(type, id, extra)
        list.value = list.value.filter((x) => itemKey(x) !== itemKey(item))
        closeConvSheet()
        uni.showToast({ title: '已删除', icon: 'none' })
      } catch (e) {
        uni.showToast({ title: (e && e.message) || '删除失败', icon: 'none' })
      }
    },
  })
}

async function switchHomeTab(tab) {
  homeTab.value = tab
  plusOpen.value = false
  if (tab === 'community') await loadCommunity()
  else if (tab === 'notice') {
    syncPromoteEarnPanel()
    await loadNotices()
  } else {
    stopPromoteEarnScroll()
    if (tab === 'commission') await loadCommission()
  }
}

function setCommunitySub(sub) {
  communitySub.value = sub
  if (sub === 'mine' || sub === 'friends') loadCommunityExtra()
}

function setNoticeCat(cat) {
  const allowed = ['latest', 'promote', 'ads', 'rules']
  noticeCat.value = allowed.indexOf(cat) >= 0 ? cat : 'latest'
  syncPromoteEarnPanel()
  loadNotices()
}

async function loadCommunity() {
  try {
    const rec = await apiRequest('communityrecommend', 'GET', {})
    const rows = (rec && (rec.list || rec.rows || rec.items)) || rec || []
    communityRecs.value = Array.isArray(rows) ? rows : []
  } catch (e) {
    communityRecs.value = []
  }
  await loadCommunityExtra()
  markMineInRecs()
}

async function loadCommunityExtra() {
  try {
    await imConnect()
    const mine = await listMyGroups()
    const md = (mine && mine.data) || {}
    myGroups.value = md.list || md.items || []
  } catch (e) {
    myGroups.value = []
  }
  try {
    const fr = await listFriends()
    const fd = (fr && fr.data) || {}
    friends.value = fd.list || fd.items || []
  } catch (e) {
    friends.value = []
  }
  markMineInRecs()
}

function markMineInRecs() {
  const mineIds = {}
  ;(myGroups.value || []).forEach((g) => {
    mineIds[g.id | 0] = true
  })
  communityRecs.value = (communityRecs.value || []).map((g) => {
    const id = (g.id | 0) || (g.group_id | 0)
    return Object.assign({}, g, { is_member: !!mineIds[id] || !!g.is_member })
  })
}

async function openGroup(g) {
  const groupId = (g && (g.id || g.group_id)) | 0
  if (!groupId) return
  try {
    if (!g.is_member) {
      await joinGroup(groupId)
      uni.showToast({ title: '已加入社群', icon: 'none' })
      await loadCommunity()
    }
    uni.navigateTo({
      url:
        '/pages/chat/chat?type=2&id=' +
        encodeURIComponent(groupId) +
        '&group=' +
        encodeURIComponent(groupId) +
        '&title=' +
        encodeURIComponent(g.name || ('群' + groupId)),
    })
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '进入社群失败', icon: 'none' })
  }
}

function openFriendChat(f) {
  const peer = (f.peer_user_id || f.user_id) | 0
  const title = friendName(f)
  const cid = f.conversation_id || ''
  uni.navigateTo({
    url:
      '/pages/chat/chat?type=1&id=' +
      encodeURIComponent(cid) +
      '&peer=' +
      encodeURIComponent(peer) +
      '&title=' +
      encodeURIComponent(title) +
      '&nickname=' +
      encodeURIComponent(f.peer_nickname || f.nickname || '') +
      '&remark=' +
      encodeURIComponent(f.remark || ''),
  })
}

function openCreateGroupPane(opts = {}) {
  plusOpen.value = false
  createGroupPrivacy.value = 'private'
  createGroupMode.value = 'chat'
  createGroupSubmitting.value = false
  createGroupBindRebate.value = !!opts.fromCreateCard
  createGroupName.value = opts.fromCreateCard ? '我的专属保密对战群' : ''
  if (!createGroupAvatar.value) createGroupAvatar.value = '🐵'
  createGroupOpen.value = true
}

function closeCreateGroupPane() {
  createGroupOpen.value = false
}

function cycleCreateGroupAvatar() {
  const cur = createGroupAvatar.value || '🐵'
  const idx = CREATE_GROUP_AVATARS.indexOf(cur)
  createGroupAvatar.value = CREATE_GROUP_AVATARS[(idx + 1 + CREATE_GROUP_AVATARS.length) % CREATE_GROUP_AVATARS.length]
}

async function submitCreateGroup() {
  if (createGroupSubmitting.value) return
  const name = String(createGroupName.value || '').trim()
  if (!name) {
    uni.showToast({ title: '请输入群名称', icon: 'none' })
    return
  }
  createGroupSubmitting.value = true
  try {
    await imConnect()
    const packet = await createGroup({
      name,
      member_ids: [],
      privacy_mode: createGroupPrivacy.value || 'private',
      chat_mode: createGroupMode.value || 'chat',
      bind_owner_rebate: createGroupBindRebate.value ? 1 : 0,
    })
    const g = (packet && packet.data && packet.data.group) || (packet && packet.group) || null
    if (!g || !(g.id | 0)) throw new Error('创建失败')
    closeCreateGroupPane()
    await loadList(true)
    await loadMyGroupsSafe()
    uni.showToast({ title: '群聊已创建', icon: 'none' })
    setTimeout(() => {
      uni.navigateTo({
        url:
          '/pages/chat/chat?type=2&id=' +
          encodeURIComponent(g.id) +
          '&group=' +
          encodeURIComponent(g.id) +
          '&title=' +
          encodeURIComponent(g.name || name || '群聊'),
      })
    }, 200)
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '创建失败', icon: 'none' })
  } finally {
    createGroupSubmitting.value = false
  }
}

async function loadMyGroupsSafe() {
  try {
    const packet = await listMyGroups()
    const data = (packet && packet.data) || {}
    myGroups.value = data.list || data.groups || []
  } catch (e) {}
}

async function refreshAuthFlags() {
  canCreateGroup.value = canCreateGroupFromAuth()
  const meta = getImAuthMeta() || {}
  const uid = meta.user_id || meta.uid || 0
  if (uid) {
    myIdText.value = '我的会员ID：' + uid
  }
}

async function loadMyIdLine() {
  try {
    const p = await fetchProfile()
    const uid = (p && (p.user_id || p.id)) | 0
    if (uid) {
      myIdText.value = '我的会员ID：' + uid
      setInboxMyId(uid)
    }
  } catch (e) {}
}

async function onPlusAction(kind) {
  plusOpen.value = false
  if (kind === 'share') {
    uni.navigateTo({ url: '/pages/messages/share-poster' })
    return
  }
  if (kind === 'scan') {
    const meta = getImAuthMeta() || {}
    const selfId = meta.user_id || meta.uid || ''
    openFriendScanSheet({
      selfUserId: selfId,
      onManual: () => uni.navigateTo({ url: '/pages/friend/add' }),
    })
    return
  }
  if (kind === 'friend') {
    uni.navigateTo({ url: '/pages/friend/add' })
    return
  }
  if (kind === 'request') {
    uni.navigateTo({ url: '/pages/friend/requests' })
    return
  }
  if (kind === 'createGroup') {
    openCreateGroupPane()
  }
}

async function loadNotices() {
  try {
    const data = await apiRequest('notices', 'GET', { page: 1, limit: 30, category: noticeCat.value })
    const rows = (data && (data.list || data.rows || data.items)) || []
    notices.value = Array.isArray(rows) ? rows : []
  } catch (e) {
    notices.value = []
  }
}

async function loadCommission() {
  try {
    const data = await apiRequest('commission', 'GET', {})
    commission.value = data || {}
  } catch (e) {
    commission.value = {}
  }
}

async function loadFriendReqBadge() {
  try {
    await imConnect()
    const packet = await friendRequests()
    const data = (packet && packet.data) || {}
    friendReqPending.value = data.pending_count | 0
  } catch (e) {
    friendReqPending.value = 0
  }
}

function goCommission() {
  uni.navigateTo({ url: '/pages/wallet/withdraw' })
}

function goCommissionNav(kind) {
  // 四宫格留在佣金页切换下方列表（对齐 888）
  if (kind === 'promo' || kind === 'rebate' || kind === 'withdraw_list' || kind === 'ledger') {
    commissionListMode.value = kind === 'ledger' ? 'ledger' : kind
  }
}

async function loadList(silent = false) {
  if (loading) return
  loading = true
  refreshStatus()
  try {
    await imConnect()
    refreshStatus()
    const packet = await listConversations(80)
    const data = (packet && packet.data) || packet || {}
    const rows = data.list || data.items || data.conversations || []
    rows.sort((a, b) => {
      const ap = a.pinned ? 1 : 0
      const bp = b.pinned ? 1 : 0
      if (ap !== bp) return bp - ap
      return (b.updatetime | 0) - (a.updatetime | 0)
    })
    list.value = rows
    syncInboxFromServerList(rows)
    const next = Object.assign({}, localUnread.value)
    rows.forEach((it) => {
      const key = itemKey(it)
      const server = it.unread_count | 0
      next[key] = Math.max(next[key] | 0, server)
    })
    localUnread.value = next
    let sum = 0
    Object.keys(next).forEach((k) => {
      sum += next[k] | 0
    })
    setChatUnreadTotal(sum)
  } catch (e) {
    if (!silent) uni.showToast({ title: e.message || '拉取会话失败', icon: 'none' })
  } finally {
    loaded.value = true
    loading = false
    refreshStatus()
  }
}

async function onListRefresh() {
  listRefreshing.value = true
  try {
    await loadList(true)
  } finally {
    listRefreshing.value = false
  }
}

async function reconnect() {
  try {
    await imConnect()
    await loadList()
    uni.showToast({ title: '已重连', icon: 'success' })
  } catch (e) {
    uni.showToast({ title: e.message || '重连失败', icon: 'none' })
  }
}

function applyPageShell(on) {
  // #ifdef H5
  try {
    if (typeof document !== 'undefined') {
      document.body.classList.toggle('tab-messages', !!on)
      if (on) {
        const h = window.matchMedia('(max-width: 480px)').matches ? '44px' : '48px'
        document.documentElement.style.setProperty('--top-bar-height', h)
      }
    }
  } catch (e) {}
  // #endif
}

onShow(() => {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  pageAlive = true
  applyPageShell(true)
  startImInbox()
  bindForegroundResume()
  resumeFromBackground('messages-onShow')
  if (off) off()
  off = onImEvent((type, data) => {
    if (type === 'im.resume') {
      if (pageAlive) loadList(true)
      return
    }
    if (type === 'auth.ok') {
      refreshAuthFlags()
      refreshStatus()
      const meta = getImAuthMeta() || {}
      const uid = (meta.user_id || meta.uid) | 0
      if (uid) setInboxMyId(uid)
    }
    if (type === 'socket.close' || type === 'socket.error') refreshStatus()
    if (type === 'private.message' || type === 'group.message' || type === 'redpacket.relay_next') {
      const msg = (data && data.message) || data
      bumpUnread(msg)
    }
    if (type === 'conversation.updated' || type === 'redpacket.update') {
      loadList(true)
    }
    if (type === 'group.created' || type === 'group.kicked') {
      loadList(true)
      loadMyGroupsSafe()
    }
    if (type === 'friend.request' || type === 'friend.accepted' || type === 'friend.rejected' || type === 'friend.cancelled') {
      loadFriendReqBadge()
    }
  })
  const onInboxUnread = (map) => {
    if (!map) return
    localUnread.value = Object.assign({}, localUnread.value, map)
  }
  const onInboxMsg = (msg) => {
    upsertListFromMessage(msg)
  }
  try {
    uni.$on && uni.$on('fanshub-inbox-unread', onInboxUnread)
    uni.$on && uni.$on('fanshub-inbox-msg', onInboxMsg)
  } catch (e) {}
  off._inboxCleanup = () => {
    try {
      uni.$off && uni.$off('fanshub-inbox-unread', onInboxUnread)
      uni.$off && uni.$off('fanshub-inbox-msg', onInboxMsg)
    } catch (e2) {}
  }
  loadMyIdLine()
  loadList().then(() => refreshAuthFlags())
  loadFriendReqBadge()
})

onHide(() => {
  pageAlive = false
  applyPageShell(false)
  if (off) {
    if (typeof off._inboxCleanup === 'function') off._inboxCleanup()
    off()
    off = null
  }
  // 注意：不停止全局 im-inbox，离开消息 Tab 仍收未读
})
</script>

<style scoped>
.chat-reconnect {
  color: var(--color-primary-start, #e63022);
  font-weight: 700;
  text-decoration: underline;
}
.chat-conv-ptr-host {
  height: calc(100vh - 280px);
  min-height: 320px;
}
.chat-conv-scroll {
  flex: 1 1 auto;
  min-height: 0;
  height: 100%;
}
</style>

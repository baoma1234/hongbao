<template>
  <view class="messages-page">
    <!-- 必须有 TopBar spacer：否则顶栏盖住「红宝社区」，高度又扣了顶栏 → 底栏上方灰空白 -->
    <TopBar />
    <view
      id="tabMessages"
      class="tab-page active msg-tab-root"
      :style="tabRootStyle"
    >
      <view class="chat-shell">
        <view class="chat-list-pane">
          <view class="chat-hero-hd chat-list-hero">
            <view class="chat-hero-title">红宝社区</view>
            <view class="chat-list-actions">
              <view class="chat-hero-icon-btn" @click="toggleSearch">
                <text class="chat-hero-glyph">⌕</text>
              </view>
              <view class="chat-plus-menu-wrap">
                <view class="chat-hero-icon-btn" @click.stop="plusOpen = !plusOpen">
                  <text class="chat-hero-glyph">＋</text>
                </view>
                <!-- 全屏遮罩：点菜单外任意处收起（含列表/顶栏空白） -->
                <view
                  v-if="plusOpen"
                  class="chat-plus-menu-mask"
                  @click="plusOpen = false"
                />
                <view v-if="plusOpen" class="chat-plus-menu" @click.stop>
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
            <view class="chat-my-id" v-if="myIdText">{{ myIdLine }}</view>

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
            <view
              id="chatHomePanelChat"
              class="chat-home-panel"
              :class="{ 'is-hidden': homeTab !== 'chat' }"
              :style="homeTab === 'chat' ? panelHostStyle : null"
            >
              <view class="chat-conv-ptr-host" :style="panelHostStyle">
                <view class="chat-conv-ptr" :class="{ refreshing: listRefreshing, ready: listRefreshing }">
                  <view class="chat-conv-ptr-inner">
                    <view class="chat-conv-ptr-spinner" />
                    <text class="chat-conv-ptr-text">{{ listRefreshing ? '刷新中…' : '下拉刷新' }}</text>
                  </view>
                </view>
                <scroll-view
                  scroll-y
                  class="chat-conv-scroll"
                  :style="panelScrollStyle"
                  :show-scrollbar="false"
                  :enable-flex="true"
                  :refresher-enabled="convRefresherEnabled"
                  :refresher-triggered="listRefreshing"
                  @refresherrefresh="onListRefresh"
                  @scroll="onConvScroll"
                >
                  <view class="chat-conv-list">
                    <view class="chat-empty" v-if="!displayList.length && loaded">暂无会话（登录后通常会有客服）</view>
                    <view
                      v-for="item in displayList"
                      :key="itemKey(item)"
                      class="chat-conv-swipe"
                      :class="{
                        open: swipeOpenKey === itemKey(item),
                        'is-dragging': swipeDragKey === itemKey(item),
                      }"
                    >
                      <view class="chat-conv-swipe-actions">
                        <view
                          class="chat-conv-swipe-btn chat-conv-swipe-pin"
                          @click.stop="onSwipePin(item)"
                        >
                          <text class="chat-conv-swipe-lab">{{ item.pinned ? '取消置顶' : '置顶' }}</text>
                        </view>
                        <view
                          class="chat-conv-swipe-btn chat-conv-swipe-del"
                          @click.stop="onSwipeDelete(item)"
                        >
                          <text class="chat-conv-swipe-lab">删除</text>
                        </view>
                      </view>
                      <view
                        class="chat-conv-item"
                        :class="{ 'is-pinned': !!item.pinned, 'is-admin': !!item.is_im_admin }"
                        :style="swipeFrontStyle(item)"
                        @touchstart="onSwipeTouchStart($event, item)"
                        @touchmove="onSwipeTouchMove($event, item)"
                        @touchend="onSwipeTouchEnd($event, item)"
                        @touchcancel="onSwipeTouchEnd($event, item)"
                        @click="onConvClick(item)"
                      >
                        <view class="chat-avatar" :class="{ group: (item.conversation_type | 0) === 2, admin: !!item.is_im_admin }">
                          <image :src="avatarSrc(item.avatar_url || item.avatar)" mode="aspectFill" lazy-load />
                        </view>
                        <view class="chat-conv-body">
                          <view class="chat-conv-title">
                            <view class="chat-conv-title-main">
                              <text v-if="item.pinned" class="chat-conv-pin">📌</text>
                              <text class="chat-conv-name">{{ displayTitle(item) }}</text>
                              <text v-if="item.is_im_admin" class="chat-admin-tag">客服</text>
                            </view>
                            <text class="chat-conv-time">{{ itemTime(item) }}</text>
                          </view>
                          <view class="chat-conv-preview">{{ itemPreview(item) }}</view>
                        </view>
                        <view v-if="unreadOf(item) > 0" class="chat-badge">
                          {{ unreadOf(item) > 99 ? '99+' : unreadOf(item) }}
                        </view>
                      </view>
                    </view>
                    <!-- 末项后再留 20px：App 上 padding 常不撑开 scroll 内容，需实体占位 -->
                    <view class="chat-list-scroll-pad" aria-hidden="true">
                      <text class="chat-list-scroll-pad-mark"> </text>
                    </view>
                  </view>
                </scroll-view>
              </view>
            </view>

            <!-- 社群 -->
            <view
              id="chatHomePanelCommunity"
              class="chat-home-panel chat-community-glass"
              :class="{ 'is-hidden': homeTab !== 'community' }"
              :style="homeTab === 'community' ? communityHostStyle : null"
            >
              <view class="chat-community-seg is-4">
                <view class="chat-community-seg-btn" :class="{ active: communitySub === 'official' }" @click="setCommunitySub('official')">官方社群</view>
                <view class="chat-community-seg-btn" :class="{ active: communitySub === 'mine' }" @click="setCommunitySub('mine')">我的群组</view>
                <view class="chat-community-seg-btn" :class="{ active: communitySub === 'created' }" @click="setCommunitySub('created')">我创建的</view>
                <view class="chat-community-seg-btn" :class="{ active: communitySub === 'friends' }" @click="setCommunitySub('friends')">好友列表</view>
              </view>

              <view v-if="communitySub === 'official'" class="chat-community-pane active chat-community-pane--official">
                <scroll-view
                  scroll-y
                  class="chat-community-body-scroll"
                  :style="officialScrollStyle"
                  :show-scrollbar="false"
                >
                  <view class="chat-official-list">
                    <view
                      v-for="(g, idx) in communityRecs"
                      :key="g.id || g.group_id"
                      class="chat-official-row"
                      @click="openGroup(g)"
                    >
                      <view class="chat-avatar group">
                        <image :src="avatarSrc(g.avatar_url || g.avatar)" mode="aspectFill" lazy-load />
                      </view>
                      <view class="chat-official-body">
                        <text class="chat-official-title">{{ g.name || ('#' + (g.id || g.group_id)) }}</text>
                        <view class="chat-official-sub">
                          <text class="chat-official-online">{{ groupMembersText(g) }}</text>
                          <text class="chat-official-tag">{{ officialGroupTag(g, idx) }}</text>
                        </view>
                      </view>
                      <view class="chat-official-join" @click.stop="openGroup(g)">立即进群</view>
                    </view>
                    <view v-if="!communityRecs.length" class="chat-empty chat-empty-glass">暂无推荐社群</view>
                    <view v-if="communityRecs.length" class="chat-official-end">————— 已经滑到底部啦 —————</view>
                  </view>
                </scroll-view>
              </view>

              <!-- 我的/创建/好友：首次访问后常驻，v-show 切换，避免 App 反复销毁 scroll-view -->
              <view
                v-if="communityPaneVisited.mine"
                v-show="communitySub === 'mine'"
                class="chat-community-pane chat-community-pane--feed"
                :class="{ active: communitySub === 'mine' }"
              >
                <scroll-view
                  scroll-y
                  class="chat-community-body-scroll"
                  :style="panelScrollStyle"
                  :show-scrollbar="false"
                  :enable-flex="true"
                >
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
                            <image :src="avatarSrc(g.avatar_url || g.avatar)" mode="aspectFill" lazy-load />
                          </view>
                          <text class="chat-my-group-name">{{ g.name || ('#' + g.id) }}</text>
                        </view>
                        <view class="chat-my-group-count">{{ g.display_member_count || g.member_count || 0 }}<text>人</text></view>
                      </view>
                      <view v-if="!myGroups.length && communityExtraLoading" class="chat-empty chat-empty-glass">加载中…</view>
                      <view v-else-if="!myGroups.length" class="chat-empty chat-empty-glass">暂无已加入社群</view>
                      <view class="chat-list-scroll-pad" />
                  </view>
                </scroll-view>
              </view>

              <view
                v-if="communityPaneVisited.created"
                v-show="communitySub === 'created'"
                class="chat-community-pane chat-community-pane--feed"
                :class="{ active: communitySub === 'created' }"
              >
                <scroll-view
                  scroll-y
                  class="chat-community-body-scroll"
                  :style="panelScrollStyle"
                  :show-scrollbar="false"
                  :enable-flex="true"
                >
                  <view class="chat-my-groups-list">
                      <view v-for="g in myCreatedGroups" :key="'c-' + g.id" class="chat-my-group-item" @click="openGroup(g)">
                        <view class="chat-my-group-main">
                          <view class="chat-my-group-avatar">
                            <image :src="avatarSrc(g.avatar_url || g.avatar)" mode="aspectFill" lazy-load />
                          </view>
                          <view class="chat-my-group-create-text">
                            <text class="chat-my-group-name">{{ g.name || ('#' + g.id) }}</text>
                            <text class="chat-my-group-sub">{{ (g.my_role | 0) >= 3 ? '群主' : '管理员' }}</text>
                          </view>
                        </view>
                        <view class="chat-my-group-count">{{ g.display_member_count || g.member_count || 0 }}<text>人</text></view>
                      </view>
                      <view v-if="!myCreatedGroups.length && communityExtraLoading" class="chat-empty chat-empty-glass">加载中…</view>
                      <view v-else-if="!myCreatedGroups.length" class="chat-empty chat-empty-glass">暂无我创建/管理的群</view>
                      <view class="chat-list-scroll-pad" />
                  </view>
                </scroll-view>
              </view>

              <view
                v-if="communityPaneVisited.friends"
                v-show="communitySub === 'friends'"
                class="chat-community-pane chat-community-pane--feed"
                :class="{ active: communitySub === 'friends' }"
              >
                <scroll-view
                  scroll-y
                  class="chat-community-body-scroll"
                  :style="panelScrollStyle"
                  :show-scrollbar="false"
                  :enable-flex="true"
                >
                  <view class="chat-friend-feed-list">
                      <view
                        v-for="f in friends"
                        :key="f.peer_user_id || f.user_id"
                        class="chat-feed-item"
                        :class="{ 'is-pinned-cs': !!(f.is_default_cs || f.pinned) }"
                        @click="openFriendChat(f)"
                      >
                        <view class="chat-avatar">
                          <image :src="avatarSrc(f.avatar_url || f.avatar)" mode="aspectFill" lazy-load />
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
                      <view v-if="!friends.length && communityExtraLoading" class="chat-empty chat-empty-glass">加载中…</view>
                      <view v-else-if="!friends.length" class="chat-empty chat-empty-glass">暂无好友</view>
                      <view class="chat-list-scroll-pad" />
                  </view>
                </scroll-view>
              </view>
            </view>
            <!-- 公告（对齐 888：四分类 + 推广收益表 + 动态卡片） -->
            <view
              id="chatHomePanelNotice"
              class="chat-home-panel chat-notice-feed-panel"
              :class="{ 'is-hidden': homeTab !== 'notice' }"
              :style="homeTab === 'notice' ? panelHostStyle : null"
            >
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
              <view class="chat-notice-pane" :style="homeTab === 'notice' ? panelScrollStyle : null">
              <scroll-view class="chat-notice-body-scroll" scroll-y :style="panelScrollStyle" :show-scrollbar="false">
                <view
                  v-if="fissionNoticeVisible && (noticeCat === 'latest' || noticeCat === 'promote')"
                  class="chat-fission-card"
                >
                  <view class="chat-fission-card-hd">
                    <text class="chat-fission-card-tag">官方活动</text>
                    <text class="chat-fission-card-time">{{ fissionNoticeTime }}</text>
                  </view>
                  <view class="chat-fission-envelope" @click="goFissionFromNotice">
                    <text class="chat-fission-title">裂变红宝</text>
                    <text class="chat-fission-pool">¥ {{ fissionNoticePool }} 奖金池</text>
                    <text class="chat-fission-progress">当前 {{ fissionNoticeQuals }} / {{ fissionNoticeCap }} 份资格</text>
                    <text class="chat-fission-remain">剩余 {{ fissionNoticeRemain }}</text>
                    <view class="chat-fission-cta" :class="{ disabled: fissionNoticeEnded }">
                      {{ fissionNoticeEnded ? '活动已结束' : '点击拆开红包' }}
                    </view>
                    <text class="chat-fission-risk">72小时未集齐资格，红包池作废</text>
                  </view>
                  <view class="chat-fission-card-ft" @click.stop="openFissionShare">
                    <view class="chat-notice-share-btn">分享</view>
                  </view>
                </view>

                <view
                  v-if="noticeCat === 'promote'"
                  class="chat-promote-earn-wrap"
                  id="chatPromoteEarnWrap"
                >
                  <view class="chat-promote-earn-card">
                    <view class="chat-promote-earn-hd">
                      <text class="chat-promote-earn-title">{{ tt('promote_earn_title', '推广收益数据表') }}</text>
                      <text class="chat-promote-earn-live" @click="refreshPromoteEarnMock">{{ tt('promote_earn_live', '实时更新 ›') }}</text>
                    </view>
                    <view class="chat-promote-earn-table">
                      <view class="chat-promote-earn-thead">
                        <view class="chat-promote-earn-th"><text>{{ tt('promote_earn_col_uid', '用户ID') }}</text></view>
                        <view class="chat-promote-earn-th is-active">
                          <text class="pe-th-pill">{{ tt('promote_earn_col_type', '收益类型') }}</text>
                        </view>
                        <view class="chat-promote-earn-th"><text>{{ tt('promote_earn_col_detail', '广细记录') }}</text></view>
                        <view class="chat-promote-earn-th"><text>{{ tt('promote_earn_col_amount', '到手佣金') }}</text></view>
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
                        class="chat-notice-avatar"
                        :src="avatarSrc(n.author_avatar || '')"
                        mode="aspectFill"
                      />
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
                      <view
                        class="chat-notice-imgs"
                        :class="[
                          'imgs-' + Math.min(9, noticeImages(n).length),
                          { 'imgs-full': noticeImagesFull(n) },
                        ]"
                      >
                        <view
                          v-for="(src, ii) in noticeImages(n).slice(0, 9)"
                          :key="ii"
                          class="chat-notice-img-wrap"
                          @click="previewNoticeImages(n, ii)"
                        >
                          <image
                            class="chat-notice-img"
                            :src="avatarSrc(src)"
                            :mode="noticeImagesFull(n) ? 'widthFix' : 'aspectFill'"
                          />
                        </view>
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
                  <view class="chat-list-scroll-pad" />
                </view>
              </scroll-view>
              </view>
            </view>

            <!-- 佣金 -->
            <view
              id="chatHomePanelCommission"
              class="chat-home-panel chat-commission-panel"
              :class="{ 'is-hidden': homeTab !== 'commission' }"
              :style="homeTab === 'commission' ? panelHostStyle : null"
            >
              <scroll-view scroll-y class="chat-commission-body-scroll" :style="panelScrollStyle">
              <view class="chat-commission-hero-card">
                <view class="chat-commission-hero-top">
                  <text class="chat-commission-hero-label">累计佣金</text>
                  <view class="chat-commission-withdraw-btn" @click="goCommission"><text>提现</text></view>
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
                    <text class="chat-commission-stat-label">红宝返佣</text>
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
                    <text class="chat-commission-nav-glyph">☰</text>
                  </view>
                  <text class="chat-commission-nav-label">推广结算 ›</text>
                </view>
                <view
                  class="chat-commission-nav-item"
                  :class="{ 'is-active': commissionListMode === 'rebate' }"
                  @click="goCommissionNav('rebate')"
                >
                  <view class="chat-commission-nav-ico">
                    <text class="chat-commission-nav-glyph">🧧</text>
                  </view>
                  <text class="chat-commission-nav-label">红宝返佣 ›</text>
                </view>
                <view
                  class="chat-commission-nav-item"
                  :class="{ 'is-active': commissionListMode === 'ledger' }"
                  @click="goCommissionNav('ledger')"
                >
                  <view class="chat-commission-nav-ico">
                    <text class="chat-commission-nav-glyph">◎</text>
                  </view>
                  <text class="chat-commission-nav-label">收益明细 ›</text>
                </view>
                <view
                  class="chat-commission-nav-item"
                  :class="{ 'is-active': commissionListMode === 'withdraw_list' }"
                  @click="goCommissionNav('withdraw_list')"
                >
                  <view class="chat-commission-nav-ico">
                    <text class="chat-commission-nav-glyph">⬇</text>
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
                      <text class="chat-commission-nav-glyph">✓</text>
                    </view>
                    <view class="chat-commission-row-main">
                      <view class="chat-commission-row-title">{{ commissionRowTitle(row) }}</view>
                      <view class="chat-commission-row-time">{{ formatNoticeTime(row) }}</view>
                    </view>
                    <view class="chat-commission-row-amt" :class="{ 'is-out': isAmtOut(row) }">{{ formatCommissionAmt(row) }}</view>
                  </view>
                  <view v-if="!commissionRows.length" class="chat-empty chat-empty-glass">{{ commissionEmptyText }}</view>
                  <view class="chat-list-scroll-pad" />
                </view>
              </view>
              </scroll-view>
            </view>
          </view>
        </view>
      </view>
    </view>

    <!-- 创建新群聊（对齐 888 chatCreateGroupPane） -->
    <view
      class="chat-create-group-pane"
      :class="{ open: createGroupOpen, 'cg-app-fix': createGroupAppFix }"
      :aria-hidden="createGroupOpen ? 'false' : 'true'"
      :style="createGroupPaneStyle"
    >
      <view class="chat-cg-header">
        <view class="chat-cg-back" @click="closeCreateGroupPane">
          <text class="chat-hero-back-char">‹</text>
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

      </view>
    </view>

    <!-- 分享到好友/可发言群 -->
    <view v-if="shareSheetOpen" class="chat-share-sheet-mask" @click="closeShareSheet">
      <view class="chat-share-sheet" @click.stop>
        <view class="chat-share-sheet-hd">
          <text class="chat-share-sheet-title">分享到</text>
          <text class="chat-share-sheet-close" @click="closeShareSheet">关闭</text>
        </view>
        <view class="chat-share-sheet-preview">{{ sharePreviewText }}</view>
        <scroll-view scroll-y class="chat-share-sheet-list">
          <view v-if="shareTargets.length" class="chat-share-sec-lab">好友</view>
          <view
            v-for="f in shareFriendTargets"
            :key="'f-' + shareFriendId(f)"
            class="chat-share-row"
            @click="sendShareToFriend(f)"
          >
            <image class="chat-share-av" :src="avatarSrc(f.avatar || f.peer_avatar || '')" mode="aspectFill" />
            <text class="chat-share-name">{{ friendName(f) }}</text>
            <text class="chat-share-go">发送</text>
          </view>
          <view v-if="shareGroupTargets.length" class="chat-share-sec-lab">可发言群聊</view>
          <view
            v-for="g in shareGroupTargets"
            :key="'g-' + ((g.id || g.group_id) | 0)"
            class="chat-share-row"
            @click="sendShareToGroup(g)"
          >
            <image class="chat-share-av" :src="avatarSrc(g.avatar_url || g.avatar || '')" mode="aspectFill" />
            <text class="chat-share-name">{{ g.name || ('群' + (g.id || g.group_id)) }}</text>
            <text class="chat-share-go">发送</text>
          </view>
          <view v-if="!shareTargets.length && !shareLoading" class="chat-empty">暂无可分享的好友或群</view>
          <view v-if="shareLoading" class="chat-empty">加载中…</view>
        </scroll-view>
      </view>
    </view>

    <FriendScanSheet />
    <!-- 官方规则：fixed 钉在底栏上方，避免被 TabBar(z=9000) / overflow 裁切 -->
    <view
      v-if="homeTab === 'community' && communitySub === 'official'"
      class="chat-official-rules chat-official-rules--dock"
      @click="openGameRulesFromCommunity"
    >
      <view class="chat-official-rules-ico">📜</view>
      <view class="chat-official-rules-text">
        <text class="chat-official-rules-title">🧧 红宝官方游戏规则</text>
        <text class="chat-official-rules-desc">新手通关玩法与佣金保障说明</text>
      </view>
      <text class="chat-official-rules-link">点开查看规则图 ›</text>
    </view>
    <BottomTabBar active="messages" />
  </view>
</template>

<script setup>
import { computed, nextTick, onUnmounted, reactive, ref, watch } from 'vue'
import { onShow, onHide } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import BottomTabBar from '../../components/BottomTabBar.vue'
import FriendScanSheet from '../../components/FriendScanSheet.vue'
import '../../styles/chat.bundle.css'
import '../../styles/chat-uni-adapter.css'
import '../../styles/chat-888-parity.css'
import { apiRequest, fetchProfile, getToken } from '../../utils/auth.js'
import { applySafeAreaCssVars, getSafeAreaInsets, measureChatOverlayTop } from '../../utils/safe-area.js'
import {
  avatarLetter,
  avatarSrc,
  convKey,
  displayTitle,
  formatConvTime,
  previewText,
  resolveConvId,
} from '../../utils/chat.js'
import { t, tt } from '../../utils/i18n.js'
import { packagedStaticUrl } from '../../utils/config.js'
import { openFriendScanSheet } from '../../utils/friend-scan.js'
import { saveActiveChat } from '../../utils/chat-route.js'
import {
  canCreateGroupFromAuth,
  createGroup,
  fetchGroupInfo,
  friendRequests,
  getImAuthMeta,
  getImStatus,
  hideConversation,
  imConnect,
  imForceReconnect,
  imSend,
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
  getInboxUnread,
  getReadWatermark,
  isConversationRecentlyRead,
  noteConversationRead,
  setInboxMyId,
  startImInbox,
  syncInboxFromServerList,
} from '../../utils/im-inbox.js'
import { setChatUnreadTotal } from '../../utils/tab-badge.js'

const CREATE_GROUP_AVATARS = ['🐵', '🐼', '🦊', '🐯', '🦁', '🐶', '🐱', '🐰', '🐻', '🐨', '🐸', '🐷']
let chatSubpkgPrefetched = false

function prefetchChatSubpackages() {
  if (chatSubpkgPrefetched) return
  chatSubpkgPrefetched = true
  const urls = ['/pages/chat/chat', '/pages/friend/add', '/pages/friend/requests']
  urls.forEach((url) => {
    try {
      if (typeof uni.preloadPage === 'function') {
        uni.preloadPage({ url })
      }
    } catch (e) {}
  })
  // #ifdef H5
  // Vite 异步 chunk 预热（与 preloadPage 互补）
  try {
    import('../chat/chat.vue').catch(() => {})
  } catch (e) {}
  // #endif
}

const list = ref([])
const loaded = ref(false)
const status = ref('disconnected')
const localUnread = ref({})
const fxIcon = packagedStaticUrl('chat/fx.png')
const icoScan = packagedStaticUrl('chat/plus_scan.png')
const icoAddFriend = packagedStaticUrl('chat/plus_add_friend.png')
const icoFriendReq = packagedStaticUrl('chat/plus_friend_req.png')
const icoCreateGroup = packagedStaticUrl('chat/plus_create_group.png')
const homeTab = ref('chat')
/** App WebView 常算不出 flex 高度：用 JS 量出面板/scroll 像素高 */
const panelScrollPx = ref(420)
const tabRootPx = ref(0)

/**
 * 仅 iPhone/iPad 的 H5 Safari（不含 APP-PLUS / 桌面网页）：
 * uni refresher + 页面滚动争抢时，会话列表下滑会被拽回顶部。
 */
function isIosSafariH5() {
  // #ifdef H5
  try {
    if (typeof navigator === 'undefined') return false
    const ua = String(navigator.userAgent || '')
    const iOS = /iPhone|iPad|iPod/i.test(ua)
    if (!iOS) return false
    // 排除 iOS 上的 Chrome/Firefox/Edge 外壳也可一并修，但 WebKit 同源；这里覆盖所有 iOS H5
    return true
  } catch (e) {
    return false
  }
  // #endif
  // #ifndef H5
  return false
  // #endif
}

/** APK / 桌面网页保留下拉刷新；iOS Safari H5 关掉，避免滚到下方又弹回顶部 */
const convRefresherEnabled = computed(() => !isIosSafariH5())

/** 会话列表当前 scrollTop（用于列表静默刷新后还原，避免 Safari 跳顶） */
let convScrollTop = 0
let convScrollRestoreTimer = null

function onConvScroll(e) {
  const d = (e && e.detail) || {}
  const top = Number(d.scrollTop)
  if (isFinite(top) && top >= 0) convScrollTop = top
}

function restoreConvScrollSoon() {
  if (!isIosSafariH5()) return
  if (convScrollTop <= 0) return
  const top = convScrollTop
  const apply = () => {
    try {
      if (typeof document === 'undefined') return
      const roots = document.querySelectorAll(
        '.msg-tab-root .chat-conv-scroll, #tabMessages .chat-conv-scroll, .chat-conv-scroll'
      )
      roots.forEach((node) => {
        const cands = [
          node,
          node.querySelector && node.querySelector('.uni-scroll-view'),
        ]
        cands.forEach((el) => {
          if (!el) return
          try {
            if (typeof el.scrollTop === 'number') el.scrollTop = top
          } catch (e0) {}
        })
      })
    } catch (e1) {}
  }
  nextTick(() => {
    apply()
    if (convScrollRestoreTimer) clearTimeout(convScrollRestoreTimer)
    convScrollRestoreTimer = setTimeout(apply, 40)
    setTimeout(apply, 120)
  })
}

const tabRootStyle = computed(() => {
  const h = Number(tabRootPx.value) || 0
  if (h < 200) return {}
  return { height: h + 'px', minHeight: h + 'px' }
})
const panelHostStyle = computed(() => {
  const h = Number(panelScrollPx.value) || 420
  return { height: h + 'px', minHeight: h + 'px', maxHeight: h + 'px', flex: 'none', overflow: 'hidden' }
})
/** 官方规则卡片钉在面板外时，社群宿主需预留高度，避免和规则叠在一起 */
const OFFICIAL_RULES_DOCK_PX = 96
const communityHostStyle = computed(() => {
  let h = Number(panelScrollPx.value) || 420
  if (communitySub.value === 'official') {
    h = Math.max(200, h - OFFICIAL_RULES_DOCK_PX)
  }
  return { height: h + 'px', minHeight: h + 'px', maxHeight: h + 'px', flex: 'none', overflow: 'hidden' }
})
const panelScrollStyle = computed(() => {
  let h = Number(panelScrollPx.value) || 420
  // 社群/公告顶部有分段 Tab，scroll 区再扣一截
  if (homeTab.value === 'community' || homeTab.value === 'notice') {
    h = Math.max(180, h - 52)
  }
  if (homeTab.value === 'community' && communitySub.value === 'official') {
    h = Math.max(140, h - OFFICIAL_RULES_DOCK_PX)
  }
  // 显式像素高：Safari 上 height:100% 常算不出，必须靠内联；勿被 CSS !important 盖掉
  return { height: h + 'px', minHeight: h + 'px', maxHeight: h + 'px', flex: 'none' }
})
/** 官方社群列表：宿主已扣规则高度，这里再扣分段 Tab */
const officialScrollStyle = computed(() => {
  let h = Number(panelScrollPx.value) || 420
  h = Math.max(140, h - 52 - OFFICIAL_RULES_DOCK_PX)
  return { height: h + 'px', minHeight: h + 'px', maxHeight: h + 'px', flex: 'none' }
})

function measureMessagesLayout() {
  try {
    applySafeAreaCssVars()
    const sys = uni.getSystemInfoSync() || {}
    let winH = Number(sys.windowHeight || sys.screenHeight || 667)
    // #ifdef H5
    try {
      if (typeof window !== 'undefined') {
        // Safari 地址栏显隐会改 innerHeight；用较大值稳住像素高，避免 scroll-view 被重设高度后跳顶
        const vh = window.innerHeight || 0
        const docH = (document.documentElement && document.documentElement.clientHeight) || 0
        const stable = Math.max(vh, docH, Number(sys.windowHeight) || 0)
        if (stable > 200) winH = stable
      }
    } catch (e0) {}
    // #endif
    const inset = getSafeAreaInsets()
    const status = Number(inset.top || 0)
    const topBar = 48
    // 与 BottomTabBar 实际高度对齐：padding 6+6 + 按钮(8+30+3+字≈12+6) ≈ 71，取 72
    const tabBar = 72 + Number(inset.bottom || 0)
    // TopBar 已有 spacer（status+48），#tabMessages 只占 spacer 下方到 Tab 上方
    const shell = Math.max(280, winH - status - topBar - tabBar)
    tabRootPx.value = shell
    // 再扣：红宝社区标题 + 连接行 + 会员ID行 + 四个子 Tab（约 168）
    const chrome = 168
    let next = Math.max(220, shell - chrome)
    // iOS Safari：忽略地址栏导致的小幅高度回缩，防止内联 height 变化重置滚动
    if (isIosSafariH5() && panelScrollPx.value > 0) {
      const shrink = panelScrollPx.value - next
      if (shrink > 0 && shrink < 120) next = panelScrollPx.value
    }
    panelScrollPx.value = next
  } catch (e) {
    tabRootPx.value = 0
    panelScrollPx.value = 420
  }
}

const searchOpen = ref(false)
const keyword = ref('')
const plusOpen = ref(false)
const canCreateGroup = ref(false)
const myIdText = ref('')
const myHongbao = ref(null)
const myHongbaoFrozen = ref(null)
const createGroupOpen = ref(false)
const createGroupName = ref('')
const createGroupPrivacy = ref('private')
const createGroupMode = ref('chat')
const createGroupAvatar = ref('🐵')
const createGroupSubmitting = ref(false)
const createGroupBindRebate = ref(false)
// #ifdef APP-PLUS
const createGroupAppFix = true
// #endif
// #ifndef APP-PLUS
const createGroupAppFix = false
// #endif
/** App 建群面板顶距用 JS 测量，避免 env(safe-area) 为 0 时被顶栏盖住；H5/Safari 仍走 CSS */
const createGroupPaneStyle = computed(() => {
  if (!createGroupOpen.value || !createGroupAppFix) return null
  applySafeAreaCssVars()
  const top = measureChatOverlayTop()
  return { '--cg-app-top': Math.max(44, top) + 'px' }
})
const communitySub = ref('official')
const communityPaneVisited = reactive({ mine: false, created: false, friends: false })
const communityRecs = ref([])
const myGroups = ref([])
const myCreatedGroups = computed(() =>
  (myGroups.value || []).filter((g) => ((g.my_role | 0) || (g.role | 0)) >= 2)
)
const friends = ref([])
const communityExtraLoading = ref(false)
/** 社群群/好友列表缓存：避免每次点 Tab 都卡在 WS */
const COMMUNITY_EXTRA_TTL_MS = 60000
let communityExtraAt = 0
let communityExtraOk = false
let communityExtraInflight = null
const notices = ref([])
const noticeCat = ref('latest')
const promoteEarnRows = ref([])
const promoteEarnOffset = ref(0)
let promoteEarnTimer = null
const fissionNotice = ref(null)
const fissionNoticeRemainSec = ref(0)
let fissionNoticeTick = null
const commission = ref({})
const commissionListMode = ref('recent')
const friendReqPending = ref(0)
const listRefreshing = ref(false)
const swipeOpenKey = ref('')
const swipeDragKey = ref('')
const swipeOffset = ref(0)
let swipeState = null
/** 置顶 + 删除两钮总宽 */
const SWIPE_ACTIONS_W = 128
let skipNextConvClick = false
let off = null
let loading = false
let pageAlive = false
let inboxListBound = false

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

const statusText = computed(() => {
  if (status.value === 'online') return '已连接'
  if (status.value === 'connecting') return '连接中…'
  return '未连接'
})

function formatMoneyYuan(n) {
  const v = Number(n)
  if (!isFinite(v)) return '￥0.00'
  return '￥' + v.toFixed(2)
}

const myIdLine = computed(() => {
  const base = String(myIdText.value || '').trim()
  if (!base) return ''
  if (myHongbao.value == null || !isFinite(Number(myHongbao.value))) return base
  const bal = formatMoneyYuan(myHongbao.value)
  const fr = formatMoneyYuan(myHongbaoFrozen.value != null ? myHongbaoFrozen.value : 0)
  return base + ' · 红宝 ' + bal + ' · 冻结 ' + fr
})

let walletPollTimer = null
let walletPollBusy = false
function stopWalletPoll() {
  if (walletPollTimer) {
    clearInterval(walletPollTimer)
    walletPollTimer = null
  }
}
function startWalletPoll() {
  stopWalletPoll()
  loadMyIdLine()
  walletPollTimer = setInterval(() => {
    if (!pageAlive || walletPollBusy) return
    loadMyIdLine()
  }, 20000)
}

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
  const id = resolveConvId(item)
  if (!id) return 0
  if (isConversationRecentlyRead(item.conversation_type, id)) return 0
  const last = item.last_message
  const lastId = last ? (last.id | 0) || (last.msg_id | 0) : 0
  if (lastId > 0 && getReadWatermark(item.conversation_type, id) >= lastId) return 0
  const fromInbox = getInboxUnread(item.conversation_type, id)
  if (fromInbox <= 0) return 0
  return fromInbox
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
  if (mode === 'rebate') return '红宝返佣'
  if (mode === 'withdraw_list') return '提现记录'
  if (mode === 'ledger') return '收益明细'
  return '最近结算'
})

const commissionEmptyText = computed(() => {
  const mode = commissionListMode.value
  if (mode === 'promo') return '暂无推广结算'
  if (mode === 'rebate') return '暂无红宝返佣'
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
  const n = (g && (g.online_count || g.member_count || g.display_member_count)) | 0
  if (n <= 0) return '欢迎加入'
  return n.toLocaleString('en-US') + '人在线'
}

const OFFICIAL_TAGS = ['玩法火爆', '官方保障', '极速开奖', '大奖奖池', '官方保障']
function officialGroupTag(g, idx) {
  const t = String((g && (g.recommend_tag || g.tag || g.badge)) || '').trim()
  if (t) return t
  return OFFICIAL_TAGS[(idx | 0) % OFFICIAL_TAGS.length]
}

async function openGameRulesFromCommunity() {
  await switchHomeTab('notice')
  setNoticeCat('rules')
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

/** 游戏规则等长图（750 宽）：按宽度铺满、高度完整展示，不裁切 */
function noticeImagesFull(n) {
  const c = String((n && n.category) || noticeCat.value || '')
  if (c === 'rules' || c.indexOf('规则') >= 0) return true
  // 单张长图公告也完整展示
  return noticeImages(n).length === 1
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
  if (/裂变/.test(label) || /fission/i.test(url)) {
    uni.switchTab({ url: '/pages/fission/detail' })
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
  openShareSheet(shareText)
}

const shareSheetOpen = ref(false)
const shareLoading = ref(false)
const shareTextPayload = ref('')
const shareFissionPayload = ref(null)
const shareFriendTargets = ref([])
const shareGroupTargets = ref([])
const shareBusy = ref(false)

const sharePreviewText = computed(() => {
  if (shareFissionPayload.value) {
    const p = shareFissionPayload.value
    return '裂变红宝卡片 · ¥' + (p.pool || '0') + ' · ' + (p.quals || 0) + '/' + (p.cap || 100)
  }
  const s = String(shareTextPayload.value || '')
  return s.length > 80 ? s.slice(0, 80) + '…' : s
})
const shareTargets = computed(() => [
  ...(shareFriendTargets.value || []),
  ...(shareGroupTargets.value || []),
])

function shareFriendId(f) {
  return (f && (f.peer_user_id || f.user_id || f.id)) | 0
}

function closeShareSheet() {
  shareSheetOpen.value = false
  shareFissionPayload.value = null
}

async function loadShareTargets() {
  shareSheetOpen.value = true
  shareLoading.value = true
  shareFriendTargets.value = []
  shareGroupTargets.value = []
  try {
    await imConnect()
    if (!friends.value.length || !myGroups.value.length) {
      await loadCommunityExtra()
    }
    shareFriendTargets.value = (friends.value || []).filter((f) => shareFriendId(f) > 0)
    const groups = myGroups.value || []
    const speakable = []
    const slice = groups.slice(0, 24)
    await Promise.all(
      slice.map(async (g) => {
        const gid = (g.id || g.group_id) | 0
        if (!gid) return
        const role = (g.my_role | 0) || (g.role | 0) || 0
        if (role >= 2) {
          speakable.push(g)
          return
        }
        try {
          const info = await fetchGroupInfo(gid)
          const data = (info && info.data) || info || {}
          const pol = data.policy || {}
          if (data.can_speak === false) return
          if (pol.can_send_text === false) return
          speakable.push(g)
        } catch (e) {
          speakable.push(g)
        }
      })
    )
    shareGroupTargets.value = speakable
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '加载失败', icon: 'none' })
  } finally {
    shareLoading.value = false
  }
}

async function openShareSheet(text) {
  shareFissionPayload.value = null
  shareTextPayload.value = String(text || '').trim()
  if (!shareTextPayload.value) {
    uni.showToast({ title: '分享内容为空', icon: 'none' })
    return
  }
  await loadShareTargets()
}

async function openFissionShare() {
  const pool = fissionNoticePool.value
  const quals = fissionNoticeQuals.value
  const cap = fissionNoticeCap.value
  const ended = fissionNoticeEnded.value ? 1 : 0
  const act = (fissionNotice.value && fissionNotice.value.activity) || {}
  shareTextPayload.value = ''
  shareFissionPayload.value = {
    pool: String(pool),
    quals: quals | 0,
    cap: cap | 0,
    ended,
    activity_id: (act.id | 0) || 0,
  }
  await loadShareTargets()
}

async function sendShareToFriend(f) {
  if (shareBusy.value) return
  const peer = shareFriendId(f)
  if (!peer) return
  shareBusy.value = true
  try {
    await imConnect()
    if (shareFissionPayload.value) {
      const p = shareFissionPayload.value
      await imSend(
        'private.send',
        {
          to_user_id: peer,
          content: '[裂变红宝]',
          msg_type: 11,
          extra: {
            fission: 1,
            pool: p.pool,
            quals: p.quals,
            cap: p.cap,
            ended: p.ended,
            activity_id: p.activity_id,
          },
        },
        true
      )
    } else {
      await imSend(
        'private.send',
        { to_user_id: peer, content: shareTextPayload.value, msg_type: 1 },
        true
      )
    }
    uni.showToast({ title: '已分享给好友', icon: 'success' })
    closeShareSheet()
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '发送失败', icon: 'none' })
  } finally {
    shareBusy.value = false
  }
}

async function sendShareToGroup(g) {
  if (shareBusy.value) return
  const gid = (g && (g.id || g.group_id)) | 0
  if (!gid) return
  shareBusy.value = true
  try {
    await imConnect()
    if (shareFissionPayload.value) {
      const p = shareFissionPayload.value
      await imSend(
        'group.send',
        {
          group_id: gid,
          content: '[裂变红宝]',
          msg_type: 11,
          extra: {
            fission: 1,
            pool: p.pool,
            quals: p.quals,
            cap: p.cap,
            ended: p.ended,
            activity_id: p.activity_id,
          },
        },
        true
      )
    } else {
      await imSend(
        'group.send',
        { group_id: gid, content: shareTextPayload.value, msg_type: 1 },
        true
      )
    }
    uni.showToast({ title: '已分享到群', icon: 'success' })
    closeShareSheet()
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '发送失败（可能禁言）', icon: 'none' })
  } finally {
    shareBusy.value = false
  }
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
  return key === 'group'
    ? tt('promote_earn_type_group', '红包返佣')
    : tt('promote_earn_type_share', '分享推广')
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
  const last = item.last_message
  const lastId = last ? (last.id | 0) || (last.msg_id | 0) : 0
  const peer = item.peer_user_id | 0
  const group = (item.group_id || (type === 2 ? id : 0)) | 0
  const title = displayTitle(item)
  localUnread.value = Object.assign({}, localUnread.value, { [key]: 0 })
  item.unread_count = 0
  noteConversationRead(type, id, lastId)
  if (type === 2 && item.group_id) {
    noteConversationRead(2, String(item.group_id), lastId)
  }
  if (id && lastId > 0) markConversationRead(type, id, lastId).catch(() => null)
  // 进房前先挂上 activeChat，避免 navigate 空隙里延迟推送又把未读加回来
  saveActiveChat({
    type,
    id,
    peer,
    group,
    title,
    nickname: item.peer_nickname || '',
    remark: item.remark || '',
  })
  const q = [
    'type=' + encodeURIComponent(type),
    'id=' + encodeURIComponent(id),
    'peer=' + encodeURIComponent(peer),
    'group=' + encodeURIComponent(group),
    'title=' + encodeURIComponent(title),
    'nickname=' + encodeURIComponent(item.peer_nickname || ''),
    'remark=' + encodeURIComponent(item.remark || ''),
  ].join('&')
  uni.navigateTo({ url: '/pages/chat/chat?' + q })
}

function bumpUnread(msg) {
  // 未读与列表预览统一走 inbox → fanshub-inbox-msg，避免 WS 路径再 upsert 一次
  void msg
}

function upsertListFromMessage(msg) {
  if (!msg) return
  const type = (msg.conversation_type | 0) || ((msg.group_id | 0) > 0 ? 2 : 1)
  let id = ''
  if (type === 2) id = String(msg.group_id || msg.conversation_id || '')
  else id = String(msg.conversation_id || '')
  if (!id) return
  const key = convKey(type, id)
  const rows = list.value
  let foundIdx = -1
  for (let i = 0; i < rows.length; i++) {
    if (itemKey(rows[i]) === key) {
      foundIdx = i
      break
    }
  }
  let found = null
  if (foundIdx < 0) {
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
  } else {
    found = Object.assign({}, rows[foundIdx], {
      last_message: msg,
      updatetime: msg.createtime | 0,
      unread_count: localUnread.value[key] | 0,
    })
    rows.splice(foundIdx, 1)
  }
  // 免全表 sort：置顶区后插入，非置顶插到首个非置顶位置
  if (found.pinned) {
    rows.unshift(found)
  } else {
    let insertAt = 0
    while (insertAt < rows.length && rows[insertAt] && rows[insertAt].pinned) insertAt++
    rows.splice(insertAt, 0, found)
  }
  list.value = rows.slice()
  restoreConvScrollSoon()
}

function myIdNum() {
  const n = parseInt(String(myIdText.value || '').replace(/\D+/g, ''), 10)
  return n || 0
}

function closeAllSwipe(exceptKey) {
  if (!exceptKey) {
    swipeOpenKey.value = ''
    swipeDragKey.value = ''
    swipeOffset.value = 0
    return
  }
  if (swipeOpenKey.value && swipeOpenKey.value !== exceptKey) {
    swipeOpenKey.value = ''
  }
}

function swipeFrontStyle(item) {
  const key = itemKey(item)
  if (swipeDragKey.value === key) {
    const x = Number(swipeOffset.value) || 0
    return {
      transform: 'translateX(' + x + 'px)',
      transition: 'none',
    }
  }
  if (swipeOpenKey.value === key) {
    return {
      transform: 'translateX(-' + SWIPE_ACTIONS_W + 'px)',
      transition: 'transform 0.22s cubic-bezier(0.2, 0.9, 0.3, 1)',
    }
  }
  return {
    transform: 'translateX(0)',
    transition: 'transform 0.22s cubic-bezier(0.2, 0.9, 0.3, 1)',
  }
}

function touchPoint(ev) {
  const t = (ev && ev.touches && ev.touches[0]) || (ev && ev.changedTouches && ev.changedTouches[0])
  if (!t) return null
  return { x: t.clientX, y: t.clientY }
}

function onSwipeTouchStart(ev, item) {
  const p = touchPoint(ev)
  if (!p || !item) return
  const type = item.conversation_type | 0
  if (type !== 1 && type !== 2) {
    swipeState = null
    return
  }
  const key = itemKey(item)
  const baseX = swipeOpenKey.value === key ? -SWIPE_ACTIONS_W : 0
  swipeState = {
    key,
    item,
    startX: p.x,
    startY: p.y,
    baseX,
    moved: false,
    horizontal: false,
  }
}

function onSwipeTouchMove(ev, item) {
  if (!swipeState || !item || swipeState.key !== itemKey(item)) return
  const p = touchPoint(ev)
  if (!p) return
  const dx = p.x - swipeState.startX
  const dy = p.y - swipeState.startY
  if (!swipeState.horizontal) {
    if (Math.abs(dx) < 8 && Math.abs(dy) < 8) return
    if (Math.abs(dx) <= Math.abs(dy)) {
      swipeState = null
      return
    }
    swipeState.horizontal = true
    closeAllSwipe(swipeState.key)
    swipeDragKey.value = swipeState.key
  }
  swipeState.moved = true
  const nx = Math.max(-SWIPE_ACTIONS_W, Math.min(0, swipeState.baseX + dx))
  swipeOffset.value = nx
  if (nx <= -SWIPE_ACTIONS_W / 2) swipeOpenKey.value = swipeState.key
  else if (swipeOpenKey.value === swipeState.key) swipeOpenKey.value = ''
}

function onSwipeTouchEnd(ev, item) {
  if (!swipeState || !item || swipeState.key !== itemKey(item)) {
    swipeState = null
    return
  }
  const st = swipeState
  swipeState = null
  if (!st.horizontal) {
    swipeDragKey.value = ''
    return
  }
  const open = swipeOffset.value <= -SWIPE_ACTIONS_W * 0.4
  swipeDragKey.value = ''
  swipeOffset.value = 0
  swipeOpenKey.value = open ? st.key : ''
  if (st.moved) skipNextConvClick = true
}

function onConvClick(item) {
  if (skipNextConvClick) {
    skipNextConvClick = false
    return
  }
  const key = itemKey(item)
  if (swipeOpenKey.value && swipeOpenKey.value !== key) {
    closeAllSwipe()
    return
  }
  if (swipeOpenKey.value === key) {
    closeAllSwipe()
    return
  }
  openChat(item)
}

function confirmDeleteConv(item) {
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
        closeAllSwipe()
        uni.showToast({ title: '已删除', icon: 'none' })
      } catch (e) {
        uni.showToast({ title: (e && e.message) || '删除失败', icon: 'none' })
      }
    },
  })
}

function onSwipeDelete(item) {
  closeAllSwipe()
  confirmDeleteConv(item)
}

async function onSwipePin(item) {
  if (!item) return
  const type = item.conversation_type | 0
  const id = resolveConvId(item)
  const pinned = !!item.pinned
  closeAllSwipe()
  try {
    await pinConversation(type, id, !pinned)
    const key = itemKey(item)
    const rows = list.value.slice()
    for (let i = 0; i < rows.length; i++) {
      if (itemKey(rows[i]) === key) {
        rows[i] = Object.assign({}, rows[i], { pinned: !pinned })
        break
      }
    }
    rows.sort((a, b) => {
      const ap = a.pinned ? 1 : 0
      const bp = b.pinned ? 1 : 0
      if (ap !== bp) return bp - ap
      return (b.updatetime | 0) - (a.updatetime | 0)
    })
    list.value = rows
    uni.showToast({ title: pinned ? '已取消置顶' : '已置顶', icon: 'none' })
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '操作失败', icon: 'none' })
  }
}

async function switchHomeTab(tab) {
  homeTab.value = tab
  plusOpen.value = false
  if (tab === 'community') await loadCommunity()
  else {
    stopOfficialCommunityPoll()
    if (tab === 'notice') {
      syncPromoteEarnPanel()
      await loadNotices()
    } else {
      stopPromoteEarnScroll()
      if (tab === 'commission') await loadCommission()
    }
  }
}

function setCommunitySub(sub) {
  communitySub.value = sub
  if (sub === 'mine' || sub === 'created' || sub === 'friends') {
    communityPaneVisited[sub] = true
    stopOfficialCommunityPoll()
    // 有缓存则立刻展示；后台按 TTL 静默刷新
    void loadCommunityExtra({ force: false })
  } else {
    startOfficialCommunityPoll()
  }
}

function setNoticeCat(cat) {
  const allowed = ['latest', 'promote', 'ads', 'rules']
  noticeCat.value = allowed.indexOf(cat) >= 0 ? cat : 'latest'
  syncPromoteEarnPanel()
  loadNotices()
}

let officialCommunityPoll = null
function stopOfficialCommunityPoll() {
  if (officialCommunityPoll) {
    clearInterval(officialCommunityPoll)
    officialCommunityPoll = null
  }
}
function startOfficialCommunityPoll() {
  stopOfficialCommunityPoll()
  if (homeTab.value !== 'community' || communitySub.value !== 'official') return
  officialCommunityPoll = setInterval(() => {
    if (homeTab.value !== 'community' || communitySub.value !== 'official') {
      stopOfficialCommunityPoll()
      return
    }
    loadCommunityQuiet()
  }, 20000)
}
async function loadCommunityQuiet() {
  try {
    const rec = await apiRequest('communityrecommend', 'GET', {})
    const rows = (rec && (rec.list || rec.rows || rec.items)) || rec || []
    if (!Array.isArray(rows)) return
    communityRecs.value = rows
    markMineInRecs()
  } catch (e) {}
}

async function loadCommunity() {
  try {
    const rec = await apiRequest('communityrecommend', 'GET', {})
    const rows = (rec && (rec.list || rec.rows || rec.items)) || rec || []
    communityRecs.value = Array.isArray(rows) ? rows : []
  } catch (e) {
    communityRecs.value = []
  }
  // 进入社群即预拉群/好友（并行+TTL），切换子 Tab 可秒开
  await loadCommunityExtra({ force: false })
  markMineInRecs()
  startOfficialCommunityPoll()
}

function normalizeMyGroups(list) {
  return (list || []).map((g) =>
    Object.assign({}, g, {
      is_member: true,
      my_role: (g.my_role | 0) || (g.role | 0) || 0,
    })
  )
}

async function loadCommunityExtra(opts) {
  const force = !!(opts && opts.force)
  const now = Date.now()
  if (!force && communityExtraOk && communityExtraAt > 0 && now - communityExtraAt < COMMUNITY_EXTRA_TTL_MS) {
    return
  }
  if (communityExtraInflight) return communityExtraInflight

  const showLoading = !communityExtraOk || !(myGroups.value || []).length
  if (showLoading) communityExtraLoading.value = true

  communityExtraInflight = (async () => {
    try {
      await imConnect()
      const [mineRes, frRes] = await Promise.all([
        listMyGroups().then((r) => ({ ok: true, r })).catch(() => ({ ok: false, r: null })),
        listFriends().then((r) => ({ ok: true, r })).catch(() => ({ ok: false, r: null })),
      ])
      if (mineRes.ok) {
        const md = (mineRes.r && mineRes.r.data) || {}
        myGroups.value = normalizeMyGroups(md.list || md.items || [])
      }
      // 失败时保留旧缓存，勿清空导致「空白再等几秒」
      if (frRes.ok) {
        const fd = (frRes.r && frRes.r.data) || {}
        friends.value = fd.list || fd.items || []
      }
      if (mineRes.ok || frRes.ok) {
        communityExtraAt = Date.now()
        communityExtraOk = true
      }
      if (homeTab.value === 'community' && communitySub.value === 'official') {
        markMineInRecs()
      }
    } finally {
      communityExtraLoading.value = false
      communityExtraInflight = null
    }
  })()
  return communityExtraInflight
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

function isMyGroupMember(groupId) {
  const gid = groupId | 0
  if (!gid) return false
  return (myGroups.value || []).some((x) => ((x.id || x.group_id) | 0) === gid)
}

async function openGroup(g) {
  const groupId = (g && (g.id || g.group_id)) | 0
  if (!groupId) return
  // 「我的群组」接口不带 is_member；已入群再调 join 会被隐私群拒绝 → 点不开
  const alreadyMember = !!(g && g.is_member) || isMyGroupMember(groupId)
  try {
    if (!alreadyMember) {
      await joinGroup(groupId)
      uni.showToast({ title: '已加入社群', icon: 'none' })
      communityExtraOk = false
      communityExtraAt = 0
      await loadCommunityExtra({ force: true })
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
    myGroups.value = normalizeMyGroups(data.list || data.groups || [])
    communityExtraAt = Date.now()
    communityExtraOk = true
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
  if (walletPollBusy) return
  walletPollBusy = true
  try {
    const p = await fetchProfile()
    const uid = (p && (p.user_id || p.id)) | 0
    if (uid) {
      myIdText.value = '我的会员ID：' + uid
      setInboxMyId(uid)
    }
    if (p) {
      const hb = p.hongbao != null ? p.hongbao : p.balance
      if (hb != null && isFinite(Number(hb))) {
        myHongbao.value = Number(hb)
      }
      if (p.hongbao_frozen != null && isFinite(Number(p.hongbao_frozen))) {
        myHongbaoFrozen.value = Math.max(0, Number(p.hongbao_frozen))
      } else if (myHongbaoFrozen.value == null) {
        myHongbaoFrozen.value = 0
      }
    }
  } catch (e) {
  } finally {
    walletPollBusy = false
  }
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
  if (noticeCat.value === 'promote' || noticeCat.value === 'latest') {
    loadFissionNotice()
  }
}

async function loadFissionNotice() {
  try {
    const data = await apiRequest('fissionentry', 'GET', {})
    fissionNotice.value = data || null
    const act = (data && data.activity) || {}
    const st = Number((data && data.server_time) || Math.floor(Date.now() / 1000))
    fissionNoticeRemainSec.value = Math.max(0, Number(act.end_time || 0) - st)
    if (fissionNoticeTick) clearInterval(fissionNoticeTick)
    if ((data && data.entry_state) === 'active' && fissionNoticeRemainSec.value > 0) {
      fissionNoticeTick = setInterval(() => {
        if (fissionNoticeRemainSec.value > 0) fissionNoticeRemainSec.value -= 1
        else clearInterval(fissionNoticeTick)
      }, 1000)
    }
  } catch (e) {
    fissionNotice.value = null
  }
}

const fissionNoticeVisible = computed(() => {
  const f = fissionNotice.value
  return !!(f && f.has_activity)
})
const fissionNoticeEnded = computed(() => {
  const f = fissionNotice.value
  return !!(f && f.entry_state === 'ended')
})
const fissionNoticePool = computed(() => {
  const a = (fissionNotice.value && fissionNotice.value.activity) || {}
  return a.pool_amount != null ? a.pool_amount : 1000
})
const fissionNoticeQuals = computed(() => ((fissionNotice.value && fissionNotice.value.activity && fissionNotice.value.activity.global_quals) | 0))
const fissionNoticeCap = computed(() => ((fissionNotice.value && fissionNotice.value.activity && fissionNotice.value.activity.global_cap) || 100))
const fissionNoticeTime = computed(() => {
  const a = (fissionNotice.value && fissionNotice.value.activity) || {}
  const ts = Number(a.start_time || 0)
  if (!ts) return ''
  const d = new Date(ts * 1000)
  const pad = (n) => (n < 10 ? '0' + n : '' + n)
  return pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes())
})
const fissionNoticeRemain = computed(() => {
  const s = Math.max(0, fissionNoticeRemainSec.value | 0)
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  const sec = s % 60
  const pad = (n) => (n < 10 ? '0' + n : '' + n)
  return pad(h) + ':' + pad(m) + ':' + pad(sec)
})

function goFissionFromNotice() {
  uni.switchTab({ url: '/pages/fission/detail' })
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
      const id = resolveConvId(it)
      const last = it.last_message
      const lastId = last ? (last.id | 0) || (last.msg_id | 0) : 0
      if (
        isConversationRecentlyRead(it.conversation_type, id) ||
        (lastId > 0 && getReadWatermark(it.conversation_type, id) >= lastId)
      ) {
        next[key] = 0
        it.unread_count = 0
        return
      }
      next[key] = getInboxUnread(it.conversation_type, id)
      it.unread_count = next[key]
    })
    localUnread.value = next
    let sum = 0
    rows.forEach((it) => {
      sum += unreadOf(it)
    })
    setChatUnreadTotal(sum)
    restoreConvScrollSoon()
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
    await imForceReconnect()
    await loadList()
    uni.showToast({ title: '已重连', icon: 'success' })
  } catch (e) {
    uni.showToast({ title: e.message || '重连失败', icon: 'none' })
  }
}

function applyPageShell(on) {
  try {
    const h =
      typeof window !== 'undefined' && window.matchMedia && window.matchMedia('(max-width: 480px)').matches
        ? '44px'
        : '48px'
    // #ifdef H5
    if (typeof document !== 'undefined') {
      document.body.classList.toggle('tab-messages', !!on)
      document.documentElement.classList.toggle('tab-messages-ios-safari', !!(on && isIosSafariH5()))
      document.body.classList.toggle('tab-messages-ios-safari', !!(on && isIosSafariH5()))
      if (on) document.documentElement.style.setProperty('--top-bar-height', h)
      if (!on) {
        document.documentElement.style.removeProperty('overflow')
        document.documentElement.style.removeProperty('overscroll-behavior-y')
        document.body.style.removeProperty('overscroll-behavior-y')
      } else if (isIosSafariH5()) {
        document.documentElement.style.overflow = 'hidden'
        document.documentElement.style.overscrollBehaviorY = 'none'
        document.body.style.overscrollBehaviorY = 'none'
      }
    }
    // #endif
    // App：同样写入 CSS 变量（无 document.body 类，适配器已用 #tabMessages）
    // #ifdef APP-PLUS
    if (typeof document !== 'undefined' && document.documentElement && on) {
      document.documentElement.style.setProperty('--top-bar-height', h)
      if (document.body) document.body.classList.add('tab-messages')
    }
    // #endif
  } catch (e) {}
}

onShow(() => {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  pageAlive = true
  prefetchChatSubpackages()
  measureMessagesLayout()
  // Safari / 旋转后 windowHeight 偶发滞后，下一帧再量一次
  setTimeout(() => {
    if (pageAlive) measureMessagesLayout()
  }, 50)
  applyPageShell(true)
  startImInbox()
  bindForegroundResume()
  resumeFromBackground('messages-onShow')
  if (typeof off === 'function') off()
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
      // 列表预览 / 未读只走 inbox（fanshub-inbox-msg），避免双重 upsert
      return
    }
    if (type === 'conversation.updated') {
      loadList(true)
    }
    if (type === 'redpacket.update') {
      // A2：抢包风暴不刷整表；预览由 inbox/msg 路径维护
      return
    }
    if (type === 'group.created' || type === 'group.kicked') {
      loadList(true)
      loadMyGroupsSafe()
    }
    if (type === 'friend.request' || type === 'friend.accepted' || type === 'friend.rejected' || type === 'friend.cancelled') {
      loadFriendReqBadge()
    }
  })
  // inbox 只绑一次：进聊天页 onHide 后仍更新会话预览
  if (!inboxListBound) {
    inboxListBound = true
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
  }
  loadMyIdLine()
  startWalletPoll()
  loadList().then(() => refreshAuthFlags())
  loadFriendReqBadge()
})

onHide(() => {
  pageAlive = false
  applyPageShell(false)
  stopWalletPoll()
  stopOfficialCommunityPoll()
  if (typeof off === 'function') {
    off()
    off = null
  }
  // 保留 inbox 监听与全局 im-inbox
})
</script>

<style scoped>
.chat-reconnect {
  color: var(--color-primary-start, #e63022);
  font-weight: 700;
  text-decoration: underline;
}
.chat-conv-ptr-host {
  flex: 1 1 0%;
  min-height: 120px;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  position: relative;
}
.chat-conv-scroll {
  /* 高度由内联 panelScrollStyle 像素给出（Safari 勿依赖 height:100%） */
  flex: none;
  min-height: 120px;
  width: 100%;
  box-sizing: border-box;
}
.chat-community-body-scroll,
.chat-official-scroll,
.chat-commission-body-scroll,
.chat-notice-scroll,
.chat-notice-body-scroll {
  flex: none;
  min-height: 120px;
  width: 100%;
  box-sizing: border-box;
}
/* 列表末项后留白，确保最后一条可滚出底栏遮挡（H5/Safari/APK/IPA） */
.chat-list-scroll-pad {
  display: block;
  width: 100%;
  height: 20px;
  min-height: 20px;
  max-height: 20px;
  flex-shrink: 0;
  overflow: hidden;
  pointer-events: none;
  box-sizing: border-box;
}
.chat-list-scroll-pad-mark {
  display: block;
  height: 20px;
  line-height: 20px;
  font-size: 20px;
  opacity: 0;
}
/* 会话列表：最后一条渲染完后再留 20px（padding + 实体 pad，双保险） */
.chat-conv-list {
  padding-bottom: 20px !important;
  box-sizing: border-box;
  overflow: visible !important;
  height: auto !important;
  min-height: 0 !important;
  flex: none !important;
}
/* 社群好友列表：同会话列表，完整可滚 + 末项后再留 20px */
.chat-friend-feed-list {
  padding-bottom: 20px !important;
  box-sizing: border-box;
}
.chat-official-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 8px 12px 20px;
  box-sizing: border-box;
}
.chat-official-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 12px;
  border-radius: 14px;
  background: #fff;
  box-shadow: 0 2px 8px rgba(120, 40, 20, 0.08);
}
/* 官方社群头像：复用会话列表 .chat-avatar.group（见 chat-uni-adapter） */
.chat-official-body {
  flex: 1 1 auto;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.chat-official-title {
  font-size: 15px;
  font-weight: 800;
  color: #222;
  line-height: 1.25;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.chat-official-sub {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
}
.chat-official-online {
  font-size: 12px;
  color: #999;
}
.chat-official-tag {
  font-size: 11px;
  color: #c45a12;
  background: #fff4e8;
  border-radius: 4px;
  padding: 1px 6px;
  font-weight: 700;
}
.chat-official-join {
  flex-shrink: 0;
  padding: 7px 12px;
  border-radius: 999px;
  border: 1.5px solid #e63022;
  color: #e63022;
  font-size: 13px;
  font-weight: 800;
  background: #fff;
}
.chat-official-end {
  text-align: center;
  color: rgba(255, 230, 210, 0.85);
  font-size: 12px;
  padding: 10px 0 8px;
  letter-spacing: 0.5px;
}
.chat-community-pane--official {
  display: flex !important;
  flex-direction: column !important;
  flex: 1 1 auto !important;
  min-height: 0 !important;
  overflow: hidden !important;
  box-sizing: border-box !important;
}
.chat-community-pane--feed {
  display: flex !important;
  flex-direction: column !important;
  flex: 1 1 0% !important;
  min-height: 0 !important;
  overflow: hidden !important;
  box-sizing: border-box !important;
}
.chat-community-pane--official .chat-community-body-scroll,
.chat-community-pane--feed .chat-community-body-scroll {
  flex: none !important;
  min-height: 0 !important;
}
/* 好友列表末项后 20px（与会话列表一致，App 勿依赖空 view） */
.chat-friend-feed-list,
.chat-my-groups-list {
  padding-bottom: 20px !important;
  box-sizing: border-box;
}
.chat-official-rules,
.chat-official-rules--dock {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
  position: relative;
  z-index: 20;
  margin: 4px 10px 6px;
  padding: 12px 12px;
  border-radius: 14px;
  background: linear-gradient(180deg, #fff8ee 0%, #fff1df 100%);
  border: 1px solid rgba(230, 180, 100, 0.45);
  box-shadow: 0 2px 10px rgba(120, 60, 20, 0.1);
  box-sizing: border-box;
}
.chat-official-rules--dock {
  /* fixed 压在底栏之上（BottomTabBar z-index:9000） */
  position: fixed !important;
  left: 0;
  right: 0;
  bottom: calc(72px + var(--safe-area-inset-bottom, env(safe-area-inset-bottom, 0px)));
  z-index: 9100 !important;
  margin: 0 10px 0;
  flex: none;
  max-width: 720px;
  margin-left: auto;
  margin-right: auto;
  width: calc(100% - 20px);
  box-sizing: border-box;
}
.chat-official-rules-ico {
  width: 42px;
  height: 42px;
  border-radius: 10px;
  background: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
  flex-shrink: 0;
  box-shadow: inset 0 0 0 1px rgba(230, 180, 100, 0.25);
}
.chat-official-rules-text {
  flex: 1 1 auto;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 3px;
}
.chat-official-rules-title {
  font-size: 14px;
  font-weight: 800;
  color: #e63022;
  line-height: 1.3;
}
.chat-official-rules-desc {
  font-size: 12px;
  color: #555;
  line-height: 1.3;
}
.chat-official-rules-link {
  flex-shrink: 0;
  font-size: 12px;
  color: #999;
  font-weight: 600;
  white-space: nowrap;
}
/* App only：建群红头勿负 margin 上叠裁切正文；顶距用 --cg-app-top 压过 bundle !important */
/* #ifdef APP-PLUS */
.chat-create-group-pane.cg-app-fix {
  top: var(--cg-app-top, 88px) !important;
}
.chat-create-group-pane.cg-app-fix .chat-cg-header {
  padding-top: 14px !important;
  padding-bottom: 18px !important;
}
.chat-create-group-pane.cg-app-fix .chat-cg-main {
  margin-top: 0 !important;
  padding-top: 18px !important;
}
/* #endif */
</style>

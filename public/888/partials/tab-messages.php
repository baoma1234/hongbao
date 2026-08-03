        <!-- 5. 消息 IM -->
        <div class="tab-page" id="tabMessages" data-tab="messages">
            <div class="chat-shell">
                <div class="chat-list-pane" id="chatListPane">
                    <div class="chat-hero-hd chat-list-hero">
                        <div class="chat-hero-title" data-copy="chat_community_title">红宝社区</div>
                        <div class="chat-list-actions">
                            <button type="button" class="chat-hero-icon-btn" id="chatSearchToggleBtn" aria-label="搜索" title="搜索" data-copy-aria="aria_search" data-copy-title="aria_search">
                                <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2"/><path d="M20 20l-3.5-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                            </button>
                            <div class="chat-plus-menu-wrap" id="chatPlusMenuWrap">
                                <button type="button" class="chat-hero-icon-btn" id="chatPlusMenuBtn" aria-label="更多" title="更多" data-copy-aria="aria_more" data-copy-title="aria_more" aria-expanded="false" aria-controls="chatPlusMenu">
                                    <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="currentColor" d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                                </button>
                                <div class="chat-plus-menu" id="chatPlusMenu" hidden>
                                    <button type="button" class="chat-plus-menu-item" id="chatQrScanBtn">
                                        <img class="chat-plus-menu-ico-img" src="img/chat/plus_scan.png" width="28" height="28" alt="" decoding="async">
                                        <span data-copy="chat_scan">扫一扫</span>
                                    </button>
                                    <button type="button" class="chat-plus-menu-item" id="chatAddFriendBtn">
                                        <img class="chat-plus-menu-ico-img" src="img/chat/plus_add_friend.png" width="28" height="28" alt="" decoding="async">
                                        <span data-copy="chat_add_friend_btn">添加好友</span>
                                    </button>
                                    <button type="button" class="chat-plus-menu-item" id="chatFriendReqEntryBtn">
                                        <img class="chat-plus-menu-ico-img" src="img/chat/plus_friend_req.png" width="28" height="28" alt="" decoding="async">
                                        <span data-copy="chat_friend_req_entry">好友申请</span>
                                        <span class="chat-friend-req-badge" id="chatFriendReqBadge" style="display:none;">0</span>
                                    </button>
                                    <button type="button" class="chat-plus-menu-item" id="chatSharePromoBtn">
                                        <img class="chat-plus-menu-ico-img" src="img/chat/fx.png" width="28" height="28" alt="" decoding="async">
                                        <span data-copy="chat_share_promo_btn">分享推广</span>
                                    </button>
                                    <button type="button" class="chat-plus-menu-item" id="chatNewGroupBtn" style="display:none;">
                                        <img class="chat-plus-menu-ico-img" src="img/chat/plus_create_group.png" width="28" height="28" alt="" decoding="async">
                                        <span data-copy="chat_create_group_btn">建群</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="chat-list-main">
                        <div class="chat-conn" id="chatConnStatus" data-copy="chat_conn_off">未连接</div>
                        <div class="chat-my-id" id="chatMyId"></div>

                        <div class="chat-home-search-area">
                            <div class="chat-home-search-row" id="chatHomeSearchRow" hidden>
                                <div class="chat-home-search-box">
                                    <input type="search" class="chat-home-search-input" id="chatConvSearch" data-copy-placeholder="chat_search_placeholder" placeholder="搜索会话 / 昵称 / 内容" autocomplete="off">
                                </div>
                                <button type="button" class="chat-home-search-cancel" id="chatSearchCancelBtn" data-copy="chat_cancel">取消</button>
                            </div>
                            <div class="chat-home-tabs-row" role="tablist">
                                <button type="button" class="chat-home-tab chat-home-tab-chat active" id="chatHomeTabChat" data-home-tab="chat" role="tab" aria-selected="true">
                                    <span data-copy="chat_tab_chat">聊天</span>
                                </button>
                                <button type="button" class="chat-home-tab chat-home-tab-community" id="chatHomeTabCommunity" data-home-tab="community" role="tab" aria-selected="false">
                                    <span data-copy="chat_tab_community">社群</span>
                                </button>
                                <button type="button" class="chat-home-tab chat-home-tab-notice" id="chatHomeTabNotice" data-home-tab="notice" role="tab" aria-selected="false">
                                    <span data-copy="chat_tab_notice">公告</span>
                                </button>
                                <button type="button" class="chat-home-tab chat-home-tab-commission" id="chatHomeTabCommission" data-home-tab="commission" role="tab" aria-selected="false">
                                    <span data-copy="chat_tab_commission">佣金</span>
                                </button>
                            </div>
                        </div>

                        <div class="chat-home-panel" id="chatHomePanelChat">
                            <div class="chat-conv-list" id="chatConvList">
                                <div class="chat-empty" data-copy="chat_empty">登录后自动连接消息服务</div>
                            </div>
                        </div>

                        <div class="chat-home-panel chat-community-glass" id="chatHomePanelCommunity" style="display:none;" hidden>
                            <div class="chat-community-seg" role="tablist" aria-label="社群分类" data-copy-aria="aria_community_cats">
                                <button type="button" class="chat-community-seg-btn active" id="chatCommunityTabOfficial" data-community-tab="official" role="tab" aria-selected="true" data-copy="chat_community_official">官方社群</button>
                                <button type="button" class="chat-community-seg-btn" id="chatCommunityTabMine" data-community-tab="mine" role="tab" aria-selected="false" data-copy="chat_my_groups">我的群组</button>
                                <button type="button" class="chat-community-seg-btn" id="chatCommunityTabFriends" data-community-tab="friends" role="tab" aria-selected="false" data-copy="chat_friend_list">好友列表</button>
                            </div>

                            <div class="chat-community-pane active" id="chatCommunityPaneOfficial" data-community-pane="official" role="tabpanel">
                                <div class="chat-community-hero-cards" id="chatRecommendGroups">
                                    <div class="chat-empty chat-empty-glass" data-copy="chat_recommend_empty">暂无推荐社群</div>
                                </div>
                            </div>

                            <div class="chat-community-pane" id="chatCommunityPaneMine" data-community-pane="mine" role="tabpanel" hidden>
                                <div class="chat-community-glass-panel chat-community-pane-body">
                                    <div class="chat-my-groups-list" id="chatMyGroupsList">
                                        <div class="chat-empty chat-empty-glass" data-copy="chat_my_groups_empty">暂无群组</div>
                                    </div>
                                </div>
                            </div>

                            <div class="chat-community-pane" id="chatCommunityPaneFriends" data-community-pane="friends" role="tabpanel" hidden>
                                <div class="chat-community-glass-panel chat-community-pane-body">
                                    <div class="chat-friend-feed-list" id="chatFriendFeedList">
                                        <div class="chat-empty chat-empty-glass" data-copy="chat_friend_feed_empty">暂无好友动态</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="chat-home-panel chat-notice-feed-panel" id="chatHomePanelNotice" style="display:none;" hidden>
                            <div class="chat-community-seg chat-notice-seg" id="chatNoticeCats" role="tablist" aria-label="公告分类" data-copy-aria="aria_notice_cats">
                                <button type="button" class="chat-community-seg-btn active" data-notice-cat="latest" role="tab" aria-selected="true" data-copy="chat_notice_latest">最新发布</button>
                                <button type="button" class="chat-community-seg-btn" data-notice-cat="promote" role="tab" aria-selected="false" data-copy="chat_notice_promote">推广赚钱</button>
                                <button type="button" class="chat-community-seg-btn" data-notice-cat="ads" role="tab" aria-selected="false" data-copy="chat_notice_ads">广告发布</button>
                                <button type="button" class="chat-community-seg-btn" data-notice-cat="rules" role="tab" aria-selected="false" data-copy="chat_notice_rules">游戏规则</button>
                            </div>

                            <div class="chat-notice-body-scroll" id="chatNoticeBodyScroll">
                            <div class="chat-promote-earn-wrap" id="chatPromoteEarnWrap" hidden>
                                <div class="chat-promote-earn-card">
                                    <div class="chat-promote-earn-hd">
                                        <div class="chat-promote-earn-title" data-copy="promote_earn_title">推广收益数据表</div>
                                        <button type="button" class="chat-promote-earn-live" id="chatPromoteEarnLiveBtn" data-copy="promote_earn_live">实时更新 ></button>
                                    </div>
                                    <div class="chat-promote-earn-table" role="table" aria-label="推广收益数据表">
                                        <div class="chat-promote-earn-thead" role="row">
                                            <div class="chat-promote-earn-th" role="columnheader" data-copy="promote_earn_col_uid">用户ID</div>
                                            <div class="chat-promote-earn-th is-active" role="columnheader"><span data-copy="promote_earn_col_type">收益类型</span></div>
                                            <div class="chat-promote-earn-th" role="columnheader" data-copy="promote_earn_col_detail">广细记录</div>
                                            <div class="chat-promote-earn-th" role="columnheader" data-copy="promote_earn_col_amount">到手佣金</div>
                                        </div>
                                        <div class="chat-promote-earn-viewport">
                                            <div class="chat-promote-earn-track" id="chatPromoteEarnTrack"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="chat-notice-feed" id="chatNoticeFeed">
                                <div class="chat-empty chat-empty-glass" data-copy="chat_notice_empty">暂无公告</div>
                            </div>
                            </div>
                        </div>

                        <div class="chat-home-panel chat-commission-panel" id="chatHomePanelCommission" style="display:none;" hidden>
                            <div class="chat-commission-hero-card" id="chatCommissionSummary">
                                <div class="chat-commission-hero-top">
                                    <span class="chat-commission-hero-label" data-copy="chat_commission_total">累计佣金</span>
                                    <button type="button" class="chat-commission-withdraw-btn" id="chatCommissionWithdrawBtn" data-copy="chat_commission_withdraw_btn">提现</button>
                                </div>
                                <div class="chat-commission-hero-value" id="chatCommissionTotal">¥ 0.00</div>
                                <div class="chat-commission-hero-stats">
                                    <div class="chat-commission-stat">
                                        <span class="chat-commission-stat-label" data-copy="chat_commission_withdrawable">可提现</span>
                                        <span class="chat-commission-stat-value" id="chatCommissionWithdrawable">¥ 0.00</span>
                                    </div>
                                    <div class="chat-commission-stat-divider" aria-hidden="true"></div>
                                    <div class="chat-commission-stat">
                                        <span class="chat-commission-stat-label" data-copy="chat_commission_today">今日收益</span>
                                        <span class="chat-commission-stat-value" id="chatCommissionToday">¥ 0.00</span>
                                    </div>
                                    <div class="chat-commission-stat-divider" aria-hidden="true"></div>
                                    <div class="chat-commission-stat">
                                        <span class="chat-commission-stat-label" data-copy="chat_commission_rebate">红包返佣</span>
                                        <span class="chat-commission-stat-value" id="chatCommissionRebate">¥ 0.00</span>
                                    </div>
                                </div>
                            </div>

                            <div class="chat-commission-nav-grid" id="chatCommissionNav">
                                <button type="button" class="chat-commission-nav-item" data-commission-nav="promo">
                                    <span class="chat-commission-nav-ico" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm4 18H6V4h7v5h5v11zm-3.5-5.5l-1.4 1.4L11 13.8l-2.1 2.1-1.4-1.4L9.6 12.4 7.5 10.3l1.4-1.4L11 11l2.1-2.1 1.4 1.4-2.1 2.1 2.1 2.1z"/></svg>
                                    </span>
                                    <span class="chat-commission-nav-label"><span data-copy="chat_commission_nav_promo">推广结算</span> <span aria-hidden="true">›</span></span>
                                </button>
                                <button type="button" class="chat-commission-nav-item" data-commission-nav="rebate">
                                    <span class="chat-commission-nav-ico" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm1 15H9v-2h6v2zm0-4H9v-2h6v2zm-3-5V3.5L18.5 9H12z"/></svg>
                                    </span>
                                    <span class="chat-commission-nav-label"><span data-copy="chat_commission_nav_rebate">红包返佣</span> <span aria-hidden="true">›</span></span>
                                </button>
                                <button type="button" class="chat-commission-nav-item" data-commission-nav="ledger">
                                    <span class="chat-commission-nav-ico" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 14h-2v-2h2v2zm0-4h-2V7h2v5z"/></svg>
                                    </span>
                                    <span class="chat-commission-nav-label"><span data-copy="chat_commission_nav_ledger">收益明细</span> <span aria-hidden="true">›</span></span>
                                </button>
                                <button type="button" class="chat-commission-nav-item" data-commission-nav="withdraw_list">
                                    <span class="chat-commission-nav-ico" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 14l-5-5 1.41-1.41L11 13.17V7h2v6.17l2.59-2.58L17 12l-5 5z"/></svg>
                                    </span>
                                    <span class="chat-commission-nav-label"><span data-copy="chat_commission_nav_withdraw">提现记录</span> <span aria-hidden="true">›</span></span>
                                </button>
                            </div>

                            <div class="chat-commission-recent-card">
                                <div class="chat-commission-recent-hd" id="chatCommissionListTitle" data-copy="chat_commission_recent">最近结算</div>
                                <div class="chat-commission-list" id="chatCommissionList">
                                    <div class="chat-empty chat-empty-glass" data-copy="chat_commission_login_hint">登录后查看佣金明细</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="chat-sub-pane" id="chatQrScanPane" aria-hidden="true">
                    <div class="chat-hero-hd">
                        <button type="button" class="chat-hero-back" id="chatQrScanBack" aria-label="返回" data-copy-aria="aria_back"><svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path fill="currentColor" d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6 6 6 1.41-1.41z"/></svg></button>
                        <div class="chat-hero-title" data-copy="chat_scan">扫一扫</div>
                        <span class="chat-hero-spacer"></span>
                    </div>
                    <div class="chat-sub-main chat-qr-scan-main">
                        <div class="chat-qr-scan-frame">
                            <video id="chatQrScanVideo" class="chat-qr-scan-video" playsinline muted></video>
                            <canvas id="chatQrScanCanvas" class="chat-qr-scan-canvas" aria-hidden="true"></canvas>
                            <div class="chat-qr-scan-mask" aria-hidden="true"></div>
                        </div>
                        <p class="chat-qr-scan-hint" data-copy="chat_qr_scan_hint">将好友二维码放入框内即可自动识别</p>
                        <button type="button" class="chat-setting-save-btn" id="chatQrPickBtn" data-copy="chat_qr_pick_album">从相册选择图片</button>
                        <input type="file" id="chatQrPickInput" accept="image/*" hidden>
                    </div>
                </div>

                <div class="chat-sub-pane" id="chatAddFriendPane" aria-hidden="true">
                    <div class="chat-hero-hd">
                        <button type="button" class="chat-hero-back" id="chatAddFriendBack" aria-label="返回" data-copy-aria="aria_back"><svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path fill="currentColor" d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6 6 6 1.41-1.41z"/></svg></button>
                        <div class="chat-hero-title" data-copy="chat_add_friend_title">添加好友</div>
                        <span class="chat-hero-spacer"></span>
                    </div>
                    <div class="chat-sub-main">
                        <button type="button" class="chat-add-friend-req-link" id="chatAddFriendReqLink">
                            <span class="chat-add-friend-req-link-ico" aria-hidden="true">
                                <img src="img/chat/plus_friend_req.png" width="28" height="28" alt="" decoding="async">
                            </span>
                            <span class="chat-add-friend-req-link-body">
                                <span class="chat-add-friend-req-link-title" data-copy="chat_friend_req_entry">好友申请</span>
                                <span class="chat-add-friend-req-link-sub" data-copy="chat_friend_req_entry_sub">查看收到与发出的申请</span>
                            </span>
                            <span class="chat-friend-req-badge chat-add-friend-req-badge" id="chatAddFriendReqBadge" style="display:none;">0</span>
                            <span class="chat-add-friend-req-link-arrow" aria-hidden="true">›</span>
                        </button>
                        <div class="chat-add-friend-card">
                            <label class="chat-setting-label" data-copy="chat_add_friend_phone_label">对方手机号</label>
                            <div class="chat-add-friend-phone-row">
                                <select id="chatAddFriendCountry" class="chat-add-friend-country" aria-label="区号" data-copy-aria="aria_dial"></select>
                                <input type="tel" id="chatAddFriendMobile" class="chat-setting-input chat-add-friend-mobile" inputmode="numeric" autocomplete="tel" data-copy-placeholder="chat_add_friend_phone_placeholder" placeholder="请输入手机号">
                            </div>
                            <div class="chat-add-friend-or" data-copy="chat_add_friend_or">或</div>
                            <label class="chat-setting-label" data-copy="chat_add_friend_id_label">对方会员ID</label>
                            <input type="text" id="chatAddFriendUserId" class="chat-setting-input chat-add-friend-userid" inputmode="numeric" maxlength="8" autocomplete="off" data-copy-placeholder="chat_add_friend_id_placeholder" placeholder="请输入8位数字会员ID">
                            <p class="chat-add-friend-hint" data-copy="chat_add_friend_hint">仅支持手机号或8位会员ID精确查找，二选一；对方通过后才能发消息</p>
                            <button type="button" class="chat-setting-save-btn" id="chatAddFriendSubmit" data-copy="chat_add_friend_submit">查找并申请</button>
                        </div>
                    </div>
                </div>

                <div class="chat-sub-pane" id="chatFriendReqPane" aria-hidden="true">
                    <div class="chat-hero-hd">
                        <button type="button" class="chat-hero-back" id="chatFriendReqBack" aria-label="返回" data-copy-aria="aria_back"><svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path fill="currentColor" d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6 6 6 1.41-1.41z"/></svg></button>
                        <div class="chat-hero-title" data-copy="chat_friend_req_title">好友申请</div>
                        <span class="chat-hero-spacer"></span>
                    </div>
                    <div class="chat-sub-main">
                        <div class="chat-friend-req-tabs" role="tablist">
                            <button type="button" class="chat-friend-req-tab active" id="chatFriendReqTabIn" data-req-tab="incoming" data-copy="chat_friend_req_incoming">收到的</button>
                            <button type="button" class="chat-friend-req-tab" id="chatFriendReqTabOut" data-req-tab="outgoing" data-copy="chat_friend_req_outgoing">发出的</button>
                        </div>
                        <div class="chat-friend-req-list" id="chatFriendReqList">
                            <div class="chat-empty chat-empty-sm" data-copy="chat_friend_req_empty">暂无申请</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="chat-room-pane" id="chatRoomPane">
                <div class="chat-hero-hd">
                    <button type="button" class="chat-hero-back" id="chatBackBtn" data-copy-aria="chat_back" aria-label="‹ 返回">
                        <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path fill="currentColor" d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6 6 6 1.41-1.41z"/></svg>
                    </button>
                    <div class="chat-hero-title chat-room-title" id="chatRoomTitle">会话</div>
                    <button type="button" class="chat-hero-more" id="chatGroupMoreBtn" aria-label="更多" data-copy-aria="aria_more">
                        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><circle cx="6" cy="12" r="1.8" fill="currentColor"/><circle cx="12" cy="12" r="1.8" fill="currentColor"/><circle cx="18" cy="12" r="1.8" fill="currentColor"/></svg>
                    </button>
                    <span class="chat-hero-spacer" id="chatRoomHeroSpacer" hidden></span>
                </div>
                <div class="chat-room-main">
                <div class="chat-notice-pin" id="chatNoticePin" style="display:none" aria-hidden="true">
                        <span class="chat-notice-pin-icon" aria-hidden="true">📢</span>
                    <div class="chat-notice-pin-body">
                            <div class="chat-notice-pin-label" data-copy="chat_group_notice_pin">群公告</div>
                        <div class="chat-notice-pin-text" id="chatNoticePinText"></div>
                        </div>
                        <button type="button" class="chat-notice-pin-close" id="chatNoticePinClose" aria-label="收起" data-copy-aria="aria_collapse">×</button>
                </div>
                <div class="chat-msg-scroll" id="chatMsgScroll"></div>
                <div class="chat-composer-wrap" id="chatComposerWrap">
                    <div class="chat-emoji-panel" id="chatEmojiPanel" aria-hidden="true"></div>
                    <div class="chat-attach-panel" id="chatAttachPanel" aria-hidden="true">
                        <button type="button" class="chat-attach-item" id="chatPickImageBtn">
                                <span class="chat-attach-icon">🖼️</span>
                                <span data-copy="chat_attach_image">图片</span>
                        </button>
                        <button type="button" class="chat-attach-item" id="chatPickVideoBtn">
                                <span class="chat-attach-icon">🎬</span>
                                <span data-copy="chat_attach_video">视频</span>
                        </button>
                        <button type="button" class="chat-attach-item" id="chatAttachRpBtn">
                                <span class="chat-attach-icon">🧧</span>
                                <span data-copy="chat_attach_rp">红包</span>
                        </button>
                        <button type="button" class="chat-attach-item" id="chatAttachTransferBtn" hidden>
                                <span class="chat-attach-icon">💸</span>
                                <span data-copy="chat_attach_transfer">转账</span>
                        </button>
                    </div>
                        <div class="chat-composer chat-footer">
                            <button type="button" class="chat-tool-icon" id="chatEmojiBtn" title="表情" aria-label="表情" data-copy-title="aria_emoji" data-copy-aria="aria_emoji">
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <path d="M8 14s1.5 2 4 2 4-2 4-2"></path>
                                    <line x1="9" y1="9" x2="9.01" y2="9"></line>
                                    <line x1="15" y1="9" x2="15.01" y2="9"></line>
                                </svg>
                            </button>
                            <input type="text" id="chatInput" class="input-box" maxlength="2000" data-copy-placeholder="chat_input_placeholder" placeholder="输入消息…">
                            <button type="button" class="btn-plus" id="chatAttachBtn" title="更多" aria-label="更多" data-copy-title="aria_more" data-copy-aria="aria_more">
                                <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
                                    <path fill="currentColor" d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"></path>
                                </svg>
                            </button>
                            <button type="button" id="chatSendBtn" data-copy="chat_send">发送</button>
                    </div>
                    <input type="file" id="chatImageInput" accept="image/jpeg,image/png,image/gif,image/webp,image/bmp" hidden>
                    <input type="file" id="chatVideoInput" accept="video/mp4,video/webm,video/quicktime" hidden>
                    <input type="file" id="chatStickerUploadInput" accept="image/jpeg,image/png,image/gif" hidden>
                </div>
            </div>
            </div>

            <div class="chat-sub-pane" id="chatGroupSettingsPane" aria-hidden="true">
                <div class="chat-hero-hd">
                    <button type="button" class="chat-hero-back" id="chatGroupSettingsBack" aria-label="返回" data-copy-aria="aria_back"><svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path fill="currentColor" d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6 6 6 1.41-1.41z"/></svg></button>
                    <div class="chat-hero-title" data-copy="chat_group_settings">群设置</div>
                    <span class="chat-hero-spacer"></span>
                </div>
                <div class="chat-sub-main">
                    <div class="chat-setting-card chat-setting-profile">
                        <button type="button" class="chat-setting-avatar-btn" id="chatGroupAvatarBtn" title="更换群头像" data-copy-title="title_change_group_avatar">
                            <img id="chatGroupSettingsAvatar" alt="" style="display:none">
                            <span class="chat-setting-avatar-fallback" id="chatGroupSettingsAvatarFb" data-copy="chat_group_avatar_fb">群</span>
                            <span class="chat-setting-avatar-edit" id="chatGroupAvatarEditHint" style="display:none" data-copy="chat_group_avatar_change">更换</span>
                        </button>
                        <div class="chat-setting-profile-main">
                            <div class="chat-setting-name" id="chatGroupSettingsName">群聊</div>
                            <div class="chat-setting-meta" id="chatGroupSettingsMeta">0 名成员</div>
                        </div>
                    </div>
                    <div class="chat-setting-edit" id="chatGroupEditBlock" style="display:none">
                        <label class="chat-setting-label" for="chatGroupNameInput" data-copy="chat_group_name_label">群名称</label>
                        <input type="text" class="chat-setting-input" id="chatGroupNameInput" maxlength="64" data-copy-placeholder="chat_group_name_ph" placeholder="输入群名称">
                        <label class="chat-setting-label" for="chatGroupNoticeInput" data-copy="chat_group_notice_label">群公告（聊天页置顶）</label>
                        <textarea class="chat-setting-textarea" id="chatGroupNoticeInput" maxlength="500" rows="4" data-copy-placeholder="chat_group_notice_ph" placeholder="输入群公告，成员进入聊天可见"></textarea>
                        <button type="button" class="chat-setting-save-btn" id="chatGroupSaveBtn" data-copy="chat_group_save">保存修改</button>
                    </div>
                    <div class="chat-setting-hint" id="chatGroupNoticeHint"></div>
                    <button type="button" class="chat-setting-row" id="chatViewMembersBtn">
                        <span data-copy="chat_view_members">查看群成员</span>
                        <span class="chat-setting-arrow">›</span>
                    </button>
                    <div class="chat-setting-row chat-setting-toggle-row" id="chatMuteAllRow" style="display:none">
                        <span data-copy="chat_mute_all">全员禁言</span>
                        <label class="chat-switch">
                            <input type="checkbox" id="chatMuteAllSwitch">
                            <span class="chat-switch-slider"></span>
                        </label>
                    </div>
                    <button type="button" class="chat-setting-leave-btn" id="chatGroupLeaveBtn" data-copy="chat_group_leave">退出群组</button>
                    <input type="file" id="chatGroupAvatarInput" accept="image/jpeg,image/png,image/gif,image/webp" hidden>
                </div>
            </div>

            <div class="chat-sub-pane" id="chatGroupMembersPane" aria-hidden="true">
                <div class="chat-hero-hd">
                    <button type="button" class="chat-hero-back" id="chatGroupMembersBack" aria-label="返回" data-copy-aria="aria_back"><svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path fill="currentColor" d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6 6 6 1.41-1.41z"/></svg></button>
                    <div class="chat-hero-title" data-copy="chat_group_members_title">群成员</div>
                    <span class="chat-hero-spacer"></span>
                </div>
                <div class="chat-sub-main">
                <div class="chat-sub-toolbar">
                        <button type="button" class="chat-add-member-btn" id="chatAddMemberBtn" style="display:none" data-copy="chat_add_members_btn">＋ 添加群成员</button>
                        <input type="search" class="chat-search-input" id="chatMemberSearch" data-copy-placeholder="chat_member_search_ph" placeholder="搜索成员昵称/ID">
                    </div>
                    <div class="chat-member-list" id="chatMemberList"></div>
                </div>
            </div>

            <div class="chat-sub-pane" id="chatGroupInvitePane" aria-hidden="true">
                <div class="chat-hero-hd">
                    <button type="button" class="chat-hero-back" id="chatGroupInviteBack" aria-label="返回" data-copy-aria="aria_back"><svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path fill="currentColor" d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6 6 6 1.41-1.41z"/></svg></button>
                    <div class="chat-hero-title" data-copy="chat_add_members_title">添加群成员</div>
                    <span class="chat-hero-spacer"></span>
                </div>
                <div class="chat-sub-main">
                <div class="chat-sub-toolbar">
                        <input type="search" class="chat-search-input" id="chatInviteSearch" data-copy-placeholder="chat_invite_search_ph" placeholder="搜索用户名/手机号/ID">
                </div>
                <div class="chat-member-list chat-invite-list" id="chatInviteList"></div>
                <div class="chat-invite-ft">
                        <button type="button" class="chat-invite-confirm-btn" id="chatInviteConfirmBtn" disabled>确认添加 (0 人)</button>
                    </div>
                </div>
            </div>

            <div class="chat-action-sheet" id="chatConvActionSheet" aria-hidden="true">
                <div class="chat-action-sheet-mask" id="chatConvActionMask"></div>
                <div class="chat-action-sheet-panel">
                    <div class="chat-action-sheet-title" id="chatConvActionTitle">会话操作</div>
                    <button type="button" class="chat-action-item" id="chatConvActPin">置顶聊天</button>
                    <button type="button" class="chat-action-item" id="chatConvActUnpin" style="display:none">取消置顶</button>
                    <button type="button" class="chat-action-item danger" id="chatConvActDelete" style="display:none">删除聊天</button>
                    <button type="button" class="chat-action-item cancel" id="chatConvActionCancel">取消</button>
                </div>
            </div>
            <div class="chat-action-sheet" id="chatMemberActionSheet" aria-hidden="true">
                <div class="chat-action-sheet-mask" id="chatMemberActionMask"></div>
                <div class="chat-action-sheet-panel">
                    <div class="chat-action-sheet-title" id="chatMemberActionTitle" data-copy="chat_member_actions">成员操作</div>
                    <button type="button" class="chat-action-item" data-act="mute" id="chatActMute" data-copy="chat_mute_one">单人禁言</button>
                    <button type="button" class="chat-action-item" data-act="unmute" id="chatActUnmute" style="display:none" data-copy="chat_unmute">取消禁言</button>
                    <button type="button" class="chat-action-item" data-act="set_admin" id="chatActSetAdmin" style="display:none" data-copy="chat_set_admin">设为管理员</button>
                    <button type="button" class="chat-action-item" data-act="unset_admin" id="chatActUnsetAdmin" style="display:none" data-copy="chat_unset_admin">取消管理员</button>
                    <button type="button" class="chat-action-item danger" data-act="kick" id="chatActKick" data-copy="chat_kick">踢出群组</button>
                    <button type="button" class="chat-action-item cancel" id="chatMemberActionCancel" data-copy="chat_cancel">取消</button>
                </div>
            </div>
            <div class="chat-action-sheet" id="chatMuteDurationSheet" aria-hidden="true">
                <div class="chat-action-sheet-mask" id="chatMuteDurationMask"></div>
                <div class="chat-action-sheet-panel">
                    <div class="chat-action-sheet-title" data-copy="chat_mute_duration">选择禁言时长</div>
                    <button type="button" class="chat-action-item" data-mute-sec="600" data-copy="chat_mute_10m">10 分钟</button>
                    <button type="button" class="chat-action-item" data-mute-sec="3600" data-copy="chat_mute_1h">1 小时</button>
                    <button type="button" class="chat-action-item" data-mute-sec="86400" data-copy="chat_mute_24h">24 小时</button>
                    <button type="button" class="chat-action-item" data-mute-sec="0" data-copy="chat_unmute">取消禁言</button>
                    <button type="button" class="chat-action-item cancel" id="chatMuteDurationCancel" data-copy="chat_close">关闭</button>
                </div>
            </div>
            <div class="chat-media-lightbox" id="chatMediaLightbox" aria-hidden="true">
                <button type="button" class="chat-media-lightbox-close" id="chatMediaLightboxClose" aria-label="关闭" data-copy-aria="aria_close">×</button>
                <div class="chat-media-lightbox-body" id="chatMediaLightboxBody"></div>
            </div>

            <div class="chat-rp-send-pane chat-transfer-send-pane" id="chatTransferSendPane" aria-hidden="true">
                <div class="chat-hero-hd">
                    <button type="button" class="chat-hero-back" id="chatTransferCancelBtn" data-copy="chat_cancel">取消</button>
                    <div class="chat-hero-title" data-copy="chat_transfer_title">转账</div>
                    <span class="chat-hero-spacer"></span>
                </div>
                <div class="chat-rp-send-main">
                    <div class="chat-rp-send-body">
                        <div class="chat-transfer-preview">
                            <div class="chat-transfer-preview-icon">💸</div>
                            <div class="chat-transfer-preview-lab">转账给对方</div>
                            <div class="chat-transfer-preview-amt" id="chatTransferPreviewAmt">￥0.00</div>
                        </div>
                        <div class="chat-rp-balance-hint">
                            <span data-copy="chat_rp_balance_hint">可用红宝：</span>
                            <strong id="chatTransferBalance">￥0.00</strong>
                        </div>
                        <div class="chat-rp-form">
                            <div class="chat-rp-field chat-rp-field--amount">
                                <label for="chatTransferAmount" data-copy="chat_transfer_amount_label">金额</label>
                                <div class="chat-rp-amount-row">
                                    <span class="chat-rp-yuan">￥</span>
                                    <input type="number" id="chatTransferAmount" inputmode="decimal" step="0.01" min="0.01" placeholder="0.00">
                                </div>
                            </div>
                            <div class="chat-rp-field">
                                <label for="chatTransferRemark" data-copy="chat_transfer_remark_label">备注</label>
                                <input type="text" id="chatTransferRemark" maxlength="40" placeholder="可选填写">
                            </div>
                        </div>
                    </div>
                    <div class="chat-rp-send-ft">
                        <button type="button" class="chat-rp-submit-btn chat-transfer-submit-btn" id="chatTransferSubmitBtn">确认转账</button>
                    </div>
                </div>
            </div>

            <div class="chat-rp-send-pane" id="chatRpSendPane" aria-hidden="true">
                <div class="chat-hero-hd">
                    <button type="button" class="chat-hero-back" id="chatRpCancelBtn" data-copy="chat_cancel">取消</button>
                    <div class="chat-hero-title" data-copy="chat_rp_title">发红包</div>
                    <span class="chat-hero-spacer"></span>
                </div>
                <div class="chat-rp-send-main">
                    <div class="chat-rp-send-body">
                        <div class="chat-rp-preview" id="chatRpPreview">
                            <div class="chat-rp-preview-seal">红</div>
                            <div class="chat-rp-preview-bless" id="chatRpPreviewBless">恭喜发财</div>
                            <div class="chat-rp-preview-sub" id="chatRpPreviewSub">拼手气红包</div>
                        </div>

                        <div class="chat-rp-balance-hint">
                            <span data-copy="chat_rp_balance_hint">可用红宝：</span>
                            <strong id="chatRpBalance">￥0.00</strong>
                        </div>

                        <div class="chat-rp-form">
                            <div class="chat-rp-field chat-rp-field--amount">
                                <label for="chatRpAmount" data-copy="chat_rp_amount_label">金额</label>
                                <div class="chat-rp-amount-row">
                                    <span class="chat-rp-yuan">￥</span>
                                    <input type="number" id="chatRpAmount" inputmode="decimal" step="0.01" min="10" data-copy-placeholder="chat_rp_amount_ph" placeholder="0.00">
                                </div>
                            </div>

                            <div class="chat-rp-field" id="chatRpCountWrap">
                                <label for="chatRpCount" data-copy="chat_rp_count_label">个数</label>
                                <div class="chat-rp-count-tabs" id="chatRpCountTabs" hidden role="group" aria-label="扫雷个数">
                                    <button type="button" class="chat-rp-count-btn active" data-count="5">5</button>
                                    <button type="button" class="chat-rp-count-btn" data-count="7">7</button>
                                    <button type="button" class="chat-rp-count-btn" data-count="9">9</button>
                                </div>
                                <div class="chat-rp-inline-ctrl" id="chatRpCountInputWrap">
                                    <input type="number" id="chatRpCount" inputmode="numeric" min="5" max="10" value="5" placeholder="5-10">
                                    <span class="chat-rp-unit">个</span>
                                </div>
                                <div class="chat-rp-field-hint" id="chatRpCountHint" data-copy="chat_rp_count_hint">群聊 5～10 个 · 私聊固定 1 个</div>
                            </div>

                            <div class="chat-rp-field chat-rp-field--type">
                                <label data-copy="chat_rp_type_label">类型</label>
                                <div class="chat-rp-type-tabs" id="chatRpTypeTabs" role="tablist">
                                    <button type="button" class="chat-rp-type-btn active" data-type="2" data-copy="chat_rp_type_lucky">拼手气</button>
                                    <button type="button" class="chat-rp-type-btn" data-type="3" data-copy="chat_rp_type_mine">埋雷</button>
                                    <button type="button" class="chat-rp-type-btn" data-type="1" data-copy="chat_rp_type_avg">普通红包</button>
                                    <button type="button" class="chat-rp-type-btn" data-type="4" data-copy="chat_rp_type_random">随机红包</button>
                                </div>
                            </div>

                            <div class="chat-rp-field chat-rp-mine-card" id="chatRpMineWrap" hidden>
                                <div class="chat-rp-mine-title" data-copy="chat_rp_mine_digit">埋雷数字（0～9）</div>
                                <div class="chat-rp-mine-digits" id="chatRpMineDigits" role="group" aria-label="选择雷号"></div>
                                <input type="hidden" id="chatRpMineDigit" value="0">
                                <div class="chat-rp-field-hint" data-copy="chat_rp_mine_hint">手填雷号；开奖后匹配哈希末位相同的波场区块作证明。金额尾数等于雷号即中雷，可多人同时中雷。</div>
                            </div>

                            <div class="chat-rp-field">
                                <label for="chatRpBlessing" data-copy="chat_rp_blessing_label">祝福语</label>
                                <input type="text" id="chatRpBlessing" maxlength="40" data-copy-placeholder="chat_rp_blessing_ph" placeholder="恭喜发财，大吉大利" value="恭喜发财">
                            </div>
                        </div>
                    </div>
                    <div class="chat-rp-send-ft">
                        <button type="button" class="chat-rp-submit-btn" id="chatRpSubmitBtn" data-copy="chat_rp_submit">塞钱进红包</button>
                    </div>
                </div>
            </div>

            <!-- 创建新群聊 -->
            <div class="chat-create-group-pane" id="chatCreateGroupPane" aria-hidden="true">
                <div class="chat-cg-header">
                    <button type="button" class="chat-cg-back" id="chatCreateGroupBack" aria-label="返回" data-copy-aria="aria_back"><svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path fill="currentColor" d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6 6 6 1.41-1.41z"/></svg></button>
                    <div class="chat-cg-title" data-copy="chat_create_group_title">创建新群聊</div>
                    <button type="button" class="chat-cg-next-top" id="chatCreateGroupNextTop" data-copy="chat_next">下一步</button>
                </div>
                <div class="chat-cg-main">
                    <div class="chat-cg-input-group">
                        <button type="button" class="chat-cg-avatar" id="chatCreateGroupAvatar" title="点击切换头像" data-copy-title="title_toggle_avatar">🐵</button>
                        <div class="chat-cg-input-box">
                            <input type="text" id="chatCreateGroupName" maxlength="64" data-copy-placeholder="chat_create_group_name_ph" placeholder="请输入群名称" autocomplete="off">
                            <button type="button" class="chat-cg-next-inner" id="chatCreateGroupNext" data-copy="chat_next">下一步</button>
                        </div>
                    </div>

                    <div class="chat-cg-section-title"><span data-copy="chat_group_type_title">群类型</span></div>
                    <div class="chat-cg-cards" id="chatCgPrivacyCards">
                        <button type="button" class="chat-cg-card" data-privacy="open">
                            <div class="chat-cg-card-header">
                                <span class="chat-cg-radio"></span> <span data-copy="chat_group_type_open">开放群</span>
                            </div>
                            <div class="chat-cg-card-body">
                                <span class="chat-cg-dot"></span>
                                <span data-copy="chat_group_type_open_desc">可查看成员资料，支持自由加好友</span>
                            </div>
                        </button>
                        <button type="button" class="chat-cg-card active" data-privacy="private">
                            <div class="chat-cg-card-header">
                                <span class="chat-cg-radio"></span> <span data-copy="chat_group_type_private">隐私群</span>
                            </div>
                            <div class="chat-cg-card-body">
                                <span class="chat-cg-dot"></span>
                                <span data-copy="chat_group_type_private_desc">隐藏成员列表，陌生人不可互加</span>
                            </div>
                        </button>
                    </div>

                    <div class="chat-cg-section-title"><span data-copy="chat_run_mode_title">运行模式</span></div>
                    <div class="chat-cg-cards" id="chatCgModeCards">
                        <button type="button" class="chat-cg-card active-light" data-mode="chat">
                            <div class="chat-cg-card-header">
                                <span class="chat-cg-radio"></span> <span data-copy="chat_run_mode_chat">聊天模式</span>
                            </div>
                            <div class="chat-cg-card-body">
                                <span class="chat-cg-dot"></span>
                                <span data-copy="chat_run_mode_chat_desc">自由聊天，可发普通/手气/埋雷红包</span>
                            </div>
                        </button>
                        <button type="button" class="chat-cg-card" data-mode="grab">
                            <div class="chat-cg-card-header">
                                <span class="chat-cg-radio"></span> <span data-copy="chat_run_mode_grab">红包对战模式</span>
                            </div>
                            <div class="chat-cg-card-body">
                                <span class="chat-cg-dot"></span>
                                <span data-copy="chat_run_mode_grab_desc">全员禁言，仅管理员/机器人可发红包</span>
                            </div>
                        </button>
                    </div>

                    <div class="chat-cg-hint" data-copy="chat_create_group_hint">群主可后续在群设置中修改</div>
                </div>
            </div>
        </div>

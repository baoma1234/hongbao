    <!-- 个人中心：编辑资料二级页 -->
    <div class="profile-sub-pane" id="profileInfoPane" aria-hidden="true">
        <div class="profile-sub-hd">
            <button type="button" class="chat-back-btn profile-back-btn" onclick="closeProfileSubPage()" data-copy-aria="profile_back" aria-label="返回">‹</button>
            <div class="profile-sub-title" data-copy="profile_info_title">编辑资料</div>
            <span class="profile-sub-spacer"></span>
        </div>
        <div class="profile-sub-body">
            <div class="match-card profile-card">
                <div class="profile-avatar-wrap" onclick="document.getElementById('profileAvatarInput').click()">
                    <img id="profileEditAvatarImg" class="profile-avatar-img" src="" alt="" style="display:none;">
                    <div id="profileEditAvatarFallback" class="profile-avatar-fallback">?</div>
                    <div class="profile-avatar-hint" data-copy="profile_avatar_hint">点击更换头像</div>
                </div>
                <input type="file" id="profileAvatarInput" accept="image/jpeg,image/png,image/gif,image/webp,image/bmp" hidden>
                <div class="profile-field">
                    <label data-copy="profile_nickname_label">昵称</label>
                    <input type="text" id="profileNicknameInput" maxlength="30" data-copy-placeholder="profile_nickname_placeholder" placeholder="请输入昵称（最多30字）">
                </div>
                <button type="button" class="btn-uid-submit" id="profileSaveBtn" onclick="saveProfileNickname()" data-copy="profile_save_btn">保存资料</button>
            </div>
        </div>
    </div>

    <!-- 个人中心：修改密码二级页 -->
    <div class="profile-sub-pane" id="profilePasswordPane" aria-hidden="true">
        <div class="profile-sub-hd">
            <button type="button" class="chat-back-btn profile-back-btn" onclick="closeProfileSubPage()" data-copy-aria="profile_back" aria-label="返回">‹</button>
            <div class="profile-sub-title" data-copy="profile_password_page_title">修改密码</div>
            <span class="profile-sub-spacer"></span>
        </div>
        <div class="profile-sub-body">
            <div class="match-card profile-card">
                <div class="profile-pwd-modes">
                    <button type="button" class="profile-mode-btn active" data-mode="old" id="profilePwdModeOld" data-copy="profile_pwd_mode_old">旧密码验证</button>
                    <button type="button" class="profile-mode-btn" data-mode="sms" id="profilePwdModeSms" data-copy="profile_pwd_mode_sms">短信验证码</button>
                </div>
                <div class="profile-field" id="profileOldPwdWrap">
                    <label data-copy="profile_old_password_label">旧密码</label>
                    <input type="password" id="profileOldPassword" autocomplete="current-password" data-copy-placeholder="profile_old_password_ph" placeholder="请输入当前密码">
                </div>
                <div class="profile-field" id="profileSmsWrap" style="display:none;">
                    <label data-copy="profile_sms_code_label">短信验证码</label>
                    <div class="profile-sms-row">
                        <input type="text" id="profileSmsCode" inputmode="numeric" maxlength="8" data-copy-placeholder="profile_sms_code_ph" placeholder="请输入验证码">
                        <button type="button" class="btn-captcha" id="profileSmsSendBtn" onclick="sendProfileSmsCode()" data-copy="profile_sms_send_btn">获取验证码</button>
                    </div>
                </div>
                <div class="profile-field">
                    <label data-copy="profile_new_password_label">新密码</label>
                    <input type="password" id="profileNewPassword" autocomplete="new-password" data-copy-placeholder="profile_new_password_ph" placeholder="6-32位新密码">
                </div>
                <div class="profile-field">
                    <label data-copy="profile_confirm_password_label">确认新密码</label>
                    <input type="password" id="profileConfirmPassword" autocomplete="new-password" data-copy-placeholder="profile_confirm_password_ph" placeholder="再次输入新密码">
                </div>
                <button type="button" class="btn-uid-submit" id="profilePasswordBtn" onclick="submitProfilePassword()" data-copy="profile_password_btn">确认修改密码</button>
            </div>
        </div>
    </div>

    <!-- 个人中心：充值 -->
    <div class="profile-sub-pane" id="profileRechargePane" aria-hidden="true">
        <div class="profile-sub-hd">
            <button type="button" class="chat-back-btn profile-back-btn" onclick="closeProfileSubPage()" data-copy-aria="profile_back" aria-label="返回">‹</button>
            <div class="profile-sub-title" data-copy="profile_recharge_title">充值</div>
            <span class="profile-sub-spacer"></span>
        </div>
        <div class="profile-sub-body">
            <div class="match-card profile-card">
                <div class="profile-field">
                    <label data-copy="profile_recharge_channel_label">选择充值通道</label>
                    <div id="profileRechargePartitionTabs" class="wallet-partition-tabs" hidden></div>
                </div>
                <div id="profileRechargeChannels" class="wallet-channel-list"></div>
                <div id="profileRechargeForm" class="wallet-amount-panel" hidden>
                    <div class="profile-meta-line" id="profileRechargeLimitHint"></div>
                    <div class="profile-field">
                        <label data-copy="profile_recharge_amount_label">充值红宝金额（元）</label>
                        <input type="number" id="profileRechargeAmount" step="0.01" min="1" data-copy-placeholder="profile_amount_ph" placeholder="请输入金额">
                    </div>
                    <button type="button" class="btn-uid-submit" id="profileRechargeSubmit" onclick="submitProfileRecharge()" data-copy="profile_recharge_submit">确认充值</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 个人中心：提现 -->
    <div class="profile-sub-pane" id="profileWithdrawPane" aria-hidden="true">
        <div class="profile-sub-hd">
            <button type="button" class="chat-back-btn profile-back-btn" onclick="closeProfileSubPage()" data-copy-aria="profile_back" aria-label="返回">‹</button>
            <div class="profile-sub-title" data-copy="profile_withdraw_title">提现</div>
            <span class="profile-sub-spacer"></span>
        </div>
        <div class="profile-sub-body">
            <div class="match-card profile-card">
                <div class="profile-meta-line"><span data-copy="profile_withdraw_avail_prefix">可提现红宝：</span><strong id="profileWithdrawBalance">￥0.00</strong></div>
                <div class="profile-meta-line" id="profileTurnoverLine" data-turnover-prefix-key="profile_turnover_prefix">累计流水：￥0.00</div>
                    <div class="profile-field">
                        <label data-copy="profile_withdraw_channel_label">选择提现通道</label>
                        <div id="profileWithdrawPartitionTabs" class="wallet-partition-tabs" hidden></div>
                    </div>
                    <div id="profileWithdrawChannels" class="wallet-channel-list"></div>
                    <div id="profileWithdrawForm" class="wallet-amount-panel" hidden>
                    <div class="profile-meta-line" id="profileWithdrawLimitHint"></div>
                    <div class="profile-field">
                        <label data-copy="profile_withdraw_amount_label">提现红宝金额（元）</label>
                        <input type="number" id="profileWithdrawAmount" step="0.01" min="1" data-copy-placeholder="profile_amount_ph" placeholder="请输入金额">
                    </div>
                    <!-- 钱包地址绑定区 -->
                    <div id="profileWithdrawWalletBind" class="wallet-bind-panel" hidden>
                        <div class="profile-meta-line" id="profileWithdrawBindHint" data-copy="wallet_bind_hint">请先绑定该钱包收款地址（每个钱包类型独立，地址不可重复）</div>
                        <div class="wallet-bind-current" id="profileWithdrawBindCurrent" hidden>
                            <div class="profile-meta-line"><span data-copy="wallet_bound_label">已绑定地址：</span><strong id="profileWithdrawBoundAddr">-</strong></div>
                        </div>
                        <div class="wallet-bind-form" id="profileWithdrawBindForm">
                            <div class="profile-field">
                                <label data-copy="wallet_bind_address_label">钱包地址</label>
                                <input type="text" id="profileWithdrawBindAddress" data-copy-placeholder="wallet_bind_address_ph" placeholder="请输入钱包收款地址">
                            </div>
                            <div class="profile-field">
                                <label data-copy="wallet_bind_name_label">备注姓名（可选）</label>
                                <input type="text" id="profileWithdrawBindName" data-copy-placeholder="wallet_bind_name_ph" placeholder="可选">
                            </div>
                            <button type="button" class="btn-uid-submit" id="profileWithdrawBindSubmit" onclick="submitProfileWalletBind()" data-copy="wallet_bind_submit">确认绑定</button>
                        </div>
                    </div>
                    <!-- 常规绑定（支付宝/银行卡） -->
                    <div id="profileWithdrawConventional" class="wallet-conventional-panel">
                    <div class="profile-field" id="profileWithdrawChanelWrap" style="display:none;">
                        <label data-copy="profile_withdraw_method">收款方式</label>
                        <select id="profileWithdrawChanel" class="login-input">
                            <option value="102" data-copy="profile_withdraw_opt_bank">银行卡 (102)</option>
                            <option value="101" data-copy="profile_withdraw_opt_alipay">支付宝 (101)</option>
                        </select>
                    </div>
                    <div class="profile-field">
                        <label data-copy="profile_withdraw_name_label">收款人姓名</label>
                        <input type="text" id="profileWithdrawName" data-copy-placeholder="profile_withdraw_name_ph" placeholder="真实姓名 / 支付宝实名">
                    </div>
                    <div class="profile-field">
                        <label data-copy="profile_withdraw_account_label">收款账号 / 钱包地址</label>
                        <input type="text" id="profileWithdrawAccount" data-copy-placeholder="profile_withdraw_account_ph" placeholder="钱包地址 / 银行卡号 / 支付宝账号">
                    </div>
                    <div class="profile-field" id="profileWithdrawBankWrap">
                        <label data-copy="profile_withdraw_bank_label">银行名称</label>
                        <input type="text" id="profileWithdrawBank" data-copy-placeholder="profile_withdraw_bank_ph" placeholder="钱包通道可填通道名；支付宝填：支付宝">
                    </div>
                    <div class="profile-field" id="profileWithdrawBranchWrap" style="display:none;">
                        <label data-copy="profile_withdraw_branch_label">支行（可选）</label>
                        <input type="text" id="profileWithdrawBranch" data-copy-placeholder="profile_withdraw_branch_ph" placeholder="开户支行">
                    </div>
                    <div class="profile-field" id="profileWithdrawRegionWrap" style="display:none;">
                        <label data-copy="profile_withdraw_region_label">省市（可选）</label>
                        <div class="profile-sms-row">
                            <input type="text" id="profileWithdrawProvince" data-copy-placeholder="profile_withdraw_province_ph" placeholder="省">
                            <input type="text" id="profileWithdrawCity" data-copy-placeholder="profile_withdraw_city_ph" placeholder="市">
                        </div>
                    </div>
                    </div>
                    <button type="button" class="btn-uid-submit" id="profileWithdrawSubmit" onclick="submitProfileWithdraw()" data-copy="profile_withdraw_submit">确认提现</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 个人中心：资金流水 -->
    <div class="profile-sub-pane" id="profileLedgerPane" aria-hidden="true">
        <div class="profile-sub-hd">
            <button type="button" class="chat-back-btn profile-back-btn" onclick="closeProfileSubPage()" data-copy-aria="profile_back" aria-label="返回">‹</button>
            <div class="profile-sub-title" data-copy="profile_ledger_title">资金流水</div>
            <span class="profile-sub-spacer"></span>
        </div>
        <div class="profile-sub-body">
            <div class="wallet-ledger-list" id="profileLedgerList">
                <div class="wallet-ledger-empty" data-copy="profile_ledger_loading">加载中…</div>
            </div>
            <button type="button" class="wallet-ledger-more" id="profileLedgerMoreBtn" style="display:none;" onclick="loadProfileLedgerMore()" data-copy="profile_ledger_more">加载更多</button>
        </div>
    </div>

    <!-- 个人中心：我的二维码 -->
    <div class="profile-sub-pane" id="profileQrPane" aria-hidden="true">
        <div class="profile-sub-hd">
            <button type="button" class="chat-back-btn profile-back-btn" id="profileQrBack" data-copy-aria="profile_back" aria-label="返回">‹</button>
            <div class="profile-sub-title" data-copy="profile_qr_title">我的二维码</div>
            <span class="profile-sub-spacer"></span>
        </div>
        <div class="profile-sub-body">
            <div class="match-card profile-card profile-qr-card">
                <div class="profile-qr-canvas-wrap">
                    <canvas id="profileQrCanvas" width="220" height="220"></canvas>
                </div>
                <div class="profile-qr-uid-line"><span data-copy="profile_qr_uid_prefix">会员ID：</span><strong id="profileQrUid">-</strong></div>
                <p class="profile-qr-tip" id="profileQrTip" data-copy="profile_qr_tip">好友扫一扫即可添加你</p>
                <button type="button" class="btn-uid-submit" id="profileQrCopyBtn" data-copy="profile_qr_copy_btn">复制会员ID</button>
            </div>
        </div>
    </div>

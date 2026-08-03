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

    <!-- 个人中心：支付密码 -->
    <div class="profile-sub-pane" id="profilePayPasswordPane" aria-hidden="true">
        <div class="profile-sub-hd">
            <button type="button" class="chat-back-btn profile-back-btn" onclick="closeProfileSubPage()" data-copy-aria="profile_back" aria-label="返回">‹</button>
            <div class="profile-sub-title" data-copy="profile_pay_password_title">支付密码</div>
            <span class="profile-sub-spacer"></span>
        </div>
        <div class="profile-sub-body">
            <div class="match-card profile-card">
                <p class="profile-meta-line" id="profilePayPasswordHint" data-copy="profile_pay_password_set_hint">首次可直接设置支付密码；用于提现与绑定地址</p>
                <div class="profile-field" id="profilePayPwdSmsWrap" hidden>
                    <label data-copy="profile_sms_code_label">短信验证码</label>
                    <div class="profile-sms-row">
                        <input type="text" id="profilePayPwdSmsCode" inputmode="numeric" maxlength="8" data-copy-placeholder="profile_sms_code_ph" placeholder="请输入验证码">
                        <button type="button" class="btn-captcha" id="profilePayPwdSmsBtn" onclick="sendPayPasswordSms()" data-copy="profile_sms_send_btn">获取验证码</button>
                    </div>
                </div>
                <div class="profile-field">
                    <label data-copy="profile_pay_password_label">支付密码</label>
                    <input type="password" id="profilePayPasswordNew" autocomplete="new-password" maxlength="32" data-copy-placeholder="profile_pay_password_ph" placeholder="6-32位支付密码">
                </div>
                <div class="profile-field">
                    <label data-copy="profile_pay_password_confirm_label">确认支付密码</label>
                    <input type="password" id="profilePayPasswordConfirm" autocomplete="new-password" maxlength="32" data-copy-placeholder="profile_pay_password_confirm_ph" placeholder="再次输入支付密码">
                </div>
                <button type="button" class="btn-uid-submit" id="profilePayPasswordBtn" onclick="submitPayPasswordForm()" data-copy="profile_pay_password_set_btn">设置支付密码</button>
            </div>
        </div>
    </div>

    <!-- 支付密码输入弹层（提现/绑定） -->
    <div id="walletPayPwdModal" class="wallet-paypwd-modal" hidden>
        <div class="wallet-paypwd-sheet" role="dialog" aria-modal="true">
            <div class="wallet-paypwd-title" id="walletPayPwdTitle" data-copy="profile_pay_password_enter_title">请输入支付密码</div>
            <p class="wallet-paypwd-desc" id="walletPayPwdDesc"></p>
            <div class="profile-field">
                <label id="walletPayPwdInputLabel" data-copy="profile_pay_password_label">支付密码</label>
                <input type="password" id="walletPayPwdInput" maxlength="32" data-copy-placeholder="api_pay_password_required" placeholder="请输入支付密码">
            </div>
            <div class="profile-field" id="walletPayPwdConfirmWrap" hidden>
                <label data-copy="profile_pay_password_confirm_label">确认支付密码</label>
                <input type="password" id="walletPayPwdConfirm" maxlength="32" data-copy-placeholder="wallet_paypwd_confirm_ph" placeholder="再次输入">
            </div>
            <div class="wallet-paypwd-actions">
                <button type="button" class="wallet-paypwd-cancel" id="walletPayPwdCancel" data-copy="wallet_paypwd_cancel">取消</button>
                <button type="button" class="btn-uid-submit wallet-paypwd-ok" id="walletPayPwdOk" data-copy="wallet_paypwd_ok">确认</button>
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
                    <div class="profile-field">
                        <label data-copy="profile_recharge_amount_label">充值红宝金额（元）</label>
                        <div id="profileRechargeQuickAmounts" class="wallet-quick-amounts" aria-label="快捷金额"></div>
                        <input type="number" id="profileRechargeAmount" step="0.01" min="1" data-copy-placeholder="profile_amount_ph" placeholder="请输入金额">
                        <div class="profile-meta-line wallet-fx-hint" id="profileRechargeFxHint" hidden></div>
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
                    <!-- 钱包地址绑定区（先绑定再填金额） -->
                    <div id="profileWithdrawWalletBind" class="wallet-bind-panel" hidden>
                        <div class="profile-meta-line" id="profileWithdrawBindHint" data-copy="wallet_bind_unbound_hint">请先为该钱包绑定收款地址，每种钱包类型独立绑定，地址不可重复使用。</div>
                        <div class="wallet-bind-current" id="profileWithdrawBindCurrent" hidden>
                            <div class="profile-meta-line"><span id="profileWithdrawBoundLabel" data-copy="wallet_bound_label">钱包地址：</span><strong id="profileWithdrawBoundAddr">-</strong></div>
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
                    <!-- 线上合作（主站账号提现） -->
                    <div id="profileWithdrawOnlineCoop" class="wallet-online-coop-panel" hidden>
                        <div class="profile-field">
                            <label data-copy="profile_withdraw_platform_label">合作平台</label>
                            <select id="profileWithdrawPlatform" class="login-input">
                                <option value="555" selected>555</option>
                            </select>
                        </div>
                        <div class="profile-field">
                            <label data-copy="profile_withdraw_main_uid_label">主站账号</label>
                            <input type="text" id="profileWithdrawMainUid" data-copy-placeholder="profile_withdraw_main_uid_ph" placeholder="请输入已绑定的主站账号" readonly>
                        </div>
                        <div class="profile-meta-line" id="profileWithdrawOnlineHint" data-copy="profile_withdraw_online_hint">仅已绑定主站账号的用户可使用线上合作提现，提交后需人工审核出款。</div>
                    </div>
                    <div id="profileWithdrawAmountGate" class="wallet-amount-gate" hidden>
                    <div class="profile-field">
                        <label data-copy="profile_withdraw_amount_label">提现红宝金额（元）</label>
                        <input type="number" id="profileWithdrawAmount" step="0.01" min="1" data-copy-placeholder="profile_amount_ph" placeholder="请输入金额">
                        <div class="profile-meta-line wallet-fx-hint" id="profileWithdrawFxHint" hidden></div>
                    </div>
                    <div class="profile-meta-line wallet-withdraw-verify-addr" id="profileWithdrawVerifyAddrWrap" hidden>
                        <span id="profileWithdrawVerifyAddrLabel" data-copy="wallet_bind_address_label">钱包地址</span>
                        <strong id="profileWithdrawVerifyAddr">-</strong>
                    </div>
                    <button type="button" class="btn-uid-submit" id="profileWithdrawSubmit" onclick="submitProfileWithdraw()" data-copy="profile_withdraw_submit">确认提现</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 个人中心：钱包地址绑定 -->
    <div class="profile-sub-pane" id="profilePayeePane" aria-hidden="true">
        <div class="profile-sub-hd">
            <button type="button" class="chat-back-btn profile-back-btn" onclick="closeProfileSubPage()" data-copy-aria="profile_back" aria-label="返回">‹</button>
            <div class="profile-sub-title" data-copy="profile_payee_title">钱包地址</div>
            <span class="profile-sub-spacer"></span>
        </div>
        <div class="profile-sub-body">
            <div class="match-card profile-card">
                <div class="wallet-payee-tabs" id="profilePayeeTabs" role="tablist">
                    <button type="button" class="wallet-payee-tab active" data-payee-tab="bank" data-copy="profile_payee_tab_bank">银行卡</button>
                    <button type="button" class="wallet-payee-tab" data-payee-tab="wallet" data-copy="profile_payee_tab_wallet">数字钱包</button>
                </div>

                <div class="wallet-payee-panel" id="profilePayeePanelBank" data-payee-panel="bank">
                    <div class="profile-meta-line" id="profilePayeeBankBound" hidden></div>
                    <div class="profile-field">
                        <label data-copy="profile_payee_bank_name_label">开户名</label>
                        <input type="text" id="profilePayeeBankAccountName" data-copy-placeholder="profile_payee_bank_name_ph" placeholder="持卡人姓名">
                    </div>
                    <div class="profile-field">
                        <label data-copy="profile_payee_bank_no_label">银行卡号</label>
                        <input type="text" id="profilePayeeBankAccountNo" data-copy-placeholder="profile_payee_bank_no_ph" placeholder="请输入银行卡号">
                    </div>
                    <div class="profile-field">
                        <label data-copy="profile_payee_bank_label">开户行</label>
                        <input type="text" id="profilePayeeBankName" data-copy-placeholder="profile_payee_bank_ph" placeholder="如：中国工商银行">
                    </div>
                    <button type="button" class="btn-uid-submit" onclick="submitProfilePayeeBind('bank')" data-copy="profile_payee_save_bank">保存银行卡</button>
                </div>

                <div class="wallet-payee-panel" id="profilePayeePanelWallet" data-payee-panel="wallet" hidden>
                    <p class="profile-meta-line" data-copy="profile_payee_wallet_hint">选择钱包类型，展示并管理已绑定地址（与提现钱包一致）</p>
                    <div id="profilePayeeWalletTypes" class="wallet-channel-list is-grid"></div>
                    <div id="profilePayeeWalletForm" class="wallet-bind-panel" hidden>
                        <div class="profile-meta-line"><span data-copy="profile_payee_wallet_type_label">当前钱包：</span><strong id="profilePayeeWalletTypeLabel">-</strong></div>
                        <div class="profile-meta-line" id="profilePayeeWalletBoundLine" hidden>
                            <span id="profilePayeeWalletBoundLabel" data-copy="wallet_bound_label">钱包地址：</span><strong id="profilePayeeWalletBoundAddr">-</strong>
                        </div>
                        <div id="profilePayeeWalletSingleFields">
                            <div class="profile-field">
                                <label id="profilePayeeWalletAddressLabel" data-copy="wallet_bind_address_label">钱包地址</label>
                                <input type="text" id="profilePayeeWalletAddress" data-copy-placeholder="wallet_bind_address_ph" placeholder="请输入钱包收款地址">
                            </div>
                            <div class="profile-field">
                                <label data-copy="wallet_bind_name_label">备注姓名（可选）</label>
                                <input type="text" id="profilePayeeWalletName" data-copy-placeholder="wallet_bind_name_ph" placeholder="可选">
                            </div>
                        </div>
                        <div id="profilePayeeUsdtChainFields" hidden>
                            <p class="profile-meta-line" data-copy="profile_payee_usdt_hint">USDT 分三条填写：TRC20 / ERC20 / TON，可只绑其中一条或几条</p>
                            <div class="profile-field">
                                <label data-copy="profile_payee_usdt_trc20_label">TRC20 地址</label>
                                <input type="text" id="profilePayeeUsdtTrc20" data-copy-placeholder="profile_payee_usdt_trc20_ph" placeholder="USDT-TRC20 收款地址">
                            </div>
                            <div class="profile-field">
                                <label data-copy="profile_payee_usdt_erc20_label">ERC20 地址</label>
                                <input type="text" id="profilePayeeUsdtErc20" data-copy-placeholder="profile_payee_usdt_erc20_ph" placeholder="USDT-ERC20 收款地址">
                            </div>
                            <div class="profile-field">
                                <label data-copy="profile_payee_usdt_ton_label">TON 地址</label>
                                <input type="text" id="profilePayeeUsdtTon" data-copy-placeholder="profile_payee_usdt_ton_ph" placeholder="USDT-TON 收款地址">
                            </div>
                            <div class="profile-field">
                                <label data-copy="wallet_bind_name_label">备注姓名（可选）</label>
                                <input type="text" id="profilePayeeUsdtName" data-copy-placeholder="wallet_bind_name_ph" placeholder="可选">
                            </div>
                        </div>
                        <button type="button" class="btn-uid-submit" onclick="submitProfilePayeeBind('wallet')" data-copy="wallet_bind_submit">确认绑定</button>
                    </div>
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

    <div class="page-view active" id="loginView">
        <div class="login-wrapper">
            <div class="login-brand">
                <img class="login-logo-img" src="img/logo.png?v=202607281935" width="120" height="120" alt="红宝" decoding="async">
                <div class="login-logo" data-copy="brand_name">红宝</div>
            </div>
            <div class="login-subtitle" data-copy="login_subtitle">🛡️ 官方福利通道激活：请输您的手机号码</div>
            
            <div class="input-group">
                <label class="input-label" data-copy="login_phone_label">📱 会员登记：请输入您的手机号码</label>
                <div class="phone-row">
                    <select id="countrySelect" class="country-select" data-copy-aria="aria_country_select" aria-label="选择国家区号"></select>
                    <input type="tel" id="loginPhone" class="login-input" data-copy-placeholder="login_phone_placeholder" placeholder="请输入11位中国大陆手机号" maxlength="11" inputmode="numeric">
                </div>
            </div>
            
            <div class="input-group">
                <label class="input-label" data-copy="login_captcha_label">🔑 动态安全验校</label>
                <input type="number" id="loginCaptcha" class="login-input" data-copy-placeholder="login_captcha_placeholder" placeholder="请输入短信验证码" maxlength="6">
                <button class="captcha-btn" id="captchaBtn" onclick="sendMockCaptcha()" data-copy="login_captcha_btn">获取验证码</button>
            </div>
            
            <button class="btn-login-submit" id="loginSubmitBtn" onclick="submitLogin()" data-copy="login_submit_btn">进入官方福利大厅 ｜ 瓜分888888彩金</button>
        </div>
    </div>


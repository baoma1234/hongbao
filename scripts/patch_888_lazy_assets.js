const fs = require('fs');
const corePath = 'c:/wwwroot/caijin.com_7111/public/888/js/app-core.js';
const bootPath = 'c:/wwwroot/caijin.com_7111/public/888/js/app-boot.js';
let core = fs.readFileSync(corePath, 'utf8').replace(/\r\n/g, '\n');
let boot = fs.readFileSync(bootPath, 'utf8').replace(/\r\n/g, '\n');

function mustReplace(src, oldStr, newStr, label) {
  if (src.includes(newStr.slice(0, 40)) && label !== 'exports') {
    // already patched loosely
  }
  if (!src.includes(oldStr)) {
    throw new Error(label + ' old block not found');
  }
  return src.replace(oldStr, newStr);
}

if (!core.includes('window.CONFIG = CONFIG')) {
  core = mustReplace(
    core,
    'SMS_SEND_INTERVAL: 60\n        };',
    'SMS_SEND_INTERVAL: 60\n        };\n        window.CONFIG = CONFIG;',
    'CONFIG'
  );
  console.log('patched CONFIG');
}

if (!core.includes('FansHubAssets.ensureChat')) {
  core = mustReplace(
    core,
    `            if (tab === 'claim') syncClaimPageEcho();
            if (tab === 'messages' && window.FansHubChat) {
                FansHubChat.onTabEnter();
            }
            if (tab === 'master' && phase2State && phase2State.user_mode === 'master') {
                if (!teamRadarLoaded && typeof loadTeamRadar === 'function') {
                    teamRadarLoaded = true;
                    loadTeamRadar();
                }
            }
            if (tab === 'exchange' && typeof refreshPriceLinkedHints === 'function') {
                refreshPriceLinkedHints();
            }
            if (tab === 'profile') {
                closeProfileSubPage();
                renderProfilePanel();
            }
        }`,
    `            if (tab === 'claim') syncClaimPageEcho();
            if (tab === 'messages') {
                if (window.FansHubAssets && typeof FansHubAssets.ensureChat === 'function') {
                    FansHubAssets.ensureChat().then(function () {
                        if (window.FansHubChat) FansHubChat.onTabEnter();
                    }).catch(function (e) {
                        console.warn('chat load fail', e);
                        showFanshubToast((e && e.message) || '消息模块加载失败', 'error');
                    });
                } else if (window.FansHubChat) {
                    FansHubChat.onTabEnter();
                }
            }
            if (tab === 'master' && phase2State && phase2State.user_mode === 'master') {
                if (!teamRadarLoaded && typeof loadTeamRadar === 'function') {
                    teamRadarLoaded = true;
                    loadTeamRadar();
                }
            }
            if (tab === 'exchange' && typeof refreshPriceLinkedHints === 'function') {
                refreshPriceLinkedHints();
            }
            if (tab === 'profile') {
                closeProfileSubPage();
                renderProfilePanel();
                if (window.FansHubAssets && typeof FansHubAssets.ensureWallet === 'function') {
                    FansHubAssets.ensureWallet().catch(function (e) {
                        console.warn('wallet load fail', e);
                    });
                }
            }
        }`,
    'switchTab'
  );
  console.log('patched switchTab');
}

if (!core.includes("which === 'recharge' || which === 'withdraw'")) {
  core = mustReplace(
    core,
    `        function openProfileSubPage(which) {
            closeProfileSubPage();
            const id = which === 'password' ? 'profilePasswordPane' : 'profileInfoPane';
            const pane = document.getElementById(id);
            if (!pane) return;
            renderProfilePanel();
            if (which === 'password') setProfilePwdMode(profilePwdMode || 'old');
            pane.classList.add('open');
            pane.setAttribute('aria-hidden', 'false');
            if (typeof setBottomActionBarVisible === 'function') setBottomActionBarVisible(false);
        }`,
    `        function openProfileSubPage(which) {
            if (which === 'recharge' || which === 'withdraw') {
                var openWallet = function () {
                    if (typeof window.openProfileWalletPage === 'function') {
                        window.openProfileWalletPage(which);
                        return;
                    }
                    showFanshubToast('钱包模块加载失败', 'error');
                };
                if (window.FansHubAssets && typeof FansHubAssets.ensureWallet === 'function') {
                    FansHubAssets.ensureWallet().then(openWallet).catch(function (e) {
                        console.warn('wallet load fail', e);
                        showFanshubToast((e && e.message) || '钱包模块加载失败', 'error');
                    });
                    return;
                }
                openWallet();
                return;
            }
            closeProfileSubPage();
            const id = which === 'password' ? 'profilePasswordPane' : 'profileInfoPane';
            const pane = document.getElementById(id);
            if (!pane) return;
            renderProfilePanel();
            if (which === 'password') setProfilePwdMode(profilePwdMode || 'old');
            pane.classList.add('open');
            pane.setAttribute('aria-hidden', 'false');
            if (typeof setBottomActionBarVisible === 'function') setBottomActionBarVisible(false);
        }
        window.openProfileSubPage = openProfileSubPage;`,
    'openProfileSubPage'
  );
  console.log('patched openProfileSubPage');
}

if (!core.includes('window.switchTab = switchTab')) {
  core = mustReplace(
    core,
    'window.apiRequest = apiRequest;',
    `window.apiRequest = apiRequest;
        window.formatMoney = formatMoney;
        window.switchTab = switchTab;
        window.closeProfileSubPage = closeProfileSubPage;
        window.showFanshubToast = showFanshubToast;`,
    'exports'
  );
  console.log('patched exports');
}

fs.writeFileSync(corePath, core, 'utf8');

const loginSnippetNew = `if (window.FansHubAssets && typeof FansHubAssets.ensureChat === 'function') {
                    FansHubAssets.ensureChat().then(function () {
                        if (window.FansHubChat) FansHubChat.onLogin();
                    }).catch(function (e) { console.warn('chat load fail', e); });
                } else if (window.FansHubChat) {
                    FansHubChat.onLogin();
                }`;

let count = 0;
boot = boot.replace(/if \(window\.FansHubChat\) FansHubChat\.onLogin\(\);/g, () => {
  count += 1;
  return loginSnippetNew;
});
fs.writeFileSync(bootPath, boot, 'utf8');
console.log('done core yen', (core.match(/\u00a5|\uffe5/g) || []).length, 'boot onLogin', count);

// rebuild chat syntax file properly
const parts = ['01-core.js', '02-room.js', '03-rp.js', '04-net.js'].map((f) =>
  fs.readFileSync('c:/wwwroot/caijin.com_7111/public/888/js/chat/' + f, 'utf8')
);
const code = "(function (global) {\n'use strict';\n" + parts.join('\n') + '\n})(window);';
fs.writeFileSync('c:/wwwroot/caijin.com_7111/public/888/js/chat/_syntax_check.js', code, 'utf8');
console.log('syntax file bytes', Buffer.byteLength(code, 'utf8'));

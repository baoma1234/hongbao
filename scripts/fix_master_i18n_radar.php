<?php
/**
 * Master page i18n (EN phase2 keys) + patch virtual team radar + locale refresh.
 */
$root = dirname(__DIR__);

$enPath = $root . '/application/extra/i18n/en-PH.php';
$en = include $enPath;

$enAdd = [
    'page_hero_master_title' => '👑 Master Hall · Phase 2',
    'page_hero_master_sub' => 'Honor ladder · 7-day spark blitz · Team revive radar',
    'master_lock_title' => '🔒 Master channel locked',
    'master_lock_desc' => 'After your first VIP claim, unlock: honor chests, 7-day spark blitz (¥175 pool), and team revive radar. Head to Claim and hit the threshold first!',
    'master_lock_btn' => 'Go to Claim',
    'phase2_honor_title' => '🏅 Long-term Master Honor Ladder',
    'phase2_honor_hint' => 'Tap to copy your promo code and climb the ladder',
    'phase2_honor_capped' => '👑 Ladder capped · pack up to ¥{pack_total} · tap to copy promo code',
    'phase2_honor_progress' => '{count} formed · {need} more to unlock [{name}] for ¥{pack_total} · tap to copy',
    'phase2_honor_name_1' => 'Bronze Master',
    'phase2_honor_name_2' => 'Platinum Master',
    'phase2_honor_name_3' => 'Diamond Master',
    'phase2_honor_name_4' => 'Challenger',
    'phase2_honor_name_5' => 'Glory King',
    'phase2_honor_reward_full' => 'Unlock {rights} shares (¥{rights_val}) + ¥{balance} cash',
    'phase2_honor_reward_rights' => 'Unlock {rights} shares (¥{rights_val})',
    'phase2_honor_people' => '({count} ppl)',
    'phase2_checkin_streak' => '🔥 7-day spark: day {streak} / 7',
    'phase2_checkin_ledger' => '🔥 Ledger: day {streak} locked. Hit 7 violent check-ins for the 5× pool — guaranteed ¥{jackpot}. Miss a day and you lose ¥{loss}. Keep posting daily!',
    'phase2_checkin_toggle' => 'Enable [5× violent share check-in] (today up to ¥{amount})',
    'phase2_checkin_violent_btn' => '🔘 Run [Violent share check-in] — copy today’s code · lock 7-day streak',
    'phase2_checkin_normal_btn' => '⚪ [Normal check-in] only (¥1 today · forfeit ¥175 7-day prize)',
    'phase2_checkin_done_btn' => '✅ Checked in today ({mode}) · tap to copy promo again',
    'phase2_checkin_mode_violent' => 'Violent',
    'phase2_checkin_mode_normal' => 'Normal',
    'phase2_checkin_frozen' => '⚠️ Streak frozen: invite {need} more today to revive the ¥175 blitz',
    'phase2_checkin_revive_ready' => '🔥 2 invites today — blitz status will auto-revive',
    'phase2_checkin_pending' => '🔒 Today’s violent box: ¥{amount} (waiting for new sign-ups…)',
    'phase2_checkin_pending_ok' => '✅ Cleared — extra ¥{amount} credited',
    'phase2_radar_title' => '📡 Team revive radar · live list',
    'phase2_radar_empty' => 'No direct downlines yet — copy your code and invite',
    'phase2_radar_progress' => 'Progress: {balance} / {threshold}',
    'phase2_radar_done' => 'Ready ✅',
    'phase2_radar_urge' => 'Nudge to claim',
    'phase2_radar_fail' => 'Failed to load',
    'phase2_toast_urge_ok' => 'Nudge text copied — @ them in the group!',
    'phase2_toast_promo_ok' => 'Promo code copied — share in groups!',
    'phase2_toast_checkin_ok' => 'Check-in OK · promo code copied',
    'phase2_toast_copy_fail' => 'Copy failed',
    'phase2_urge_copy' => 'Almost at the claim threshold — open the rewards hall here: {link}',
    'phase2_btn_know' => 'Got it',
    'phase2_btn_enter_master' => 'Enter Master Hall',
    'loading_generic' => 'Loading...',
];

$en = array_merge($en, $enAdd);
file_put_contents($enPath, "<?php\nreturn " . var_export($en, true) . ";\n");
echo "en=" . count($en) . "\n";

$corePath = $root . '/public/888/js/app-core.js';
$core = file_get_contents($corePath);

$oldRadar = <<<'JS'
        async function loadTeamRadar() {
            const box = document.getElementById('teamRadarList');
            if (!box) return;
            try {
                const data = await apiRequest('teamradar', 'GET');
                const list = data.list || [];
                if (!list.length) {
                    box.innerHTML = '<div style="font-size:12px;color:var(--text-muted);">' + (fc('phase2_radar_empty') || '鏆傛棤鐩村睘涓嬬嚎锛屽揩鍘诲鍒跺瘑浠ゆ媺浜哄崰浣') + '</div>';
                    return;
                }
                box.innerHTML = list.map(function(row) {
                    const prog = fc('phase2_radar_progress', {
                        balance: row.balance.toFixed(2),
                        threshold: row.threshold.toFixed(0)
                    }) || ('进度：' + row.balance.toFixed(2) + ' / ' + row.threshold.toFixed(0) + ' 元');
                    const right = row.withdrawn
                        ? '<span class="team-radar-done">' + (fc('phase2_radar_done') || '已达标 ✅') + '</span>'
                        : '<button type="button" class="btn-urge" onclick="urgeTeammate(' + row.user_id + ')">' + (fc('phase2_radar_urge') || '一键催促老哥提现') + '</button>';
                    return '<div class="team-radar-row"><div>\uD83D\uDCF1 ' + row.mobile_mask + '<br>' + prog + '</div>' + right + '</div>';
                }).join('');
            } catch (e) {
                box.innerHTML = '<div style="font-size:12px;color:var(--danger);">' + (fc('phase2_radar_fail') || '鍔犺浇澶辫触') + '</div>';
            }
        }
JS;

// Match whatever garbled fallback is currently in file by reading actual function
if (!preg_match('/async function loadTeamRadar\(\) \{.*?\n        \}/s', $core, $m)) {
    echo "radar_fn=0\n";
} else {
    echo "radar_fn_len=" . strlen($m[0]) . "\n";
}

$newRadar = <<<'JS'
        function teamRadarDaySeed() {
            const d = new Date();
            return d.getFullYear() * 10000 + (d.getMonth() + 1) * 100 + d.getDate();
        }

        function teamRadarRng(seed) {
            let a = (seed >>> 0) || 1;
            return function () {
                a |= 0;
                a = (a + 0x6D2B79F5) | 0;
                let t = Math.imul(a ^ (a >>> 15), 1 | a);
                t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
                return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
            };
        }

        /** 虚拟战队雷达：多国脱敏号 + 接近门槛进度 */
        function buildVirtualTeamRadar(limit) {
            limit = Math.max(3, Math.min(12, limit || 8));
            const rnd = teamRadarRng(teamRadarDaySeed() ^ 0x7EADA1);
            const threshold = Number(CONFIG.WITHDRAW_THRESHOLD) || 50;
            const pools = [
                { dial: '+86', heads: ['130', '135', '136', '137', '138', '139', '150', '158', '186', '188'], tailLen: 4 },
                { dial: '+63', heads: ['905', '906', '915', '917', '918', '919', '920', '921'], tailLen: 4 },
                { dial: '+84', heads: ['90', '91', '93', '96', '97', '98', '32', '33'], tailLen: 4 },
                { dial: '+60', heads: ['10', '11', '12', '13', '16', '17', '18', '19'], tailLen: 4 },
                { dial: '+855', heads: ['10', '11', '12', '15', '16', '17', '70', '77'], tailLen: 3 },
                { dial: '+62', heads: ['812', '813', '815', '816', '817', '818', '821', '822'], tailLen: 4 }
            ];
            const fracs = [0.96, 0.88, 0.76, 0.64, 0.52, 0.41, 0.33, 1.0, 0.28, 0.22, 0.18, 0.12];
            const rows = [];
            for (let i = 0; i < limit; i++) {
                const pool = pools[Math.floor(rnd() * pools.length)];
                const head = pool.heads[Math.floor(rnd() * pool.heads.length)];
                let tail = '';
                for (let t = 0; t < pool.tailLen; t++) tail += String(Math.floor(rnd() * 10));
                const frac = fracs[i] != null ? fracs[i] : (0.2 + rnd() * 0.5);
                let bal = Math.round(threshold * frac * 100) / 100;
                if (frac >= 1) bal = threshold;
                else bal = Math.min(threshold - 0.5, Math.max(1, bal + Math.floor(rnd() * 3) - 1));
                const withdrawn = bal >= threshold;
                rows.push({
                    user_id: -(i + 1),
                    mobile_mask: pool.dial + ' ' + head + '****' + tail,
                    balance: bal,
                    threshold: threshold,
                    withdrawn: withdrawn,
                    virtual: true
                });
            }
            rows.sort(function (a, b) {
                if (a.withdrawn !== b.withdrawn) return a.withdrawn ? 1 : -1;
                return b.balance - a.balance;
            });
            return rows;
        }

        function renderTeamRadarList(box, list) {
            if (!box) return;
            if (!list || !list.length) {
                box.innerHTML = '<div style="font-size:12px;color:var(--text-muted);">' + (fc('phase2_radar_empty') || '') + '</div>';
                return;
            }
            box.innerHTML = list.map(function (row) {
                const bal = Number(row.balance) || 0;
                const th = Number(row.threshold) || (Number(CONFIG.WITHDRAW_THRESHOLD) || 50);
                const prog = fc('phase2_radar_progress', {
                    balance: bal.toFixed(2),
                    threshold: th.toFixed(0)
                }) || ('Progress: ' + bal.toFixed(2) + ' / ' + th.toFixed(0));
                let right;
                if (row.withdrawn) {
                    right = '<span class="team-radar-done">' + (fc('phase2_radar_done') || 'Ready') + '</span>';
                } else if (row.virtual) {
                    right = '<button type="button" class="btn-urge" onclick="urgeVirtualTeammate()">' + (fc('phase2_radar_urge') || 'Nudge') + '</button>';
                } else {
                    right = '<button type="button" class="btn-urge" onclick="urgeTeammate(' + (row.user_id | 0) + ')">' + (fc('phase2_radar_urge') || 'Nudge') + '</button>';
                }
                return '<div class="team-radar-row"><div>\uD83D\uDCF1 ' + row.mobile_mask + '<br>' + prog + '</div>' + right + '</div>';
            }).join('');
        }

        async function loadTeamRadar() {
            const box = document.getElementById('teamRadarList');
            if (!box) return;
            let list = buildVirtualTeamRadar(8);
            try {
                const data = await apiRequest('teamradar', 'GET');
                const real = (data && data.list) || [];
                if (Array.isArray(real) && real.length) {
                    const map = {};
                    real.forEach(function (r) {
                        if (!r || !r.mobile_mask) return;
                        map[r.mobile_mask] = {
                            user_id: r.user_id,
                            mobile_mask: r.mobile_mask,
                            balance: Number(r.balance) || 0,
                            threshold: Number(r.threshold) || (Number(CONFIG.WITHDRAW_THRESHOLD) || 50),
                            withdrawn: !!r.withdrawn,
                            virtual: false
                        };
                    });
                    list.forEach(function (v) {
                        if (!map[v.mobile_mask]) map[v.mobile_mask] = v;
                    });
                    list = Object.keys(map).map(function (k) { return map[k]; });
                    list.sort(function (a, b) {
                        if (!!a.withdrawn !== !!b.withdrawn) return a.withdrawn ? 1 : -1;
                        return (b.balance || 0) - (a.balance || 0);
                    });
                    list = list.slice(0, 10);
                }
            } catch (e) {
                // keep virtual
            }
            renderTeamRadarList(box, list);
        }

        async function urgeVirtualTeammate() {
            try {
                const link = (CONFIG && CONFIG.SHARE_LINK) || (location.origin + location.pathname);
                const text = fc('phase2_urge_copy', { link: link }) || link;
                if (typeof copyTextSilent === 'function') await copyTextSilent(text);
                else if (navigator.clipboard && navigator.clipboard.writeText) await navigator.clipboard.writeText(text);
                showFanshubToast(fc('phase2_toast_urge_ok') || 'Copied', 'success');
            } catch (e) {
                showFanshubToast(fc('phase2_toast_copy_fail') || 'Copy failed', 'error');
            }
        }
JS;

if (preg_match('/async function loadTeamRadar\(\) \{.*?\n        \}\n\n        async function urgeTeammate/s', $core)) {
    $core = preg_replace(
        '/async function loadTeamRadar\(\) \{.*?\n        \}\n\n        async function urgeTeammate/s',
        $newRadar . "\n\n        async function urgeTeammate",
        $core,
        1
    );
    echo "radar_patched=1\n";
} elseif (strpos($core, 'buildVirtualTeamRadar') !== false) {
    echo "radar_patched=already\n";
} else {
    echo "radar_patched=0\n";
}

// Hook master panels into updateDynamicCopy
$marker = '/* master-i18n-refresh */';
if (strpos($core, $marker) === false) {
    $needle = "            if (typeof loadLeaderboard === 'function') {\n                try { loadLeaderboard(); } catch (eLb) {}\n            }\n        }";
    $inject = "            if (typeof loadLeaderboard === 'function') {\n                try { loadLeaderboard(); } catch (eLb) {}\n            }\n"
        . "            " . $marker . "\n"
        . "            if (phase2State && phase2State.user_mode === 'master') {\n"
        . "                try {\n"
        . "                    if (typeof renderHonorLadder === 'function') renderHonorLadder(phase2State.honor || {});\n"
        . "                    if (typeof renderCheckinPanel === 'function') renderCheckinPanel(phase2State.checkin || {});\n"
        . "                    if (typeof loadTeamRadar === 'function') loadTeamRadar();\n"
        . "                } catch (eMaster) {}\n"
        . "            }\n"
        . "        }";
    if (strpos($core, $needle) !== false) {
        $core = str_replace($needle, $inject, $core);
        echo "dynamic_master=1\n";
    } else {
        // fallback without loadLeaderboard
        $needle2 = "            if (typeof updateUidStatusHint === 'function') {\n                try { updateUidStatusHint(typeof account !== 'undefined' ? account : null); } catch (e3) {}\n            }\n        }";
        $inject2 = "            if (typeof updateUidStatusHint === 'function') {\n                try { updateUidStatusHint(typeof account !== 'undefined' ? account : null); } catch (e3) {}\n            }\n"
            . "            " . $marker . "\n"
            . "            if (phase2State && phase2State.user_mode === 'master') {\n"
            . "                try {\n"
            . "                    if (typeof renderHonorLadder === 'function') renderHonorLadder(phase2State.honor || {});\n"
            . "                    if (typeof renderCheckinPanel === 'function') renderCheckinPanel(phase2State.checkin || {});\n"
            . "                    if (typeof loadTeamRadar === 'function') loadTeamRadar();\n"
            . "                } catch (eMaster) {}\n"
            . "            }\n"
            . "        }";
        if (strpos($core, $needle2) !== false) {
            $core = str_replace($needle2, $inject2, $core);
            echo "dynamic_master=1alt\n";
        } else {
            echo "dynamic_master=0\n";
        }
    }
} else {
    echo "dynamic_master=already\n";
}

// expose urgeVirtualTeammate
if (strpos($core, 'window.urgeVirtualTeammate') === false && strpos($core, 'function urgeVirtualTeammate') !== false) {
    $core = str_replace(
        'async function urgeVirtualTeammate()',
        "window.urgeVirtualTeammate = urgeVirtualTeammate;\n        async function urgeVirtualTeammate()",
        $core
    );
    // that would break - urgeVirtualTeammate used before assignment. Better append at end of function block:
    $core = str_replace(
        "window.urgeVirtualTeammate = urgeVirtualTeammate;\n        async function urgeVirtualTeammate()",
        'async function urgeVirtualTeammate()',
        $core
    );
}
if (strpos($core, 'window.urgeVirtualTeammate') === false) {
    $core = str_replace(
        "showFanshubToast(fc('phase2_toast_copy_fail') || 'Copy failed', 'error');\n            }\n        }\n\n        async function urgeTeammate",
        "showFanshubToast(fc('phase2_toast_copy_fail') || 'Copy failed', 'error');\n            }\n        }\n        window.urgeVirtualTeammate = urgeVirtualTeammate;\n        window.loadTeamRadar = loadTeamRadar;\n\n        async function urgeTeammate",
        $core
    );
    echo "window_expose=" . (strpos($core, 'window.urgeVirtualTeammate') !== false ? '1' : '0') . "\n";
}

file_put_contents($corePath, $core);

$index = file_get_contents($root . '/public/888/index.php');
$index = preg_replace('/\$assetVer\s*=\s*[\'"][^\'"]+[\'"]/', "\$assetVer = '202607252200'", $index, 1);
file_put_contents($root . '/public/888/index.php', $index);
echo "assetVer=202607252200\n";
echo "DONE_WRITE\n";

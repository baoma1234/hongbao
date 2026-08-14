<?php
$path = dirname(__DIR__) . '/public/888/index.html';
$lines = file($path);
$fixes = [
    2459 => "            var currency = (window.FanshubI18n && FanshubI18n.currencySymbol()) || '￥';\n",
    2496 => "            const sym = (window.FanshubI18n && FanshubI18n.currencySymbol()) || '￥';\n",
    2593 => "                }) || ('当前全网股份人数：' + formatCountNum(fissionUserCount) + ' 人 ( 🚀 今日暴涨 +' + formatCountNum(partnerTodayUp) + ' 人 )');\n",
    2612 => "                }) || ('今日大盘实时持仓行权价：￥' + p2 + ' / 份 ( 🔥 较昨日大盘拉升 +' + pct + '% )');\n",
    2680 => "            node.textContent = text || ('（ 当前估值：￥' + amt.toFixed(2) + ' 元 ）');\n",
    2751 => "            if (shares) shares.textContent = fc('lottery_shares_locked', { shares: LOTTERY_SHARES.toFixed(2) }) || ('锁定 ' + LOTTERY_SHARES.toFixed(2) + ' 份');\n",
    2968 => "                badge: fc('open_account_badge_fallback', { open_account_rights: rights }) || ('立得 ' + rights + ' 份大盘股。')\n",
    3101 => "                        || ('已达标￥' + thr + ' · 可直接申请 VIP 特权领取');\n",
    3104 => "                        || ('还差 ￥' + Math.max(0, thr - bal).toFixed(2) + ' 达标 · 先去闪兑攒余额');\n",
    3107 => "                        || ('满￥' + thr + ' 可发起 VIP 特权领取');\n",
    3671 => "                        }) || ('👑 荣誉团长 · 已邀' + (p2.total_register_count || rank.invite_count || 0) + '人');\n",
    4029 => "                    }) || ('解锁 ' + (rightsText || 0) + '股（￥' + rightsVal + '）并 额外直发 ' + bal + '元 现金'))\n",
    4033 => "                    }) || ('解锁 ' + (rightsText || 0) + '股（￥' + rightsVal + '）'));\n",
    4084 => "            if (streakLabel) streakLabel.textContent = fc('phase2_checkin_streak', { streak: streak }) || ('🔥 7天星火暴击：连续 ' + streak + ' / 7 天');\n",
    4091 => "                }) || ('🔥 终极账本：今日已签' + streak + '天。连续暴击打卡满7天，终极触发5倍核爆总池，直接保底到账￥75.00元密码箱提现！由于断签会导致大奖降级、直接损失￥40.00元，请务必每天保持发圈不中断！');\n",
    4098 => "                        ? (fc('phase2_checkin_frozen', { need: need }) || ('⚠️ 断签冻结中：今日再拉 ' + need + ' 人注册可复活 175 元暴击资格'))\n",
    4113 => "                    || ('✓ 今日已签到(' + mode + ') · 点击再复制推广链接');\n",
    4124 => "                    pending.textContent = fc('phase2_checkin_pending', { amount: bonusAmt }) || ('⏳ 今日暴力对账中：￥' + bonusAmt + ' 元(等待散户新客注册中… ⏱)');\n",
    4127 => "                    pending.textContent = fc('phase2_checkin_pending_ok', { amount: bonusAmt }) || ('✓ 对账成功，今日额外￥' + bonusAmt + ' 已全额到账！');\n",
    4148 => "                    }) || ('进度：' + row.balance.toFixed(2) + ' / ' + row.threshold.toFixed(0) + ' 元');\n",
];

$changed = 0;
foreach ($fixes as $lineNo => $newLine) {
    $idx = $lineNo - 1;
    if (!isset($lines[$idx])) {
        echo "MISS line $lineNo\n";
        continue;
    }
    if ($lines[$idx] !== $newLine) {
        $lines[$idx] = $newLine;
        $changed++;
        echo "FIX L$lineNo\n";
    } else {
        echo "SAME L$lineNo\n";
    }
}
file_put_contents($path, implode('', $lines));
echo "DONE changed=$changed\n";

// Verify quote balance on fixed lines
foreach (array_keys($fixes) as $lineNo) {
    $line = $lines[$lineNo - 1];
    $q = substr_count($line, "'");
    if ($q % 2 !== 0) {
        echo "ODD quotes L$lineNo q=$q\n";
    }
}

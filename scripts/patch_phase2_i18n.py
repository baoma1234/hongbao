# -*- coding: utf-8 -*-
"""Append phase2 / stepper / bottom-bar EN strings into locale PHP files."""
import re

EN = {
    'stepper_1': 'Join',
    'stepper_2': 'Open',
    'stepper_3': 'Redeem',
    'stepper_4': 'UID',
    'stepper_5': 'Claim',
    'bottom_bar_share': 'Share',
    'bottom_bar_exchange': 'Redeem',
    'bottom_bar_claim': 'Claim',
    'phase2_master_only': 'Master-only feature. Unlock by completing a VIP claim first.',
    'phase2_checkin_done': 'Already checked in today. Come back tomorrow.',
    'phase2_confirm_violent_title': 'Skip Violent Share?',
    'phase2_confirm_violent_msg': 'Normal check-in only gives ₱1.00 today and abandons the 7-day spark bonus. Choose Violent Share for 5x rewards.',
    'phase2_checkin_bonus_unlocked': 'Bonus unlocked and credited',
    'phase2_checkin_bonus_pending': 'Bonus locked — waiting for a new invitee registration today',
    'phase2_checkin_success_title': 'Check-in OK · Day {streak}',
    'phase2_checkin_success_msg': 'Base ₱{base} credited. {unlocked}',
    'phase2_checkin_unlock_hint': 'Tip: Invite one new user today to unlock the bonus box.',
    'phase2_streak_broken_title': 'Streak Frozen',
    'phase2_streak_broken_msg': 'Missed day froze the 7-day bonus. Invite 2 new users today to revive.',
    'phase2_day7_title': '🎉 Day-7 Spark Bonus Settled',
    'phase2_day7_msg': 'Full attendance! Extra ₱{amount} settled for the 7-day violent share streak.',
    'phase2_bonus_unlocked_title': 'Violent Bonus Unlocked',
    'phase2_bonus_unlocked_msg': 'Congrats! Extra ₱{amount} fully credited.',
    'phase2_streak_revived_title': 'Bonus Eligibility Revived',
    'phase2_streak_revived_msg': 'Today’s invites revived your 7-day spark bonus. Keep the streak!',
    'phase2_master_unlock_title': '🎖️ Master Mode Unlocked',
    'phase2_master_unlock_msg': 'First VIP claim done. Check-in, honor ladder and team radar are now open.',
    'phase2_urge_copy': 'Almost there — open the rewards hall and claim here: {link}',
    'phase2_honor_tier_title': '🏆 Promoted to [{name}]',
    'phase2_honor_tier_msg': 'Team withdrawals reached! Reward: {rights} shares + ₱{balance}.',
    'phase2_honor_title': '🏅 Long-term Master Honor Ladder',
    'phase2_honor_capped': '👑 Ladder capped · Tap to copy promo code and keep recruiting',
    'phase2_honor_progress': '{count} team withdrawals · {need} more to [{name}] · Tap to copy code',
    'phase2_honor_hint': 'Tap to copy your promo code and climb the ladder',
    'phase2_checkin_streak': '🔥 7-day Spark: {streak} / 7 days',
    'phase2_checkin_frozen': '⚠️ Streak frozen: invite {need} more today to revive the ₱175 bonus path',
    'phase2_checkin_revive_ready': '🔥 2 invites done today — bonus eligibility will revive',
    'phase2_checkin_done_btn': '✅ Checked in today ({mode})',
    'phase2_checkin_mode_violent': 'Violent',
    'phase2_checkin_mode_normal': 'Normal',
    'phase2_checkin_violent_btn': '🔘 Violent Share Check-in (copy today’s code · keep the 7-day streak)',
    'phase2_checkin_normal_btn': '⚪ Normal Check-in (₱1 only · skip 7-day ₱175 path)',
    'phase2_checkin_toggle': 'Enable [5x Violent Share Check-in] (up to ₱{amount} today)',
    'phase2_checkin_pending': '🔒 Violent box today: ₱{amount} (waiting for a new invitee...)',
    'phase2_checkin_pending_ok': '✅ Reconciled — extra ₱{amount} fully credited today!',
    'phase2_radar_title': '📡 Team Urge Radar',
    'phase2_radar_empty': 'No downlines yet — copy your code and invite',
    'phase2_radar_progress': 'Progress: {balance} / {threshold}',
    'phase2_radar_done': 'Ready ✅',
    'phase2_radar_urge': 'Urge claim now',
    'phase2_radar_fail': 'Load failed',
    'phase2_toast_urge_ok': 'Urge text copied — ping them in the group!',
    'phase2_toast_promo_ok': 'Promo code copied — share in groups!',
    'phase2_toast_checkin_ok': 'Check-in OK · promo code copied',
    'phase2_toast_copy_fail': 'Copy failed',
    'phase2_toast_checkin_fail': 'Check-in failed',
    'phase2_btn_know': 'Got it',
    'phase2_btn_day7_cash': 'Redeem now',
    'phase2_btn_honor_withdraw': 'Generate master claim code',
    'phase2_btn_honor_exchange': 'Go redeem now',
    'phase2_btn_enter_master': 'Enter Master Hall',
    'phase2_btn_persist_1': 'Take ₱1 only (skip bonus)',
    'phase2_btn_reselect_violent': 'Switch to Violent Share (5x)',
    'share_promo_action_btn': 'Share now',
    'user_status_shield': 'Key protection ON',
    'jackpot_meta': '📈 Shareholders today: {fission_user_count} | Open price: ₱{current_share_price} / share',
    'asset_valuation_hint': '( 💡 Est. value: ₱{amount} )',
    'lottery_chest_hint': 'Tap the black-gold chest to open newbie shares',
    'lottery_opening': 'Opening…',
    'lottery_result_shares': 'Locked 5.00 shares',
    'lottery_close_btn': 'Close',
    'lottery_close_wait': 'Open the chest first',
}


def append_keys(path, mapping):
    with open(path, 'r', encoding='utf-8') as f:
        text = f.read()
    existing = set(re.findall(r"'([^']+)'\s*=>", text))
    add = []
    for k, v in mapping.items():
        if k in existing:
            continue
        vv = v.replace("\\", "\\\\").replace("'", "\\'")
        add.append("  '%s' => '%s'," % (k, vv))
    if not add:
        print('SKIP', path)
        return
    # insert before closing );
    text2 = re.sub(r'\);\s*$', "\n".join(add) + "\n);\n", text, count=1)
    with open(path, 'w', encoding='utf-8', newline='\n') as f:
        f.write(text2)
    print('OK', path, '+', len(add))


files = [
    r'c:\wwwroot\caijin.com_7111\application\extra\i18n\en-PH.php',
    r'c:\wwwroot\caijin.com_7111\application\extra\i18n\id-ID.php',
    r'c:\wwwroot\caijin.com_7111\application\extra\i18n\vi-VN.php',
    r'c:\wwwroot\caijin.com_7111\application\extra\i18n\ms-MY.php',
    r'c:\wwwroot\caijin.com_7111\application\extra\i18n\km-KH.php',
]
for p in files:
    append_keys(p, EN)

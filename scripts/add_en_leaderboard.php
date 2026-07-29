<?php
$p = dirname(__DIR__) . '/application/extra/i18n/en-PH.php';
$e = include $p;
$e['leaderboard_invite_template'] = 'Invited {count}';
$e['leaderboard_user_fallback'] = 'User';
$e['leaderboard_empty'] = 'No rankings yet — invite friends to claim a spot!';
$e['leaderboard_fail'] = 'Failed to load leaderboard';
$e['leaderboard_loading'] = 'Loading…';
$e['leaderboard_title'] = json_decode('"\ud83c\udfc6 Invite fission TOP10"');
file_put_contents($p, "<?php\nreturn " . var_export($e, true) . ";\n");
echo 'ok en=' . count($e) . "\n";

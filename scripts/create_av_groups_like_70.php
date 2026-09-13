<?php
/**
 * 按群70配置创建：成人视频、国产自拍
 * php scripts/create_av_groups_like_70.php
 */
$root = dirname(__DIR__);
$env = parse_ini_file($root . '/.env', true);
$d = $env['database'];
$pdo = new PDO(
    "mysql:host={$d['hostname']};dbname={$d['database']};charset=utf8mb4",
    $d['username'],
    $d['password'],
    [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4', PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pre = $d['prefix'] ?? 'fa_';
$gTable = $pre . 'chat_groups';
$mTable = $pre . 'chat_group_members';

$tpl = $pdo->query("SELECT * FROM {$gTable} WHERE id=70")->fetch(PDO::FETCH_ASSOC);
if (!$tpl) {
    fwrite(STDERR, "group 70 not found\n");
    exit(1);
}

// 与群70核心成员对齐：群主机器人 + 管理员（含深夜欲望）+ 默认客服
$ownerId = (int)$tpl['owner_user_id']; // 74282747
$adminIds = [11111111, 58904307];
$memberIds = [88888888];
$names = ['成人视频', '国产自拍'];
$now = time();
$created = [];

foreach ($names as $name) {
    $exist = $pdo->prepare("SELECT id,name,new_member_see_history,status FROM {$gTable} WHERE name=? AND status IN (1,3) ORDER BY id DESC LIMIT 1");
    $exist->execute([$name]);
    $row = $exist->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $gid = (int)$row['id'];
        echo "EXISTS #{$gid} {$name} — align settings\n";
        $pdo->prepare(
            "UPDATE {$gTable} SET
                avatar=?, owner_user_id=?, notice=?, notice_i18n=?, notice_images=?,
                hide_member_list=?, display_member_count=?, privacy_mode=?, is_recommend=?, weigh=?,
                new_member_see_history=1, chat_mode=?, forbid_modes=?, forbid_speak_hint=?,
                is_vip_group=?, rp_min_amount=?, rp_max_amount=?, rp_min_count=?, rp_max_count=?,
                rp_agent_rebate_rate=?, rp_enabled_types=?, rp_robot_only=?,
                niuniu_enabled=0, yxx_enabled=0, rp_fixed_amount=?, max_members=?, status=?, updatetime=?
             WHERE id=?"
        )->execute([
            (string)$tpl['avatar'],
            $ownerId,
            (string)$tpl['notice'],
            (string)($tpl['notice_i18n'] ?? '[]'),
            (string)($tpl['notice_images'] ?? ''),
            (int)$tpl['hide_member_list'],
            (int)$tpl['display_member_count'],
            (string)$tpl['privacy_mode'],
            (int)$tpl['is_recommend'],
            (int)$tpl['weigh'],
            (string)$tpl['chat_mode'],
            (string)$tpl['forbid_modes'],
            (string)($tpl['forbid_speak_hint'] ?? ''),
            (int)$tpl['is_vip_group'],
            $tpl['rp_min_amount'],
            $tpl['rp_max_amount'],
            (int)$tpl['rp_min_count'],
            (int)$tpl['rp_max_count'],
            $tpl['rp_agent_rebate_rate'],
            (string)$tpl['rp_enabled_types'],
            (int)$tpl['rp_robot_only'],
            $tpl['rp_fixed_amount'],
            (int)$tpl['max_members'],
            (int)$tpl['status'], // 3
            $now,
            $gid,
        ]);
    } else {
        $pdo->prepare(
            "INSERT INTO {$gTable}
            (name,avatar,owner_user_id,notice,notice_i18n,notice_images,member_count,
             hide_member_list,display_member_count,privacy_mode,is_recommend,weigh,
             new_member_see_history,chat_mode,forbid_modes,forbid_speak_hint,is_vip_group,
             rp_min_amount,rp_max_amount,rp_min_count,rp_max_count,rp_agent_rebate_rate,
             rp_enabled_types,rp_robot_only,niuniu_enabled,niuniu_desc,yxx_enabled,
             niuniu_loop,niuniu_loop_starter,niuniu_loop_mode,rp_fixed_amount,max_members,
             status,createtime,updatetime)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?,?,?,?,?,?,?,?,?,?,?,0,'',0,0,0,1,?,?,?,?,?)"
        )->execute([
            $name,
            (string)$tpl['avatar'],
            $ownerId,
            (string)$tpl['notice'],
            (string)($tpl['notice_i18n'] ?? '[]'),
            (string)($tpl['notice_images'] ?? ''),
            0,
            (int)$tpl['hide_member_list'],
            (int)$tpl['display_member_count'],
            (string)$tpl['privacy_mode'],
            (int)$tpl['is_recommend'],
            (int)$tpl['weigh'],
            (string)$tpl['chat_mode'],
            (string)$tpl['forbid_modes'],
            (string)($tpl['forbid_speak_hint'] ?? ''),
            (int)$tpl['is_vip_group'],
            $tpl['rp_min_amount'],
            $tpl['rp_max_amount'],
            (int)$tpl['rp_min_count'],
            (int)$tpl['rp_max_count'],
            $tpl['rp_agent_rebate_rate'],
            (string)$tpl['rp_enabled_types'],
            (int)$tpl['rp_robot_only'],
            $tpl['rp_fixed_amount'],
            (int)$tpl['max_members'],
            (int)$tpl['status'],
            $now,
            $now,
        ]);
        $gid = (int)$pdo->lastInsertId();
        echo "INSERT #{$gid} {$name}\n";
    }

    $ensure = function ($uid, $role) use ($pdo, $mTable, $gid, $now) {
        $st = $pdo->prepare("SELECT id,role,status FROM {$mTable} WHERE group_id=? AND user_id=? LIMIT 1");
        $st->execute([$gid, $uid]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if ($m) {
            $pdo->prepare("UPDATE {$mTable} SET role=?, status=1, updatetime=? WHERE id=?")
                ->execute([$role, $now, (int)$m['id']]);
        } else {
            $pdo->prepare(
                "INSERT INTO {$mTable} (group_id,user_id,role,nickname,status,jointime,updatetime)
                 VALUES (?,?,?,'',1,?,?)"
            )->execute([$gid, $uid, $role, $now, $now]);
        }
    };

    $ensure($ownerId, 3);
    foreach ($adminIds as $aid) {
        if ($aid !== $ownerId) {
            $ensure($aid, 2);
        }
    }
    foreach ($memberIds as $mid) {
        if ($mid !== $ownerId && !in_array($mid, $adminIds, true)) {
            $ensure($mid, 1);
        }
    }

    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM {$mTable} WHERE group_id={$gid} AND status=1")->fetchColumn();
    $pdo->prepare("UPDATE {$gTable} SET member_count=?, updatetime=? WHERE id=?")->execute([$cnt, $now, $gid]);

    $out = $pdo->query("SELECT id,name,owner_user_id,status,new_member_see_history,privacy_mode,forbid_modes,member_count FROM {$gTable} WHERE id={$gid}")->fetch(PDO::FETCH_ASSOC);
    $ms = $pdo->query("SELECT user_id,role FROM {$mTable} WHERE group_id={$gid} AND status=1 ORDER BY role DESC,user_id ASC")->fetchAll(PDO::FETCH_ASSOC);
    echo 'OK ' . json_encode($out, JSON_UNESCAPED_UNICODE) . "\n";
    echo 'MEMBERS ' . json_encode($ms, JSON_UNESCAPED_UNICODE) . "\n";
    $created[] = $gid;
}

// bump infover
try {
    $cfg = include $root . '/im-server/config/app.php';
    $r = $cfg['redis'] ?? [];
    if (class_exists('Redis')) {
        $redis = new Redis();
        $redis->connect((string)($r['host'] ?? '127.0.0.1'), (int)($r['port'] ?? 6379), 1.5);
        if (!empty($r['password'])) {
            $redis->auth((string)$r['password']);
        }
        if (isset($r['db'])) {
            $redis->select((int)$r['db']);
        }
        $prefix = (string)($r['prefix'] ?? 'im:');
        foreach ($created as $gid) {
            echo 'infover ' . $gid . '=' . $redis->incr($prefix . 'g:' . $gid . ':infover') . "\n";
        }
    }
} catch (Throwable $e) {
    echo 'redis bump skip: ' . $e->getMessage() . "\n";
}

echo "DONE ids=" . implode(',', $created) . "\n";

<?php
/**
 * 修复：扣款成功但因「禁止发言」未落红包消息的孤儿包
 * 用法: php scripts/repair_orphan_rp_messages.php [group_id]
 */
$root = dirname(__DIR__); // im-server
require $root . '/vendor/autoload.php';
$cfg = require $root . '/config/app.php';
Im\Support\Db::init($cfg['db']);
Im\Support\RedisClient::init($cfg['redis']);

$gid = isset($argv[1]) ? (int)$argv[1] : 0;
$since = time() - 86400 * 3;
$sql = 'SELECT * FROM ' . Im\Support\Db::table('chat_red_packets')
    . ' WHERE scope_type=2 AND status=1 AND createtime>=?';
$bind = [$since];
if ($gid > 0) {
    $sql .= ' AND group_id=?';
    $bind[] = $gid;
}
$sql .= ' ORDER BY id DESC LIMIT 200';
$rows = Im\Support\Db::fetchAll($sql, $bind);
$messages = new Im\Service\MessageService();
$fixed = 0;
$skip = 0;
foreach ($rows as $p) {
    $pid = (int)$p['id'];
    $groupId = (int)$p['group_id'];
    $from = (int)$p['from_user_id'];
    $exist = Im\Support\Db::fetch(
        'SELECT id FROM ' . Im\Support\Db::table('chat_messages')
        . ' WHERE conversation_type=2 AND group_id=? AND msg_type=2 AND status IN (1,2)'
        . ' AND (extra LIKE ? OR extra LIKE ?) LIMIT 1',
        [$groupId, '%"packet_id":' . $pid . '%', '%"packet_id":"' . $pid . '"%']
    );
    if ($exist) {
        $skip++;
        continue;
    }
    $blessing = (string)($p['blessing'] ?? '恭喜发财');
    $extra = [
        'packet_id'      => $pid,
        'packet_no'      => (string)$p['packet_no'],
        'total_amount'   => (float)$p['total_amount'],
        'total_count'    => (int)$p['total_count'],
        'packet_type'    => (int)$p['packet_type'],
        'mine_digit'     => (int)($p['mine_digit'] ?? 0),
        'tron_block_num' => (int)($p['tron_block_num'] ?? 0),
        'tron_block_id'  => (string)($p['tron_block_id'] ?? ''),
        'tron_lucky'     => (string)($p['tron_lucky'] ?? ''),
        'tron_status'    => (int)($p['tron_status'] ?? 0),
        'skin_id'        => (int)($p['skin_id'] ?? 0),
        'bg_image'       => (string)($p['bg_image'] ?? ''),
        'blessing'       => $blessing,
        'expiretime'     => (int)$p['expiretime'],
        'proof_type'     => in_array((int)$p['packet_type'], [2, 3], true) ? 'tron' : '',
        'repaired'       => 1,
    ];
    $msg = $messages->insertGroupMessageUnchecked($from, $groupId, '[红包]' . $blessing, 2, $extra);
    echo "FIXED packet={$pid} group={$groupId} msg=" . ($msg['id'] ?? 0) . "\n";
    $fixed++;
}
echo "done fixed={$fixed} skip={$skip}\n";

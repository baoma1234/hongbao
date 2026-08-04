<?php
/**
 * 补账：拼手气(type2) 已标记赔付完成但无 worst_pay 流水的订单
 * 用法: php scripts/repair_lucky_missing_compensate.php [--dry-run] [packet_id...]
 */
$dry = in_array('--dry-run', $argv, true);
$onlyIds = [];
foreach ($argv as $i => $a) {
    if ($i === 0 || $a === '--dry-run') {
        continue;
    }
    if (ctype_digit((string)$a)) {
        $onlyIds[] = (int)$a;
    }
}

$root = dirname(__DIR__);
require $root . '/im-server/vendor/autoload.php';

use Im\Support\Db;

$cfg = require $root . '/im-server/config/app.php';
Db::init($cfg['db']);

$sql = 'SELECT p.id, p.packet_no, p.from_user_id, p.total_amount, p.compensate_amount,'
    . ' r.id AS record_id, r.user_id AS worst_uid, r.compensate_amount AS rec_comp,'
    . ' r.compensate_status, r.compensate_ledger_id'
    . ' FROM ' . Db::table('chat_red_packets') . ' p'
    . ' INNER JOIN ' . Db::table('chat_red_packet_records') . ' r'
    . '   ON r.packet_id=p.id AND r.is_worst=1 AND r.need_compensate=1'
    . ' WHERE p.packet_type=2 AND p.scope_type=2 AND p.status=5'
    . ' AND r.compensate_status=2'
    . ' AND NOT EXISTS ('
    . '   SELECT 1 FROM ' . Db::table('fans_ledger') . ' l'
    . "   WHERE l.biz_no=p.packet_no AND l.type='red_packet_worst_pay'"
    . ' )';
if ($onlyIds) {
    $sql .= ' AND p.id IN (' . implode(',', array_map('intval', $onlyIds)) . ')';
}
$sql .= ' ORDER BY p.id ASC';

$rows = Db::fetchAll($sql) ?: [];
echo 'found ' . count($rows) . " missing compensate payment(s)\n";
if (!$rows) {
    exit(0);
}

$field = 'hongbao';
$acct = Db::table('fans_account');
$ledger = Db::table('fans_ledger');
$settlements = Db::table('chat_red_packet_settlements');
$records = Db::table('chat_red_packet_records');
$now = time();
$ok = 0;
$partial = 0;
$fail = 0;

foreach ($rows as $row) {
    $packetId = (int)$row['id'];
    $packetNo = (string)$row['packet_no'];
    $sender = (int)$row['from_user_id'];
    $worst = (int)$row['worst_uid'];
    $recordId = (int)$row['record_id'];
    $amt = round((float)($row['rec_comp'] ?: $row['compensate_amount'] ?: $row['total_amount']), 2);
    echo sprintf(
        "#%d %s worst=%d sender=%d amount=%.2f ... ",
        $packetId,
        $packetNo,
        $worst,
        $sender,
        $amt
    );
    if ($amt <= 0 || $worst <= 0 || $sender <= 0) {
        echo "SKIP invalid\n";
        $fail++;
        continue;
    }
    if ($dry) {
        echo "DRY\n";
        continue;
    }
    try {
        Db::begin();
        // 再确认无 worst_pay
        $exist = Db::fetch(
            'SELECT id FROM ' . $ledger . " WHERE biz_no=? AND type='red_packet_worst_pay' LIMIT 1",
            [$packetNo]
        );
        if ($exist) {
            Db::rollBack();
            echo "already has ledger\n";
            continue;
        }
        $balRow = Db::fetch(
            "SELECT `{$field}` AS bal FROM {$acct} WHERE user_id=? FOR UPDATE",
            [$worst]
        );
        $bal = round((float)($balRow['bal'] ?? 0), 2);
        $take = round(min($amt, max(0.0, $bal)), 2);
        $debt = round($amt - $take, 2);
        $ledgerOutId = 0;
        if ($take > 0.00001) {
            $abs = sprintf('%.2f', $take);
            $aff = Db::exec(
                "UPDATE {$acct} SET `{$field}`=`{$field}`-(?), turnover=turnover+(?), updatetime=? WHERE user_id=? AND status='normal' AND `{$field}`>=?",
                [$abs, $abs, $now, $worst, $abs]
            );
            if ($aff <= 0) {
                throw new RuntimeException('debit worst fail bal=' . $bal);
            }
            $afterW = Db::fetch("SELECT `{$field}` AS bal, rights FROM {$acct} WHERE user_id=? LIMIT 1", [$worst]);
            Db::exec(
                'INSERT INTO ' . $ledger
                . ' (user_id,type,rights_change,balance_change,hongbao_change,rights_after,balance_after,hongbao_after,remark,channel,biz_no,ref_type,ref_id,admin_id,createtime)'
                . ' VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $worst, 'red_packet_worst_pay', '0.00', '0.00', sprintf('%.2f', -$take),
                    sprintf('%.2f', (float)($afterW['rights'] ?? 0)), '0.00', sprintf('%.2f', (float)$afterW['bal']),
                    '手气最差赔付补账 ' . $packetNo, 'im_red_packet_repair', $packetNo, 'red_packet', $packetId, 0, $now,
                ]
            );
            $ledgerOutId = (int)Db::lastId();

            Db::exec(
                "UPDATE {$acct} SET `{$field}`=`{$field}`+(?), updatetime=? WHERE user_id=? AND status='normal'",
                [$abs, $now, $sender]
            );
            $afterS = Db::fetch("SELECT `{$field}` AS bal, rights FROM {$acct} WHERE user_id=? LIMIT 1", [$sender]);
            Db::exec(
                'INSERT INTO ' . $ledger
                . ' (user_id,type,rights_change,balance_change,hongbao_change,rights_after,balance_after,hongbao_after,remark,channel,biz_no,ref_type,ref_id,admin_id,createtime)'
                . ' VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $sender, 'red_packet_compensate_in', '0.00', '0.00', sprintf('%.2f', $take),
                    sprintf('%.2f', (float)($afterS['rights'] ?? 0)), '0.00', sprintf('%.2f', (float)$afterS['bal']),
                    '收到手气最差赔付补账 ' . $packetNo, 'im_red_packet_repair', $packetNo, 'red_packet', $packetId, 0, $now,
                ]
            );

            Db::exec(
                'INSERT INTO ' . $settlements
                . ' (packet_id,packet_no,settle_type,from_user_id,to_user_id,amount,ledger_id,status,remark,createtime)'
                . ' VALUES (?,?,?,?,?,?,?,?,?,?)',
                [
                    $packetId, $packetNo, 'compensate', $worst, $sender,
                    sprintf('%.2f', $take), $ledgerOutId, 1,
                    $debt > 0 ? ('手气最差赔付补账(分笔 ' . sprintf('%.2f', $take) . ')') : '手气最差赔付补账',
                    $now,
                ]
            );
        }
        if ($debt > 0.00001) {
            Db::exec(
                'INSERT INTO ' . $settlements
                . ' (packet_id,packet_no,settle_type,from_user_id,to_user_id,amount,ledger_id,status,remark,createtime)'
                . ' VALUES (?,?,?,?,?,?,?,?,?,?)',
                [
                    $packetId, $packetNo, 'compensate_debt', $worst, $sender,
                    sprintf('%.2f', $debt), 0, 0, '手气最差赔付欠款待补', $now,
                ]
            );
        }
        if ($ledgerOutId > 0) {
            Db::exec(
                'UPDATE ' . $records . ' SET compensate_ledger_id=? WHERE id=?',
                [$ledgerOutId, $recordId]
            );
        }
        Db::commit();
        if ($debt > 0.00001) {
            echo "PARTIAL take={$take} debt={$debt}\n";
            $partial++;
        } else {
            echo "OK take={$take}\n";
            $ok++;
        }
    } catch (Throwable $e) {
        try {
            Db::rollBack();
        } catch (Throwable $e2) {
        }
        echo 'FAIL ' . $e->getMessage() . "\n";
        $fail++;
    }
}

echo "done ok={$ok} partial={$partial} fail={$fail} dry=" . ($dry ? '1' : '0') . "\n";

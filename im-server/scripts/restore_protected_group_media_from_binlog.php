<?php
/**
 * 从 MySQL binlog 文本 / mysqlbinlog 输出中，恢复频道群（默认 70/71/72/77）的图片/视频消息。
 *
 * 清理脚本是硬删，库内无软删备份；只能靠 binlog 里曾出现过的 INSERT 重建。
 *
 * 用法：
 *   # 1) 先把 binlog 解码成文本（Linux）：
 *   mysqlbinlog --no-defaults --base64-output=DECODE-ROWS -v /var/lib/mysql/mysql-bin.0xxxxx \
 *     > /tmp/binlog_chat.txt
 *
 *   # 2) dry-run 统计可恢复条数
 *   php scripts/restore_protected_group_media_from_binlog.php --file=/tmp/binlog_chat.txt
 *
 *   # 3) 真正写入
 *   php scripts/restore_protected_group_media_from_binlog.php --file=/tmp/binlog_chat.txt --execute
 *
 *   # 也可一次喂多个文件
 *   php scripts/restore_protected_group_media_from_binlog.php --file=/tmp/a.txt --file=/tmp/b.txt --execute
 *
 * Windows 本机示例：
 *   php scripts/restore_protected_group_media_from_binlog.php --file=scripts/_binlog_extract/binlog_000021.txt --execute
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$cfg = require $root . '/config/app.php';
Im\Support\Db::init($cfg['db']);

$protect = [70, 71, 72, 77];
$files = [];
$execute = false;
$mediaOnly = true;

foreach ($argv as $i => $arg) {
    if ($i === 0) {
        continue;
    }
    if ($arg === '--execute') {
        $execute = true;
        continue;
    }
    if ($arg === '--all-types') {
        $mediaOnly = false;
        continue;
    }
    if (preg_match('/^--protect-groups=(.*)$/', $arg, $m)) {
        $protect = [];
        foreach (preg_split('/[,\s]+/', trim($m[1])) as $p) {
            $id = (int)$p;
            if ($id > 0) {
                $protect[] = $id;
            }
        }
        continue;
    }
    if (preg_match('/^--file=(.+)$/', $arg, $m)) {
        $files[] = $m[1];
        continue;
    }
    if ($arg === '--help' || $arg === '-h') {
        echo "Usage: php restore_protected_group_media_from_binlog.php --file=binlog.txt [--execute] [--protect-groups=70,71,72,77] [--all-types]\n";
        exit(0);
    }
}

if (!$files) {
    fwrite(STDERR, "need at least one --file=...\n");
    exit(1);
}

$msgTable = Im\Support\Db::table('chat_messages');
$protectMap = array_fill_keys($protect, true);
$found = [];
$re = '/INSERT INTO `?(?:[a-z0-9_]+`?\.)?`?fa_chat_messages`?\s*\(([^)]+)\)\s*VALUES\s*\((.+)\)\s*;?/i';

foreach ($files as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "missing file: {$file}\n");
        exit(1);
    }
    $fh = fopen($file, 'rb');
    if (!$fh) {
        fwrite(STDERR, "cannot open: {$file}\n");
        exit(1);
    }
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '' || stripos($line, 'INSERT INTO') === false || stripos($line, 'chat_messages') === false) {
            continue;
        }
        if (!preg_match($re, $line, $m)) {
            continue;
        }
        $cols = array_map(static function ($c) {
            return strtolower(trim(str_replace('`', '', $c)));
        }, explode(',', $m[1]));
        $vals = parseSqlValues($m[2]);
        if (count($cols) !== count($vals)) {
            continue;
        }
        $row = [];
        foreach ($cols as $i => $col) {
            $row[$col] = $vals[$i];
        }
        $gid = (int)($row['group_id'] ?? 0);
        $ctype = (int)($row['conversation_type'] ?? 0);
        $cid = (int)($row['conversation_id'] ?? 0);
        if (!isset($protectMap[$gid]) && !($ctype === 2 && isset($protectMap[$cid]))) {
            continue;
        }
        $msgType = (int)($row['msg_type'] ?? 0);
        if ($mediaOnly && !in_array($msgType, [4, 5], true)) {
            continue;
        }
        $msgId = (string)($row['msg_id'] ?? '');
        if ($msgId === '') {
            continue;
        }
        $found[$msgId] = $row;
    }
    fclose($fh);
}

echo 'protect_groups=' . implode(',', $protect) . ' media_only=' . ($mediaOnly ? '1' : '0') . "\n";
echo 'candidates=' . count($found) . ' mode=' . ($execute ? 'EXECUTE' : 'DRY-RUN') . "\n";

$restored = 0;
$skippedExist = 0;
$failed = 0;
foreach ($found as $msgId => $row) {
    $exists = Im\Support\Db::fetch(
        "SELECT id FROM {$msgTable} WHERE msg_id=? LIMIT 1",
        [$msgId]
    );
    if ($exists) {
        $skippedExist++;
        continue;
    }
    $extra = $row['extra'] ?? null;
    if ($extra === 'NULL' || $extra === null) {
        $extra = null;
    }
    echo ($execute ? 'RESTORE' : 'WOULD') . " msg_id={$msgId} group=" . ($row['group_id'] ?? '') . ' type=' . ($row['msg_type'] ?? '') . ' content=' . mb_substr((string)($row['content'] ?? ''), 0, 40) . "\n";
    if (!$execute) {
        $restored++;
        continue;
    }
    try {
        Im\Support\Db::exec(
            "INSERT INTO {$msgTable}
             (msg_id,conversation_type,conversation_id,group_id,from_user_id,to_user_id,msg_type,content,extra,status,createtime)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)",
            [
                $msgId,
                (int)($row['conversation_type'] ?? 2),
                (string)($row['conversation_id'] ?? ''),
                (int)($row['group_id'] ?? 0),
                (int)($row['from_user_id'] ?? 0),
                (int)($row['to_user_id'] ?? 0),
                (int)($row['msg_type'] ?? 1),
                (string)($row['content'] ?? ''),
                $extra,
                (int)($row['status'] ?? 1),
                (int)($row['createtime'] ?? time()),
            ]
        );
        $restored++;
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, "fail {$msgId}: " . $e->getMessage() . "\n");
    }
}

echo "restored={$restored} skipped_exist={$skippedExist} failed={$failed}\n";
exit($failed > 0 ? 2 : 0);

/**
 * @return list<string|null>
 */
function parseSqlValues(string $raw): array
{
    $out = [];
    $len = strlen($raw);
    $i = 0;
    while ($i < $len) {
        while ($i < $len && ($raw[$i] === ' ' || $raw[$i] === ',')) {
            $i++;
        }
        if ($i >= $len) {
            break;
        }
        if (substr($raw, $i, 4) === 'NULL' && ($i + 4 >= $len || $raw[$i + 4] === ',' || $raw[$i + 4] === ' ')) {
            $out[] = null;
            $i += 4;
            continue;
        }
        if ($raw[$i] === "'") {
            $i++;
            $buf = '';
            while ($i < $len) {
                $ch = $raw[$i];
                if ($ch === '\\' && $i + 1 < $len) {
                    $buf .= $raw[$i + 1];
                    $i += 2;
                    continue;
                }
                if ($ch === "'" && $i + 1 < $len && $raw[$i + 1] === "'") {
                    $buf .= "'";
                    $i += 2;
                    continue;
                }
                if ($ch === "'") {
                    $i++;
                    break;
                }
                $buf .= $ch;
                $i++;
            }
            $out[] = $buf;
            continue;
        }
        // bare number
        $buf = '';
        while ($i < $len && $raw[$i] !== ',') {
            $buf .= $raw[$i];
            $i++;
        }
        $out[] = trim($buf);
    }
    return $out;
}

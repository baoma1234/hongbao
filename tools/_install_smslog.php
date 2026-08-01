<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=caijin_com_7111;charset=utf8mb4', 'caijin_com_7111', 'zJ3EkWE47y', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$pdo->exec("CREATE TABLE IF NOT EXISTS `fa_fans_sms_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `event` varchar(32) NOT NULL DEFAULT '' COMMENT '事件',
  `mobile` varchar(32) NOT NULL DEFAULT '' COMMENT '手机号',
  `code` varchar(16) NOT NULL DEFAULT '' COMMENT '验证码',
  `channel` varchar(32) NOT NULL DEFAULT '' COMMENT '通道 mock/dagou/una/http/default',
  `ip` varchar(64) NOT NULL DEFAULT '',
  `status` varchar(16) NOT NULL DEFAULT 'sent' COMMENT 'sent/used',
  `createtime` int unsigned DEFAULT NULL,
  `usedtime` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mobile` (`mobile`),
  KEY `idx_event` (`event`),
  KEY `idx_createtime` (`createtime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='福利大厅短信发送记录'");
echo "table ok\n";

$pid = 227;
$exists = $pdo->query("SELECT id FROM fa_auth_rule WHERE name='fanshub/smslog'")->fetchColumn();
if (!$exists) {
    $now = time();
    $sql = "INSERT INTO fa_auth_rule (`type`,`pid`,`name`,`title`,`icon`,`url`,`condition`,`remark`,`ismenu`,`menutype`,`extend`,`py`,`pinyin`,`createtime`,`updatetime`,`weigh`,`status`)
        VALUES ('file',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    $pdo->prepare($sql)->execute([
        $pid, 'fanshub/smslog', '短信记录', 'fa fa-commenting', '', '', '查看短信发送记录与验证码', 1, 'addtabs', '', 'dxjl', 'duanxinjilu', $now, $now, 80, 'normal'
    ]);
    $menuId = (int)$pdo->lastInsertId();
    $pdo->prepare($sql)->execute([
        $menuId, 'fanshub/smslog/index', '查看', 'fa fa-circle-o', '', '', '', 0, null, '', '', '', $now, $now, 0, 'normal'
    ]);
    $group = $pdo->query("SELECT id,rules FROM fa_auth_group WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    if ($group) {
        $rules = array_filter(explode(',', (string)$group['rules']));
        $newIds = $pdo->query("SELECT id FROM fa_auth_rule WHERE name LIKE 'fanshub/smslog%'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($newIds as $rid) {
            if (!in_array((string)$rid, $rules, true)) {
                $rules[] = (string)$rid;
            }
        }
        $pdo->prepare("UPDATE fa_auth_group SET rules=? WHERE id=1")->execute([implode(',', $rules)]);
    }
    echo "menu created id={$menuId}\n";
} else {
    echo "menu exists id={$exists}\n";
}

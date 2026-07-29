<?php
$root = dirname(__DIR__);
$env = parse_ini_file($root . '/.env', true);
$d = $env['database'];
$p = new PDO('mysql:host='.$d['hostname'].';port='.$d['hostport'].';dbname='.$d['database'].';charset=utf8mb4', $d['username'], $d['password']);
$prefix = $d['prefix'] ?? 'fa_';
$q = $p->query(\"SELECT id,pid,name,title,ismenu,weigh FROM {$prefix}auth_rule WHERE (name LIKE 'fanshub%' OR name IN ('fanshub_member','fanshub_im')) AND ismenu=1 ORDER BY pid,weigh DESC,id\");
foreach ($q as $r) echo \"{$r['id']}\t{$r['pid']}\t{$r['weigh']}\t{$r['name']}\t{$r['title']}\n\";

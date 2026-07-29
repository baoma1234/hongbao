<?php
$html = <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>红包公平性验证</title>
<link rel="stylesheet" href="css/fair-verify.css">
</head>
<body>
<div class="wrap">
<h1>红包公平性验证</h1>
<p class="sub">拼手气接龙 / 扫雷：发包时写入 SHA-256 承诺，抢完或过期后公开种子与金额序列。</p>
<div class="card">
<label for="packetNo">红包单号 packet_no</label>
<input id="packetNo" type="text" placeholder="RP..." autocomplete="off">
<button type="button" id="btnVerify">查询并验证</button>
<div id="formErr" class="err" style="display:none"></motion>
</div>
<div id="result" style="display:none"></motion>
</motion>
<script src="js/fair-verify.js"></script>
</body>
</html>
HTML;
$html = str_replace('</motion>', '</' . 'div>', $html);
file_put_contents(__DIR__ . '/../public/888/fair-verify.html', $html);
echo "ok\n";

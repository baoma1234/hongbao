<?php
$path = __DIR__ . '/../public/888/js/chat/02-room.js';
$js = file_get_contents($path);
if (strpos($js, 'chat-rp-fair-hash') !== false) {
    echo "SKIP already patched\n";
    exit(0);
}
$d = 'motion';
$d = 'div';
$old = "      if (head) {\n"
    . "        head.innerHTML = '<{$d} class=\"chat-rp-detail-bless\">' + escapeHtml(bless) + '</{$d}>' +\n"
    . "          '<{$d} class=\"chat-rp-detail-meta\">共 ' + (p.total_count | 0) + ' 个 · ￥' + (parseFloat(p.total_amount || 0).toFixed(2)) + '</{$d}>' +\n"
    . "          (locked\n"
    . "            ? '<{$d} class=\"chat-rp-privacy-tip locked\">\u{1F512} 隐私群：领取人资料已隐藏</{$d}>'\n"
    . "            : '');\n"
    . "      }";
$new = "      if (head) {\n"
    . "        var fairHash = p.fair_hash || '';\n"
    . "        var fairBits = '';\n"
    . "        if (fairHash && (p.packet_type | 0) !== 1) {\n"
    . "          var pno = encodeURIComponent(p.packet_no || '');\n"
    . "          fairBits = '<{$d} class=\"chat-rp-fair-hash\"><span class=\"chat-rp-fair-label\">SHA-256</span><code>' + escapeHtml(fairHash) + '</code></{$d}>' +\n"
    . "            '<a class=\"chat-rp-fair-link\" href=\"fair-verify.html?packet_no=' + pno + '\" target=\"_blank\" rel=\"noopener\">\u516c\u5e73\u9a8c\u8bc1</a>';\n"
    . "        }\n"
    . "        head.innerHTML = '<{$d} class=\"chat-rp-detail-bless\">' + escapeHtml(bless) + '</{$d}>' +\n"
    . "          '<{$d} class=\"chat-rp-detail-meta\">\u5171 ' + (p.total_count | 0) + ' \u4e2a \u00b7 \uffe5' + (parseFloat(p.total_amount || 0).toFixed(2)) + '</{$d}>' +\n"
    . "          fairBits +\n"
    . "          (locked\n"
    . "            ? '<{$d} class=\"chat-rp-privacy-tip locked\">\u{1F512} \u9690\u79c1\u7fa4\uff1a\u9886\u53d6\u4eba\u8d44\u6599\u5df2\u9690\u85cf</{$d}>'\n"
    . "            : '');\n"
    . "      }";
if (strpos($js, $old) === false) {
    fwrite(STDERR, "pattern not found\n");
    exit(1);
}
file_put_contents($path, str_replace($old, $new, $js));
echo "OK 02-room.js\n";

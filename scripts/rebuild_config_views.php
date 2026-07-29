<?php
/**
 * Rebuild fanshub config views: real newlines + absolute {:url()} links
 * php scripts/rebuild_config_views.php
 */
$root = dirname(__DIR__);
$view = $root . '/application/admin/view/fanshub/config';
$u = function ($s) {
    return json_decode('"' . $s . '"');
};

function w($path, $content)
{
    // normalize: no literal \n sequences
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    if (file_put_contents($path, $content) === false) {
        throw new RuntimeException($path);
    }
    $c = file_get_contents($path);
    $lit = substr_count($c, '\\n');
    echo basename($path) . " bytes=" . strlen($c) . " literal\\n=$lit\n";
}

// ---- _nav.html ----
$nav = '<div class="btn-group config-section-nav" style="margin-bottom:12px;display:flex;flex-wrap:wrap;gap:6px;">' . "\n"
    . '    <a href="{:url(\'fanshub.config/basic\')}" class="btn btn-sm {:$configSection==\'basic\'?\'btn-primary\':\'btn-default\'} addtabsit"><i class="fa fa-sliders"></i> ' . $u('\u57fa\u7840\u53c2\u6570') . '</a>' . "\n"
    . '    <a href="{:url(\'fanshub.config/exchange\')}" class="btn btn-sm {:$configSection==\'exchange\'?\'btn-primary\':\'btn-default\'} addtabsit"><i class="fa fa-exchange"></i> ' . $u('\u8d44\u4ea7\u95ea\u5151') . '</a>' . "\n"
    . '    <a href="{:url(\'fanshub.config/invite\')}" class="btn btn-sm {:$configSection==\'invite\'?\'btn-primary\':\'btn-default\'} addtabsit"><i class="fa fa-share-alt"></i> ' . $u('\u9080\u8bf7\u5206\u4eab') . '</a>' . "\n"
    . '    <a href="{:url(\'fanshub.config/copy\')}" class="btn btn-sm {:$configSection==\'copy\'?\'btn-primary\':\'btn-default\'} addtabsit"><i class="fa fa-file-text-o"></i> H5' . $u('\u6587\u6848') . '</a>' . "\n"
    . '    <a href="{:url(\'fanshub.config/market\')}" class="btn btn-sm {:$configSection==\'market\'?\'btn-primary\':\'btn-default\'} addtabsit"><i class="fa fa-line-chart"></i> ' . $u('\u5927\u76d8\u63a7\u76d8') . '</a>' . "\n"
    . '    <a href="{:url(\'fanshub.config/security\')}" class="btn btn-sm {:$configSection==\'security\'?\'btn-primary\':\'btn-default\'} addtabsit"><i class="fa fa-shield"></i> ' . $u('\u5b89\u5168\u6821\u9a8c') . '</a>' . "\n"
    . '    <a href="{:url(\'fanshub.config/i18n\')}" class="btn btn-sm {:$configSection==\'i18n\'?\'btn-primary\':\'btn-default\'} addtabsit"><i class="fa fa-language"></i> ' . $u('\u591a\u8bed\u8a00') . '</a>' . "\n"
    . '    <a href="{:url(\'fanshub.sms/index\')}" class="btn btn-sm btn-info addtabsit"><i class="fa fa-envelope"></i> ' . $u('\u77ed\u4fe1\u914d\u7f6e') . '</a>' . "\n"
    . '</div>' . "\n";
w("$view/_nav.html", $nav);

// ---- shells ----
$shells = [
    'basic' => 'section_basic',
    'exchange' => 'section_exchange',
    'invite' => 'section_invite',
    'copy' => 'copy_fields',
    'market' => 'section_market',
    'security' => 'section_security',
];
foreach ($shells as $sec => $inc) {
    $html = '<div class="panel panel-default panel-intro">' . "\n"
        . '    <div class="panel-heading">' . "\n"
        . '        <div class="panel-lead"><em>{$sectionTitle}</em>' . "\n"
        . '            <span class="text-muted" style="font-size:12px;margin-left:8px;">{$sectionDesc}</span>' . "\n"
        . '        </div>' . "\n"
        . '    </div>' . "\n"
        . '    <div class="panel-body">' . "\n"
        . '        {include file="fanshub/config/_nav" /}' . "\n"
        . '        <form id="config-form" class="form-horizontal" role="form" data-toggle="validator" method="POST" action="{:url(\'fanshub.config/save\')}">' . "\n"
        . '            <input type="hidden" name="section" value="' . $sec . '">' . "\n"
        . '            {include file="fanshub/config/' . $inc . '" /}' . "\n"
        . '            <div class="form-group layer-footer">' . "\n"
        . '                <label class="control-label col-xs-12 col-sm-2"></label>' . "\n"
        . '                <div class="col-xs-12 col-sm-8">' . "\n"
        . '                    <button type="submit" class="btn btn-primary btn-embossed">' . $u('\u4fdd\u5b58\u672c\u9875\u914d\u7f6e') . '</button>' . "\n"
        . '                </div>' . "\n"
        . '            </div>' . "\n"
        . '        </form>' . "\n"
        . '    </div>' . "\n"
        . '</div>' . "\n";
    w("$view/{$sec}.html", $html);
}

// ---- index hub ----
$cards = [
    ['basic', 'fa-sliders text-primary', '\u57fa\u7840\u53c2\u6570', '\u9762\u503c\u3001\u63d0\u73b0\u95e8\u69db\u3001\u9001\u80a1\u3001\u5916\u94fe\u3001\u9ed8\u8ba4\u8bed\u8a00\u3001\u751f\u4ea7\u68c0\u67e5'],
    ['exchange', 'fa-exchange text-success', '\u8d44\u4ea7\u95ea\u5151', '\u80a1\u4efd / \u7ea2\u5229 / \u7ea2\u5b9d \u4e92\u5151\u5f00\u5173\u4e0e\u5355\u6b21\u6700\u4f4e\u989d'],
    ['invite', 'fa-share-alt text-info', '\u9080\u8bf7\u5206\u4eab', '\u843d\u5730\u9875\u57df\u540d\u3001\u9080\u8bf7\u7801\u3001\u5206\u4eab/\u8dd1\u9a6c\u706f\u3001\u9632\u5237'],
    ['copy', 'fa-file-text-o text-warning', 'H5\u6587\u6848', '\u5927\u5385\u754c\u9762\u6587\u6848\u5b57\u6bb5\uff08\u4e2d\u6587\u9ed8\u8ba4\u7a3f\uff09'],
    ['market', 'fa-line-chart', '\u5927\u76d8\u63a7\u76d8', '\u865a\u62df\u4eba\u6570\u3001\u80a1\u4ef7\u516c\u5f0f\u3001\u521b\u9020\u4ef7\u503c\u5956\u6c60'],
    ['security', 'fa-shield text-danger', '\u5b89\u5168\u6821\u9a8c', 'API \u7b7e\u540d\u3001\u8bbe\u5907\u6307\u7eb9\u3001\u4e3b\u7ad9 UID \u6821\u9a8c'],
    ['i18n', 'fa-language', '\u591a\u8bed\u8a00', '\u5404\u8bed\u8a00\u6279\u91cf\u5bf9\u7167\u7f16\u8f91'],
];
$index = '<div class="panel panel-default panel-intro">' . "\n"
    . '    <div class="panel-heading">' . "\n"
    . '        <div class="panel-lead"><em>' . $u('\u6d3b\u52a8\u914d\u7f6e') . '</em>' . "\n"
    . '            <span class="text-muted" style="font-size:12px;margin-left:8px;">' . $u('\u6309\u529f\u80fd\u62c6\u5206\uff0c\u907f\u514d\u5355\u9875\u8fc7\u957f') . '</span>' . "\n"
    . '        </div>' . "\n"
    . '    </div>' . "\n"
    . '    <div class="panel-body">' . "\n"
    . '        {include file="fanshub/config/_nav" /}' . "\n"
    . '        <div class="row">' . "\n";
foreach ($cards as $card) {
    $style = $card[0] === 'market' ? ' style="color:#9b59b6;"' : ($card[0] === 'i18n' ? ' style="color:#16a085;"' : '');
    $icoClass = $card[1];
    if ($card[0] === 'market') {
        $icoClass = 'fa-line-chart';
        $style = ' style="color:#9b59b6;"';
    } elseif ($card[0] === 'i18n') {
        $icoClass = 'fa-language';
        $style = ' style="color:#16a085;"';
    } else {
        $style = '';
    }
    $index .= '            <div class="col-sm-4" style="margin-bottom:12px;">' . "\n"
        . '                <a href="{:url(\'fanshub.config/' . $card[0] . '\')}" class="btn btn-default btn-block btn-lg addtabsit" style="text-align:left;padding:16px 18px;">' . "\n"
        . '                    <i class="fa ' . $icoClass . ($style ? '' : '') . '"' . $style . '></i> <strong>' . $u($card[2]) . '</strong>' . "\n"
        . '                    <div class="text-muted" style="font-size:12px;margin-top:6px;white-space:normal;">' . $u($card[3]) . '</div>' . "\n"
        . '                </a>' . "\n"
        . '            </div>' . "\n";
}
$index .= '            <div class="col-sm-4" style="margin-bottom:12px;">' . "\n"
    . '                <a href="{:url(\'fanshub.sms/index\')}" class="btn btn-default btn-block btn-lg addtabsit" style="text-align:left;padding:16px 18px;">' . "\n"
    . '                    <i class="fa fa-envelope text-info"></i> <strong>' . $u('\u77ed\u4fe1\u914d\u7f6e') . '</strong>' . "\n"
    . '                    <div class="text-muted" style="font-size:12px;margin-top:6px;white-space:normal;">' . $u('\u5927\u72d7\u77ed\u4fe1 / \u56fd\u9645\u77ed\u4fe1\u901a\u9053\u53c2\u6570') . '</div>' . "\n"
    . '                </a>' . "\n"
    . '            </div>' . "\n"
    . '        </div>' . "\n"
    . '    </div>' . "\n"
    . '</div>' . "\n";
w("$view/index.html", $index);

// Fix any remaining literal \n in other html files under config
foreach (glob("$view/*.html") as $f) {
    $c = file_get_contents($f);
    if (strpos($c, '\\n') === false) {
        continue;
    }
    // Only replace literal backslash-n that were meant as newlines (not in JS strings carefully)
    // For template files, replace all \n sequences that appear as two-char literal
    $fixed = str_replace('\\n', "\n", $c);
    // Avoid breaking JS if any - config templates rarely have JS \\n intentionally except copy_fields script
    if (basename($f) === 'copy_fields.html') {
        // restore JS string newlines if we broke them - copy_fields uses real newlines in script normally
        // After replace, JS string '...\n...' becomes real newline inside quotes which breaks JS.
        // So for copy_fields, regenerate separately below; skip auto fix here.
        continue;
    }
    file_put_contents($f, $fixed);
    echo 'FIX literal\\n in ' . basename($f) . "\n";
}

echo "DONE shells/nav/index\n";

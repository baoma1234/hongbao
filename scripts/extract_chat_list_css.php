<?php
/**
 * Extract slim CSS slices from chat.bundle.css for list / friend pages.
 */
$root = dirname(__DIR__);
$bundle = file_get_contents($root . '/uni-999/src/styles/chat.bundle.css');
$paritySrc = file_get_contents($root . '/uni-999/src/styles/chat-888-parity.css');

function css_top_rules($css)
{
    $out = [];
    $len = strlen($css);
    $i = 0;
    while ($i < $len) {
        while ($i < $len && ctype_space($css[$i])) {
            $i++;
        }
        if ($i >= $len) {
            break;
        }
        if ($css[$i] === '/' && ($css[$i + 1] ?? '') === '*') {
            $end = strpos($css, '*/', $i + 2);
            $i = $end === false ? $len : $end + 2;
            continue;
        }
        $start = $i;
        $depth = 0;
        $started = false;
        for (; $i < $len; $i++) {
            $ch = $css[$i];
            if ($ch === '{') {
                $depth++;
                $started = true;
            } elseif ($ch === '}') {
                $depth--;
                if ($started && $depth === 0) {
                    $i++;
                    $out[] = trim(substr($css, $start, $i - $start));
                    break;
                }
            }
        }
        if (!$started) {
            break;
        }
    }
    return $out;
}

function collect_classes($vuePath, array $extra = [])
{
    $vue = file_get_contents($vuePath);
    $classes = [];
    foreach (['/class="([^"]+)"/', "/class='([^']+)'/"] as $re) {
        if (preg_match_all($re, $vue, $m)) {
            foreach ($m[1] as $c) {
                foreach (preg_split('/\s+/', $c) as $p) {
                    $p = trim($p);
                    if ($p === '' || strpbrk($p, '{:()') !== false) {
                        continue;
                    }
                    $classes[$p] = true;
                }
            }
        }
    }
    if (preg_match_all('/[\'"]((?:chat|msg|tab|cg)-[a-zA-Z0-9_-]+)[\'"]/', $vue, $m3)) {
        foreach ($m3[1] as $p) {
            $classes[$p] = true;
        }
    }
    foreach ($extra as $p) {
        $classes[$p] = true;
    }
    return $classes;
}

function rule_hits(array $classes, $rule, $denyRoom = true)
{
    if ($denyRoom) {
        $roomOnly = preg_match(
            '/\.chat-room-page|\.chat-composer|\.chat-bubble|\.chat-rp-send|\.chat-msg-row|\.chat-toolbar|\.chat-input-bar|\.chat-room-pane|\.chat-msg-list/',
            $rule
        );
        $listHit = preg_match(
            '/\.chat-list-|\.chat-conv-|\.chat-home-|\.chat-notice-|\.chat-commission|\.chat-community|\.chat-official|\.chat-feed|\.chat-avatar|\.chat-shell|\.chat-hero|\.chat-plus|\.chat-empty|\.chat-my-|\.chat-conn|\.chat-create|\.chat-fission|\.chat-promote|\.chat-friend|\.chat-badge|\.chat-admin|\.chat-add-friend|\.chat-setting-|\.chat-sub-main|\.chat-friend-page/',
            $rule
        );
        if ($roomOnly && !$listHit) {
            return false;
        }
    }
    foreach ($classes as $cls => $_) {
        if ($cls === '') {
            continue;
        }
        if (preg_match('/[.#]' . preg_quote($cls, '/') . '(?![a-zA-Z0-9_-])/', $rule)) {
            return true;
        }
    }
    if (strpos($rule, ':root') === 0 || strpos($rule, ':root ') === 0 || preg_match('/^:root\b/', $rule)) {
        return true;
    }
    return false;
}

function extract_for(array $vueFiles, array $extraClasses, $outRel, $banner, $bundle, $denyRoom = true)
{
    global $root;
    $classes = [];
    foreach ($vueFiles as $f) {
        foreach (collect_classes($root . $f, $extraClasses) as $k => $_) {
            $classes[$k] = true;
        }
    }
    $rules = css_top_rules($bundle);
    $keep = [];
    foreach ($rules as $rule) {
        if (rule_hits($classes, $rule, $denyRoom)) {
            $keep[] = $rule;
        }
    }
    $out = $banner . implode("\n\n", $keep) . "\n";
    $path = $root . $outRel;
    file_put_contents($path, $out);
    echo basename($path) . ' classes=' . count($classes) . ' rules=' . count($keep) . ' KB=' . round(strlen($out) / 1024, 1) . PHP_EOL;
    return $classes;
}

$sharedExtra = [
    'chat-shell', 'messages-page', 'msg-tab-root', 'tab-page', 'tab-messages',
    'is-hidden', 'online', 'offline', 'connecting', 'group', 'admin', 'pinned',
    'active', 'active-light', 'danger', 'ok', 'reject', 'accept',
];

extract_for(
    ['/uni-999/src/pages/messages/messages.vue'],
    $sharedExtra,
    '/uni-999/src/styles/chat-messages-list.css',
    "/* Auto-extracted from chat.bundle.css for messages list tab. Prefer chat-uni-adapter.css for list-only fixes. */\n",
    $bundle,
    true
);

extract_for(
    [
        '/uni-999/src/pages/friend/add.vue',
        '/uni-999/src/pages/friend/requests.vue',
    ],
    array_merge($sharedExtra, ['chat-friend-page', 'chat-sub-main', 'chat-hero-spacer']),
    '/uni-999/src/styles/chat-friend-shell.css',
    "/* Auto-extracted from chat.bundle.css for friend add/requests pages. */\n",
    $bundle,
    true
);

extract_for(
    ['/uni-999/src/pages/group/settings.vue'],
    array_merge($sharedExtra, [
        'chat-group-settings-page', 'chat-sub-main', 'chat-hero-spacer', 'chat-switch',
        'chat-forbid-modes', 'chat-forbid-item', 'chat-forbid-check', 'is-on', 'on',
    ]),
    '/uni-999/src/styles/chat-group-settings.css',
    "/* Auto-extracted from chat.bundle.css for group settings page. */\n",
    $bundle,
    true
);

// Slim parity for messages
$msgClasses = collect_classes($root . '/uni-999/src/pages/messages/messages.vue', $sharedExtra);
$prules = css_top_rules($paritySrc);
$pkeep = [];
foreach ($prules as $rule) {
    if (strpos($rule, '.chat-room-page') !== false) {
        continue;
    }
    if (preg_match('/\.chat-rp-|\.chat-composer|\.chat-bubble|\.chat-msg-/', $rule)) {
        continue;
    }
    if (rule_hits($msgClasses, $rule, true)) {
        $pkeep[] = $rule;
    }
}
$parityOut = "/* Slim parity for messages list (room/rp rules omitted). */\n" . implode("\n\n", $pkeep) . "\n";
file_put_contents($root . '/uni-999/src/styles/chat-messages-parity.css', $parityOut);
echo 'chat-messages-parity.css KB=' . round(strlen($parityOut) / 1024, 1) . PHP_EOL;

// Group settings parity slice
$gkeep = [];
foreach ($prules as $rule) {
    if (preg_match('/chat-group-settings|chat-setting-|chat-forbid|chat-switch|chat-member-|chat-action-|chat-invite-box/', $rule)
        && !preg_match('/\.chat-room-page|\.chat-composer|\.chat-bubble|\.chat-rp-|\.chat-msg-/', $rule)
    ) {
        $gkeep[] = $rule;
    }
}
$gout = "/* Slim parity for group settings (from chat-888-parity.css). */\n" . implode("\n\n", $gkeep) . "\n";
file_put_contents($root . '/uni-999/src/styles/chat-group-settings-parity.css', $gout);
echo 'chat-group-settings-parity.css KB=' . round(strlen($gout) / 1024, 1) . PHP_EOL;

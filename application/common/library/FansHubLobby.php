<?php

namespace app\common\library;

use think\Db;

/**
 * 大厅装修（轮播 / 分类 / 游戏格 / 邀请条）
 */
class FansHubLobby
{
    const CACHE_KEY = 'fanshub_lobby_home_v4';
    const OG_READY_KEY = 'fanshub_lobby_og_ready_v1';
    const HOT_READY_KEY = 'fanshub_lobby_hot_ready_v1';

    public static function clearCache()
    {
        try {
            \think\Cache::rm(self::CACHE_KEY);
            \think\Cache::rm(self::CACHE_KEY . '_live');
            \think\Cache::rm(self::CACHE_KEY . '_hot');
        } catch (\Throwable $e) {
        }
    }

    public static function isLiveEnabled()
    {
        try {
            return !empty(\think\Config::get('fanshub.lobby_live_enabled'));
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function liveTestToken()
    {
        try {
            return trim((string)\think\Config::get('fanshub.lobby_live_test_token'));
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * 热门推荐改为真人视讯，并补上 OG GameID 列与四款视讯游戏。只跑一次。
     */
    public static function ensureOgLobby()
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            if (\think\Cache::get(self::OG_READY_KEY)) {
                return;
            }
        } catch (\Throwable $e) {
        }

        try {
            $prefix = (string)\think\Config::get('database.prefix');
            if ($prefix === '') {
                $prefix = 'fa_';
            }
            $gamesTable = $prefix . 'fans_lobby_games';
            $col = Db::query("SHOW COLUMNS FROM `{$gamesTable}` LIKE 'og_game_id'");
            if (!$col) {
                Db::execute("ALTER TABLE `{$gamesTable}` ADD COLUMN `og_game_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'OG GameID，0 表示非视讯' AFTER `title`");
            }

            $now = time();
            $live = Db::name('fans_lobby_categories')->where('cat_key', 'live')->find();
            $hot = Db::name('fans_lobby_categories')->where('cat_key', 'hot')->find();
            if (!$live && $hot) {
                Db::name('fans_lobby_categories')->where('id', (int)$hot['id'])->update([
                    'cat_key'     => 'live',
                    'title'       => '真人视讯',
                    'icon'        => 'home/lobby/cat-live.png',
                    'icon_static' => '',
                    'action'      => 'filter',
                    'weigh'       => 190,
                    'status'      => 'normal',
                    'updatetime'  => $now,
                ]);
            } elseif (!$live) {
                Db::name('fans_lobby_categories')->insert([
                    'cat_key'     => 'live',
                    'title'       => '真人视讯',
                    'icon'        => 'home/lobby/cat-live.png',
                    'icon_static' => '',
                    'action'      => 'filter',
                    'action_url'  => '',
                    'weigh'       => 190,
                    'status'      => 'normal',
                    'createtime'  => $now,
                    'updatetime'  => $now,
                ]);
            }

            $gamesCat = Db::name('fans_lobby_categories')->where('cat_key', 'games')->find();
            $top = Db::name('fans_lobby_categories')->order('weigh', 'desc')->order('id', 'asc')->find();
            if ($gamesCat && (!$top || (string)$top['cat_key'] !== 'games')) {
                $max = (int)Db::name('fans_lobby_categories')->max('weigh');
                Db::name('fans_lobby_categories')->where('id', (int)$gamesCat['id'])->update([
                    'weigh'      => $max + 10,
                    'updatetime' => $now,
                ]);
            }

            $tagged = Db::name('fans_lobby_games')->where('cats', 'like', '%hot%')->select();
            foreach ((array)$tagged as $row) {
                $parts = preg_split('/\s*,\s*/', trim((string)($row['cats'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
                $parts = array_values(array_filter($parts, function ($x) {
                    return $x !== 'hot';
                }));
                if (!$parts) {
                    $parts = ['games'];
                }
                Db::name('fans_lobby_games')->where('id', (int)$row['id'])->update([
                    'cats'       => implode(',', $parts),
                    'updatetime' => $now,
                ]);
            }

            $seed = [
                ['og_baccarat', '百家乐', 35, 'home/lobby/og-baccarat.png', 40],
                ['og_dragon', '经典龙虎', 32, 'home/lobby/og-dragon.png', 30],
                ['og_roulette', '轮盘', 34, 'home/lobby/og-roulette.png', 20],
                ['og_niuniu', '牛牛', 30, 'home/lobby/og-niuniu.png', 10],
            ];
            foreach ($seed as $g) {
                $exists = Db::name('fans_lobby_games')->where('game_key', $g[0])->find();
                if ($exists) {
                    if ((int)($exists['og_game_id'] ?? 0) <= 0) {
                        Db::name('fans_lobby_games')->where('id', (int)$exists['id'])->update([
                            'og_game_id' => (int)$g[2],
                            'updatetime' => $now,
                        ]);
                    }
                    continue;
                }
                Db::name('fans_lobby_games')->insert([
                    'game_key'        => $g[0],
                    'title'           => $g[1],
                    'og_game_id'      => (int)$g[2],
                    'cover'           => $g[3],
                    'badge'           => '',
                    'cats'            => 'live',
                    'group_match'     => '',
                    'sum_group_match' => '',
                    'coming_soon'     => 0,
                    'weigh'           => (int)$g[4],
                    'status'          => 'normal',
                    'createtime'      => $now,
                    'updatetime'      => $now,
                ]);
            }

            self::clearCache();
            try {
                \think\Cache::set(self::OG_READY_KEY, 1, 86400 * 365);
            } catch (\Throwable $e) {
            }
        } catch (\Throwable $e) {
            $done = false;
        }
    }

    /**
     * 暂停真人视讯时：补回「热门推荐」，红宝游戏打回 hot,games 标签。
     */
    public static function ensureHotLobby()
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            if (\think\Cache::get(self::HOT_READY_KEY)) {
                return;
            }
        } catch (\Throwable $e) {
        }

        try {
            $now = time();
            $hot = Db::name('fans_lobby_categories')->where('cat_key', 'hot')->find();
            $maxWeigh = (int)Db::name('fans_lobby_categories')->max('weigh');
            $hotWeigh = max(210, $maxWeigh + 10);
            if (!$hot) {
                Db::name('fans_lobby_categories')->insert([
                    'cat_key'     => 'hot',
                    'title'       => '热门推荐',
                    'icon'        => 'home/lobby/cat-1.png',
                    'icon_static' => '',
                    'action'      => 'filter',
                    'action_url'  => '',
                    'weigh'       => $hotWeigh,
                    'status'      => 'normal',
                    'createtime'  => $now,
                    'updatetime'  => $now,
                ]);
            } else {
                Db::name('fans_lobby_categories')->where('id', (int)$hot['id'])->update([
                    'title'      => '热门推荐',
                    'icon'       => 'home/lobby/cat-1.png',
                    'action'     => 'filter',
                    'status'     => 'normal',
                    'weigh'      => $hotWeigh,
                    'updatetime' => $now,
                ]);
            }

            $rows = Db::name('fans_lobby_games')
                ->where('status', 'normal')
                ->where('cats', 'like', '%games%')
                ->select();
            foreach ((array)$rows as $row) {
                if ((int)($row['og_game_id'] ?? 0) > 0) {
                    continue;
                }
                $parts = preg_split('/\s*,\s*/', trim((string)($row['cats'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
                if (!in_array('hot', $parts, true)) {
                    array_unshift($parts, 'hot');
                    $parts = array_values(array_unique($parts));
                    Db::name('fans_lobby_games')->where('id', (int)$row['id'])->update([
                        'cats'       => implode(',', $parts),
                        'updatetime' => $now,
                    ]);
                }
            }

            self::clearCache();
            try {
                \think\Cache::set(self::HOT_READY_KEY, 1, 86400 * 365);
            } catch (\Throwable $e2) {
            }
        } catch (\Throwable $e) {
            $done = false;
        }
    }

    public static function resolveImage($raw)
    {
        $u = trim((string)$raw);
        if ($u === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $u)) {
            return $u;
        }
        $u = ltrim(str_replace('\\', '/', $u), '/');
        // 打包 static：home/lobby/xxx.png → OSS /999/static/...（启用时）或原相对路径
        if (strpos($u, 'home/lobby/') === 0 || strpos($u, 'static/') === 0) {
            $path = $u;
            if (strpos($path, 'static/') === 0) {
                $path = substr($path, strlen('static/'));
            }
            if (strpos($path, 'home/lobby/') === 0) {
                try {
                    if (class_exists('\\app\\common\\library\\OssService') && \app\common\library\OssService::enabled()) {
                        $full = \app\common\library\OssService::fullUrl('/999/static/' . $path, '');
                        if ($full !== '') {
                            return $full;
                        }
                    }
                } catch (\Throwable $e) {
                }
                return '/999/static/' . $path;
            }
            return $u;
        }
        try {
            if (class_exists('\\app\\common\\library\\OssService') && \app\common\library\OssService::enabled()) {
                $full = \app\common\library\OssService::fullUrl('/' . $u, '');
                if ($full) {
                    return $full;
                }
            }
        } catch (\Throwable $e) {
        }
        if (function_exists('cdnurl')) {
            return cdnurl('/' . $u, true);
        }
        return '/' . $u;
    }

    /**
     * 后台列表/预览绝对地址（打包图走 /999/static，上传图走 OSS）
     */
    public static function adminUrl($raw)
    {
        $u = trim((string)$raw);
        if ($u === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $u) || stripos($u, 'data:') === 0) {
            try {
                if (class_exists('\\app\\common\\library\\OssService') && \app\common\library\OssService::enabled()) {
                    $path = parse_url($u, PHP_URL_PATH);
                    if (is_string($path) && strpos($path, '/uploads/') === 0) {
                        $oss = \app\common\library\OssService::fullUrl($path, '');
                        if ($oss !== '') {
                            return $oss;
                        }
                    }
                }
            } catch (\Throwable $e) {
            }
            return $u;
        }
        $path = ltrim(str_replace('\\', '/', $u), '/');
        if (strpos($path, 'static/') === 0) {
            $path = substr($path, strlen('static/'));
        }
        if (strpos($path, 'home/lobby/') === 0) {
            $rel = '/999/static/' . $path;
            try {
                if (class_exists('\\app\\common\\library\\OssService') && \app\common\library\OssService::enabled()) {
                    $full = \app\common\library\OssService::fullUrl($rel, '');
                    if ($full !== '') {
                        return $full;
                    }
                }
            } catch (\Throwable $e) {
            }
            return $rel;
        }
        // 已是 /999/static/... 时同样优先 OSS
        if (strpos($path, '999/static/') === 0) {
            $rel = '/' . $path;
            try {
                if (class_exists('\\app\\common\\library\\OssService') && \app\common\library\OssService::enabled()) {
                    $full = \app\common\library\OssService::fullUrl($rel, '');
                    if ($full !== '') {
                        return $full;
                    }
                }
            } catch (\Throwable $e) {
            }
            return $rel;
        }
        try {
            if (class_exists('\\app\\common\\library\\OssService') && \app\common\library\OssService::enabled()) {
                $full = \app\common\library\OssService::fullUrl('/' . $path, '');
                if ($full !== '') {
                    return $full;
                }
            }
        } catch (\Throwable $e) {
        }
        if (function_exists('cdnurl')) {
            return cdnurl('/' . $path, true);
        }
        return '/' . $path;
    }

    /**
     * 保存前：OSS/本站绝对上传地址收成 /uploads/...
     */
    public static function normalizeStoredPath($raw)
    {
        $u = trim((string)$raw);
        if ($u === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $u)) {
            $path = parse_url($u, PHP_URL_PATH);
            if (is_string($path) && $path !== '' && strpos($path, '/uploads/') === 0) {
                return $path;
            }
            return $u;
        }
        if (isset($u[0]) && $u[0] !== '/' && strpos($u, 'uploads/') === 0) {
            return '/' . $u;
        }
        return $u;
    }

    /** @param array|\think\Collection $rows @return array */
    public static function mapAdminImageFields($rows, array $fields)
    {
        $out = [];
        foreach ((array)$rows as $row) {
            if (is_object($row) && method_exists($row, 'toArray')) {
                $item = $row->toArray();
            } else {
                $item = (array)$row;
            }
            foreach ($fields as $f) {
                if (array_key_exists($f, $item)) {
                    $item[$f] = self::adminUrl($item[$f]);
                }
            }
            $out[] = $item;
        }
        return $out;
    }

    public static function isPackagedStatic($path)
    {
        $p = ltrim((string)$path, '/');
        return $p !== '' && (
            strpos($p, 'home/lobby/') === 0
            || strpos($p, 'static/') === 0
            || !preg_match('#^https?://#i', $p) && strpos($p, '/') === false
        );
    }

    /** @return array{banners:array,categories:array,games:array,invites:array} */
    /** @param bool|null $includeLive null=跟配置；true=强制含真人视讯（测试接口） */
    public static function homePayload($includeLive = null)
    {
        self::ensureOgLobby();
        if ($includeLive === null) {
            $includeLive = self::isLiveEnabled();
        } else {
            $includeLive = (bool)$includeLive;
        }
        if (!$includeLive) {
            self::ensureHotLobby();
        }

        $cacheKey = self::CACHE_KEY . ($includeLive ? '_live' : '_hot');
        $cached = null;
        try {
            $cached = \think\Cache::get($cacheKey);
        } catch (\Throwable $e) {
        }
        if (is_array($cached) && isset($cached['banners'], $cached['categories'], $cached['games'])) {
            $cached['live_enabled'] = $includeLive ? 1 : 0;
            return $includeLive ? self::withLiveOnline($cached) : self::stripLive($cached);
        }

        $banners = [];
        try {
            $rows = Db::name('fans_lobby_banners')
                ->where('status', 'normal')
                ->order('weigh', 'desc')
                ->order('id', 'desc')
                ->select();
            foreach ((array)$rows as $r) {
                $img = self::resolveImage($r['image'] ?? '');
                if ($img === '') {
                    continue;
                }
                $banners[] = [
                    'id'         => (int)$r['id'],
                    'title'      => (string)($r['title'] ?? ''),
                    'image'      => $img,
                    'image_raw'  => (string)($r['image'] ?? ''),
                    'link_type'  => (string)($r['link_type'] ?? 'none'),
                    'link_url'   => (string)($r['link_url'] ?? ''),
                    'packaged'   => self::isPackagedStatic($r['image'] ?? ''),
                ];
            }
        } catch (\Throwable $e) {
        }

        $categories = [];
        try {
            $rows = Db::name('fans_lobby_categories')
                ->where('status', 'normal')
                ->order('weigh', 'desc')
                ->order('id', 'asc')
                ->select();
            foreach ((array)$rows as $r) {
                $categories[] = [
                    'id'          => (int)$r['id'],
                    'key'         => (string)($r['cat_key'] ?? ''),
                    'title'       => (string)($r['title'] ?? ''),
                    'icon'        => self::resolveImage($r['icon'] ?? ''),
                    'icon_raw'    => (string)($r['icon'] ?? ''),
                    'icon_static' => (string)($r['icon_static'] ?? ''),
                    'action'      => (string)($r['action'] ?? 'filter'),
                    'action_url'  => (string)($r['action_url'] ?? ''),
                    'packaged'    => self::isPackagedStatic($r['icon'] ?? ''),
                ];
            }
        } catch (\Throwable $e) {
        }

        $games = [];
        try {
            $rows = Db::name('fans_lobby_games')
                ->where('status', 'normal')
                ->order('weigh', 'desc')
                ->order('id', 'asc')
                ->select();
            foreach ((array)$rows as $r) {
                $cats = preg_split('/\s*,\s*/', trim((string)($r['cats'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
                $games[] = [
                    'id'              => (int)$r['id'],
                    'key'             => (string)($r['game_key'] ?? ''),
                    'title'           => (string)($r['title'] ?? ''),
                    'og_game_id'      => (int)($r['og_game_id'] ?? 0),
                    'cover'           => self::resolveImage($r['cover'] ?? ''),
                    'cover_raw'       => (string)($r['cover'] ?? ''),
                    'badge'           => (string)($r['badge'] ?? ''),
                    'cats'            => array_values($cats ?: []),
                    'group_match'     => (string)($r['group_match'] ?? ''),
                    'sum_group_match' => (string)($r['sum_group_match'] ?? ''),
                    'coming_soon'     => !empty($r['coming_soon']),
                    'packaged'        => self::isPackagedStatic($r['cover'] ?? ''),
                    'order'           => (int)($r['weigh'] ?? 0),
                    'online_count'    => 0,
                ];
            }
        } catch (\Throwable $e) {
        }

        $invites = [];
        try {
            $rows = Db::name('fans_lobby_invites')
                ->where('status', 'normal')
                ->order('weigh', 'desc')
                ->order('id', 'desc')
                ->select();
            foreach ((array)$rows as $r) {
                $img = self::resolveImage($r['image'] ?? '');
                if ($img === '') {
                    continue;
                }
                $invites[] = [
                    'id'        => (int)$r['id'],
                    'title'     => (string)($r['title'] ?? ''),
                    'image'     => $img,
                    'image_raw' => (string)($r['image'] ?? ''),
                    'link_type' => (string)($r['link_type'] ?? 'share'),
                    'link_url'  => (string)($r['link_url'] ?? ''),
                    'packaged'  => self::isPackagedStatic($r['image'] ?? ''),
                ];
            }
        } catch (\Throwable $e) {
        }

        $payload = [
            'banners'      => $banners,
            'categories'   => $categories,
            'games'        => $games,
            'invites'      => $invites,
            'live_enabled' => $includeLive ? 1 : 0,
        ];
        try {
            \think\Cache::set($cacheKey, $payload, 60);
        } catch (\Throwable $e2) {
        }
        return $includeLive ? self::withLiveOnline($payload) : self::stripLive($payload);
    }

    /** 正式大厅：去掉真人视讯分类与 OG 游戏，保留热门 */
    protected static function stripLive(array $payload)
    {
        $cats = [];
        foreach ((array)($payload['categories'] ?? []) as $c) {
            if (!is_array($c)) {
                continue;
            }
            $key = strtolower(trim((string)($c['key'] ?? '')));
            if ($key === 'live') {
                continue;
            }
            $cats[] = $c;
        }
        $payload['categories'] = array_values($cats);

        $games = [];
        foreach ((array)($payload['games'] ?? []) as $g) {
            if (!is_array($g)) {
                continue;
            }
            $key = strtolower(trim((string)($g['key'] ?? '')));
            $ogId = (int)($g['og_game_id'] ?? 0);
            $gCats = isset($g['cats']) && is_array($g['cats']) ? $g['cats'] : [];
            if ($ogId > 0 || in_array('live', $gCats, true) || strpos($key, 'og_') === 0) {
                continue;
            }
            $games[] = $g;
        }
        $payload['games'] = array_values($games);
        $payload['live_enabled'] = 0;
        return $payload;
    }

    /** 每次请求刷新真人视讯在线分摊（不进缓存，按分钟桶变化） */
    protected static function withLiveOnline(array $payload)
    {
        if (empty($payload['games']) || !is_array($payload['games'])) {
            $payload['live_enabled'] = 1;
            return $payload;
        }
        // 测试/开启时：隐藏「热门推荐」，只留真人视讯 + 红宝等
        $cats = [];
        foreach ((array)($payload['categories'] ?? []) as $c) {
            if (!is_array($c)) {
                continue;
            }
            if (strtolower(trim((string)($c['key'] ?? ''))) === 'hot') {
                continue;
            }
            $cats[] = $c;
        }
        $payload['categories'] = array_values($cats);

        $map = [];
        try {
            $map = FansHubOfficialStats::liveOnlineMap();
        } catch (\Throwable $e) {
            $map = [];
        }
        foreach ($payload['games'] as $i => $g) {
            if (!is_array($g)) {
                continue;
            }
            $key = strtolower(trim((string)($g['key'] ?? '')));
            $ogId = (int)($g['og_game_id'] ?? 0);
            $gCats = isset($g['cats']) && is_array($g['cats']) ? $g['cats'] : [];
            $isLive = $ogId > 0 || in_array('live', $gCats, true) || strpos($key, 'og_') === 0;
            if ($isLive && $key !== '' && isset($map[$key])) {
                $payload['games'][$i]['online_count'] = (int)$map[$key];
            }
        }
        $payload['live_enabled'] = 1;
        return $payload;
    }
}

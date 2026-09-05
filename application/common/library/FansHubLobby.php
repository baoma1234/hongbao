<?php

namespace app\common\library;

use think\Db;

/**
 * 大厅装修（轮播 / 分类 / 游戏格 / 邀请条）
 */
class FansHubLobby
{
    const CACHE_KEY = 'fanshub_lobby_home_v1';

    public static function clearCache()
    {
        try {
            \think\Cache::rm(self::CACHE_KEY);
        } catch (\Throwable $e) {
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
        $u = ltrim($u, '/');
        // 打包 static：home/lobby/xxx.png → 前端用 packagedStaticUrl
        if (strpos($u, 'home/lobby/') === 0 || strpos($u, 'static/') === 0) {
            return $u;
        }
        try {
            if (class_exists('\\app\\common\\library\\OssService') && \app\common\library\OssService::enabled()) {
                $full = \app\common\library\OssService::fullUrl($u, '');
                if ($full) {
                    return $full;
                }
            }
        } catch (\Throwable $e) {
        }
        if (function_exists('cdnurl')) {
            return cdnurl($u, true);
        }
        return '/' . $u;
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
    public static function homePayload()
    {
        $cached = null;
        try {
            $cached = \think\Cache::get(self::CACHE_KEY);
        } catch (\Throwable $e) {
        }
        if (is_array($cached) && isset($cached['banners'], $cached['categories'], $cached['games'])) {
            return $cached;
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
                    'cover'           => self::resolveImage($r['cover'] ?? ''),
                    'cover_raw'       => (string)($r['cover'] ?? ''),
                    'badge'           => (string)($r['badge'] ?? ''),
                    'cats'            => array_values($cats ?: []),
                    'group_match'     => (string)($r['group_match'] ?? ''),
                    'sum_group_match' => (string)($r['sum_group_match'] ?? ''),
                    'coming_soon'     => !empty($r['coming_soon']),
                    'packaged'        => self::isPackagedStatic($r['cover'] ?? ''),
                    'order'           => (int)($r['weigh'] ?? 0),
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
            'banners'    => $banners,
            'categories' => $categories,
            'games'      => $games,
            'invites'    => $invites,
        ];
        try {
            \think\Cache::set(self::CACHE_KEY, $payload, 60);
        } catch (\Throwable $e2) {
        }
        return $payload;
    }
}

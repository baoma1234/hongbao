<?php

namespace app\common\library;

use think\Db;

/**
 * 大厅玩法说明（游戏详情 intro/rules，后台可编）
 */
class FansHubLobbyGuide
{
    const CACHE_KEY = 'fanshub_lobby_guides_v1';

    public static function clearCache()
    {
        try {
            \think\Cache::rm(self::CACHE_KEY);
        } catch (\Throwable $e) {
        }
    }

    /**
     * @return array<string,array>
     */
    public static function allMap()
    {
        $cached = null;
        try {
            $cached = \think\Cache::get(self::CACHE_KEY);
        } catch (\Throwable $e) {
        }
        if (is_array($cached)) {
            return $cached;
        }
        $map = [];
        try {
            $rows = Db::name('fans_lobby_guides')
                ->where('status', 'normal')
                ->order('weigh', 'desc')
                ->order('id', 'asc')
                ->select();
            foreach ((array)$rows as $r) {
                $key = preg_replace('/[^a-z0-9_]/i', '', strtolower(trim((string)($r['game_key'] ?? ''))));
                if ($key === '') {
                    continue;
                }
                $rulesRaw = trim((string)($r['rules'] ?? ''));
                $rules = [];
                if ($rulesRaw !== '') {
                    $parts = preg_split('/\r\n|\r|\n/', $rulesRaw);
                    foreach ((array)$parts as $line) {
                        $line = trim((string)$line);
                        if ($line !== '') {
                            $rules[] = $line;
                        }
                    }
                }
                $map[$key] = [
                    'game_key' => $key,
                    'title'    => (string)($r['title'] ?? ''),
                    'intro'    => (string)($r['intro'] ?? ''),
                    'rules'    => $rules,
                    'hero'     => FansHubLobby::resolveImage((string)($r['hero'] ?? '')),
                    'hero_raw' => (string)($r['hero'] ?? ''),
                    'badge'    => (string)($r['badge'] ?? ''),
                    'badge_text' => (string)($r['badge_text'] ?? ''),
                ];
            }
        } catch (\Throwable $e) {
            $map = [];
        }
        try {
            \think\Cache::set(self::CACHE_KEY, $map, 60);
        } catch (\Throwable $e) {
        }
        return $map;
    }

    public static function one($gameKey)
    {
        $key = preg_replace('/[^a-z0-9_]/i', '', strtolower(trim((string)$gameKey)));
        if ($key === '') {
            return null;
        }
        $map = self::allMap();
        return isset($map[$key]) ? $map[$key] : null;
    }
}

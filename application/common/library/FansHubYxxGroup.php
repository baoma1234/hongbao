<?php

namespace app\common\library;

use think\Db;

/**
 * 鱼虾蟹私域群桌：开/关桌、成员校验、群主 1% 分账。
 */
class FansHubYxxGroup
{
    const OWNER_RATE = 0.01;
    const OPEN_SET = 'fh:yxx:gtables';

    public static function assertMember($groupId, $uid)
    {
        $groupId = (int)$groupId;
        $uid = (int)$uid;
        if ($groupId <= 0 || $uid <= 0) {
            throw new \RuntimeException(FansHubService::h5CopyText('yxx_err_login') ?: '请先登录');
        }
        $mem = Db::name('chat_group_members')
            ->where('group_id', $groupId)
            ->where('user_id', $uid)
            ->where('status', 1)
            ->find();
        if (!$mem) {
            throw new \RuntimeException(FansHubService::h5CopyText('yxx_err_not_member') ?: '你不在该群');
        }
        return $mem;
    }

    public static function assertAdmin($groupId, $uid)
    {
        $mem = self::assertMember($groupId, $uid);
        $role = (int)($mem['role'] ?? 0);
        if ($role < 2) {
            throw new \RuntimeException(FansHubService::h5CopyText('yxx_err_not_admin') ?: '仅群主/管理员可操作');
        }
        return $mem;
    }

    public static function ownerId($groupId)
    {
        $groupId = (int)$groupId;
        if ($groupId <= 0) {
            return 0;
        }
        try {
            return (int)Db::name('chat_groups')->where('id', $groupId)->value('owner_user_id');
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function groupName($groupId)
    {
        try {
            return (string)Db::name('chat_groups')->where('id', $groupId)->value('name');
        } catch (\Throwable $e) {
            return '';
        }
    }

    public static function row($groupId)
    {
        $groupId = (int)$groupId;
        if ($groupId <= 0) {
            return null;
        }
        try {
            $row = Db::name('fans_yxx_group_state')->where('group_id', $groupId)->find();
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function isOpen($groupId)
    {
        $row = self::row($groupId);
        return $row && !empty($row['is_open']);
    }

    public static function ensureRow($groupId)
    {
        $groupId = (int)$groupId;
        $row = self::row($groupId);
        if ($row) {
            return $row;
        }
        $now = time();
        $owner = self::ownerId($groupId);
        try {
            Db::name('fans_yxx_group_state')->insert([
                'group_id'        => $groupId,
                'owner_user_id'   => $owner,
                'is_open'         => 0,
                'gross_pool'      => 0,
                'cycle_count'     => 0,
                'boom_half_count' => 0,
                'updatetime'      => $now,
            ]);
        } catch (\Throwable $e) {
        }
        return self::row($groupId) ?: [
            'group_id'        => $groupId,
            'owner_user_id'   => $owner,
            'is_open'         => 0,
            'gross_pool'      => 0,
            'cycle_count'     => 0,
            'boom_half_count' => 0,
        ];
    }

    public static function start($groupId, $uid)
    {
        self::assertAdmin($groupId, $uid);
        $row = self::ensureRow($groupId);
        $now = time();
        Db::name('fans_yxx_group_state')->where('group_id', (int)$groupId)->update([
            'is_open'       => 1,
            'owner_user_id' => self::ownerId($groupId) ?: (int)($row['owner_user_id'] ?? 0),
            'updatetime'    => $now,
        ]);
        self::markOpen($groupId, true);
        return self::publicState($groupId);
    }

    public static function stop($groupId, $uid)
    {
        self::assertAdmin($groupId, $uid);
        self::ensureRow($groupId);
        Db::name('fans_yxx_group_state')->where('group_id', (int)$groupId)->update([
            'is_open'    => 0,
            'updatetime' => time(),
        ]);
        self::markOpen($groupId, false);
        return self::publicState($groupId);
    }

    public static function publicState($groupId)
    {
        $row = self::ensureRow($groupId);
        return [
            'group_id'    => (int)$groupId,
            'group_name'  => self::groupName($groupId),
            'table_open'  => !empty($row['is_open']) ? 1 : 0,
            'owner_rate'  => self::OWNER_RATE,
            'gross_pool'  => (int)($row['gross_pool'] ?? 0),
            'cycle_count' => (int)($row['cycle_count'] ?? 0),
        ];
    }

    public static function markOpen($groupId, $open)
    {
        $groupId = (int)$groupId;
        $redis = FansHubYxxStore::redis();
        if ($redis) {
            try {
                $key = FansHubYxxStore::rkey(self::OPEN_SET);
                if ($open) {
                    $redis->sAdd($key, (string)$groupId);
                } else {
                    $redis->sRem($key, (string)$groupId);
                }
            } catch (\Throwable $e) {
            }
            return;
        }
        $list = \think\Cache::get(self::OPEN_SET);
        if (!is_array($list)) {
            $list = [];
        }
        if ($open) {
            $list[$groupId] = 1;
        } else {
            unset($list[$groupId]);
        }
        \think\Cache::set(self::OPEN_SET, $list, 86400 * 7);
    }

    /**
     * @return int[]
     */
    public static function openIds()
    {
        $redis = FansHubYxxStore::redis();
        if ($redis) {
            try {
                $raw = $redis->sMembers(FansHubYxxStore::rkey(self::OPEN_SET));
                $ids = [];
                foreach (is_array($raw) ? $raw : [] as $v) {
                    $id = (int)$v;
                    if ($id > 0) {
                        $ids[] = $id;
                    }
                }
                if ($ids) {
                    return $ids;
                }
            } catch (\Throwable $e) {
            }
        } else {
            $list = \think\Cache::get(self::OPEN_SET);
            if (is_array($list) && $list) {
                return array_map('intval', array_keys($list));
            }
        }
        try {
            $rows = Db::name('fans_yxx_group_state')->where('is_open', 1)->column('group_id');
            return is_array($rows) ? array_map('intval', $rows) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function gross($groupId)
    {
        $row = self::row($groupId);
        return max(0, (int)($row['gross_pool'] ?? 0));
    }

    public static function setGross($groupId, $amount)
    {
        $groupId = (int)$groupId;
        $amount = max(0, (int)$amount);
        self::ensureRow($groupId);
        try {
            Db::name('fans_yxx_group_state')->where('group_id', $groupId)->update([
                'gross_pool' => $amount,
                'updatetime' => time(),
            ]);
        } catch (\Throwable $e) {
        }
        return $amount;
    }

    public static function cycle($groupId)
    {
        $row = self::row($groupId);
        return max(0, (int)($row['cycle_count'] ?? 0));
    }

    public static function setCycle($groupId, $cycle, $halfBoom)
    {
        $groupId = (int)$groupId;
        self::ensureRow($groupId);
        try {
            Db::name('fans_yxx_group_state')->where('group_id', $groupId)->update([
                'cycle_count'     => max(0, (int)$cycle),
                'boom_half_count' => max(0, (int)$halfBoom),
                'updatetime'      => time(),
            ]);
        } catch (\Throwable $e) {
        }
    }

    public static function halfBoom($groupId)
    {
        $row = self::row($groupId);
        return max(0, (int)($row['boom_half_count'] ?? 0));
    }
}

<?php

namespace app\common\library;

use think\Db;

/**
 * 红宝消息页弹窗：列表（按用户过滤）+ 回执
 */
class FansHubMessagesPopup
{
    /**
     * @return array{list:array}
     */
    public static function listForUser($userId)
    {
        $userId = (int)$userId;
        $rows = Db::name('chat_messages_popups')
            ->where('status', 'normal')
            ->order('weigh', 'desc')
            ->order('id', 'desc')
            ->limit(20)
            ->select();
        if (!$rows) {
            return ['list' => []];
        }

        $popupIds = [];
        foreach ($rows as $r) {
            $popupIds[] = (int)$r['id'];
        }
        $dismissedDay = $userId > 0 ? self::userActionTodayIds($popupIds, $userId, 'dismiss_day') : [];
        $viewedOnce = $userId > 0 ? self::userActionIds($popupIds, $userId, 'dismiss_once') : [];

        $list = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $mode = (string)($r['show_mode'] ?? 'daily');
            if ($mode === 'once' && isset($viewedOnce[$id])) {
                continue;
            }
            if (isset($dismissedDay[$id])) {
                continue;
            }
            $list[] = self::formatPopup($r);
        }
        return ['list' => $list];
    }

    /**
     * @param int $popupId
     * @param int $userId
     * @param string $action view|dismiss_day|dismiss_once|click
     */
    public static function ack($popupId, $userId, $action = 'view')
    {
        $popupId = (int)$popupId;
        $userId = (int)$userId;
        if ($popupId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException('invalid params');
        }
        $row = Db::name('chat_messages_popups')->where('id', $popupId)->find();
        if (!$row) {
            throw new \InvalidArgumentException('popup not found');
        }
        $action = strtolower(trim((string)$action));
        if (!in_array($action, ['view', 'dismiss_day', 'dismiss_once', 'click'], true)) {
            $action = 'view';
        }
        // once 模式点关闭/点击都记 dismiss_once；daily 首次展示即记 dismiss_day（每日只弹一次）
        $mode = (string)($row['show_mode'] ?? 'daily');
        if ($mode === 'once' && in_array($action, ['dismiss_day', 'click', 'view'], true)) {
            $action = 'dismiss_once';
        } elseif ($mode === 'daily' && $action === 'view') {
            $action = 'dismiss_day';
        }
        Db::name('chat_messages_popup_logs')->insert([
            'popup_id'   => $popupId,
            'user_id'    => $userId,
            'action'     => $action,
            'createtime' => time(),
        ]);
        return ['ok' => 1, 'action' => $action];
    }

    protected static function formatPopup(array $r)
    {
        $images = $r['images'] ?? [];
        if (is_string($images)) {
            $trim = trim($images);
            if ($trim === '') {
                $images = [];
            } else {
                $decoded = json_decode($trim, true);
                $images = is_array($decoded) ? $decoded : array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $trim) ?: [])));
            }
        }
        if (!is_array($images)) {
            $images = [];
        }
        $jump = strtolower((string)($r['jump_type'] ?? 'none'));
        if (!in_array($jump, ['community', 'notice', 'url', 'none'], true)) {
            $jump = 'none';
        }
        $mode = (string)($r['show_mode'] ?? 'daily');
        if (!in_array($mode, ['daily', 'once', 'always'], true)) {
            $mode = 'daily';
        }
        return [
            'id'         => (int)$r['id'],
            'title'      => (string)($r['title'] ?? ''),
            'content'    => (string)($r['content'] ?? ''),
            'images'     => array_values(array_filter(array_map('strval', $images))),
            'jump_type'  => $jump,
            'jump_extra' => (string)($r['jump_extra'] ?? ''),
            'btn_text'   => (string)(($r['btn_text'] ?? '') !== '' ? $r['btn_text'] : '查看'),
            'show_mode'  => $mode,
            'weigh'      => (int)($r['weigh'] ?? 0),
        ];
    }

    protected static function userActionTodayIds(array $popupIds, $userId, $action)
    {
        if (!$popupIds) {
            return [];
        }
        $dayStart = strtotime(date('Y-m-d'));
        $rows = Db::name('chat_messages_popup_logs')
            ->where('user_id', (int)$userId)
            ->where('action', $action)
            ->where('popup_id', 'in', $popupIds)
            ->where('createtime', '>=', $dayStart)
            ->column('popup_id');
        $map = [];
        foreach ($rows as $pid) {
            $map[(int)$pid] = true;
        }
        return $map;
    }

    protected static function userActionIds(array $popupIds, $userId, $action)
    {
        if (!$popupIds) {
            return [];
        }
        $rows = Db::name('chat_messages_popup_logs')
            ->where('user_id', (int)$userId)
            ->where('action', $action)
            ->where('popup_id', 'in', $popupIds)
            ->column('popup_id');
        $map = [];
        foreach ($rows as $pid) {
            $map[(int)$pid] = true;
        }
        return $map;
    }
}

<?php

namespace Im\Service;

use Im\Support\Db;

/**
 * 进群弹窗：列表（按用户过滤）+ 展示/永久关闭回执
 */
class GroupPopupService
{
    /** @var GroupService */
    protected $groups;

    public function __construct(GroupService $groups = null)
    {
        $this->groups = $groups ?: new GroupService();
    }

    /**
     * 当前用户进入群时应展示的弹窗（已过滤 once / 永久关闭）
     */
    public function listForUser($groupId, $userId)
    {
        $groupId = (int)$groupId;
        $userId = (int)$userId;
        if ($groupId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException('invalid params');
        }
        if (!$this->groups->isMember($groupId, $userId)) {
            throw new \InvalidArgumentException('not in group');
        }

        $rows = Db::fetchAll(
            'SELECT id, group_id, title, title_i18n, content, content_i18n, images, show_mode, weigh
             FROM ' . Db::table('chat_group_popups')
            . ' WHERE group_id=? AND status=? ORDER BY weigh DESC, id DESC',
            [$groupId, 'normal']
        );
        if (!$rows) {
            return [];
        }

        $popupIds = [];
        foreach ($rows as $r) {
            $popupIds[] = (int)$r['id'];
        }
        $dismissed = $this->userActionPopupIds($popupIds, $userId, 'dismiss_forever');
        $viewedOnce = $this->userActionPopupIds($popupIds, $userId, 'view');

        $out = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $mode = ((string)($r['show_mode'] ?? '') === 'once') ? 'once' : 'always';
            if (isset($dismissed[$id])) {
                continue;
            }
            if ($mode === 'once' && isset($viewedOnce[$id])) {
                continue;
            }
            $out[] = $this->formatPopup($r, $mode);
        }
        return $out;
    }

    /**
     * 记录展示；forever=1 时记永久关闭（仅对 always 有意义，once 也可记）
     */
    public function ack($popupId, $userId, $forever = false)
    {
        $popupId = (int)$popupId;
        $userId = (int)$userId;
        if ($popupId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException('invalid params');
        }
        $row = Db::fetch(
            'SELECT id, group_id, show_mode, status FROM ' . Db::table('chat_group_popups') . ' WHERE id=? LIMIT 1',
            [$popupId]
        );
        if (!$row || (string)($row['status'] ?? '') !== 'normal') {
            throw new \InvalidArgumentException('popup unavailable');
        }
        $groupId = (int)$row['group_id'];
        if (!$this->groups->isMember($groupId, $userId)) {
            throw new \InvalidArgumentException('not in group');
        }

        $now = time();
        Db::exec(
            'INSERT INTO ' . Db::table('chat_group_popup_logs')
            . ' (popup_id, group_id, user_id, action, createtime) VALUES (?,?,?,?,?)',
            [$popupId, $groupId, $userId, 'view', $now]
        );
        $action = 'view';
        if ($forever) {
            Db::exec(
                'INSERT INTO ' . Db::table('chat_group_popup_logs')
                . ' (popup_id, group_id, user_id, action, createtime) VALUES (?,?,?,?,?)',
                [$popupId, $groupId, $userId, 'dismiss_forever', $now]
            );
            $action = 'dismiss_forever';
        }

        return [
            'popup_id' => $popupId,
            'group_id' => $groupId,
            'action'   => $action,
        ];
    }

    protected function userActionPopupIds(array $popupIds, $userId, $action)
    {
        $popupIds = array_values(array_filter(array_map('intval', $popupIds)));
        if (!$popupIds) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($popupIds), '?'));
        $params = $popupIds;
        $params[] = (int)$userId;
        $params[] = (string)$action;
        $rows = Db::fetchAll(
            'SELECT DISTINCT popup_id FROM ' . Db::table('chat_group_popup_logs')
            . " WHERE popup_id IN ($ph) AND user_id=? AND action=?",
            $params
        );
        $map = [];
        foreach ($rows ?: [] as $r) {
            $map[(int)$r['popup_id']] = true;
        }
        return $map;
    }

    protected function formatPopup(array $r, $mode)
    {
        return [
            'id'           => (int)$r['id'],
            'group_id'     => (int)$r['group_id'],
            'title'        => (string)($r['title'] ?? ''),
            'title_i18n'   => $this->decodeJsonMap($r['title_i18n'] ?? ''),
            'content'      => (string)($r['content'] ?? ''),
            'content_i18n' => $this->decodeJsonMap($r['content_i18n'] ?? ''),
            'images'       => $this->decodeImages($r['images'] ?? ''),
            'show_mode'    => $mode,
            'weigh'        => (int)($r['weigh'] ?? 0),
            'allow_forever_close' => $mode === 'always' ? 1 : 0,
        ];
    }

    protected function decodeJsonMap($raw)
    {
        if (is_array($raw)) {
            return $raw;
        }
        $raw = trim((string)$raw);
        if ($raw === '') {
            return new \stdClass();
        }
        $arr = json_decode($raw, true);
        return is_array($arr) ? $arr : new \stdClass();
    }

    protected function decodeImages($raw)
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map('strval', $raw)));
        }
        $raw = trim((string)$raw);
        if ($raw === '') {
            return [];
        }
        if ($raw[0] === '[') {
            $arr = json_decode($raw, true);
            return is_array($arr) ? array_values(array_filter(array_map('strval', $arr))) : [];
        }
        $parts = preg_split('/[\r\n,]+/', $raw);
        return array_values(array_filter(array_map('trim', $parts ?: [])));
    }
}

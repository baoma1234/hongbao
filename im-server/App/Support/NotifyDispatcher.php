<?php

namespace Im\Support;

use Im\Service\AdminService;
use Im\Service\GroupService;

/**
 * 将 notify 事件扇出给在线用户（不预过滤 offline，由 ConnMap 本地连接决定能否送达）
 */
class NotifyDispatcher
{
    public static function dispatch($type, array $message, $adminOnly = false, GroupService $groups = null)
    {
        $type = (string)$type;
        if ($type === '' || !$message) {
            return;
        }
        if ($adminOnly) {
            $uids = AdminService::adminUserIds();
            if (!$uids) {
                return;
            }
            if ($type === 'message.recalled') {
                PushBus::toUsers($uids, 'admin.notify', [
                    'event'   => $type,
                    'message' => $message,
                ]);
            } else {
                PushBus::toUsers($uids, $type, ['message' => $message]);
            }
            return;
        }

        if ((int)($message['conversation_type'] ?? 0) === 2) {
            $gid = (int)($message['group_id'] ?? 0);
            if ($gid > 0) {
                PushBus::toGroup($gid, $type, ['message' => $message]);
            }
            return;
        }
        $uids = array_filter([
            (int)($message['from_user_id'] ?? 0),
            (int)($message['to_user_id'] ?? 0),
        ]);
        if ($uids) {
            PushBus::toUsers(array_values($uids), $type, ['message' => $message]);
        }
    }
}

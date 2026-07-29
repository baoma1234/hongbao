<?php

namespace app\common\library;

use app\common\exception\UploadException;
use app\common\model\Attachment;
use think\Db;
use think\Exception;

/**
 * H5 自定义聊天表情包
 */
class FansHubSticker
{
    const USER_LIMIT = 50;

    const ALLOW_EXT = ['gif', 'png', 'jpg', 'jpeg'];

    const MAX_BYTES = 2097152; // 2MB

    public static function isAdminUser($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return false;
        }
        $row = Db::name('chat_agent_accounts')
            ->where(['user_id' => $userId, 'status' => 1])
            ->find();
        return !empty($row);
    }

    public static function listPayload($userId)
    {
        $userId = (int)$userId;
        $isAdmin = self::isAdminUser($userId);
        $rows = Db::name('chat_user_stickers')
            ->where(['user_id' => $userId, 'status' => 1])
            ->order('id desc')
            ->select();
        $items = [];
        foreach ($rows as $row) {
            $url = (string)($row['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $items[] = [
                'id'   => (int)$row['id'],
                'code' => (string)($row['name'] ?: ('表情' . (int)$row['id'])),
                'url'  => $url,
                'pack' => 'custom',
            ];
        }
        return [
            'is_admin' => $isAdmin,
            'limit'    => $isAdmin ? 0 : self::USER_LIMIT,
            'count'    => count($items),
            'items'    => $items,
        ];
    }

    public static function upload($userId, $file)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            throw new Exception('请先登录');
        }
        if (empty($file)) {
            throw new Exception('请选择图片');
        }
        $isAdmin = self::isAdminUser($userId);
        if (!$isAdmin) {
            $count = (int)Db::name('chat_user_stickers')
                ->where(['user_id' => $userId, 'status' => 1])
                ->count();
            if ($count >= self::USER_LIMIT) {
                throw new Exception('最多上传 ' . self::USER_LIMIT . ' 个自定义表情');
            }
        }
        $info = $file->getInfo();
        $size = (int)($info['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new Exception('图片不能超过 2MB');
        }
        $suffix = strtolower(pathinfo((string)($info['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($suffix, self::ALLOW_EXT, true)) {
            throw new Exception('仅支持 gif、png、jpg、jpeg');
        }

        $upload = new Upload($file);
        $md5 = md5_file($info['tmp_name']);
        $savekey = '/uploads/stickers/' . $userId . '/' . $md5 . '.' . $suffix;
        try {
            /** @var Attachment $attachment */
            $attachment = $upload->upload($savekey);
        } catch (UploadException $e) {
            throw new Exception($e->getMessage());
        }

        $url = (string)$attachment->url;
        $now = time();
        $name = '表情' . date('mdHis', $now);
        $id = Db::name('chat_user_stickers')->insertGetId([
            'user_id'    => $userId,
            'name'       => $name,
            'url'        => $url,
            'status'     => 1,
            'createtime' => $now,
            'updatetime' => $now,
        ]);

        return [
            'id'       => (int)$id,
            'code'     => $name,
            'url'      => $url,
            'fullurl'  => cdnurl($url, true),
            'pack'     => 'custom',
            'is_admin' => $isAdmin,
            'limit'    => $isAdmin ? 0 : self::USER_LIMIT,
            'count'    => (int)Db::name('chat_user_stickers')->where(['user_id' => $userId, 'status' => 1])->count(),
        ];
    }

    public static function delete($userId, $stickerId)
    {
        $userId = (int)$userId;
        $stickerId = (int)$stickerId;
        if ($userId <= 0 || $stickerId <= 0) {
            throw new Exception('参数错误');
        }
        $row = Db::name('chat_user_stickers')
            ->where(['id' => $stickerId, 'user_id' => $userId, 'status' => 1])
            ->find();
        if (!$row) {
            throw new Exception('表情不存在');
        }
        Db::name('chat_user_stickers')->where('id', $stickerId)->update([
            'status'     => 0,
            'updatetime' => time(),
        ]);
        return true;
    }
}

<?php

namespace app\common\library;

use think\Db;

/**
 * 延迟邀请归因：未装 App 时先打开邀请页留下痕迹，安装后注册按 IP/设备指纹找回邀请码
 */
class FansHubInvitePending
{
    const TTL = 72 * 3600;

    /**
     * 记录邀请页点击（可匿名）
     */
    public static function trackClick($inviteCode, $deviceFp = '', $ip = '', $ua = '')
    {
        $inviteCode = trim((string)$inviteCode);
        if ($inviteCode === '' || !preg_match('/^\d{6,12}$/', $inviteCode)) {
            return false;
        }
        $inviterId = FansHubService::decodeInviteCode($inviteCode);
        if ($inviterId <= 0) {
            return false;
        }
        $ip = self::normIp($ip !== '' ? $ip : (string)request()->ip());
        $deviceFp = substr(trim((string)$deviceFp), 0, 96);
        $uaHash = self::uaHash($ua !== '' ? $ua : (string)request()->server('HTTP_USER_AGENT', ''));
        $now = time();
        $expire = $now + self::TTL;

        // 同 IP 或同指纹：更新为最新邀请码（后点为准）
        $row = null;
        if ($deviceFp !== '') {
            $row = Db::name('fans_invite_pending')
                ->where('device_fp', $deviceFp)
                ->where('consumed', 0)
                ->where('expiretime', '>', $now)
                ->order('id', 'desc')
                ->find();
        }
        if (!$row && $ip !== '' && $ip !== '0.0.0.0') {
            $row = Db::name('fans_invite_pending')
                ->where('ip', $ip)
                ->where('consumed', 0)
                ->where('expiretime', '>', $now)
                ->order('id', 'desc')
                ->find();
        }

        if ($row) {
            Db::name('fans_invite_pending')->where('id', (int)$row['id'])->update([
                'invite_code'     => $inviteCode,
                'inviter_user_id' => $inviterId,
                'ip'              => $ip !== '' ? $ip : (string)$row['ip'],
                'device_fp'       => $deviceFp !== '' ? $deviceFp : (string)$row['device_fp'],
                'ua_hash'         => $uaHash !== '' ? $uaHash : (string)$row['ua_hash'],
                'hit_count'       => (int)$row['hit_count'] + 1,
                'updatetime'      => $now,
                'expiretime'      => $expire,
            ]);
            return true;
        }

        Db::name('fans_invite_pending')->insert([
            'invite_code'     => $inviteCode,
            'inviter_user_id' => $inviterId,
            'ip'              => $ip,
            'device_fp'       => $deviceFp,
            'ua_hash'         => $uaHash,
            'hit_count'       => 1,
            'consumed'        => 0,
            'consumed_at'     => 0,
            'createtime'      => $now,
            'updatetime'      => $now,
            'expiretime'      => $expire,
        ]);
        return true;
    }

    /**
     * 注册时取回未消费的邀请码（优先指纹，再 IP）
     */
    public static function consumeForRegister($deviceFp = '', $ip = '')
    {
        $now = time();
        $deviceFp = substr(trim((string)$deviceFp), 0, 96);
        $ip = self::normIp($ip !== '' ? $ip : (string)request()->ip());

        $row = null;
        if ($deviceFp !== '') {
            $row = Db::name('fans_invite_pending')
                ->where('device_fp', $deviceFp)
                ->where('consumed', 0)
                ->where('expiretime', '>', $now)
                ->order('id', 'desc')
                ->find();
        }
        if (!$row && $ip !== '' && $ip !== '0.0.0.0') {
            // 同 IP 最近一条；公网 NAT 可能多人共用，消费后即失效，降低误绑
            $row = Db::name('fans_invite_pending')
                ->where('ip', $ip)
                ->where('consumed', 0)
                ->where('expiretime', '>', $now)
                ->order('id', 'desc')
                ->find();
        }
        if (!$row) {
            return '';
        }
        $upd = Db::name('fans_invite_pending')
            ->where('id', (int)$row['id'])
            ->where('consumed', 0)
            ->update([
                'consumed'    => 1,
                'consumed_at' => $now,
                'updatetime'  => $now,
            ]);
        if ($upd <= 0) {
            return '';
        }
        return (string)$row['invite_code'];
    }

    protected static function normIp($ip)
    {
        $ip = trim((string)$ip);
        if ($ip === '' || $ip === '::1') {
            return $ip === '::1' ? '127.0.0.1' : '';
        }
        return substr($ip, 0, 64);
    }

    protected static function uaHash($ua)
    {
        $ua = trim((string)$ua);
        if ($ua === '') {
            return '';
        }
        return substr(hash('sha256', $ua), 0, 32);
    }
}

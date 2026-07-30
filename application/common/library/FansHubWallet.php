<?php

namespace app\common\library;

use app\common\model\User;
use think\Db;

/**
 * 充值 / 提现通道与订单
 */
class FansHubWallet
{
    public static function handlerList()
    {
        return [
            'manual'      => '人工审核（创建待处理订单）',
            'url'         => '跳转外链（config.url）',
            'cs'          => '联系客服（config.url 或客服链接）',
            'merchant'    => '通用商户网关（测试用）',
            'jiuyuan'     => '久远支付/代付（Pay_AddOrder / Dfpay）',
            'wanhuitong'  => '万汇通 wanhuipay（RSA2）',
            'bs'          => 'BS 必胜 USDT（bishengusdt）',
        ];
    }

    public static function walletInfo($userId)
    {
        $account = FansHubService::getOrCreateAccount($userId);
        $cfg = FansHubService::config();
        $turnover = (float)($account->turnover ?? 0);
        // 充值/提现资产：红宝
        $hongbao = (float)($account->hongbao ?? 0);
        $minTurnover = (float)($cfg['withdraw_turnover_min'] ?? 0);
        $ratio = max(0, (float)($cfg['withdraw_turnover_ratio'] ?? 1));
        return [
            'hongbao'                  => $hongbao,
            // 兼容旧字段：可提现额 = 红宝
            'balance'                  => $hongbao,
            'turnover'                 => $turnover,
            'withdraw_turnover_min'    => $minTurnover,
            'withdraw_turnover_ratio'  => $ratio,
            'can_withdraw'             => $turnover >= $minTurnover,
            'withdraw_threshold'       => (float)($cfg['withdraw_threshold'] ?? 50),
            'wallet_asset'             => 'hongbao',
        ];
    }

    /**
     * 资金流水类型文案
     */
    public static function ledgerTypeLabels()
    {
        return [
            'register'          => '注册赠送',
            'register_bonus'    => '拉新股份',
            'share'             => '分享奖励',
            'invite'            => '邀请奖励',
            'open_account'      => '开户奖励',
            'exchange'          => '闪兑',
            'admin_adjust'      => '人工调整',
            'checkin'           => '星火签到',
            'checkin_bonus'     => '暴力对账',
            'checkin_day7'      => '7天暴击',
            'honor_tier'        => '荣誉晋升',
            'recharge'          => '充值入账',
            'withdraw'          => '提现扣款',
            'withdraw_refund'   => '提现退回',
            'red_packet_send'              => '发红包',
            'red_packet_grab'              => '红宝',
            'red_packet_refund'            => '红包退回',
            'red_packet_fee'               => '红包手续费',
            'red_packet_fee_in'            => '红包手续费收入',
            'red_packet_rebate'            => '红包返点',
            'red_packet_agent_rebate'      => '红包返佣支出',
            'red_packet_agent_rebate_in'   => '红包返佣',
            'red_packet_mine_pay'          => '中雷赔付',
            'red_packet_worst_pay'         => '手气最差赔付',
            'red_packet_compensate_in'     => '红包赔付收入',
        ];
    }

    /**
     * 会员资金流水列表
     */
    public static function ledgerList($userId, $page = 1, $limit = 20)
    {
        $userId = (int)$userId;
        $page = max(1, (int)$page);
        $limit = max(1, min(50, (int)$limit));
        if ($userId <= 0) {
            return ['list' => [], 'total' => 0, 'page' => $page, 'limit' => $limit, 'has_more' => false];
        }
        $query = Db::name('fans_ledger')->where('user_id', $userId);
        $total = (int)$query->count();
        $rows = Db::name('fans_ledger')
            ->where('user_id', $userId)
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select();
        $labels = self::ledgerTypeLabels();
        $list = [];
        foreach ($rows as $row) {
            $type = (string)($row['type'] ?? '');
            $bal = round((float)($row['balance_change'] ?? 0), 2);
            $rights = round((float)($row['rights_change'] ?? 0), 2);
            $hongbao = round((float)($row['hongbao_change'] ?? 0), 2);
            $list[] = [
                'id'              => (int)$row['id'],
                'type'            => $type,
                'type_label'      => $labels[$type] ?? ($type !== '' ? $type : '其他'),
                'balance_change'  => $bal,
                'rights_change'   => $rights,
                'hongbao_change'  => $hongbao,
                'balance_after'   => round((float)($row['balance_after'] ?? 0), 2),
                'rights_after'    => round((float)($row['rights_after'] ?? 0), 2),
                'hongbao_after'   => round((float)($row['hongbao_after'] ?? 0), 2),
                'remark'          => (string)($row['remark'] ?? ''),
                'channel'         => (string)($row['channel'] ?? ''),
                'createtime'      => (int)($row['createtime'] ?? 0),
            ];
        }
        return [
            'list'     => $list,
            'total'    => $total,
            'page'     => $page,
            'limit'    => $limit,
            'has_more' => ($page * $limit) < $total,
        ];
    }

    public static function listChannels($type)
    {
        $payload = self::listChannelsGrouped($type);
        return $payload['list'];
    }

    /**
     * 分区 + 通道列表（供 H5）
     * @return array{partitions:array,list:array,binds?:array}
     */
    public static function listChannelsGrouped($type, $userId = 0)
    {
        $type = $type === 'withdraw' ? 'withdraw' : 'recharge';
        $locale = FansHubService::requestLocale();
        $partitions = [];
        try {
            $prows = Db::name('fans_pay_partition')
                ->where(['type' => $type, 'status' => 'normal'])
                ->order('weigh desc,id asc')
                ->select();
        } catch (\Throwable $e) {
            $prows = [];
        }
        $partMap = [];
        foreach ($prows ?: [] as $p) {
            $pid = (int)$p['id'];
            $item = [
                'id'        => $pid,
                'code'      => (string)$p['code'],
                'name'      => self::localizePartitionName($p, $locale),
                'bind_mode' => (string)($p['bind_mode'] ?? 'none'),
                'channels'  => [],
            ];
            $partitions[] = $item;
            $partMap[$pid] = count($partitions) - 1;
        }

        $rows = Db::name('fans_pay_channel')
            ->where(['type' => $type, 'status' => 'normal'])
            ->order('weigh desc,id desc')
            ->select();
        $list = [];
        foreach ($rows as $row) {
            $icon = trim((string)($row['icon'] ?? ''));
            if ($icon === '') {
                continue;
            }
            if (!preg_match('#^(https?:)?//#i', $icon) && !preg_match('#^data:#i', $icon)) {
                if ($icon[0] !== '/') {
                    $icon = '/' . ltrim($icon, '/');
                }
            } else {
                $icon = cdnurl($icon, true);
            }
            $cfg = self::decodeConfig($row['config'] ?? '');
            $payChannel = trim((string)($row['pay_channel'] ?? $cfg['payment_channel'] ?? $cfg['pay_channel'] ?? ''));
            $pid = (int)($row['partition_id'] ?? 0);
            $bindMode = 'none';
            $partCode = '';
            if ($pid > 0 && isset($partMap[$pid])) {
                $bindMode = $partitions[$partMap[$pid]]['bind_mode'];
                $partCode = $partitions[$partMap[$pid]]['code'];
            } elseif ($pid === 0 && $partitions) {
                // 未归属：按处理器猜测
                $handler = strtolower((string)$row['handler']);
                $guess = in_array($handler, ['wanhuitong', 'bs'], true) ? 'wallet' : 'self_service';
                foreach ($partitions as $idx => $p) {
                    if ($p['code'] === $guess) {
                        $pid = $p['id'];
                        $bindMode = $p['bind_mode'];
                        $partCode = $p['code'];
                        break;
                    }
                }
            }
            $walletType = self::resolveWalletType($row, $cfg);
            $ch = [
                'id'              => (int)$row['id'],
                'name'            => (string)$row['name'],
                'icon'            => $icon,
                'tip'             => (string)($row['tip'] ?? ''),
                'handler'         => (string)$row['handler'],
                'payment_channel' => $payChannel,
                'wallet_type'     => $walletType,
                'partition_id'    => $pid,
                'partition_code'  => $partCode,
                'bind_mode'       => $bindMode,
                'recharge_mode'   => strtolower(trim((string)($cfg['recharge_mode'] ?? ''))),
                'min_amount'      => (float)$row['min_amount'],
                'max_amount'      => (float)$row['max_amount'],
            ];
            $list[] = $ch;
            if ($pid > 0 && isset($partMap[$pid])) {
                $partitions[$partMap[$pid]]['channels'][] = $ch;
            }
        }

        // 保留空分区：前台也要显示「自助 / 钱包地址」标题
        $partitions = array_values($partitions);

        $out = [
            'partitions' => $partitions,
            'list'       => $list,
        ];
        if ($type === 'withdraw' && (int)$userId > 0) {
            $out['binds'] = self::listWalletBinds((int)$userId);
        }
        return $out;
    }

    public static function localizePartitionName(array $row, $locale = 'zh-CN')
    {
        $name = trim((string)($row['name'] ?? ''));
        if ($locale === '' || $locale === 'zh-CN') {
            return $name;
        }
        $map = [];
        $raw = $row['name_i18n'] ?? '';
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $map = $decoded;
            }
        } elseif (is_array($raw)) {
            $map = $raw;
        }
        $t = trim((string)($map[$locale] ?? ''));
        return $t !== '' ? $t : $name;
    }

    public static function resolveWalletType(array $channel, array $cfg = [])
    {
        if (!$cfg) {
            $cfg = self::decodeConfig($channel['config'] ?? '');
        }
        $handler = strtolower((string)($channel['handler'] ?? ''));
        if ($handler === 'bs') {
            $coin = trim((string)($cfg['coin_type'] ?? $channel['pay_channel'] ?? 'USDT'));
            return 'BS_' . strtoupper($coin !== '' ? $coin : 'USDT');
        }
        $pay = trim((string)($channel['pay_channel'] ?? $cfg['payment_channel'] ?? $cfg['pay_channel'] ?? ''));
        if ($pay !== '') {
            return preg_replace('/_quick$/i', '', $pay);
        }
        $name = trim((string)($channel['name'] ?? ''));
        return $name !== '' ? ('NAME_' . md5($name)) : 'UNKNOWN';
    }

    public static function normalizeAccountNo($accountNo)
    {
        $s = trim((string)$accountNo);
        $s = preg_replace('/\s+/u', '', $s);
        return $s;
    }

    public static function accountHash($accountNo)
    {
        return hash('sha256', strtolower(self::normalizeAccountNo($accountNo)));
    }

    public static function listWalletBinds($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return [];
        }
        try {
            $rows = Db::name('fans_wallet_bind')->where('user_id', $userId)->select();
        } catch (\Throwable $e) {
            return [];
        }
        $map = [];
        foreach ($rows ?: [] as $r) {
            $type = (string)$r['wallet_type'];
            $map[$type] = [
                'id'           => (int)$r['id'],
                'wallet_type'  => $type,
                'bind_mode'    => (string)$r['bind_mode'],
                'account_name' => (string)$r['account_name'],
                'account_no'   => (string)$r['account_no'],
                'bank_name'    => (string)$r['bank_name'],
            ];
        }
        return $map;
    }

    /**
     * 绑定钱包地址（同一 wallet_type 一用户一条；同类型地址全局不可重复）
     */
    public static function bindWalletAddress($userId, $walletType, array $info)
    {
        $userId = (int)$userId;
        $walletType = trim((string)$walletType);
        $accountNo = self::normalizeAccountNo($info['account_no'] ?? $info['cardnumber'] ?? $info['account'] ?? '');
        $accountName = trim((string)($info['account_name'] ?? $info['accountname'] ?? ''));
        $bankName = trim((string)($info['bank_name'] ?? $info['bankname'] ?? ''));
        $bindMode = trim((string)($info['bind_mode'] ?? 'wallet'));
        if ($userId <= 0 || $walletType === '' || $accountNo === '') {
            FansHubService::throwCopy('api_params_incomplete');
        }
        if ($bindMode === 'wallet' && mb_strlen($accountNo) < 6) {
            throw new \RuntimeException(FansHubService::h5CopyText('wallet_bind_address_invalid') ?: '钱包地址格式不正确');
        }
        $hash = self::accountHash($accountNo);
        $now = time();
        $dup = Db::name('fans_wallet_bind')
            ->where('wallet_type', $walletType)
            ->where('account_hash', $hash)
            ->where('user_id', '<>', $userId)
            ->find();
        if ($dup) {
            throw new \RuntimeException(FansHubService::h5CopyText('wallet_bind_address_taken') ?: '该钱包地址已被其他用户绑定');
        }
        $exist = Db::name('fans_wallet_bind')
            ->where(['user_id' => $userId, 'wallet_type' => $walletType])
            ->find();
        $data = [
            'account_name' => mb_substr($accountName, 0, 64),
            'account_no'   => mb_substr($accountNo, 0, 255),
            'account_hash' => $hash,
            'bank_name'    => mb_substr($bankName, 0, 64),
            'bind_mode'    => $bindMode !== '' ? $bindMode : 'wallet',
            'updatetime'   => $now,
        ];
        try {
            if ($exist) {
                // 换绑时也要确保新地址未被占用（上面已查）
                Db::name('fans_wallet_bind')->where('id', (int)$exist['id'])->update($data);
            } else {
                $data['user_id'] = $userId;
                $data['wallet_type'] = $walletType;
                $data['createtime'] = $now;
                Db::name('fans_wallet_bind')->insert($data);
            }
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'Duplicate') !== false || stripos($msg, 'uk_type_hash') !== false) {
                throw new \RuntimeException(FansHubService::h5CopyText('wallet_bind_address_taken') ?: '该钱包地址已被其他用户绑定');
            }
            throw $e;
        }
        return self::listWalletBinds($userId);
    }

    public static function getWalletBind($userId, $walletType)
    {
        $binds = self::listWalletBinds($userId);
        return $binds[$walletType] ?? null;
    }

    public static function channelBindMode(array $channel)
    {
        $pid = (int)($channel['partition_id'] ?? 0);
        if ($pid > 0) {
            try {
                $p = Db::name('fans_pay_partition')->where('id', $pid)->find();
                if ($p) {
                    return (string)($p['bind_mode'] ?? 'none');
                }
            } catch (\Throwable $e) {
            }
        }
        $handler = strtolower((string)($channel['handler'] ?? ''));
        return in_array($handler, ['wanhuitong', 'bs'], true) ? 'wallet' : 'conventional';
    }

    public static function recharge($userId, $channelId, $amount)
    {
        $userId = (int)$userId;
        $channelId = (int)$channelId;
        $amount = round((float)$amount, 2);
        if ($userId <= 0 || $channelId <= 0 || $amount <= 0) {
            FansHubService::throwCopy('api_params_incomplete');
        }
        $channel = self::getChannel($channelId, 'recharge');
        self::assertAmount($amount, $channel);
        $orderNo = self::genOrderNo('RC');
        $now = time();
        $handler = (string)$channel['handler'];

        // 先入库：无论后续通道是否拉起成功，订单都必须落库
        Db::name('fans_recharge_order')->insert([
            'order_no'   => $orderNo,
            'user_id'    => $userId,
            'channel_id' => $channelId,
            'amount'     => $amount,
            'status'     => 'pending',
            'handler'    => $handler,
            'pay_info'   => '',
            'remark'     => '',
            'createtime' => $now,
            'updatetime' => $now,
        ]);

        $payInfo = [];
        $status = 'pending';
        try {
            $payInfo = self::dispatchRecharge($handler, $channel, $userId, $amount, $orderNo);
            $status = !empty($payInfo['paid']) ? 'paid' : 'pending';
            Db::name('fans_recharge_order')->where('order_no', $orderNo)->update([
                'status'     => $status,
                'pay_info'   => json_encode($payInfo, JSON_UNESCAPED_UNICODE),
                'remark'     => (string)($payInfo['remark'] ?? ''),
                'updatetime' => time(),
            ]);
            if ($status === 'paid') {
                self::creditHongbao($userId, $amount, 'recharge', '充值红宝到账 ' . $orderNo, (string)$channel['name']);
            }
        } catch (\Throwable $e) {
            $errPay = [
                'action'  => 'error',
                'message' => $e->getMessage(),
                'order_no'=> $orderNo,
            ];
            try {
                Db::name('fans_recharge_order')->where('order_no', $orderNo)->update([
                    'pay_info'   => json_encode($errPay, JSON_UNESCAPED_UNICODE),
                    'remark'     => mb_substr('通道拉起失败：' . $e->getMessage(), 0, 250),
                    'updatetime' => time(),
                ]);
            } catch (\Throwable $e2) {
            }
            throw $e;
        }

        return [
            'order_no' => $orderNo,
            'amount'   => $amount,
            'status'   => $status,
            'pay_info' => $payInfo,
        ];
    }

    public static function withdraw($userId, $channelId, $amount, array $accountInfo = [])
    {
        $userId = (int)$userId;
        $channelId = (int)$channelId;
        $amount = round((float)$amount, 2);
        if ($userId <= 0 || $channelId <= 0 || $amount <= 0) {
            FansHubService::throwCopy('api_params_incomplete');
        }
        $channel = self::getChannel($channelId, 'withdraw');
        self::assertAmount($amount, $channel);
        $bindMode = self::channelBindMode($channel);
        $walletType = self::resolveWalletType($channel);
        if ($bindMode === 'wallet') {
            $bindId = (int)($accountInfo['bind_id'] ?? 0);
            $bind = null;
            if ($bindId > 0) {
                $bind = Db::name('fans_wallet_bind')->where(['id' => $bindId, 'user_id' => $userId])->find();
            }
            if (!$bind) {
                $bind = Db::name('fans_wallet_bind')
                    ->where(['user_id' => $userId, 'wallet_type' => $walletType])
                    ->find();
            }
            if (!$bind) {
                throw new \RuntimeException(FansHubService::h5CopyText('wallet_need_bind') ?: '请先绑定该钱包地址');
            }
            if ((string)$bind['wallet_type'] !== $walletType) {
                throw new \RuntimeException(FansHubService::h5CopyText('wallet_bind_type_mismatch') ?: '绑定地址与当前钱包类型不匹配');
            }
            $accountInfo = array_merge($accountInfo, [
                'accountname'         => (string)$bind['account_name'] ?: '钱包用户',
                'cardnumber'          => (string)$bind['account_no'],
                'account'             => (string)$bind['account_no'],
                'account_or_address'  => (string)$bind['account_no'],
                'bankname'            => (string)$bind['bank_name'] !== '' ? (string)$bind['bank_name'] : $walletType,
                'wallet_type'         => $walletType,
                'bind_id'             => (int)$bind['id'],
            ]);
        }
        $account = FansHubService::getOrCreateAccount($userId);
        if ((string)$account->status === 'frozen') {
            FansHubService::throwCopy('srv_account_frozen');
        }
        $hongbao = (float)($account->hongbao ?? 0);
        if ($amount > $hongbao) {
            FansHubService::throwCopy('srv_insufficient_hongbao');
        }
        $cfg = FansHubService::config();
        $turnover = (float)($account->turnover ?? 0);
        $minTurnover = (float)($cfg['withdraw_turnover_min'] ?? 0);
        $ratio = max(0, (float)($cfg['withdraw_turnover_ratio'] ?? 1));
        $needTurnover = max($minTurnover, $amount * $ratio);
        if ($turnover < $needTurnover) {
            throw new \RuntimeException(sprintf(
                '流水未达标：当前流水 ￥%.2f，需达到 ￥%.2f 才可提现',
                $turnover,
                $needTurnover
            ));
        }
        $orderNo = self::genOrderNo('WD');
        $now = time();
        $handler = (string)$channel['handler'];
        // 先扣余额 + 入库，再拉起代付；通道失败不回滚余额
        Db::startTrans();
        try {
            $locked = Db::name('fans_account')->where('user_id', $userId)->lock(true)->find();
            $curHb = (float)($locked['hongbao'] ?? 0);
            if (!$locked || $curHb < $amount) {
                throw new \RuntimeException(FansHubService::h5CopyText('srv_insufficient_hongbao'));
            }
            $newHb = round($curHb - $amount, 2);
            $aff = Db::name('fans_account')
                ->where('user_id', $userId)
                ->where('hongbao', sprintf('%.2f', $curHb))
                ->update([
                    'hongbao'    => $newHb,
                    'updatetime' => $now,
                ]);
            if (!$aff) {
                throw new \RuntimeException(FansHubService::h5CopyText('srv_insufficient_hongbao'));
            }
            Db::name('fans_ledger')->insert([
                'user_id'         => $userId,
                'type'            => 'withdraw',
                'rights_change'   => 0,
                'balance_change'  => 0,
                'hongbao_change'  => -$amount,
                'rights_after'    => (float)$locked['rights'],
                'balance_after'   => (float)$locked['balance'],
                'hongbao_after'   => $newHb,
                'remark'          => '提现红宝 ' . $orderNo,
                'channel'         => (string)$channel['name'],
                'createtime'      => $now,
            ]);
            Db::name('fans_withdraw_order')->insert([
                'order_no'          => $orderNo,
                'user_id'           => $userId,
                'channel_id'        => $channelId,
                'amount'            => $amount,
                'turnover_snapshot' => $turnover,
                'status'            => 'pending',
                'handler'           => $handler,
                'account_info'      => json_encode($accountInfo, JSON_UNESCAPED_UNICODE),
                'createtime'        => $now,
                'updatetime'        => $now,
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        $extra = ['status' => 'pending', 'gateway_ok' => false];
        try {
            $extra = self::dispatchWithdraw($handler, $channel, $userId, $amount, $orderNo, $accountInfo);
            $extra['gateway_ok'] = true;
            $newStatus = isset($extra['status']) ? (string)$extra['status'] : 'pending';
            if (!in_array($newStatus, ['pending', 'processing', 'paid'], true)) {
                $newStatus = 'pending';
            }
            $upd = [
                'status'     => $newStatus,
                'updatetime' => time(),
            ];
            if (!empty($extra['message'])) {
                $upd['remark'] = mb_substr((string)$extra['message'], 0, 250);
            }
            Db::name('fans_withdraw_order')->where('order_no', $orderNo)->update($upd);
        } catch (\Throwable $e) {
            // 余额已扣、订单已入库：仅记录失败原因，不自动退款（后台可拒绝退回）
            try {
                Db::name('fans_withdraw_order')->where('order_no', $orderNo)->update([
                    'remark'     => mb_substr('通道提交失败：' . $e->getMessage(), 0, 250),
                    'updatetime' => time(),
                ]);
            } catch (\Throwable $e2) {
            }
            throw $e;
        }

        return array_merge([
            'order_no' => $orderNo,
            'amount'   => $amount,
            'status'   => isset($extra['status']) ? $extra['status'] : 'pending',
        ], $extra);
    }

    protected static function dispatchRecharge($handler, array $channel, $userId, $amount, $orderNo)
    {
        $cfg = self::decodeConfig($channel['config'] ?? '');
        switch ($handler) {
            case 'url':
                $url = trim((string)($cfg['url'] ?? ''));
                if ($url === '') {
                    throw new \RuntimeException('通道未配置跳转地址');
                }
                return [
                    'action'   => 'url',
                    'url'      => self::fillUrl($url, $userId, $amount, $orderNo),
                    'message'  => (string)($cfg['message'] ?? '请在新页面完成支付'),
                ];
            case 'cs':
                $url = trim((string)($cfg['url'] ?? FansHubService::config('customer_service_url') ?? ''));
                return [
                    'action'  => 'cs',
                    'url'       => $url,
                    'message'   => (string)($cfg['message'] ?? '请联系在线客服完成充值，报订单号：' . $orderNo),
                    'order_no'  => $orderNo,
                ];
            case 'merchant':
                $submit = FansHubPayGateway::buildRechargeSubmit($channel, $orderNo, $amount, $userId);
                return array_merge([
                    'message'  => '正在跳转支付页面',
                    'order_no' => $orderNo,
                ], $submit);
            case 'jiuyuan':
                $submit = FansHubJiuyuanGateway::buildRechargeSubmit($channel, $orderNo, $amount, $userId);
                return array_merge([
                    'message'  => '正在跳转支付页面',
                    'order_no' => $orderNo,
                ], $submit);
            case 'wanhuitong':
                $submit = FansHubWanhuitongGateway::buildRechargeSubmit($channel, $orderNo, $amount, $userId);
                return array_merge([
                    'message'  => '正在跳转支付页面',
                    'order_no' => $orderNo,
                ], $submit);
            case 'bs':
                $submit = FansHubBsGateway::buildRechargeSubmit($channel, $orderNo, $amount, $userId);
                return array_merge([
                    'message'  => '正在创建 USDT 支付订单',
                    'order_no' => $orderNo,
                ], $submit);
            case 'manual':
            default:
                return [
                    'action'   => 'manual',
                    'message'  => (string)($cfg['message'] ?? '充值申请已提交，请等待客服处理。订单号：' . $orderNo),
                    'order_no' => $orderNo,
                ];
        }
    }

    protected static function dispatchWithdraw($handler, array $channel, $userId, $amount, $orderNo, array $accountInfo)
    {
        $cfg = self::decodeConfig($channel['config'] ?? '');
        switch ($handler) {
            case 'url':
                $url = trim((string)($cfg['url'] ?? ''));
                return [
                    'action'  => 'url',
                    'url'     => self::fillUrl($url, $userId, $amount, $orderNo),
                    'message' => (string)($cfg['message'] ?? '请按指引完成提现'),
                ];
            case 'cs':
                $url = trim((string)($cfg['url'] ?? FansHubService::config('customer_service_url') ?? ''));
                return [
                    'action'   => 'cs',
                    'url'      => $url,
                    'message'  => (string)($cfg['message'] ?? '提现申请已提交，请联系客服。订单号：' . $orderNo),
                    'order_no' => $orderNo,
                ];
            case 'merchant':
                $submit = FansHubPayGateway::buildWithdrawSubmit($channel, $orderNo, $amount, $userId, $accountInfo);
                return array_merge([
                    'message'  => '正在提交提现到商户网关',
                    'order_no' => $orderNo,
                ], $submit);
            case 'jiuyuan':
                $submit = FansHubJiuyuanGateway::buildWithdrawSubmit($channel, $orderNo, $amount, $userId, $accountInfo);
                return array_merge([
                    'message'  => '代付已提交',
                    'order_no' => $orderNo,
                ], $submit);
            case 'wanhuitong':
                $submit = FansHubWanhuitongGateway::buildWithdrawSubmit($channel, $orderNo, $amount, $userId, $accountInfo);
                return array_merge([
                    'message'  => '代付已提交',
                    'order_no' => $orderNo,
                ], $submit);
            case 'bs':
                $submit = FansHubBsGateway::buildWithdrawSubmit($channel, $orderNo, $amount, $userId, $accountInfo);
                return array_merge([
                    'message'  => 'USDT 代付已提交',
                    'order_no' => $orderNo,
                ], $submit);
            case 'manual':
            default:
                return [
                    'action'   => 'manual',
                    'message'  => (string)($cfg['message'] ?? '提现申请已提交，1-3个工作日内到账。订单号：' . $orderNo),
                    'order_no' => $orderNo,
                ];
        }
    }

    public static function decodeConfigPublic($raw)
    {
        return self::decodeConfig($raw);
    }

    public static function creditBalancePublic($userId, $amount, $type, $remark, $channel = '')
    {
        // 兼容旧名：实际入账红宝
        self::creditHongbao($userId, $amount, $type, $remark, $channel);
    }

    public static function refundWithdrawOrder(array $order, $remark = '')
    {
        $userId = (int)$order['user_id'];
        $amount = (float)$order['amount'];
        $orderNo = (string)$order['order_no'];
        $now = time();
        Db::startTrans();
        try {
            $fresh = Db::name('fans_withdraw_order')->where('id', $order['id'])->lock(true)->find();
            if (!$fresh || in_array($fresh['status'], ['paid', 'rejected', 'cancelled'], true)) {
                Db::commit();
                return;
            }
            $row = Db::name('fans_account')->where('user_id', $userId)->lock(true)->find();
            if (!$row) {
                throw new \RuntimeException('account missing');
            }
            $newHb = round((float)($row['hongbao'] ?? 0) + $amount, 2);
            Db::name('fans_account')->where('user_id', $userId)->update([
                'hongbao'    => $newHb,
                'updatetime' => $now,
            ]);
            Db::name('fans_ledger')->insert([
                'user_id'         => $userId,
                'type'            => 'withdraw_refund',
                'rights_change'   => 0,
                'balance_change'  => 0,
                'hongbao_change'  => $amount,
                'rights_after'    => (float)$row['rights'],
                'balance_after'   => (float)$row['balance'],
                'hongbao_after'   => $newHb,
                'remark'          => '提现失败退回红宝 ' . $orderNo,
                'channel'         => '',
                'createtime'      => $now,
            ]);
            Db::name('fans_withdraw_order')->where('id', $order['id'])->update([
                'status'     => 'rejected',
                'remark'     => $remark ?: '提现失败退回红宝',
                'updatetime' => $now,
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 后台确认充值到账
     */
    public static function adminMarkRechargePaid($orderId, $remark = '')
    {
        $orderId = (int)$orderId;
        $now = time();
        Db::startTrans();
        try {
            $order = Db::name('fans_recharge_order')->where('id', $orderId)->lock(true)->find();
            if (!$order) {
                throw new \RuntimeException('订单不存在');
            }
            if ($order['status'] === 'paid') {
                Db::commit();
                return true;
            }
            if (!in_array($order['status'], ['pending', 'failed'], true)) {
                throw new \RuntimeException('当前状态不可确认到账');
            }
            $channelName = '';
            $ch = Db::name('fans_pay_channel')->where('id', (int)$order['channel_id'])->find();
            if ($ch) {
                $channelName = (string)$ch['name'];
            }
            Db::name('fans_recharge_order')->where('id', $orderId)->update([
                'status'     => 'paid',
                'remark'     => $remark !== '' ? $remark : ('后台确认 ' . date('Y-m-d H:i:s')),
                'updatetime' => $now,
            ]);
            self::creditHongbao(
                (int)$order['user_id'],
                (float)$order['amount'],
                'recharge',
                '充值红宝到账 ' . $order['order_no'],
                $channelName
            );
            Db::commit();
            return true;
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 后台作废充值单
     */
    public static function adminMarkRechargeFailed($orderId, $remark = '')
    {
        $orderId = (int)$orderId;
        $order = Db::name('fans_recharge_order')->where('id', $orderId)->find();
        if (!$order) {
            throw new \RuntimeException('订单不存在');
        }
        if ($order['status'] === 'paid') {
            throw new \RuntimeException('已到账订单不可作废');
        }
        Db::name('fans_recharge_order')->where('id', $orderId)->update([
            'status'     => 'failed',
            'remark'     => $remark !== '' ? $remark : ('后台作废 ' . date('Y-m-d H:i:s')),
            'updatetime' => time(),
        ]);
        return true;
    }

    /**
     * 后台确认提现打款完成
     */
    public static function adminMarkWithdrawPaid($orderId, $remark = '')
    {
        $orderId = (int)$orderId;
        $order = Db::name('fans_withdraw_order')->where('id', $orderId)->find();
        if (!$order) {
            throw new \RuntimeException('订单不存在');
        }
        if ($order['status'] === 'paid') {
            return true;
        }
        if (!in_array($order['status'], ['pending', 'processing'], true)) {
            throw new \RuntimeException('当前状态不可确认打款');
        }
        Db::name('fans_withdraw_order')->where('id', $orderId)->update([
            'status'     => 'paid',
            'remark'     => $remark !== '' ? $remark : ('后台确认打款 ' . date('Y-m-d H:i:s')),
            'updatetime' => time(),
        ]);
        return true;
    }

    /**
     * 后台拒绝提现（退回红宝）
     */
    public static function adminRejectWithdraw($orderId, $remark = '')
    {
        $order = Db::name('fans_withdraw_order')->where('id', (int)$orderId)->find();
        if (!$order) {
            throw new \RuntimeException('订单不存在');
        }
        if (in_array($order['status'], ['paid', 'rejected', 'cancelled'], true)) {
            throw new \RuntimeException('当前状态不可拒绝');
        }
        self::refundWithdrawOrder($order, $remark !== '' ? $remark : ('后台拒绝 ' . date('Y-m-d H:i:s')));
        return true;
    }

    /** @deprecated 使用 creditHongbao */
    protected static function creditBalance($userId, $amount, $type, $remark, $channel = '')
    {
        self::creditHongbao($userId, $amount, $type, $remark, $channel);
    }

    /**
     * 入账到红宝（充值到账等）
     */
    protected static function creditHongbao($userId, $amount, $type, $remark, $channel = '')
    {
        $amount = round((float)$amount, 2);
        if ($amount <= 0) {
            return;
        }
        $now = time();
        Db::startTrans();
        try {
            $row = Db::name('fans_account')->where('user_id', $userId)->lock(true)->find();
            if (!$row) {
                throw new \RuntimeException('account missing');
            }
            $newHb = round((float)($row['hongbao'] ?? 0) + $amount, 2);
            Db::name('fans_account')->where('user_id', $userId)->update([
                'hongbao'    => $newHb,
                'updatetime' => $now,
            ]);
            Db::name('fans_ledger')->insert([
                'user_id'         => $userId,
                'type'            => $type,
                'rights_change'   => 0,
                'balance_change'  => 0,
                'hongbao_change'  => $amount,
                'rights_after'    => (float)$row['rights'],
                'balance_after'   => (float)$row['balance'],
                'hongbao_after'   => $newHb,
                'remark'          => $remark,
                'channel'         => $channel,
                'createtime'      => $now,
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    protected static function getChannel($id, $type)
    {
        $row = Db::name('fans_pay_channel')
            ->where(['id' => (int)$id, 'type' => $type, 'status' => 'normal'])
            ->find();
        if (!$row) {
            throw new \RuntimeException('通道不可用');
        }
        return $row;
    }

    protected static function assertAmount($amount, array $channel)
    {
        $min = (float)($channel['min_amount'] ?? 0);
        $max = (float)($channel['max_amount'] ?? 0);
        if ($min > 0 && $amount < $min) {
            throw new \RuntimeException('金额不能低于 ￥' . number_format($min, 2, '.', ''));
        }
        if ($max > 0 && $amount > $max) {
            throw new \RuntimeException('金额不能高于 ￥' . number_format($max, 2, '.', ''));
        }
    }

    protected static function decodeConfig($raw)
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $j = json_decode($raw, true);
            return is_array($j) ? $j : [];
        }
        return [];
    }

    protected static function fillUrl($url, $userId, $amount, $orderNo)
    {
        return str_replace(
            ['{user_id}', '{amount}', '{order_no}'],
            [(string)(int)$userId, (string)$amount, (string)$orderNo],
            $url
        );
    }

    protected static function genOrderNo($prefix)
    {
        return $prefix . date('YmdHis') . sprintf('%04d', random_int(0, 9999));
    }
}

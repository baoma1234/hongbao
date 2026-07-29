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
        $type = $type === 'withdraw' ? 'withdraw' : 'recharge';
        $rows = Db::name('fans_pay_channel')
            ->where(['type' => $type, 'status' => 'normal'])
            ->order('weigh desc,id desc')
            ->select();
        $list = [];
        foreach ($rows as $row) {
            $icon = trim((string)($row['icon'] ?? ''));
            // 无图标通道不向前台展示
            if ($icon === '') {
                continue;
            }
            // 本地 /assets 图标勿走 CDN（CDN 上通常没有这些文件）
            if (!preg_match('#^(https?:)?//#i', $icon) && !preg_match('#^data:#i', $icon)) {
                if ($icon[0] !== '/') {
                    $icon = '/' . ltrim($icon, '/');
                }
            } else {
                $icon = cdnurl($icon, true);
            }
            $list[] = [
                'id'         => (int)$row['id'],
                'name'       => (string)$row['name'],
                'icon'       => $icon,
                'tip'        => (string)($row['tip'] ?? ''),
                'handler'    => (string)$row['handler'],
                'min_amount' => (float)$row['min_amount'],
                'max_amount' => (float)$row['max_amount'],
            ];
        }
        return $list;
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

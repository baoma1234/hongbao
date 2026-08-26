<?php

namespace Im\Service;

use Im\Support\Db;
use Im\Support\IdGenerator;

/**
 * 私聊即时转账（红宝）：发送即到账，无手续费、不抽点；仅转给对方本人
 */
class TransferService
{
    /** @var WalletService */
    protected $wallet;
    /** @var MessageService */
    protected $messages;
    /** @var array */
    protected $cfg;

    public function __construct(array $appCfg = [])
    {
        $this->cfg = $appCfg;
        $this->wallet = new WalletService($appCfg);
        $this->messages = new MessageService();
    }

    /**
     * @param array{from_user_id:int,to_user_id:int,amount:float,remark?:string} $params
     * @return array{transfer_no:string,message:array,balance:float,amount:float}
     */
    public function send(array $params)
    {
        $fromUserId = (int)($params['from_user_id'] ?? 0);
        $toUserId = (int)($params['to_user_id'] ?? 0);
        $amount = round((float)($params['amount'] ?? 0), 2);
        $remark = mb_substr(trim((string)($params['remark'] ?? '')), 0, 40);

        if ($fromUserId <= 0 || $toUserId <= 0 || $fromUserId === $toUserId) {
            throw new \InvalidArgumentException('invalid transfer target');
        }
        if ($amount < 0.01) {
            throw new \InvalidArgumentException('amount too small');
        }
        if ($amount > 500000) {
            throw new \InvalidArgumentException('amount too large');
        }

        AdminService::assertCanPrivateChat($fromUserId, $toUserId);
        ChatForbidService::assertCanSendRedPacket($fromUserId);
        RechargePrivilegeService::assertCanSendTransfer($fromUserId);
        RechargePrivilegeService::assertCanReceiveTransfer($toUserId);

        $transferNo = IdGenerator::transferNo();
        $field = $this->wallet->field();

        Db::begin();
        try {
            $this->wallet->change(
                $fromUserId,
                -$amount,
                'transfer_out',
                '私聊转账 ' . $transferNo,
                ['biz_no' => $transferNo, 'ref_type' => 'transfer', 'ref_id' => $toUserId]
            );
            $this->wallet->change(
                $toUserId,
                $amount,
                'transfer_in',
                '收到转账 ' . $transferNo,
                ['biz_no' => $transferNo, 'ref_type' => 'transfer', 'ref_id' => $fromUserId]
            );
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            throw $e;
        }

        $content = '[转账]￥' . sprintf('%.2f', $amount);
        if ($remark !== '') {
            $content .= ' ' . $remark;
        }
        $extra = [
            'transfer_no' => $transferNo,
            'amount'      => $amount,
            'remark'      => $remark,
            'status'      => 1,
        ];
        $msg = $this->messages->sendPrivate($fromUserId, $toUserId, $content, 8, $extra);

        return [
            'transfer_no' => $transferNo,
            'amount'      => $amount,
            'message'     => $msg,
            'wallet'      => $field,
            'balance'     => $this->wallet->getBalance($fromUserId),
        ];
    }
}

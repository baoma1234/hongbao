<?php

namespace app\common\model\fanshub;

use think\Model;

class FissionQual extends Model
{
    protected $name = 'fans_fission_qual';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = false;

    const SOURCE_JOIN = 'join';
    const SOURCE_INVITE_REWARD = 'invite_reward';
    const SOURCE_INVITEE = 'invitee';
}

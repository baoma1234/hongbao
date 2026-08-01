<?php

namespace app\common\model\fanshub;

use think\Model;

/**
 * 短信发送记录（永久留存，供后台查询）
 */
class SmsLog extends Model
{
    protected $name = 'fans_sms_log';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = false;
}

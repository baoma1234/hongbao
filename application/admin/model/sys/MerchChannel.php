<?php

namespace app\admin\model\sys;

use app\common\library\Platform;
use think\Model;

class MerchChannel extends Model
{
    protected $name = 'sys_merch_channel';

    protected $pk = 'row_id';

    protected $autoWriteTimestamp = false;

    protected $createTime = false;
    protected $updateTime = false;
    protected $deleteTime = false;

    protected $append = ['pid_text', 'status_text'];

    public function getStatusList()
    {
        return ['normal' => __('Normal'), 'hidden' => __('Hidden')];
    }

    public function getStatusTextAttr($value, $data)
    {
        $value = $value ?: ($data['status'] ?? 'normal');
        return $this->getStatusList()[$value] ?? '';
    }

    public function getPidTextAttr($value, $data)
    {
        return Platform::getName($data['pid'] ?? 0);
    }

    public function setAddtimeAttr($value)
    {
        if ($value === '' || $value === null) {
            return 0;
        }
        if (is_numeric($value)) {
            return (int)$value;
        }
        return (int)strtotime($value);
    }
}

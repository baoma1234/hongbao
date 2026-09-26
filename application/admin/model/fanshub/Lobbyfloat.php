<?php
namespace app\admin\model\fanshub;

use think\Model;
use app\common\library\FansHubLobby;

class Lobbyfloat extends Model
{
    protected $name = 'fans_lobby_floats';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    protected static function init()
    {
        self::afterInsert(function () {
            FansHubLobby::clearCache();
        });
        self::afterUpdate(function () {
            FansHubLobby::clearCache();
        });
        self::afterDelete(function () {
            FansHubLobby::clearCache();
        });
    }

    public function getStatusList()
    {
        return ['normal' => '启用', 'hidden' => '停用'];
    }

    public function getSideList()
    {
        return ['left' => '左侧', 'right' => '右侧'];
    }

    public function getLinkTypeList()
    {
        return [
            'none'     => '不跳转',
            'internal' => '站内页面',
            'external' => '站外链接',
        ];
    }
}

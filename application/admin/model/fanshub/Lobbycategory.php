<?php
namespace app\admin\model\fanshub;
use think\Model;
use app\common\library\FansHubLobby;

class Lobbycategory extends Model
{
    protected $name = 'fans_lobby_categories';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    protected static function init()
    {
        self::afterInsert(function () { FansHubLobby::clearCache(); });
        self::afterUpdate(function () { FansHubLobby::clearCache(); });
        self::afterDelete(function () { FansHubLobby::clearCache(); });
    }

    public function getStatusList()
    {
        return ['normal' => '启用', 'hidden' => '停用'];
    }
    public function getActionList()
    {
        return [
            'filter'     => '筛选游戏（本页）',
            'notice'     => '跳转公告',
            'commission' => '跳转佣金',
            'url'        => '外链/路径',
        ];
    }
}

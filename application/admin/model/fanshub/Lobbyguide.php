<?php
namespace app\admin\model\fanshub;
use think\Model;
use app\common\library\FansHubLobbyGuide;

class Lobbyguide extends Model
{
    protected $name = 'fans_lobby_guides';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    protected static function init()
    {
        self::afterInsert(function () { FansHubLobbyGuide::clearCache(); });
        self::afterUpdate(function () { FansHubLobbyGuide::clearCache(); });
        self::afterDelete(function () { FansHubLobbyGuide::clearCache(); });
    }

    public function getStatusList()
    {
        return ['normal' => '显示', 'hidden' => '暂停显示'];
    }
}

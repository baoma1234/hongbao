<?php
namespace app\admin\model\fanshub;
use think\Model;
use app\common\library\FansHubLobby;

class Lobbygame extends Model
{
    protected $name = 'fans_lobby_games';
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
        return ['normal' => '显示', 'hidden' => '暂停显示'];
    }
}

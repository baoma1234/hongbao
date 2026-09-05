<?php
namespace app\admin\model\fanshub;
use think\Model;
use app\common\library\FansHubLobby;

class Lobbyinvite extends Model
{
    protected $name = 'fans_lobby_invites';
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
    public function getLinkTypeList()
    {
        return ['share' => '复制邀请链接', 'url' => '外链/路径', 'none' => '不跳转'];
    }
}

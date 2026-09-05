<?php
namespace app\admin\model\fanshub;
use think\Model;
use app\common\library\FansHubLobby;

class Lobbybanner extends Model
{
    protected $name = 'fans_lobby_banners';
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
        return ['none' => '不跳转', 'fission' => '裂变红包', 'messages' => '消息/社群', 'url' => '外链/路径'];
    }
}

<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubTelegram;
use think\Db;

/**
 * Telegram 绑定用户
 *
 * @icon fa fa-telegram
 */
class Telegramuser extends Backend
{
    protected $model = null;
    protected $relationSearch = true;
    protected $searchFields = 'tg_username,tg_first_name,user_id,tg_user_id';

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\common\model\fanshub\TelegramBind;
    }

    public function index()
    {
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $list = $this->model
                ->with(['user'])
                ->where($where)
                ->order($sort, $order)
                ->paginate($limit);
            $bot = ltrim((string)\app\common\library\FansHubService::config('telegram_bot_username', ''), '@');
            foreach ($list as $row) {
                if ($row->getRelation('user')) {
                    $row->getRelation('user')->visible(['id', 'mobile', 'nickname', 'avatar']);
                }
                $uname = trim((string)$row['tg_username']);
                $row['tg_link'] = ($bot !== '' && $uname !== '')
                    ? ('https://t.me/' . $uname)
                    : '';
                $row['webapp_url'] = FansHubTelegram::webAppUrl();
            }
            return json(['total' => $list->total(), 'rows' => $list->items()]);
        }
        $this->view->assign('webappUrl', FansHubTelegram::webAppUrl());
        $this->view->assign('botUsername', (string)\app\common\library\FansHubService::config('telegram_bot_username', ''));
        return $this->view->fetch();
    }

    /**
     * 解绑 Telegram
     */
    public function del($ids = null)
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $ids = $ids ?: $this->request->post('ids');
        if (empty($ids)) {
            $this->error(__('Parameter %s can not be empty', 'ids'));
        }
        $idArr = is_array($ids) ? $ids : explode(',', (string)$ids);
        $idArr = array_filter(array_map('intval', $idArr));
        if (!$idArr) {
            $this->error(__('Parameter %s can not be empty', 'ids'));
        }
        $n = Db::name('fans_telegram_bind')->where('id', 'in', $idArr)->delete();
        $this->success('已解绑 ' . (int)$n . ' 条');
    }
}

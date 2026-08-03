<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubService;
use think\Db;

/**
 * 群弹窗配置
 *
 * @icon fa fa-window-restore
 */
class Grouppopup extends Backend
{
    protected $model = null;
    protected $searchFields = 'id,title,group_id';
    protected $multiFields = 'status,weigh';

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Grouppopup;
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->view->assign('showModeList', $this->model->getShowModeList());
        $this->view->assign('localeList', FansHubService::i18nLocaleCodes());
        $this->assignconfig('statusList', $this->model->getStatusList());
        $this->assignconfig('showModeList', $this->model->getShowModeList());

        $groups = Db::name('chat_groups')
            ->where('status', '<>', 2)
            ->order('id', 'desc')
            ->limit(800)
            ->column('name', 'id');
        $this->view->assign('groupList', $groups ?: []);
    }

    protected function decodeI18nField($raw)
    {
        if (is_array($raw)) {
            return $raw;
        }
        $raw = trim((string)$raw);
        if ($raw === '') {
            return [];
        }
        $arr = json_decode($raw, true);
        return is_array($arr) ? $arr : [];
    }

    protected function rowForForm($row)
    {
        $data = $row->getData();
        $images = $data['images'] ?? '';
        if (is_array($images)) {
            $data['images'] = implode(',', array_values(array_filter(array_map('strval', $images))));
        } else {
            $trim = trim((string)$images);
            if ($trim !== '' && $trim[0] === '[') {
                $arr = json_decode($trim, true);
                $data['images'] = is_array($arr)
                    ? implode(',', array_values(array_filter(array_map('strval', $arr))))
                    : '';
            } else {
                $data['images'] = $trim;
            }
        }
        $data['title_i18n_map'] = $this->decodeI18nField($data['title_i18n'] ?? '');
        $data['content_i18n_map'] = $this->decodeI18nField($data['content_i18n'] ?? '');
        return $data;
    }

    protected function normalizeRow(array $params)
    {
        $params['group_id'] = (int)($params['group_id'] ?? 0);
        if ($params['group_id'] <= 0) {
            $this->error('请选择群');
        }
        $g = Db::name('chat_groups')->where('id', $params['group_id'])->find();
        if (!$g) {
            $this->error('群不存在');
        }
        $params['title'] = mb_substr(trim((string)($params['title'] ?? '')), 0, 128);
        if ($params['title'] === '') {
            $this->error('请填写标题');
        }
        $params['content'] = trim((string)($params['content'] ?? ''));
        $params['show_mode'] = ((string)($params['show_mode'] ?? '') === 'once') ? 'once' : 'always';
        $params['weigh'] = (int)($params['weigh'] ?? 0);
        $params['status'] = ((string)($params['status'] ?? 'normal') === 'hidden') ? 'hidden' : 'normal';

        if (isset($params['images']) && is_string($params['images'])) {
            $trim = trim($params['images']);
            if ($trim !== '' && $trim[0] !== '[') {
                $parts = preg_split('/[\r\n,]+/', $trim);
                $params['images'] = array_values(array_filter(array_map('trim', $parts ?: [])));
            }
        }

        $locales = FansHubService::i18nLocaleCodes();
        foreach (['title_i18n', 'content_i18n'] as $field) {
            $map = [];
            if (isset($params[$field]) && is_array($params[$field])) {
                foreach ($params[$field] as $code => $text) {
                    $code = (string)$code;
                    if ($code === 'zh-CN' || !isset($locales[$code])) {
                        continue;
                    }
                    $text = trim((string)$text);
                    if ($text !== '') {
                        $map[$code] = $text;
                    }
                }
            }
            $params[$field] = $map;
        }
        return $params;
    }

    public function index()
    {
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $list = $this->model->where($where)->order($sort, $order)->paginate($limit);
            $rows = $list->items();
            $gids = [];
            foreach ($rows as $row) {
                $gids[] = (int)$row['group_id'];
            }
            $gids = array_values(array_unique(array_filter($gids)));
            $names = $gids
                ? Db::name('chat_groups')->where('id', 'in', $gids)->column('name', 'id')
                : [];
            foreach ($rows as $row) {
                $gid = (int)$row['group_id'];
                $row['group_name'] = $names[$gid] ?? '';
                $imgs = $row['images'];
                if (is_array($imgs)) {
                    $row['images'] = implode(',', $imgs);
                }
            }
            return json(['total' => $list->total(), 'rows' => $rows]);
        }
        return $this->view->fetch();
    }

    public function add()
    {
        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');
            if ($params) {
                $params = $this->normalizeRow($params);
                $this->request->post(['row' => $params]);
            }
        }
        $this->view->assign('row', [
            'title_i18n_map'   => [],
            'content_i18n_map' => [],
            'show_mode'        => 'always',
            'status'           => 'normal',
            'weigh'            => 0,
        ]);
        return parent::add();
    }

    public function edit($ids = null)
    {
        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');
            if ($params) {
                $params = $this->normalizeRow($params);
                $this->request->post(['row' => $params]);
            }
            return parent::edit($ids);
        }
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        $this->view->assign('row', $this->rowForForm($row));
        return $this->view->fetch();
    }
}

<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubService;

/**
 * 红宝公告动态（朋友圈风格）
 *
 * @icon fa fa-bullhorn
 */
class Notice extends Backend
{
    protected $model = null;
    protected $searchFields = 'id,author_name,category,content';

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Notice;
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->view->assign('categoryList', $this->model->getCategoryList());
        $this->view->assign('localeList', FansHubService::i18nLocaleCodes());
        $this->assignconfig('statusList', $this->model->getStatusList());
        $this->assignconfig('categoryList', $this->model->getCategoryList());
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

    /**
     * 编辑表单展示用：避免模板 {$row.getData(...)} 被解析成属性触发关联加载报错
     */
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
        $buttons = $data['action_buttons'] ?? '[]';
        if (is_array($buttons)) {
            $data['action_buttons'] = json_encode($buttons, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif (!is_string($buttons) || trim($buttons) === '') {
            $data['action_buttons'] = '[]';
        }

        $data['content_i18n_map'] = $this->decodeI18nField($data['content_i18n'] ?? '');
        $data['action_label_i18n_map'] = $this->decodeI18nField($data['action_label_i18n'] ?? '');
        $data['author_name_i18n_map'] = $this->decodeI18nField($data['author_name_i18n'] ?? '');

        // 兼容旧中文分类
        $cat = (string)($data['category'] ?? '');
        $map = \app\common\model\fanshub\Notice::categoryMap();
        if (!isset($map[$cat])) {
            $legacy = [
                '规则' => 'rules', '玩法' => 'rules', '推广' => 'promote', '广告' => 'ads',
                '最新发布' => 'latest', '推广赚钱' => 'promote', '广告发布' => 'ads',
                '游戏规则' => 'rules', '游戏规划' => 'rules',
            ];
            $data['category'] = $legacy[$cat] ?? 'latest';
        }
        return $data;
    }

    protected function normalizeRow(array $params)
    {
        if (isset($params['publishtime']) && !is_numeric($params['publishtime'])) {
            $params['publishtime'] = strtotime((string)$params['publishtime']) ?: time();
        }
        if (isset($params['images']) && is_string($params['images'])) {
            $trim = trim($params['images']);
            if ($trim !== '' && $trim[0] !== '[') {
                $parts = preg_split('/[\r\n,]+/', $trim);
                $params['images'] = array_values(array_filter(array_map('trim', $parts ?: [])));
            }
        }

        $cats = \app\common\model\fanshub\Notice::categoryMap();
        $cat = (string)($params['category'] ?? 'latest');
        $params['category'] = isset($cats[$cat]) ? $cat : 'latest';

        $locales = FansHubService::i18nLocaleCodes();
        foreach (['content_i18n', 'action_label_i18n', 'author_name_i18n'] as $field) {
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
            'content_i18n_map' => [],
            'action_label_i18n_map' => [],
            'author_name_i18n_map' => [],
            'category' => 'latest',
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
        $adminIds = $this->getDataLimitAdminIds();
        if (is_array($adminIds) && !in_array($row[$this->dataLimitField], $adminIds)) {
            $this->error(__('You have no permission'));
        }
        $this->view->assign('row', $this->rowForForm($row));
        return $this->view->fetch();
    }
}

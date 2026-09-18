<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubService;

/**
 * 红宝公告动态（朋友圈风格）— 仅「最新发布 / 推广赚钱」
 *
 * @icon fa fa-bullhorn
 */
class Notice extends Backend
{
    protected $model = null;
    protected $searchFields = 'id,author_name,category,content';

    /** 本菜单可见/可编辑的分类 */
    protected $categoryScope = ['latest', 'promote'];

    /** 新增默认分类 */
    protected $defaultCategory = 'latest';

    /** 列表：待审核优先，再按时间 */
    protected $pendingFirstSort = false;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Notice;
        $categoryList = $this->scopedCategoryList();
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->view->assign('categoryList', $categoryList);
        $this->view->assign('localeList', FansHubService::i18nLocaleCodes());
        $themeMeta = $this->themeSelectMeta();
        $this->view->assign('themeList', $this->themeSelectList($themeMeta));
        $this->assignconfig('statusList', $this->model->getStatusList());
        $this->assignconfig('categoryList', $categoryList);
        $this->assignconfig('themeMeta', $themeMeta);
    }

    /** @return string[] */
    protected function scopedCategoryList()
    {
        $all = \app\common\model\fanshub\Notice::categoryMap();
        $out = [];
        foreach ($this->categoryScope as $code) {
            if (isset($all[$code])) {
                $out[$code] = $all[$code];
            }
        }
        return $out ?: $all;
    }

    /** @return array[] */
    protected function themeSelectMeta()
    {
        $out = [];
        $cats = \app\common\model\fanshub\Notice::categoryMap();
        $scope = $this->categoryScope;
        try {
            $rows = \app\common\model\fanshub\NoticeTheme::order('weigh', 'desc')->order('id', 'asc')->select();
            foreach ($rows as $row) {
                $cat = (string)($row->category ?? 'ads');
                if ($scope && !in_array($cat, $scope, true)) {
                    continue;
                }
                $out[] = [
                    'id'        => (int)$row->id,
                    'title'     => (string)$row->title,
                    'category'  => $cat,
                    'cat_label' => $cats[$cat] ?? $cat,
                    'status'    => (string)$row->status,
                ];
            }
        } catch (\Throwable $e) {
        }
        return $out;
    }

    /** @return array id => title */
    protected function themeSelectList(array $themeMeta = null)
    {
        $out = [0 => '无主题标签'];
        $meta = $themeMeta !== null ? $themeMeta : $this->themeSelectMeta();
        foreach ($meta as $row) {
            $label = ($row['cat_label'] ?? '') . ' / ' . ($row['title'] ?? '');
            if (($row['status'] ?? '') !== 'normal') {
                $label .= ' [停用]';
            }
            $out[(int)$row['id']] = $label;
        }
        return $out;
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
                '红宝•海外圈内事' => 'rules', '彩金白嫖' => 'ads',
            ];
            $cat = $legacy[$cat] ?? $this->defaultCategory;
            $data['category'] = $cat;
        }
        if ($this->categoryScope && !in_array($data['category'], $this->categoryScope, true)) {
            $data['category'] = $this->defaultCategory;
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

        $scoped = $this->scopedCategoryList();
        $cat = (string)($params['category'] ?? $this->defaultCategory);
        $params['category'] = isset($scoped[$cat]) ? $cat : $this->defaultCategory;

        $themeId = (int)($params['theme_id'] ?? 0);
        $params['theme_id'] = max(0, $themeId);
        if ($params['theme_id'] > 0) {
            $theme = \app\common\model\fanshub\NoticeTheme::where('id', $params['theme_id'])->find();
            if (!$theme) {
                $params['theme_id'] = 0;
                $params['theme_title'] = '';
            } else {
                $themeCat = trim((string)($theme->category ?? ''));
                if ($themeCat !== '' && $themeCat !== $params['category']) {
                    $params['theme_id'] = 0;
                    $params['theme_title'] = '';
                } else {
                    $params['theme_title'] = (string)$theme->title;
                }
            }
        } else {
            $params['theme_title'] = trim((string)($params['theme_title'] ?? ''));
        }
        if (isset($params['views_count'])) {
            $params['views_count'] = max(0, (int)$params['views_count']);
        }
        if (isset($params['user_id'])) {
            $params['user_id'] = max(0, (int)$params['user_id']);
        }
        if (!isset($params['source']) || $params['source'] === '') {
            $params['source'] = ((int)($params['user_id'] ?? 0) > 0) ? 'user' : 'admin';
        }

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

    public function index()
    {
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $query = $this->model->where($where);
            if ($this->categoryScope) {
                $query->where('category', 'in', $this->categoryScope);
            }
            if ($this->pendingFirstSort) {
                // 待审核置顶，再按发布时间/创建时间倒序
                $query->orderRaw("CASE WHEN status='pending' THEN 0 ELSE 1 END ASC");
                $timeField = in_array($sort, ['publishtime', 'createtime', 'id', 'weigh'], true) ? $sort : 'publishtime';
                $timeOrder = strtolower((string)$order) === 'asc' ? 'asc' : 'desc';
                if ($timeField === 'weigh') {
                    $query->order('publishtime', 'desc')->order('id', 'desc');
                } else {
                    $query->order($timeField, $timeOrder)->order('id', 'desc');
                }
            } else {
                $query->order($sort, $order);
            }
            $list = $query->paginate($limit);
            return json(['total' => $list->total(), 'rows' => $list->items()]);
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
            'content_i18n_map'      => [],
            'action_label_i18n_map' => [],
            'author_name_i18n_map'  => [],
            'category'              => $this->defaultCategory,
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
            $this->assertIdsInScope($ids);
            return parent::edit($ids);
        }

        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        if ($this->categoryScope && !in_array((string)$row['category'], $this->categoryScope, true)) {
            $this->error('该帖不属于本菜单管理范围');
        }
        $adminIds = $this->getDataLimitAdminIds();
        if (is_array($adminIds) && !in_array($row[$this->dataLimitField], $adminIds)) {
            $this->error(__('You have no permission'));
        }
        $this->view->assign('row', $this->rowForForm($row));
        return $this->view->fetch();
    }

    public function del($ids = null)
    {
        $this->assertIdsInScope($ids);
        return parent::del($ids);
    }

    public function multi($ids = null)
    {
        $this->assertIdsInScope($ids);
        return parent::multi($ids);
    }

    protected function assertIdsInScope($ids = null)
    {
        if (!$this->categoryScope) {
            return;
        }
        $ids = $ids ?: $this->request->param('ids');
        if (!$ids) {
            return;
        }
        $idArr = is_array($ids) ? $ids : explode(',', (string)$ids);
        $idArr = array_values(array_filter(array_map('intval', $idArr)));
        if (!$idArr) {
            return;
        }
        $bad = $this->model->where('id', 'in', $idArr)->where('category', 'not in', $this->categoryScope)->column('id');
        if ($bad) {
            $this->error('含有不属于本菜单的帖子，已拒绝操作');
        }
    }
}

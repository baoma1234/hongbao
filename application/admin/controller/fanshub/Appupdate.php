<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubService;
use think\Db;

/**
 * APP升级（会员运营）
 *
 * 每次发布保留历史记录；「设为当前」同步到客户端检测配置。
 *
 * @icon fa fa-cloud-upload
 */
class Appupdate extends Backend
{
    protected $model = null;
    protected $searchFields = 'id,version_name,download_url,update_note,remark';
    protected $multiFields = 'status';

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Appupdate;
        $this->view->assign('platformList', $this->model->getPlatformList());
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->assignconfig('platformList', $this->model->getPlatformList());
        $this->assignconfig('statusList', $this->model->getStatusList());
        $this->assignconfig('forceUpdateList', $this->model->getForceUpdateList());
        $this->assignconfig('isCurrentList', $this->model->getIsCurrentList());

        $cfg = FansHubService::config() ?: [];
        $this->view->assign('updateEnabled', !isset($cfg['app_update_enabled']) || !empty($cfg['app_update_enabled']));
        $this->view->assign('fallbackDownload', (string)($cfg['app_download_url'] ?? ''));
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
            return json(['total' => $list->total(), 'rows' => $list->items()]);
        }
        return $this->view->fetch();
    }

    public function add()
    {
        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');
            if (!is_array($params)) {
                $this->error('参数错误');
            }
            $row = $this->normalizeRow($params);
            $row['admin_id'] = (int)$this->auth->id;
            $setCurrent = !empty($params['set_current']);
            Db::startTrans();
            try {
                $id = $this->model->insertGetId($row);
                if ($setCurrent) {
                    $this->makeCurrent($id, $row['platform'], false);
                }
                Db::commit();
            } catch (\Throwable $e) {
                Db::rollback();
                $this->error($e->getMessage() ?: '保存失败');
            }
            $this->success($setCurrent ? '已添加并设为当前版本' : '已添加（历史记录）');
        }
        $this->view->assign('row', [
            'platform'      => 'android',
            'version_name'  => '',
            'version_code'  => 0,
            'download_url'  => '',
            'force_update'  => 0,
            'update_note'   => '',
            'status'        => 'normal',
            'remark'        => '',
            'set_current'   => 1,
        ]);
        return $this->view->fetch();
    }

    public function edit($ids = null)
    {
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error('记录不存在');
        }
        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');
            if (!is_array($params)) {
                $this->error('参数错误');
            }
            $data = $this->normalizeRow($params, true);
            $setCurrent = !empty($params['set_current']);
            Db::startTrans();
            try {
                $row->save($data);
                if ($setCurrent) {
                    $this->makeCurrent((int)$row['id'], (string)$row['platform'], false);
                } elseif ((int)$row['is_current'] === 1) {
                    // 编辑当前版本字段后重新同步配置
                    $this->syncConfigFromRow($row->toArray());
                }
                Db::commit();
            } catch (\Throwable $e) {
                Db::rollback();
                $this->error($e->getMessage() ?: '保存失败');
            }
            $this->success('保存成功');
        }
        $data = $row->toArray();
        $data['set_current'] = (int)$data['is_current'] === 1 ? 1 : 0;
        $this->view->assign('row', $data);
        return $this->view->fetch();
    }

    /**
     * 设为当前推送版本（同平台仅一条 is_current=1）
     */
    public function publish()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        $id = (int)$this->request->post('ids', $this->request->post('id', 0));
        if ($id <= 0) {
            $this->error('缺少 ID');
        }
        $row = $this->model->get($id);
        if (!$row) {
            $this->error('记录不存在');
        }
        if ((string)$row['status'] !== 'normal') {
            $this->error('请先把状态设为正常');
        }
        Db::startTrans();
        try {
            $this->makeCurrent($id, (string)$row['platform'], false);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            $this->error($e->getMessage() ?: '操作失败');
        }
        $this->success('已设为当前版本并同步客户端配置');
    }

    /**
     * 总开关：是否开启 App 内更新检测
     */
    public function toggle()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        $on = (int)$this->request->post('enabled', 0) === 1;
        $cfg = FansHubService::config() ?: [];
        if (!is_array($cfg)) {
            $cfg = [];
        }
        $cfg['app_update_enabled'] = $on;
        if (!FansHubService::saveFanshubConfig($cfg)) {
            $this->error('保存失败');
        }
        $this->success($on ? '已开启更新检测' : '已关闭更新检测');
    }

    public function del($ids = null)
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        $ids = $ids ?: $this->request->post('ids');
        if (!$ids) {
            $this->error('请选择记录');
        }
        $idArr = is_array($ids) ? $ids : explode(',', (string)$ids);
        $idArr = array_values(array_filter(array_map('intval', $idArr)));
        if (!$idArr) {
            $this->error('请选择记录');
        }
        $currents = $this->model->where('id', 'in', $idArr)->where('is_current', 1)->column('id');
        if ($currents) {
            $this->error('当前推送版本不可删除，请先切换其它版本为当前');
        }
        // 软删：隐藏，保留历史
        $this->model->where('id', 'in', $idArr)->update([
            'status'     => 'hidden',
            'updatetime' => time(),
        ]);
        $this->success('已隐藏（记录保留）');
    }

    protected function normalizeRow(array $params, $isEdit = false)
    {
        $platform = strtolower(trim((string)($params['platform'] ?? 'android')));
        if (!in_array($platform, ['android', 'ios'], true)) {
            $platform = 'android';
        }
        $versionName = mb_substr(trim((string)($params['version_name'] ?? '')), 0, 32);
        $versionCode = (int)($params['version_code'] ?? 0);
        $downloadUrl = mb_substr(trim((string)($params['download_url'] ?? '')), 0, 500);
        $note = mb_substr(trim((string)($params['update_note'] ?? '')), 0, 1000);
        $remark = mb_substr(trim((string)($params['remark'] ?? '')), 0, 255);
        $force = !empty($params['force_update']) ? 1 : 0;
        $status = ((string)($params['status'] ?? 'normal') === 'hidden') ? 'hidden' : 'normal';

        if ($versionCode <= 0) {
            $this->error('请填写 versionCode（大于 0）');
        }
        if ($versionName === '') {
            $versionName = (string)$versionCode;
        }

        $out = [
            'platform'     => $platform,
            'version_name' => $versionName,
            'version_code' => $versionCode,
            'download_url' => $downloadUrl,
            'force_update' => $force,
            'update_note'  => $note,
            'status'       => $status,
            'remark'       => $remark,
        ];
        if (!$isEdit) {
            $out['is_current'] = 0;
        }
        return $out;
    }

    protected function makeCurrent($id, $platform, $trans = true)
    {
        $id = (int)$id;
        $platform = strtolower(trim((string)$platform));
        $row = $this->model->get($id);
        if (!$row) {
            throw new \RuntimeException('记录不存在');
        }
        $run = function () use ($id, $platform, $row) {
            $this->model->where('platform', $platform)->where('is_current', 1)->update([
                'is_current' => 0,
                'updatetime' => time(),
            ]);
            $this->model->where('id', $id)->update([
                'is_current' => 1,
                'status'     => 'normal',
                'updatetime' => time(),
            ]);
            $fresh = $this->model->get($id);
            $this->syncConfigFromRow($fresh ? $fresh->toArray() : $row->toArray());
        };
        if ($trans) {
            Db::startTrans();
            try {
                $run();
                Db::commit();
            } catch (\Throwable $e) {
                Db::rollback();
                throw $e;
            }
        } else {
            $run();
        }
    }

    protected function syncConfigFromRow(array $row)
    {
        $platform = strtolower((string)($row['platform'] ?? 'android'));
        if (!in_array($platform, ['android', 'ios'], true)) {
            return;
        }
        $cfg = FansHubService::config() ?: [];
        if (!is_array($cfg)) {
            $cfg = [];
        }
        $prefix = 'app_' . $platform . '_';
        $cfg[$prefix . 'version_name'] = (string)($row['version_name'] ?? '');
        $cfg[$prefix . 'version_code'] = (int)($row['version_code'] ?? 0);
        $cfg[$prefix . 'download_url'] = (string)($row['download_url'] ?? '');
        $cfg[$prefix . 'force_update'] = !empty($row['force_update']);
        $cfg[$prefix . 'update_note'] = (string)($row['update_note'] ?? '');
        if (!isset($cfg['app_update_enabled'])) {
            $cfg['app_update_enabled'] = true;
        }
        if (!FansHubService::saveFanshubConfig($cfg)) {
            throw new \RuntimeException('同步配置文件失败');
        }
    }
}

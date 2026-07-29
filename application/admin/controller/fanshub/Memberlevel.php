<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubPhase2;
use app\common\library\FansHubService;
use think\Config as ThinkConfig;

/**
 * 福利大厅会员等级配置
 *
 * @icon fa fa-star
 */
class Memberlevel extends Backend
{
    protected $noNeedRight = ['index', 'save'];

    public function index()
    {
        $config = $this->configForView();
        $levels = FansHubService::memberLevels();
        $levelsList = [];
        foreach ($levels as $level => $item) {
            $levelsList[] = [
                'level'         => (int)$level,
                'name'          => $item['name'],
                'invite_reward' => $item['invite_reward'],
            ];
        }
        $this->view->assign([
            'config'      => $config,
            'levelsList'  => $levelsList,
            'levelStats'  => FansHubService::memberLevelStats(),
            'shareRights' => (float)($config['share_rights'] ?? 1),
            'honorTiers'  => array_values(FansHubPhase2::honorTiers()),
        ]);
        return $this->view->fetch();
    }

    public function save()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        $data = ThinkConfig::get('fanshub') ?: [];
        if (!is_array($data)) {
            $data = [];
        }
        $data['member_level_enabled'] = $this->request->post('member_level_enabled') ? true : false;
        if ($this->request->has('default_member_level', 'post')) {
            $data['default_member_level'] = max(1, (int)$this->request->post('default_member_level'));
        }
        try {
            $levels = FansHubService::parseMemberLevelsArray($this->request->post('levels/a', []));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
        if (!isset($levels[$data['default_member_level']])) {
            $this->error('新用户默认等级必须在已配置的等级列表中');
        }
        $data['member_levels'] = $levels;
        if ($this->request->has('honor_tiers/a', 'post')) {
            $data['honor_tiers'] = $this->parseHonorTiers($this->request->post('honor_tiers/a', []));
        }
        if (!FansHubService::saveFanshubConfig($data)) {
            $this->error('保存失败，请检查文件权限');
        }
        $this->success('会员等级配置已保存');
    }

    protected function parseHonorTiers(array $rows)
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $out[] = [
                'id'        => $id,
                'name'      => trim((string)($row['name'] ?? '')),
                'threshold' => max(1, (int)($row['threshold'] ?? 1)),
                'rights'    => (float)($row['rights'] ?? 0),
                'balance'   => (float)($row['balance'] ?? 0),
            ];
        }
        usort($out, function ($a, $b) {
            return $a['id'] <=> $b['id'];
        });
        return $out ?: array_values(FansHubPhase2::defaultHonorTiers());
    }

    protected function configForView()
    {
        $config = ThinkConfig::get('fanshub') ?: [];
        if (!is_array($config)) {
            $config = [];
        }
        if (!isset($config['default_member_level']) || (int)$config['default_member_level'] <= 0) {
            $config['default_member_level'] = 1;
        }
        return $config;
    }
}

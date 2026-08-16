<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubJPush;
use think\Db;

/**
 * 极光推送（单发 / 批量）+ 日志
 *
 * @icon fa fa-bell
 */
class Push extends Backend
{
    protected $model = null;
    protected $searchFields = 'id,title,content,scene,msg_id';

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Pushlog;
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
        $this->view->assign('jpush_ready', FansHubJPush::enabled() ? 1 : 0);
        $this->view->assign('jpush_app_key', FansHubJPush::appKey());
        return $this->view->fetch();
    }

    /**
     * 发送推送
     * POST: mode=single|batch|all, user_id?, user_ids?, title, content, platform
     */
    public function send()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        if (!FansHubJPush::enabled()) {
            $this->error('极光未配置或已关闭（检查 fanshub.php jpush_*）');
        }
        $mode = strtolower(trim((string)$this->request->post('mode', 'single')));
        $title = trim((string)$this->request->post('title', '红宝'));
        $content = trim((string)$this->request->post('content', ''));
        $platform = strtolower(trim((string)$this->request->post('platform', 'all')));
        if ($content === '') {
            $this->error('请填写推送内容');
        }
        if (!in_array($platform, ['all', 'ios', 'android'], true)) {
            $platform = 'all';
        }

        $adminId = (int)$this->auth->id;
        $opts = [
            'title'    => $title !== '' ? $title : '红宝',
            'content'  => $content,
            'platform' => $platform,
            'admin_id' => $adminId,
            'extras'   => [
                'scene' => 'admin_push',
                'mode'  => $mode,
            ],
        ];

        if ($mode === 'all') {
            // 全员：仅打到极光侧已注册设备，不按用户表盲推
            $opts['audience_all'] = true;
            $opts['scene'] = 'admin_batch';
            $res = FansHubJPush::send($opts);
            if (empty($res['ok'])) {
                $this->error('推送失败：' . ($res['error'] ?: 'unknown'));
            }
            $this->success('推送成功', null, ['msg_id' => $res['msg_id'] ?? '']);
            return;
        }

        if ($mode === 'batch') {
            $raw = (string)$this->request->post('user_ids', '');
            $parts = preg_split('/[\s,;，；]+/', $raw);
            $uids = array_values(array_unique(array_filter(array_map('intval', $parts ?: []))));
            if (!$uids) {
                $this->error('请填写用户 ID 列表（逗号/换行分隔）');
            }
            if (count($uids) > 2000) {
                $this->error('单次最多 2000 个用户');
            }
            $ready = FansHubJPush::userIdsWithRegistration($uids, $platform === 'all' ? 'all' : $platform);
            $skipped = count($uids) - count($ready);
            if (!$ready) {
                // 无人有 RID：不调极光、不写推送日志
                $this->error('所选用户均无移动端 Registration ID（未用 App 登录或未上报推送），已跳过');
            }
            $opts['user_ids'] = $ready;
            $opts['scene'] = 'admin_batch';
            $res = FansHubJPush::send($opts);
            if (empty($res['ok'])) {
                if (!empty($res['skipped'])) {
                    $this->error('无可推送设备（无 Registration ID），已跳过');
                }
                $this->error('推送失败：' . ($res['error'] ?: 'unknown'));
            }
            $msg = '推送成功';
            if ($skipped > 0) {
                $msg .= '（已跳过 ' . $skipped . ' 个无 RID 用户）';
            }
            $this->success($msg, null, [
                'msg_id'         => $res['msg_id'] ?? '',
                'pushed'         => count($ready),
                'skipped_no_rid' => $skipped,
            ]);
            return;
        }

        $uid = (int)$this->request->post('user_id', 0);
        if ($uid <= 0) {
            $this->error('请填写用户 ID');
        }
        $ready = FansHubJPush::userIdsWithRegistration([$uid], $platform === 'all' ? 'all' : $platform);
        if (!$ready) {
            // 单发无 RID：不推、不记日志
            $this->error('该用户无移动端 Registration ID（未用 App 登录或未上报推送），已跳过');
        }
        $opts['user_ids'] = $ready;
        $opts['scene'] = 'admin_single';
        $res = FansHubJPush::send($opts);
        if (empty($res['ok'])) {
            if (!empty($res['skipped'])) {
                $this->error('该用户无移动端 Registration ID，已跳过');
            }
            $this->error('推送失败：' . ($res['error'] ?: 'unknown'));
        }
        $this->success('推送成功', null, ['msg_id' => $res['msg_id'] ?? '']);
    }

    public function del($ids = null)
    {
        return parent::del($ids);
    }
}

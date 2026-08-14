<?php
/**
 * 测试裂变：邀请绑定后邀请人+被邀请人各得 1 份资格
 * php scripts/test_fission_dual_qual.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = dirname(__DIR__);
define('APP_PATH', $root . '/application/');
define('ROOT_PATH', $root . DIRECTORY_SEPARATOR);
define('RUNTIME_PATH', $root . '/runtime/');
define('EXTEND_PATH', $root . '/extend/');
define('VENDOR_PATH', $root . '/vendor/');
define('CONF_PATH', APP_PATH);

require $root . '/thinkphp/base.php';
\think\App::initCommon();

use app\common\library\FansHubFission;
use app\common\model\fanshub\FissionActivity;
use app\common\model\fanshub\FissionQual;
use think\Db;

try {
    $act = Db::name('fans_fission_activity')
        ->where('status', FissionActivity::STATUS_RUNNING)
        ->order('id', 'desc')
        ->find();

    if (!$act) {
        fwrite(STDERR, "FAIL: no running fission activity\n");
        exit(1);
    }

    $aid = (int)$act['id'];
    $startTs = (int)$act['start_time'];
    $now = time();
    if ($startTs > 0 && $now < $startTs) {
        fwrite(STDERR, "FAIL: activity not started yet\n");
        exit(1);
    }

    echo "activity id={$aid} global_quals={$act['global_quals']}/{$act['global_cap']} user_cap={$act['user_cap']}\n";

    $users = Db::name('user')->order('id', 'desc')->limit(20)->column('id');
    if (count($users) < 2) {
        fwrite(STDERR, "FAIL: need >=2 users\n");
        exit(1);
    }

    $inviter = 0;
    $invitee = 0;
    $userCap = max(1, (int)$act['user_cap']);
    foreach ($users as $uid) {
        $c = (int)Db::name('fans_fission_qual')->where(['activity_id' => $aid, 'user_id' => (int)$uid])->count();
        if ($c >= $userCap) {
            continue;
        }
        if (!$inviter) {
            $inviter = (int)$uid;
            continue;
        }
        if ((int)$uid !== $inviter) {
            $invitee = (int)$uid;
            break;
        }
    }
    if ($inviter <= 0 || $invitee <= 0) {
        fwrite(STDERR, "FAIL: cannot find two users under user_cap\n");
        exit(1);
    }

    $inviteeRow = Db::name('user')->where('id', $invitee)->find();
    $oldJoin = (int)($inviteeRow['jointime'] ?? 0);
    $oldCreate = (int)($inviteeRow['createtime'] ?? 0);
    $patchedJoin = false;
    if ($startTs > 0 && $oldJoin > 0 && $oldJoin < $startTs) {
        Db::name('user')->where('id', $invitee)->update(['jointime' => $now, 'createtime' => max($oldCreate, $now)]);
        $patchedJoin = true;
        echo "patched invitee#{$invitee} jointime for test\n";
    }

    $deletePair = function ($aid, $inviter, $invitee) {
        $n1 = Db::name('fans_fission_qual')
            ->where('activity_id', $aid)
            ->where('user_id', $inviter)
            ->where('source', FissionQual::SOURCE_INVITE_REWARD)
            ->where('ref_user_id', $invitee)
            ->delete();
        $n2 = Db::name('fans_fission_qual')
            ->where('activity_id', $aid)
            ->where('user_id', $invitee)
            ->where('source', FissionQual::SOURCE_INVITEE)
            ->where('ref_user_id', $inviter)
            ->delete();
        return (int)$n1 + (int)$n2;
    };

    $removed = $deletePair($aid, $inviter, $invitee);
    if ($removed > 0) {
        Db::name('fans_fission_activity')->where('id', $aid)->setDec('global_quals', $removed);
        echo "cleaned leftover pair quals={$removed}\n";
    }

    $beforeGlobal = (int)Db::name('fans_fission_activity')->where('id', $aid)->value('global_quals');
    echo "before: global={$beforeGlobal} inviter#{$inviter} invitee#{$invitee}\n";

    FansHubFission::onInviteBound($inviter, $invitee);

    $afterInviter = (int)Db::name('fans_fission_qual')
        ->where(['activity_id' => $aid, 'user_id' => $inviter, 'source' => FissionQual::SOURCE_INVITE_REWARD, 'ref_user_id' => $invitee])
        ->count();
    $afterInvitee = (int)Db::name('fans_fission_qual')
        ->where(['activity_id' => $aid, 'user_id' => $invitee, 'source' => FissionQual::SOURCE_INVITEE, 'ref_user_id' => $inviter])
        ->count();
    $afterGlobal = (int)Db::name('fans_fission_activity')->where('id', $aid)->value('global_quals');
    echo "after:  global={$afterGlobal} inviter_reward={$afterInviter} invitee_qual={$afterInvitee}\n";

    $ok = ($afterInviter === 1 && $afterInvitee === 1 && $afterGlobal === $beforeGlobal + 2);

    FansHubFission::onInviteBound($inviter, $invitee);
    $dupInviter = (int)Db::name('fans_fission_qual')
        ->where(['activity_id' => $aid, 'user_id' => $inviter, 'source' => FissionQual::SOURCE_INVITE_REWARD, 'ref_user_id' => $invitee])
        ->count();
    $dupInvitee = (int)Db::name('fans_fission_qual')
        ->where(['activity_id' => $aid, 'user_id' => $invitee, 'source' => FissionQual::SOURCE_INVITEE, 'ref_user_id' => $inviter])
        ->count();
    $dupGlobal = (int)Db::name('fans_fission_activity')->where('id', $aid)->value('global_quals');
    echo "idempotent: global={$dupGlobal} inviter={$dupInviter} invitee={$dupInvitee}\n";
    $ok = $ok && ($dupInviter === 1 && $dupInvitee === 1 && $dupGlobal === $afterGlobal);

    $removed = $deletePair($aid, $inviter, $invitee);
    if ($removed > 0) {
        Db::name('fans_fission_activity')->where('id', $aid)->setDec('global_quals', $removed);
    }
    if ($patchedJoin) {
        Db::name('user')->where('id', $invitee)->update(['jointime' => $oldJoin, 'createtime' => $oldCreate]);
        echo "restored invitee jointime\n";
    }
    $finalGlobal = (int)Db::name('fans_fission_activity')->where('id', $aid)->value('global_quals');
    echo "rolled back; global_quals={$finalGlobal}\n";

    if ($ok) {
        echo "PASS: inviter + invitee each got 1 qual; idempotent OK\n";
        exit(0);
    }
    fwrite(STDERR, "FAIL: dual qual grant did not behave as expected\n");
    exit(1);
} catch (\Throwable $e) {
    fwrite(STDERR, 'EX: ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
}

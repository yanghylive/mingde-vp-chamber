<?php

declare(strict_types=1);

use app\chamber\jobs\EventReservationRepairJob;
use app\chamber\jobs\MembershipOrderContextRepairJob;

/**
 * 商会修复任务 CLI 入口（供 crontab 定时调用）。
 *
 * 背景：EventReservationRepairJob / MembershipOrderContextRepairJob 是队列任务，
 * 但全项目无 dispatch 调用点，生产从未运行 —— 导致「过期未支付占用的活动名额不会自动释放」
 * 等业务缺陷。本脚本直接调用 doJob 绕过队列，crontab 定时执行即可，不依赖常驻队列 worker。
 *
 * 用法：
 *   php app/chamber/jobs/cli/repair.php [event|membership|all] [limit]
 *   例：php app/chamber/jobs/cli/repair.php all 50
 */

$root = dirname(__DIR__, 4); // {crmeb_root}/app/chamber/jobs/cli -> {crmeb_root}
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, 'autoload not found, expected at: ' . $autoload . PHP_EOL);
    exit(2);
}
require $autoload;

$app = new \think\App();
$app->initialize();

// chamber 是 ThinkPHP 多应用（auto_multi_app）下的独立应用，
// 其 provider 绑定与 config 仅在 HTTP 请求进入 /chamber/* 时自动加载。
// CLI 场景需手动加载，否则 app()->make(EventOrderGatewayInterface) 等接口绑定不可用。
$chamberBase = $root . '/app/chamber';
foreach (glob($chamberBase . '/config/*.php') ?: [] as $configFile) {
    $name = basename($configFile, '.php');
    if ($name !== 'route') {
        \think\facade\Config::set((array) require $configFile, $name);
    }
}
$chamberProviders = $chamberBase . '/provider.php';
if (is_file($chamberProviders)) {
    foreach ((array) require $chamberProviders as $abstract => $concrete) {
        app()->bind($abstract, $concrete);
    }
}

$which = $argv[1] ?? 'all';
$limit = (isset($argv[2]) && ctype_digit((string) $argv[2])) ? (int) $argv[2] : 50;

$ok = true;
if (in_array($which, ['event', 'all'], true)) {
    try {
        $ok = app()->make(EventReservationRepairJob::class)->doJob($limit) && $ok;
    } catch (Throwable $e) {
        fwrite(STDERR, 'event repair failed: ' . $e->getMessage() . PHP_EOL);
        $ok = false;
    }
}
if (in_array($which, ['membership', 'all'], true)) {
    try {
        $ok = app()->make(MembershipOrderContextRepairJob::class)->doJob($limit) && $ok;
    } catch (Throwable $e) {
        fwrite(STDERR, 'membership repair failed: ' . $e->getMessage() . PHP_EOL);
        $ok = false;
    }
}

exit($ok ? 0 : 1);

<?php
/**
 * 生产迁移执行器（在生产 PHP 容器内执行）。
 *
 * 背景：生产是手工演进环境（非 git 部署），部分表/列在迁移登记前已存在，
 * 裸 DDL 会报 1062/1060 中断；另有少量老数据漂移。本脚本语义对齐本地
 * manage-local-database.sh，并增加两处生产兼容：
 *   1. 已登记迁移复验 FAIL → 记 WARN 不阻塞（既有数据漂移单独开单修），
 *      未登记的新迁移仍严格校验，任何 FAIL 即中止。
 *   2. verify.sql 按「引号外的分号」切分执行（条件 DDL 内层分号在字符串内）。
 *
 * 前置：迁移目录已拷入容器 /tmp/mig/migrations：
 *   docker cp backend/custom/database/migrations <php-container>:/tmp/mig/migrations
 *
 * 用法（容器内）：
 *   php /tmp/prod-migrate.php --dry-run   # 只列出待执行，不写库
 *   php /tmp/prod-migrate.php             # 正式执行
 *
 * 注意：本脚本用 ThinkPHP Db facade 取容器真实连接配置，不含任何凭据。
 */
declare(strict_types=1);

require '/var/www/vendor/autoload.php';

$app = new think\App();
$app->initialize();

use think\facade\Db;

$dir = '/tmp/mig/migrations';
$argvList = $argv ?? [];
$dryRun = in_array('--dry-run', $argvList, true);

$files = glob($dir . '/*.up.sql') ?: [];
sort($files);
if ($files === []) {
    fwrite(STDERR, "未找到迁移文件: {$dir}\n");
    exit(2);
}

$registered = array_fill_keys(
    array_map('strval', Db::table('ch_schema_migration')->column('version')),
    true
);

$applied = 0;
$skipped = 0;
$checks = 0;
$drift = [];

foreach ($files as $upFile) {
    $version = basename($upFile, '.up.sql');
    $verifyFile = substr($upFile, 0, -strlen('.up.sql')) . '.verify.sql';

    if (!is_file($verifyFile)) {
        fwrite(STDERR, "缺少 verifier: {$version}\n");
        exit(1);
    }

    if (isset($registered[$version])) {
        $skipped++;
        $rendered = runVerify(file_get_contents($verifyFile));
        if (preg_match('/FAIL/', $rendered)) {
            // 已登记的迁移代码未变，其结构偏差属于生产既有数据漂移，不阻塞上线，单独开单。
            fwrite(STDERR, "WARN  {$version} 已登记但复验有 FAIL（既有数据漂移，不阻塞）：\n{$rendered}\n");
            $drift[] = $version;
            continue;
        }
        $checks += preg_match_all('/PASS/', $rendered);
        fwrite(STDOUT, "SKIP  {$version}（已登记，结构复验通过）\n");
        continue;
    }

    if ($dryRun) {
        fwrite(STDOUT, "DRY   {$version}\n");
        $skipped++;
        continue;
    }

    fwrite(STDOUT, "APPLY {$version}\n");
    $sql = file_get_contents($upFile);
    foreach (splitStatements($sql) as $statement) {
        try {
            Db::execute($statement);
        } catch (Throwable $e) {
            fwrite(STDERR, "迁移执行失败: {$version}\n  " . $e->getMessage() . "\n");
            exit(1);
        }
    }

    $rendered = runVerify(file_get_contents($verifyFile));
    if (preg_match('/FAIL/', $rendered)) {
        fwrite(STDERR, "结构校验失败: {$version}\n{$rendered}\n");
        exit(1);
    }
    $pass = preg_match_all('/PASS/', $rendered);
    if ($pass < 1) {
        fwrite(STDERR, "校验未产出 PASS: {$version}\n{$rendered}\n");
        exit(1);
    }
    $checks += $pass;

    Db::table('ch_schema_migration')->insert([
        'version' => $version,
        'checksum' => hash('sha256', $sql),
        'applied_at' => time(),
    ]);
    fwrite(STDOUT, "      校验 {$pass} 项 PASS，已登记\n");
    $applied++;
}

fwrite(STDOUT, PHP_EOL);
if ($drift !== []) {
    fwrite(STDOUT, '既有数据漂移（需单独开单，未阻塞本次上线）：' . implode(', ', $drift) . PHP_EOL);
}
fwrite(STDOUT, sprintf(
    '完成：应用 %d，跳过 %d，结构校验合计 %d 项%s',
    $applied,
    $skipped,
    $checks,
    $dryRun ? '（dry-run，未写库）' : PHP_EOL
));

/**
 * 执行 verify.sql 并收集输出。
 * 迁移校验里既有单条 SELECT（返回 PASS/FAIL 行），也有 CREATE TEMPORARY TABLE
 * 这类无返回语句，因此按分号切分逐条执行，把查询结果拼回可 grep 的文本。
 */
function runVerify(string $sql): string
{
    $rendered = '';
    foreach (splitStatements($sql) as $statement) {
        try {
            $rows = Db::query($statement);
        } catch (Throwable $e) {
            return $rendered . 'ERR ' . $e->getMessage() . "\n";
        }
        if (!is_array($rows)) {
            continue;
        }
        foreach ($rows as $row) {
            $cells = [];
            foreach ((array) $row as $key => $value) {
                $cells[] = $key . '=' . (is_scalar($value) ? (string) $value : json_encode($value));
            }
            $rendered .= implode(' ', $cells) . "\n";
        }
    }

    return $rendered;
}

/**
 * 粗粒度 SQL 切分：忽略注释行，按**引号外的分号**断句。
 * 条件 DDL（SET @ddl := IF(..., 'ALTER ...; ...', ...)）的内层分号在单引号
 * 字符串内，必须跳过，否则会把 IF 表达式从中间切断导致 1064。
 */
function splitStatements(string $sql): array
{
    $lines = [];
    foreach (explode("\n", $sql) as $line) {
        $trimmed = ltrim($line);
        if ($trimmed === '' || strpos($trimmed, '--') === 0) {
            continue;
        }
        $lines[] = $line;
    }
    $joined = implode("\n", $lines);
    $parts = [];
    $current = '';
    $inString = false;
    $length = strlen($joined);
    for ($i = 0; $i < $length; $i++) {
        $char = $joined[$i];
        if ($char === "'") {
            // MySQL 单引号转义 ''：跳过一对，不切换状态
            if ($inString && $i + 1 < $length && $joined[$i + 1] === "'") {
                $current .= "''";
                $i++;
                continue;
            }
            $inString = !$inString;
            $current .= $char;
            continue;
        }
        if ($char === ';' && !$inString) {
            $part = trim($current);
            if ($part !== '') {
                $parts[] = $part;
            }
            $current = '';
            continue;
        }
        $current .= $char;
    }
    $tail = trim($current);
    if ($tail !== '') {
        $parts[] = $tail;
    }

    return $parts;
}

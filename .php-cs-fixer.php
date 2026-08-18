<?php

/**
 * PHP CS Fixer 配置（明德VP商会 / mingde-vp-chamber）
 *
 * 目标：统一 backend/custom 业务代码风格为 PSR-12，并保证 PHP 7.4 兼容。
 * 使用：vendor/bin/php-cs-fixer fix --dry-run --diff   （CI 校验）
 *       vendor/bin/php-cs-fixer fix                     （本地修复）
 */

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/backend/custom')
    ->exclude([
        'tests',                 // 门禁测试脚本按既有风格维护，不强制重排
        'libs/pdfparser',        // 第三方库（LGPL-3.0），保持上游原样
    ])
    ->name('*.php')
    ->notName('*.blade.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setUsingCache(true)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache')
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'no_trailing_whitespace' => true,
        'no_whitespace_in_blank_line' => true,
        'trim_array_spaces' => true,
        'binary_operator_spaces' => ['default' => 'single_space'],
        'concat_space' => ['spacing' => 'one'],
        'blank_line_after_namespace' => true,
        'elseif' => true,
        'no_short_bool_cast' => false,
        'phpdoc_align' => false,
        'phpdoc_separation' => false,
    ])
    ->setFinder($finder);

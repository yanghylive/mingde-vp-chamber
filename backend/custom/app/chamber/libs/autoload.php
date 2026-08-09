<?php

declare(strict_types=1);

/**
 * chamber/libs 手动 PSR-0 自动加载。
 *
 * 背景：生产容器（md_kaypal_php）无 composer，本目录随 chamber 代码一起 rsync 部署。
 * 目前仅承载 smalot/pdfparser（纯 PHP PDF 文本提取）。
 * 新增库时：保持目录内 PSR-0/PSR-4 结构，在此追加注册规则。
 */
spl_autoload_register(function (string $class): void {
    // smalot/pdfparser：PSR-0，Smalot\PdfParser\Parser -> pdfparser/src/Smalot/PdfParser/Parser.php
    if (strncmp($class, 'Smalot\\PdfParser\\', 17) === 0) {
        $file = __DIR__ . '/pdfparser/src/' . str_replace('\\', '/', $class) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

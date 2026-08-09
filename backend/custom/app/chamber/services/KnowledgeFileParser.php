<?php

declare(strict_types=1);

namespace app\chamber\services;

use RuntimeException;

/**
 * 知识库文档解析：把上传的 PDF / Word / TXT 提取为纯文本。
 *
 * - PDF   ：smalot/pdfparser（纯 PHP，libs/pdfparser，PSR-0 由 libs/autoload.php 注册）
 *           仅支持含文本层的 PDF，扫描版图片 PDF 无法提取（返回空文本，前端提示）。
 * - DOCX  ：PHP 内置 ZipArchive 解析 word/document.xml，提取 <w:t> 文本（零依赖）。
 * - DOC   ：老版二进制格式不支持，提示转存为 docx。
 * - TXT/MD：直接读文件。
 */
final class KnowledgeFileParser
{
    private const MAX_FILE_BYTES = 15 * 1024 * 1024;

    /** 单条知识内容上限（与 ch_expert_ai_knowledge.content 校验一致） */
    private const MAX_CONTENT_CHARS = 20000;

    /**
     * 解析上传文件为纯文本。
     *
     * @param string $path 临时文件绝对路径
     * @param string $origName 原始文件名（用于判断扩展名与展示）
     * @return array{title: string, content: string, length: int, truncated: bool, source_file: string}
     * @throws RuntimeException 格式不支持 / 文件过大 / 解析失败
     */
    public function parse(string $path, string $origName): array
    {
        $size = @filesize($path);
        if ($size === false || $size <= 0) {
            throw new RuntimeException('文件读取失败，请重试');
        }
        if ($size > self::MAX_FILE_BYTES) {
            throw new RuntimeException('文件过大（限 15MB）');
        }

        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $text = '';

        switch ($ext) {
            case 'pdf':
                $text = $this->parsePdf($path);
                break;
            case 'docx':
                $text = $this->parseDocx($path);
                break;
            case 'doc':
                throw new RuntimeException('暂不支持老版 Word(.doc)，请另存为 .docx 后上传');
            case 'txt':
            case 'md':
                $raw = @file_get_contents($path);
                $text = $raw === false ? '' : (string) $raw;
                break;
            default:
                throw new RuntimeException('仅支持 PDF / Word(.docx) / TXT / MD 文件');
        }

        $text = trim(preg_replace('/[ \t]+/', ' ', $text) ?? '');
        if ($text === '') {
            throw new RuntimeException('未能从文件中提取到文本（扫描版 PDF 或空文档不支持）');
        }

        $length = mb_strlen($text);
        $truncated = $length > self::MAX_CONTENT_CHARS;

        // 文件名去扩展名（pathinfo 在 C locale 下对中文文件名不可靠，手动处理）
        $title = trim((string) preg_replace('/\.[^.]+$/u', '', $origName));
        if ($title === '') {
            $title = '未命名文档';
        }

        return [
            'title' => $title,
            'content' => mb_substr($text, 0, self::MAX_CONTENT_CHARS),
            'length' => $length,
            'truncated' => $truncated,
            'source_file' => $origName,
        ];
    }

    private function parsePdf(string $path): string
    {
        require_once dirname(__DIR__) . '/libs/autoload.php';

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($path);
            $chunks = [];
            foreach ($pdf->getPages() as $page) {
                $chunks[] = (string) $page->getText();
            }

            return implode("\n", $chunks);
        } catch (\Throwable $e) {
            throw new RuntimeException('PDF 解析失败：' . mb_substr($e->getMessage(), 0, 120), 0, $e);
        }
    }

    private function parseDocx(string $path): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new RuntimeException('服务器缺少 Zip 扩展，无法解析 Word 文件');
        }

        $zip = new \ZipArchive();
        if (@$zip->open($path) !== true) {
            throw new RuntimeException('无法读取 Word 文件（可能已损坏）');
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false || $xml === null) {
            throw new RuntimeException('Word 文件结构异常（缺少 document.xml）');
        }

        // 段落/换行/制表符 → 换行与制表
        $xml = preg_replace('/<w:tab[^>]*\/>/u', "\t", $xml);
        $xml = preg_replace('/<w:br[^>]*\/>/u', "\n", $xml);
        $xml = preg_replace('/<\/w:p>/u', "\n", $xml);

        if (!preg_match_all('/<w:t[^>]*>(.*?)<\/w:t>/su', $xml, $m)) {
            return '';
        }

        $text = html_entity_decode(implode('', $m[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');

        // 压缩多余空行
        return trim((string) preg_replace('/\n{3,}/u', "\n\n", $text));
    }
}

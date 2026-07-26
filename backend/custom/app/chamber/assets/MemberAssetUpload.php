<?php

declare(strict_types=1);

namespace app\chamber\assets;

use app\chamber\exceptions\MemberTransactionException;
use RuntimeException;
use think\file\UploadedFile;

final class MemberAssetUpload
{
    public const MAX_BYTES = 10485760;
    public const MAX_IMAGE_DIMENSION = 12000;
    public const MAX_IMAGE_PIXELS = 40000000;

    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    /** @var string */
    private $temporaryPath;

    /** @var string */
    private $purpose;

    /** @var string */
    private $originalName;

    /** @var string */
    private $mimeType;

    /** @var string */
    private $extension;

    /** @var int */
    private $size;

    /** @var string */
    private $sha256;

    private function __construct()
    {
    }

    public static function fromUploadedFile(UploadedFile $file, $purpose): self
    {
        $purpose = MemberAssetPurpose::validate($purpose);
        if (!$file->isValid()) {
            throw self::invalid('file is not a valid HTTP upload');
        }

        $path = $file->getRealPath();
        if (!is_string($path) || $path === '' || !is_file($path) || !is_readable($path) || is_link($path)) {
            throw self::invalid('uploaded file is unavailable');
        }

        clearstatcache(true, $path);
        $size = filesize($path);
        if (!is_int($size) || $size < 1 || $size > self::MAX_BYTES) {
            throw self::invalid('file must contain between 1 byte and 10 MiB');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            throw new RuntimeException('Fileinfo MIME detector is unavailable');
        }
        try {
            $mimeType = finfo_file($finfo, $path);
        } finally {
            finfo_close($finfo);
        }
        if ($mimeType === 'application/pdf') {
            throw self::invalid(
                'PDF uploads are disabled until parser and malware-scanner validation is available'
            );
        }
        if (!is_string($mimeType) || !isset(self::MIME_EXTENSIONS[$mimeType])) {
            throw self::invalid('file must be a JPEG or PNG detected by its content');
        }
        self::assertContentSignature($path, $mimeType);

        $sha256 = hash_file('sha256', $path);
        if (!is_string($sha256) || preg_match('/^[0-9a-f]{64}$/D', $sha256) !== 1) {
            throw new RuntimeException('Uploaded file SHA-256 could not be calculated');
        }

        $upload = new self();
        $upload->temporaryPath = $path;
        $upload->purpose = $purpose;
        $upload->mimeType = $mimeType;
        $upload->extension = self::MIME_EXTENSIONS[$mimeType];
        $upload->size = $size;
        $upload->sha256 = $sha256;
        $upload->originalName = self::sanitizeOriginalName(
            $file->getOriginalName(),
            $upload->extension
        );

        return $upload;
    }

    public static function sanitizeOriginalName($name, string $extension): string
    {
        if (!in_array($extension, ['jpg', 'png', 'pdf'], true)) {
            throw new RuntimeException('Controlled member asset extension is invalid');
        }
        if (!is_string($name)) {
            $name = '';
        }
        $name = str_replace('\\', '/', str_replace(["\r", "\n", "\0"], '', $name));
        $name = basename($name);
        $stem = pathinfo($name, PATHINFO_FILENAME);
        if (!is_string($stem)) {
            $stem = '';
        }
        $stem = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $stem);
        $stem = is_string($stem) ? preg_replace('/[_ ]+/', '_', $stem) : '';
        $stem = is_string($stem) ? trim($stem, " ._-") : '';
        if ($stem === '') {
            $stem = 'member-proof';
        }

        $suffix = '.' . $extension;
        $maximumStemBytes = 180 - strlen($suffix);
        if (strlen($stem) > $maximumStemBytes) {
            $stem = rtrim(substr($stem, 0, $maximumStemBytes), " ._-");
        }
        if ($stem === '') {
            $stem = 'member-proof';
        }

        return $stem . $suffix;
    }

    public function temporaryPath(): string
    {
        return $this->temporaryPath;
    }

    public function purpose(): string
    {
        return $this->purpose;
    }

    public function originalName(): string
    {
        return $this->originalName;
    }

    public function mimeType(): string
    {
        return $this->mimeType;
    }

    public function extension(): string
    {
        return $this->extension;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function sha256(): string
    {
        return $this->sha256;
    }

    public function toIdempotencyArray(): array
    {
        return [
            'purpose' => $this->purpose(),
            'sha256' => $this->sha256(),
            'mime_type' => $this->mimeType(),
            'size' => $this->size(),
            'original_name' => $this->originalName(),
        ];
    }

    private static function assertContentSignature(string $path, string $mimeType): void
    {
        $image = @getimagesize($path);
        $expectedType = $mimeType === 'image/jpeg' ? IMAGETYPE_JPEG : IMAGETYPE_PNG;
        if (!is_array($image) || !isset($image[0], $image[1], $image[2])
            || !is_int($image[0]) || !is_int($image[1])
            || $image[0] < 1 || $image[1] < 1
            || $image[0] > self::MAX_IMAGE_DIMENSION || $image[1] > self::MAX_IMAGE_DIMENSION
            || $image[0] * $image[1] > self::MAX_IMAGE_PIXELS
            || $image[2] !== $expectedType) {
            throw self::invalid('uploaded image content is invalid');
        }
    }

    private static function invalid(string $message): MemberTransactionException
    {
        return new MemberTransactionException(
            422,
            'asset_upload_invalid',
            $message,
            [['field' => 'file', 'code' => 'invalid_value']]
        );
    }
}

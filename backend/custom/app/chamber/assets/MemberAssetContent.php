<?php

declare(strict_types=1);

namespace app\chamber\assets;

use InvalidArgumentException;

final class MemberAssetContent
{
    /** @var string */
    private $path;

    /** @var string */
    private $originalName;

    /** @var string */
    private $mimeType;

    public function __construct(string $path, string $originalName, string $mimeType)
    {
        if ($path === '' || !is_file($path) || is_link($path)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,175}\.(jpg|png|pdf)$/D', $originalName) !== 1
            || !in_array($mimeType, ['image/jpeg', 'image/png', 'application/pdf'], true)) {
            throw new InvalidArgumentException('Private member asset content is invalid');
        }

        $this->path = $path;
        $this->originalName = $originalName;
        $this->mimeType = $mimeType;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function originalName(): string
    {
        return $this->originalName;
    }

    public function mimeType(): string
    {
        return $this->mimeType;
    }
}

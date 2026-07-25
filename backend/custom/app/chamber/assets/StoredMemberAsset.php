<?php

declare(strict_types=1);

namespace app\chamber\assets;

use InvalidArgumentException;

final class StoredMemberAsset
{
    /** @var int */
    private $size;

    /** @var string */
    private $sha256;

    public function __construct(int $size, string $sha256)
    {
        if ($size < 1 || $size > MemberAssetUpload::MAX_BYTES
            || preg_match('/^[0-9a-f]{64}$/D', $sha256) !== 1) {
            throw new InvalidArgumentException('Stored member asset integrity metadata is invalid');
        }

        $this->size = $size;
        $this->sha256 = $sha256;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function sha256(): string
    {
        return $this->sha256;
    }
}

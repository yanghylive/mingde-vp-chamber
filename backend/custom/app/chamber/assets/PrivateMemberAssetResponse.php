<?php

declare(strict_types=1);

namespace app\chamber\assets;

use RuntimeException;
use think\response\File;
use Throwable;

final class PrivateMemberAssetResponse extends File
{
    protected function output($data)
    {
        try {
            $output = parent::output($data);
        } catch (Throwable $exception) {
            throw new RuntimeException('Private member asset response is unavailable', 0, $exception);
        }
        $this->header['Cache-Control'] = 'private, no-store, max-age=0';
        unset($this->header['Cache-control']);
        $this->header['Pragma'] = 'no-cache';
        $this->header['Expires'] = '0';
        $this->header['X-Content-Type-Options'] = 'nosniff';
        $this->header['Content-Security-Policy'] = "sandbox; default-src 'none'";
        $this->header['Cross-Origin-Resource-Policy'] = 'same-origin';
        $this->header['Referrer-Policy'] = 'no-referrer';

        return $output;
    }
}

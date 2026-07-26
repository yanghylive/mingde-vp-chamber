<?php

declare(strict_types=1);

use app\chamber\assets\LocalPrivateAssetStorage;
use app\chamber\assets\MemberAssetContentResponder;
use app\chamber\assets\MemberAssetPurpose;
use app\chamber\assets\MemberAssetRecord;
use app\chamber\assets\MemberAssetUpload;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\membership\BootstrapIdempotency;
use think\file\UploadedFile;

$vendorCandidates = [
    dirname(__DIR__, 3) . '/vendor/autoload.php',
    dirname(__DIR__, 5) . '/backend/crmeb/crmeb/vendor/autoload.php',
];
foreach ($vendorCandidates as $vendorAutoload) {
    if (is_file($vendorAutoload)) {
        require_once $vendorAutoload;
        break;
    }
}

spl_autoload_register(function (string $class): void {
    $prefix = 'app\\chamber\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = dirname(__DIR__) . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$temporaryRoot = sys_get_temp_dir() . '/chamber-member-asset-' . bin2hex(random_bytes(8));
if (!mkdir($temporaryRoot, 0700, true) && !is_dir($temporaryRoot)) {
    throw new RuntimeException('Could not create member asset test directory');
}

$tests = [];

$tests['upload accepts content-detected PNG and ignores client MIME'] = function () use ($temporaryRoot): void {
    $path = $temporaryRoot . '/source.png';
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        true
    );
    if (!is_string($png) || file_put_contents($path, $png) === false) {
        throw new RuntimeException('Could not create PNG fixture');
    }
    $file = new UploadedFile(
        $path,
        "../毕业证明\r\n.png",
        'text/plain',
        UPLOAD_ERR_OK,
        true
    );
    $upload = MemberAssetUpload::fromUploadedFile(
        $file,
        MemberAssetPurpose::GRADUATE_VERIFICATION_PROOF
    );

    assertSame('image/png', $upload->mimeType());
    assertSame('png', $upload->extension());
    assertSame('member-proof.png', $upload->originalName());
    assertSame(strlen($png), $upload->size());
    assertSame(hash('sha256', $png), $upload->sha256());
};

$tests['upload rejects every PDF until parser and scanner validation exists'] = function () use ($temporaryRoot): void {
    $fixtures = [
        'benign' => "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n",
        'escaped-active' => "%PDF-1.4\n1 0 obj\n<< /Open#41ction 2 0 R >>\nendobj\n"
            . "2 0 obj\n<< /S /J#61vaScript /J#53 (app.alert(1)) >>\nendobj\n%%EOF\n",
    ];
    foreach ($fixtures as $name => $bytes) {
        $path = $temporaryRoot . '/' . $name . '.pdf';
        file_put_contents($path, $bytes);
        expectMemberError('asset_upload_invalid', 422, function () use ($path): void {
            MemberAssetUpload::fromUploadedFile(
                new UploadedFile($path, 'proof.pdf', 'application/octet-stream', UPLOAD_ERR_OK, true),
                MemberAssetPurpose::GRADUATE_VERIFICATION_PROOF
            );
        });
    }
};

$tests['upload idempotency projection is stable and content sensitive'] = function () use ($temporaryRoot): void {
    $firstPath = $temporaryRoot . '/idempotency-first.png';
    $secondPath = $temporaryRoot . '/idempotency-second.png';
    $firstBytes = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        true
    );
    $secondBytes = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8zwAAAgEBAScY42YAAAAASUVORK5CYII=',
        true
    );
    if (!is_string($firstBytes) || !is_string($secondBytes)) {
        throw new RuntimeException('Could not decode PNG idempotency fixtures');
    }
    file_put_contents($firstPath, $firstBytes);
    file_put_contents($secondPath, $secondBytes);
    $first = MemberAssetUpload::fromUploadedFile(
        new UploadedFile($firstPath, 'proof.png', 'application/octet-stream', UPLOAD_ERR_OK, true),
        MemberAssetPurpose::GRADUATE_VERIFICATION_PROOF
    );
    $same = MemberAssetUpload::fromUploadedFile(
        new UploadedFile($firstPath, 'proof.png', 'text/plain', UPLOAD_ERR_OK, true),
        MemberAssetPurpose::GRADUATE_VERIFICATION_PROOF
    );
    $different = MemberAssetUpload::fromUploadedFile(
        new UploadedFile($secondPath, 'proof.png', 'application/octet-stream', UPLOAD_ERR_OK, true),
        MemberAssetPurpose::GRADUATE_VERIFICATION_PROOF
    );

    assertSame([
        'purpose' => 'graduate_verification_proof',
        'sha256' => hash_file('sha256', $firstPath),
        'mime_type' => 'image/png',
        'size' => filesize($firstPath),
        'original_name' => 'proof.png',
    ], $first->toIdempotencyArray());
    assertSame(
        BootstrapIdempotency::requestHash(11, $first->toIdempotencyArray()),
        BootstrapIdempotency::requestHash(11, $same->toIdempotencyArray())
    );
    assertNotSame(
        BootstrapIdempotency::requestHash(11, $first->toIdempotencyArray()),
        BootstrapIdempotency::requestHash(11, $different->toIdempotencyArray())
    );
};

/*
 * Keep storage-layer PDF coverage below for previously persisted objects. The upload
 * boundary above intentionally refuses every new PDF until structured validation exists.
 */

/*
 * Unsupported and oversized payloads must still fail before storage or idempotency state.
 */
$tests['upload rejects unsupported content and oversized files'] = function () use ($temporaryRoot): void {
    $text = $temporaryRoot . '/plain.txt';
    file_put_contents($text, 'not a private proof format');
    expectMemberError('asset_upload_invalid', 422, function () use ($text): void {
        MemberAssetUpload::fromUploadedFile(
            new UploadedFile($text, 'proof.png', 'image/png', UPLOAD_ERR_OK, true),
            MemberAssetPurpose::GRADUATE_VERIFICATION_PROOF
        );
    });

    $large = $temporaryRoot . '/large.bin';
    $handle = fopen($large, 'w+b');
    if ($handle === false || !ftruncate($handle, MemberAssetUpload::MAX_BYTES + 1)) {
        throw new RuntimeException('Could not create oversized fixture');
    }
    fclose($handle);
    expectMemberError('asset_upload_invalid', 422, function () use ($large): void {
        MemberAssetUpload::fromUploadedFile(
            new UploadedFile($large, 'proof.png', 'image/png', UPLOAD_ERR_OK, true),
            MemberAssetPurpose::GRADUATE_VERIFICATION_PROOF
        );
    });
};

$tests['purpose and content queries use closed server-side vocabularies'] = function (): void {
    assertSame(
        MemberAssetPurpose::GRADUATE_VERIFICATION_PROOF,
        MemberAssetPurpose::validate(MemberAssetPurpose::GRADUATE_VERIFICATION_PROOF)
    );
    expectMemberError('request_validation_failed', 422, function (): void {
        MemberAssetPurpose::validate('avatar');
    });

    assertSame(false, MemberAssetContentResponder::parseOwnerQuery([]));
    assertSame(false, MemberAssetContentResponder::parseOwnerQuery(['download' => '0']));
    assertSame(true, MemberAssetContentResponder::parseOwnerQuery(['download' => '1']));
    expectMemberError('request_validation_failed', 422, function (): void {
        MemberAssetContentResponder::parseOwnerQuery(['download' => '2']);
    });
    expectMemberError('request_validation_failed', 422, function (): void {
        MemberAssetContentResponder::parseOwnerQuery(['application_id' => '1']);
    });
    expectMemberError('request_validation_failed', 422, function (): void {
        MemberAssetContentResponder::parseOwnerQuery(
            ['download' => '1'],
            'download=0&download=1'
        );
    });

    assertSame(
        ['application_id' => 42, 'download' => true],
        MemberAssetContentResponder::parseAdminQuery(['application_id' => '42', 'download' => 1])
    );
    expectMemberError('request_validation_failed', 422, function (): void {
        MemberAssetContentResponder::parseAdminQuery([]);
    });
    expectMemberError('request_validation_failed', 422, function (): void {
        MemberAssetContentResponder::parseAdminQuery(['application_id' => '042']);
    });
    expectMemberError('request_validation_failed', 422, function (): void {
        MemberAssetContentResponder::parseAdminQuery(['application_id' => '42', 'unexpected' => '1']);
    });
    expectMemberError('request_validation_failed', 422, function (): void {
        MemberAssetContentResponder::parseAdminQuery(
            ['application_id' => '42'],
            'application_id=41&application_id=42'
        );
    });
};

$tests['local storage uses opaque tenant keys and verifies copied bytes'] = function () use ($temporaryRoot): void {
    $source = $temporaryRoot . '/storage-source.pdf';
    $bytes = "%PDF-1.4\nprivate proof bytes\n%%EOF\n";
    file_put_contents($source, $bytes);
    $storage = new LocalPrivateAssetStorage($temporaryRoot . '/private-runtime');
    $key = $storage->generateObjectKey(3, 'pdf');
    assertMatches('/^member-assets\/v1\/t3\/[0-9a-f]{32}\.pdf$/D', $key);

    $stored = $storage->store($source, $key);
    assertSame(strlen($bytes), $stored->size());
    assertSame(hash('sha256', $bytes), $stored->sha256());
    $path = $storage->pathForRead($key);
    assertSame($bytes, file_get_contents($path));
    assertSame(0600, fileperms($path) & 0777);
    assertSame(true, $storage->delete($key));
    assertSame(false, is_file($path));
};

$tests['local storage accepts a source exactly one read chunk long'] = function () use ($temporaryRoot): void {
    $source = $temporaryRoot . '/storage-exact-chunk.pdf';
    $bytes = str_repeat('x', 8192);
    file_put_contents($source, $bytes);
    $storage = new LocalPrivateAssetStorage($temporaryRoot . '/exact-chunk-runtime');
    $key = $storage->generateObjectKey(13, 'pdf');
    $stored = $storage->store($source, $key);

    assertSame(8192, $stored->size());
    assertSame(hash('sha256', $bytes), $stored->sha256());
    assertSame($bytes, file_get_contents($storage->pathForRead($key)));
};

$tests['local storage closes traversal, foreign tenant, and symlink paths'] = function () use ($temporaryRoot): void {
    foreach ([
        '../proof.pdf',
        'member-assets/v1/t3/../proof.pdf',
        'member-assets/v1/t4/0123456789abcdef0123456789abcdef.pdf',
        'https://example.test/proof.pdf',
    ] as $key) {
        expectException(InvalidArgumentException::class, function () use ($key): void {
            LocalPrivateAssetStorage::assertObjectKey($key, 3);
        });
    }

    $storage = new LocalPrivateAssetStorage($temporaryRoot . '/symlink-runtime');
    $key = 'member-assets/v1/t3/0123456789abcdef0123456789abcdef.pdf';
    $outside = $temporaryRoot . '/outside.pdf';
    file_put_contents($outside, 'outside');
    $link = $temporaryRoot . '/symlink-runtime/' . $key;
    if (!is_dir(dirname($link)) && !mkdir(dirname($link), 0700, true) && !is_dir(dirname($link))) {
        throw new RuntimeException('Could not create symlink fixture directory');
    }
    if (!symlink($outside, $link)) {
        throw new RuntimeException('Could not create symlink fixture');
    }
    expectException(RuntimeException::class, function () use ($storage, $key): void {
        $storage->pathForRead($key);
    });
};

$tests['asset API metadata excludes path, driver, hash, and owner identities'] = function (): void {
    $record = MemberAssetRecord::fromDatabaseRow([
        'id' => '19',
        'tenant_id' => '3',
        'channel_id' => '11',
        'member_id' => '7',
        'uid' => '42',
        'purpose' => MemberAssetPurpose::GRADUATE_VERIFICATION_PROOF,
        'object_key' => 'member-assets/v1/t3/0123456789abcdef0123456789abcdef.pdf',
        'storage_driver' => 'local',
        'original_name' => 'graduation-proof.pdf',
        'mime_type' => 'application/pdf',
        'byte_size' => '1024',
        'sha256' => str_repeat('a', 64),
        'status' => '1',
        'used_business_type' => '',
        'used_business_id' => '0',
        'used_time' => '0',
        'last_access_time' => '0',
        'add_time' => '1785000000',
        'update_time' => '1785000000',
    ]);
    $metadata = $record->publicMetadata();

    assertSame(true, $record->isReusableProof());
    assertSame(['id', 'object_key', 'original_name', 'mime_type', 'size', 'available'], array_keys($metadata));
    assertSame(true, $metadata['available']);
    foreach (['path', 'storage_driver', 'sha256', 'tenant_id', 'channel_id', 'member_id', 'uid'] as $forbidden) {
        assertSame(false, array_key_exists($forbidden, $metadata));
    }
};

$tests['consumed graduate proof remains reusable without changing first-use metadata'] = function (): void {
    $record = MemberAssetRecord::fromDatabaseRow([
        'id' => '20',
        'tenant_id' => '3',
        'channel_id' => '11',
        'member_id' => '7',
        'uid' => '42',
        'purpose' => MemberAssetPurpose::GRADUATE_VERIFICATION_PROOF,
        'object_key' => 'member-assets/v1/t3/fedcba9876543210fedcba9876543210.png',
        'storage_driver' => 'local',
        'original_name' => 'old-proof.png',
        'mime_type' => 'image/png',
        'byte_size' => '512',
        'sha256' => str_repeat('b', 64),
        'status' => '2',
        'used_business_type' => 'graduate_verification',
        'used_business_id' => '99',
        'used_time' => '1785000000',
        'last_access_time' => '0',
        'add_time' => '1784990000',
        'update_time' => '1785000000',
    ]);

    assertSame(true, $record->isReusableProof());
    assertSame(99, $record->usedBusinessId());
    assertSame(
        ['id', 'object_key', 'original_name', 'mime_type', 'size', 'available'],
        array_keys($record->publicMetadata())
    );
};

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, "PASS {$name}\n");
    } catch (Throwable $exception) {
        $failures++;
        fwrite(STDERR, "FAIL {$name}: {$exception->getMessage()}\n");
    }
}

removeDirectory($temporaryRoot);
fwrite(STDOUT, sprintf("%d tests, %d failures\n", count($tests), $failures));
exit($failures === 0 ? 0 : 1);

function assertSame($expected, $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            'Expected %s, got %s',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assertMatches(string $pattern, string $actual): void
{
    if (preg_match($pattern, $actual) !== 1) {
        throw new RuntimeException(sprintf('Expected %s to match %s', $actual, $pattern));
    }
}

function assertNotSame($expected, $actual): void
{
    if ($expected === $actual) {
        throw new RuntimeException(sprintf('Did not expect %s', var_export($actual, true)));
    }
}

function expectException(string $class, callable $callback): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        if ($exception instanceof $class) {
            return;
        }
        throw $exception;
    }

    throw new RuntimeException("Expected exception {$class}");
}

function expectMemberError(string $reason, int $status, callable $callback): void
{
    try {
        $callback();
    } catch (MemberTransactionException $exception) {
        assertSame($reason, $exception->reason());
        assertSame($status, $exception->httpStatus());

        return;
    }

    throw new RuntimeException("Expected MemberTransactionException reason {$reason}");
}

function removeDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $items = scandir($directory);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_link($path) || is_file($path)) {
            @unlink($path);
        } elseif (is_dir($path)) {
            removeDirectory($path);
        }
    }
    @rmdir($directory);
}

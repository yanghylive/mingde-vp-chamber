<?php

declare(strict_types=1);

namespace app\chamber\assets;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class LocalPrivateAssetStorage
{
    public const DRIVER = 'local';

    /** @var string */
    private $rootDirectory;

    /** @var bool */
    private $usesApplicationRoot;

    public function __construct(?string $rootDirectory = null)
    {
        $this->usesApplicationRoot = $rootDirectory === null;
        if ($rootDirectory === null) {
            if (!function_exists('root_path')) {
                throw new RuntimeException('ThinkPHP root_path is unavailable for private asset storage');
            }
            $rootDirectory = (string) root_path('runtime/chamber-private');
        }
        $rootDirectory = rtrim($rootDirectory, '/\\');
        if ($rootDirectory === '' || strpos($rootDirectory, "\0") !== false) {
            throw new InvalidArgumentException('Private asset storage root is invalid');
        }

        $this->rootDirectory = $rootDirectory;
        $this->ensureDirectory($this->rootDirectory);
        $this->assertPrivateRoot();
    }

    public function driver(): string
    {
        return self::DRIVER;
    }

    public function generateObjectKey(int $tenantId, string $extension): string
    {
        if ($tenantId <= 0 || !in_array($extension, ['jpg', 'png', 'pdf'], true)) {
            throw new InvalidArgumentException('Member asset object key parameters are invalid');
        }

        return sprintf(
            'member-assets/v1/t%d/%s.%s',
            $tenantId,
            bin2hex(random_bytes(16)),
            $extension
        );
    }

    public function store(string $sourcePath, string $objectKey): StoredMemberAsset
    {
        self::assertObjectKey($objectKey);
        if (!is_file($sourcePath) || !is_readable($sourcePath) || is_link($sourcePath)) {
            throw new RuntimeException('Private asset source file is unavailable');
        }

        $target = $this->candidatePath($objectKey);
        $directory = dirname($target);
        $this->ensureDirectory($directory);
        $this->assertContainedDirectory($directory);
        if (file_exists($target) || is_link($target)) {
            throw new RuntimeException('Private asset object key collision');
        }

        $source = fopen($sourcePath, 'rb');
        if ($source === false) {
            throw new RuntimeException('Private asset source file could not be opened');
        }
        $destination = fopen($target, 'x+b');
        if ($destination === false) {
            fclose($source);
            throw new RuntimeException('Private asset destination could not be created');
        }

        $hash = hash_init('sha256');
        $size = 0;
        try {
            while (!feof($source)) {
                $chunk = fread($source, 8192);
                if ($chunk === false) {
                    throw new RuntimeException('Private asset source read failed');
                }
                if ($chunk === '') {
                    if (feof($source)) {
                        break;
                    }
                    throw new RuntimeException('Private asset source returned an empty read before EOF');
                }
                $size += strlen($chunk);
                if ($size > MemberAssetUpload::MAX_BYTES) {
                    throw new RuntimeException('Private asset changed while being stored');
                }
                hash_update($hash, $chunk);
                $remaining = $chunk;
                while ($remaining !== '') {
                    $written = fwrite($destination, $remaining);
                    if (!is_int($written) || $written < 1) {
                        throw new RuntimeException('Private asset destination write failed');
                    }
                    $remaining = (string) substr($remaining, $written);
                }
            }
            if ($size < 1 || !fflush($destination)) {
                throw new RuntimeException('Private asset destination flush failed');
            }
        } catch (Throwable $exception) {
            fclose($source);
            fclose($destination);
            @unlink($target);
            throw $exception;
        }
        fclose($source);
        fclose($destination);
        if (!@chmod($target, 0600)) {
            @unlink($target);
            throw new RuntimeException('Private asset file permissions could not be restricted');
        }
        clearstatcache(true, $target);
        $permissions = fileperms($target);
        if (!is_int($permissions) || ($permissions & 0077) !== 0) {
            @unlink($target);
            throw new RuntimeException('Private asset file permissions are not private');
        }

        return new StoredMemberAsset($size, hash_final($hash));
    }

    public function pathForRead(string $objectKey): string
    {
        self::assertObjectKey($objectKey);
        $candidate = $this->candidatePath($objectKey);
        if (is_link($candidate)) {
            throw new RuntimeException('Private asset symlinks are forbidden');
        }
        $path = realpath($candidate);
        $root = realpath($this->rootDirectory);
        if (!is_string($path) || !is_string($root) || !is_file($path)
            || strpos($path, $root . DIRECTORY_SEPARATOR) !== 0) {
            throw new RuntimeException('Private asset object is unavailable');
        }

        return $path;
    }

    public function delete(string $objectKey): bool
    {
        self::assertObjectKey($objectKey);
        $candidate = $this->candidatePath($objectKey);
        if (!file_exists($candidate) && !is_link($candidate)) {
            return true;
        }
        if (is_link($candidate)) {
            return false;
        }

        return unlink($candidate);
    }

    public static function assertObjectKey($objectKey, int $tenantId = 0): string
    {
        if (!is_string($objectKey)
            || preg_match('/^member-assets\/v1\/t([1-9][0-9]*)\/[0-9a-f]{32}\.(jpg|png|pdf)$/D', $objectKey, $matches) !== 1
            || ($tenantId > 0 && (int) $matches[1] !== $tenantId)) {
            throw new InvalidArgumentException('Private member asset object key is invalid');
        }

        return $objectKey;
    }

    private function candidatePath(string $objectKey): string
    {
        return $this->rootDirectory . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $objectKey);
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Private asset storage directory could not be created');
        }
        if (!is_writable($directory)) {
            throw new RuntimeException('Private asset storage directory is not writable');
        }
        if (!@chmod($directory, 0700)) {
            throw new RuntimeException('Private asset storage directory permissions could not be restricted');
        }
    }

    private function assertContainedDirectory(string $directory): void
    {
        $root = realpath($this->rootDirectory);
        $path = realpath($directory);
        if (!is_string($root) || !is_string($path)
            || ($path !== $root && strpos($path, $root . DIRECTORY_SEPARATOR) !== 0)) {
            throw new RuntimeException('Private asset storage escaped its configured root');
        }
    }

    private function assertPrivateRoot(): void
    {
        $root = realpath($this->rootDirectory);
        if (!is_string($root)) {
            throw new RuntimeException('Private asset storage root could not be resolved');
        }
        if (!$this->usesApplicationRoot || !function_exists('public_path')) {
            return;
        }

        $public = realpath((string) public_path());
        if (!is_string($public)) {
            throw new RuntimeException('Application public directory could not be resolved');
        }
        if ($root === $public || strpos($root, $public . DIRECTORY_SEPARATOR) === 0) {
            throw new RuntimeException('Private asset storage must not be inside public/');
        }
    }
}

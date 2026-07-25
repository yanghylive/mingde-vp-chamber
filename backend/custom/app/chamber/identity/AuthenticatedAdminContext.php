<?php

declare(strict_types=1);

namespace app\chamber\identity;

use app\chamber\exceptions\MemberTransactionException;
use InvalidArgumentException;

final class AuthenticatedAdminContext
{
    public const CONTAINER_KEY = 'chamber.authenticated_admin_context';

    /** @var int */
    private $adminId;

    /** @var bool */
    private $superAdministrator;

    /** @var array */
    private $permissions;

    public function __construct(int $adminId, bool $superAdministrator, array $permissions)
    {
        if ($adminId <= 0) {
            throw new InvalidArgumentException('Authenticated administrator ID must be positive');
        }

        $normalized = [];
        foreach ($permissions as $permission) {
            if (!is_string($permission) || strlen($permission) < 1 || strlen($permission) > 128
                || preg_match('/^[A-Za-z][A-Za-z0-9._:-]*$/D', $permission) !== 1) {
                throw new InvalidArgumentException('Authenticated administrator permission is invalid');
            }
            $normalized[$permission] = true;
        }

        $this->adminId = $adminId;
        $this->superAdministrator = $superAdministrator;
        $this->permissions = $normalized;
    }

    public static function fromAuthInfo(array $adminInfo, array $permissions): self
    {
        $adminId = self::integer($adminInfo['id'] ?? null, 'id');
        $level = self::integer($adminInfo['level'] ?? null, 'level');
        if ($adminId <= 0 || $level < 0 || ($adminInfo['type'] ?? null) !== 'admin') {
            throw new InvalidArgumentException('CRMEB administrator authentication result is invalid');
        }

        return new self($adminId, $level === 0, $permissions);
    }

    public function adminId(): int
    {
        return $this->adminId;
    }

    public function isSuperAdministrator(): bool
    {
        return $this->superAdministrator;
    }

    public function hasPermission(string $permission): bool
    {
        return $this->superAdministrator || isset($this->permissions[$permission]);
    }

    public function assertPermission(string $permission): void
    {
        if (!$this->hasPermission($permission)) {
            throw new MemberTransactionException(403, 'permission_denied', 'Administrator permission is required');
        }
    }

    private static function integer($value, string $field): int
    {
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value)) {
            $integer = (int) $value;
            if ((string) $integer === $value) {
                $value = $integer;
            }
        }
        if (!is_int($value)) {
            throw new InvalidArgumentException(sprintf('CRMEB administrator %s is invalid', $field));
        }

        return $value;
    }
}

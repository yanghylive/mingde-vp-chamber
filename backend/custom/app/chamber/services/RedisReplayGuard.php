<?php

namespace app\chamber\services;

use app\chamber\contracts\ReplayGuardInterface;
use app\chamber\exceptions\TenantResolutionException;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Cache;
use Throwable;

final class RedisReplayGuard implements ReplayGuardInterface
{
    /** @var string */
    private $prefix;

    /** @var callable */
    private $clock;

    /** @var callable */
    private $handlerFactory;

    public function __construct(string $prefix, callable $clock = null, callable $handlerFactory = null)
    {
        if (!preg_match('/^[A-Za-z0-9:_-]{8,120}$/', $prefix)) {
            throw new InvalidArgumentException('Tenant replay key prefix is invalid');
        }

        $this->prefix = $prefix;
        $this->clock = $clock ?: 'time';
        $this->handlerFactory = $handlerFactory ?: function () {
            return Cache::store('redis')->handler();
        };
    }

    public function claim(string $nonce, int $expiresAt): bool
    {
        $ttl = $expiresAt - (int) call_user_func($this->clock);
        if ($ttl < 1) {
            return false;
        }

        $key = $this->prefix . hash('sha256', $nonce);
        $script = "local value = redis.call('SET', KEYS[1], '1', 'NX', 'EX', ARGV[1]); if value then return 1 else return 0 end";

        try {
            $handler = call_user_func($this->handlerFactory);
            if (!is_object($handler) || !method_exists($handler, 'eval')) {
                throw new RuntimeException('Redis handler does not support EVAL');
            }

            if (is_a($handler, 'Predis\\Client')) {
                $result = $handler->eval($script, 1, $key, (string) $ttl);
            } else {
                $result = $handler->eval($script, [$key, (string) $ttl], 1);
            }
        } catch (Throwable $exception) {
            throw new TenantResolutionException(
                TenantResolutionException::REPLAY_GUARD_UNAVAILABLE,
                'Tenant replay guard is unavailable',
                503
            );
        }

        return (int) $result === 1;
    }
}

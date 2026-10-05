<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\exceptions\MemberTransactionException;
use crmeb\utils\JwtAuth;
use think\facade\Env;
use think\Response;

/**
 * 本地验证用：为指定 uid 签发 CRMEB 会员 token（仅创建 JWT + 缓存项，不触发登录事件）。
 * 仅允许在开发环境使用（与 CoursePackagePaymentCompletionService::assertDevEnabled 同口径），
 * 供浏览器验证页 / 手动 curl 一键获取鉴权 token。生产环境（非 dev/local）返回 403。
 */
final class DevTokenController
{
    public function mint(Request $request): Response
    {
        $this->assertDevEnabled();

        $uid = (int) $request->get('uid', '1');
        if ($uid <= 0) {
            throw new MemberTransactionException(422, 'request_validation_failed', 'uid must be a positive integer');
        }

        /** @var JwtAuth $jwtAuth */
        $jwtAuth = app()->make(JwtAuth::class);
        $tokenInfo = $jwtAuth->createToken($uid, 'api', ['pwd' => md5('')]);
        $token = is_array($tokenInfo) && isset($tokenInfo['token'])
            ? (string) $tokenInfo['token']
            : (string) $tokenInfo;

        return Response::create([
            'status' => 200,
            'msg' => 'ok',
            'data' => [
                'uid' => $uid,
                'token' => $token,
            ],
        ], 'json', 200);
    }

    private function assertDevEnabled(): void
    {
        // 优先读本代码库约定标识 CHAMBER_ENV；兼容 APP_ENV；显式开关兜底。
        $env = strtolower((string) Env::get('CHAMBER_ENV', (string) Env::get('APP_ENV')));
        $devEnv = in_array($env, ['dev', 'development', 'local', 'test', 'testing'], true);
        $explicit = Env::get('CHAMBER_DEV_MOCK_PAY') === '1';
        // 兼容本仓库已有的本地开发开关标识。
        $localhostFlag = in_array((string) Env::get('CHAMBER_DEV_LOCALHOST_ENABLED'), ['1', 'true', 'on', 'yes'], true);
        if (!$devEnv && !$explicit && !$localhostFlag) {
            throw new MemberTransactionException(
                403,
                'dev_feature_disabled',
                'dev token mint is only allowed in development environment'
            );
        }
    }
}

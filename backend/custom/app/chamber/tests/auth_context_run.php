<?php

use app\Request;
use app\chamber\ChamberExceptionHandle;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\identity\BearerTokenExtractor;
use app\chamber\membership\GraduateVerificationState;
use app\chamber\membership\MemberContext;
use app\chamber\membership\MemberTier;
use app\chamber\middleware\CrmebAuthTokenMiddleware;
use app\services\user\UserAuthServices;
use crmeb\exceptions\AuthException;
use think\App;

$runtimeAutoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
$sourceAutoload = dirname(__DIR__, 4) . '/crmeb/crmeb/vendor/autoload.php';
$autoload = is_file($runtimeAutoload) ? $runtimeAutoload : $sourceAutoload;
if (!is_file($autoload)) {
    fwrite(STDERR, "CRMEB Composer autoloader was not found\n");
    exit(1);
}
require $autoload;

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
}, true, true);

if (!function_exists('getLang')) {
    function getLang($message, array $replace = [])
    {
        return (string) $message;
    }
}

final class FakeUserAuthServices extends UserAuthServices
{
    /** @var array|null */
    private $result;

    /** @var Throwable|null */
    private $exception;

    /** @var array */
    public $tokens = [];

    public function __construct(array $result = null, Throwable $exception = null)
    {
        $this->result = $result;
        $this->exception = $exception;
    }

    public function parseToken($token): array
    {
        $this->tokens[] = $token;
        if ($this->exception) {
            throw $this->exception;
        }

        return $this->result ?: [];
    }
}

$frameworkRoot = dirname(dirname($autoload));
$app = new App($frameworkRoot);
$tests = [];

$tests['canonical Authorization header yields its Bearer token'] = function (): void {
    assertSame('abc.def_123-XYZ', BearerTokenExtractor::fromHeaders('Bearer abc.def_123-XYZ', null));
};

$tests['legacy Authori-zation header is supported'] = function (): void {
    assertSame('legacy-token', BearerTokenExtractor::fromHeaders(null, 'Bearer legacy-token'));
};

$tests['matching canonical and legacy headers are unambiguous'] = function (): void {
    assertSame('same-token', BearerTokenExtractor::fromHeaders('Bearer same-token', 'Bearer same-token'));
};

$tests['missing Authorization headers are rejected'] = function (): void {
    expectException(InvalidArgumentException::class, function (): void {
        BearerTokenExtractor::fromHeaders(null, null);
    });
};

$tests['non Bearer and ltrim-style prefixes are rejected'] = function (): void {
    foreach (['Basic abc', 'BearerBearer abc', 'Bearer ', 'Token Bearer abc'] as $header) {
        expectException(InvalidArgumentException::class, function () use ($header): void {
            BearerTokenExtractor::fromHeaders($header, null);
        });
    }
};

$tests['Bearer syntax rejects altered casing and surrounding whitespace'] = function (): void {
    foreach (['bearer abc', ' Bearer abc', 'Bearer  abc', 'Bearer abc '] as $header) {
        expectException(InvalidArgumentException::class, function () use ($header): void {
            BearerTokenExtractor::fromHeaders($header, null);
        });
    }
};

$tests['conflicting Authorization headers are rejected'] = function (): void {
    expectException(InvalidArgumentException::class, function (): void {
        BearerTokenExtractor::fromHeaders('Bearer first', 'Bearer second');
    });
};

$tests['authenticated context retains identity facts without PII'] = function (): void {
    $context = AuthenticatedUserContext::fromAuthInfo(validAuthInfo());
    assertSame(42, $context->uid());
    assertSame(true, $context->phoneBound());
    assertSame('api', $context->tokenType());
    assertSame([
        'uid' => 42,
        'phone_bound' => true,
        'token_type' => 'api',
    ], $context->toArray());
    assertSame(false, array_key_exists('phone', $context->toArray()));
    assertSame(false, array_key_exists('token', $context->toArray()));
};

$tests['authenticated context accepts unbound phone without storing it'] = function (): void {
    $authInfo = validAuthInfo();
    $authInfo['user']['phone'] = '';
    assertSame(false, AuthenticatedUserContext::fromAuthInfo($authInfo)->phoneBound());
};

$tests['authenticated context supports CRMEB ArrayAccess user models'] = function (): void {
    $authInfo = validAuthInfo();
    $authInfo['user'] = new ArrayObject($authInfo['user']);
    assertSame(42, AuthenticatedUserContext::fromAuthInfo($authInfo)->uid());
};

$tests['authenticated context rejects mismatched trusted identities'] = function (): void {
    $authInfo = validAuthInfo();
    $authInfo['tokenData']['uid'] = 43;
    expectException(InvalidArgumentException::class, function () use ($authInfo): void {
        AuthenticatedUserContext::fromAuthInfo($authInfo);
    });
};

$tests['authenticated context rejects non-user and malformed token types'] = function (): void {
    foreach (['admin', 'kefu', 'out', 'api token'] as $tokenType) {
        $authInfo = validAuthInfo();
        $authInfo['tokenData']['type'] = $tokenType;
        expectException(InvalidArgumentException::class, function () use ($authInfo): void {
            AuthenticatedUserContext::fromAuthInfo($authInfo);
        });
    }
};

$tests['authentication middleware rejects non-user CRMEB tokens with HTTP 401'] = function () use ($app): void {
    $authInfo = validAuthInfo();
    $authInfo['tokenData']['type'] = 'admin';
    $response = (new CrmebAuthTokenMiddleware(new FakeUserAuthServices($authInfo), $app))->handle(
        requestWithHeaders(['Authorization' => 'Bearer wrong-audience-token']),
        function (): void {
            throw new RuntimeException('must not execute');
        }
    );
    assertAuthenticationRequired($response);
};

$tests['member transaction exception preserves stable fields'] = function (): void {
    $exception = new MemberTransactionException(409, 'consent_document_stale', 'Consent document is stale');
    assertSame(409, $exception->httpStatus());
    assertSame('consent_document_stale', $exception->reason());
    assertSame([], $exception->fieldErrors());
};

$tests['member transaction exception accepts strict field errors'] = function (): void {
    $errors = [['field' => 'consents[0].document_version', 'code' => 'stale_version']];
    assertSame($errors, (new MemberTransactionException(
        422,
        'request_validation_failed',
        'Request validation failed',
        $errors
    ))->fieldErrors());
};

$tests['member transaction exception rejects invalid status and reason'] = function (): void {
    expectException(InvalidArgumentException::class, function (): void {
        new MemberTransactionException(200, 'permission_denied', 'Denied');
    });
    expectException(InvalidArgumentException::class, function (): void {
        new MemberTransactionException(403, 'Permission-Denied', 'Denied');
    });
};

$tests['member transaction exception rejects non-list or expanded field errors'] = function (): void {
    expectException(InvalidArgumentException::class, function (): void {
        new MemberTransactionException(422, 'request_validation_failed', 'Invalid', [
            1 => ['field' => 'profile.name', 'code' => 'required'],
        ]);
    });
    expectException(InvalidArgumentException::class, function (): void {
        new MemberTransactionException(422, 'request_validation_failed', 'Invalid', [[
            'field' => 'profile.name',
            'code' => 'required',
            'message' => 'No',
        ]]);
    });
};

$tests['member context strictly maps database values and summaries'] = function (): void {
    $context = MemberContext::fromRow(memberRow());
    assertSame(7, $context->memberId());
    assertSame(3, $context->tenantId());
    assertSame(42, $context->uid());
    assertSame(MemberTier::L1, $context->tier());
    assertSame(MemberContext::STATUS_ACTIVE, $context->status());
    assertSame(GraduateVerificationState::DRAFT, $context->verificationStatus());
    assertSame([
        'id' => 7,
        'tier' => 'L1',
        'status' => 'active',
        'verification_status' => 'draft',
        'joined_at' => 1700000000,
        'tier_expires_at' => 0,
    ], $context->toMemberSummary());
};

$tests['member attribution and profile facts remain tenant scoped'] = function (): void {
    $row = memberRow();
    $row['referrer_uid'] = 18;
    $row['attribution_locked_time'] = 1700000001;
    $row['profile'] = profileRow();
    $context = MemberContext::fromRow($row);
    assertSame(true, $context->hasProfile());
    assertSame(true, $context->profileComplete());
    assertSame([
        'own_invite_code' => 'ABCDEFGH',
        'referrer_bound' => true,
        'locked_at' => 1700000001,
    ], $context->toAttributionSummary());
};

$tests['active draft member may edit and submit but not purchase'] = function (): void {
    assertSame([
        'can_edit_profile' => true,
        'can_submit_verification' => true,
        'can_purchase_membership' => false,
        'can_view_member_directory' => false,
    ], MemberContext::fromRow(memberRow())->capabilities());
};

$tests['active approved member receives verified capabilities'] = function (): void {
    $row = memberRow();
    $row['tier'] = 2;
    $row['verification_status'] = 2;
    $row['current_verification_id'] = 9;
    assertSame([
        'can_edit_profile' => true,
        'can_submit_verification' => false,
        'can_purchase_membership' => true,
        'can_view_member_directory' => true,
    ], MemberContext::fromRow($row)->capabilities());
};

$tests['disabled withdrawn and deleted members are never active'] = function (): void {
    foreach ([
        ['status' => 0, 'is_del' => 0],
        ['status' => 2, 'is_del' => 0],
        ['status' => 1, 'is_del' => 1],
    ] as $override) {
        $row = array_replace(memberRow(), $override);
        $context = MemberContext::fromRow($row);
        assertSame(false, $context->isActive());
        assertSame([
            'can_edit_profile' => false,
            'can_submit_verification' => false,
            'can_purchase_membership' => false,
            'can_view_member_directory' => false,
        ], $context->capabilities());
    }
};

$tests['member context rejects weakly typed and unknown database states'] = function (): void {
    foreach ([
        ['tier' => '1'],
        ['tier' => 5],
        ['status' => 3],
        ['verification_status' => 6],
        ['is_del' => 2],
    ] as $override) {
        expectException(InvalidArgumentException::class, function () use ($override): void {
            MemberContext::fromRow(array_replace(memberRow(), $override));
        });
    }
};

$tests['member context rejects a cross-tenant profile'] = function (): void {
    $row = memberRow();
    $row['profile'] = array_replace(profileRow(), ['tenant_id' => 4]);
    expectException(InvalidArgumentException::class, function () use ($row): void {
        MemberContext::fromRow($row);
    });
};

$tests['member attribution refuses an uninitialized invite code'] = function (): void {
    $row = memberRow();
    $row['invite_code'] = null;
    expectException(LogicException::class, function () use ($row): void {
        MemberContext::fromRow($row)->toAttributionSummary();
    });
};

$tests['authentication middleware binds context and CRMEB request compatibility'] = function () use ($app): void {
    $service = new FakeUserAuthServices(validAuthInfo());
    $middleware = new CrmebAuthTokenMiddleware($service, $app);
    $request = requestWithHeaders(['Authorization' => 'Bearer valid-token']);

    $result = $middleware->handle($request, function (Request $activeRequest) use ($app): array {
        $context = $app->make(AuthenticatedUserContext::class);
        assertSame($context, $app->make(AuthenticatedUserContext::CONTAINER_KEY));
        assertSame($context, $activeRequest->authenticatedUserContext);

        return [
            $activeRequest->uid(),
            $activeRequest->isLogin(),
            $activeRequest->user('nickname'),
            $activeRequest->tokenData()['type'],
            $context->phoneBound(),
        ];
    });

    assertSame([[42, true, 'Private Name', 'api', true], ['valid-token']], [$result, $service->tokens]);
};

$tests['authentication middleware finally clears request and container identity'] = function () use ($app): void {
    $middleware = new CrmebAuthTokenMiddleware(new FakeUserAuthServices(validAuthInfo()), $app);
    $request = requestWithHeaders(['Authorization' => 'Bearer valid-token']);
    $middleware->handle($request, function (): string {
        return 'ok';
    });

    assertSame(false, $app->exists(AuthenticatedUserContext::class));
    assertSame(false, $app->exists(AuthenticatedUserContext::CONTAINER_KEY));
    assertSame(false, isset($request->authenticatedUserContext));
    assertSame(0, $request->uid());
    assertSame(false, $request->isLogin());
    assertSame(null, $request->user());
    assertSame([], $request->tokenData());
};

$tests['authentication middleware finally clears identity when downstream throws'] = function () use ($app): void {
    $middleware = new CrmebAuthTokenMiddleware(new FakeUserAuthServices(validAuthInfo()), $app);
    $request = requestWithHeaders(['Authorization' => 'Bearer valid-token']);
    expectException(RuntimeException::class, function () use ($middleware, $request): void {
        $middleware->handle($request, function (): void {
            throw new RuntimeException('downstream failed');
        });
    });
    assertSame(false, $app->exists(AuthenticatedUserContext::class));
    assertSame(false, $app->exists(AuthenticatedUserContext::CONTAINER_KEY));
    assertSame(0, $request->uid());
};

$tests['missing authentication returns a real stable HTTP 401'] = function () use ($app): void {
    $service = new FakeUserAuthServices(validAuthInfo());
    $response = (new CrmebAuthTokenMiddleware($service, $app))->handle(
        requestWithHeaders([]),
        function (): void {
            throw new RuntimeException('must not execute');
        }
    );
    assertAuthenticationRequired($response);
    assertSame([], $service->tokens);
};

$tests['CRMEB AuthException returns the same stable HTTP 401'] = function () use ($app): void {
    $service = new FakeUserAuthServices(null, new AuthException('expired', [], 401));
    $response = (new CrmebAuthTokenMiddleware($service, $app))->handle(
        requestWithHeaders(['Authori-zation' => 'Bearer expired-token']),
        function (): void {
            throw new RuntimeException('must not execute');
        }
    );
    assertAuthenticationRequired($response);
};

$tests['invalid CRMEB authentication structure returns stable HTTP 401'] = function () use ($app): void {
    $service = new FakeUserAuthServices(['user' => ['uid' => 42]]);
    $response = (new CrmebAuthTokenMiddleware($service, $app))->handle(
        requestWithHeaders(['Authorization' => 'Bearer invalid-result']),
        function (): void {
            throw new RuntimeException('must not execute');
        }
    );
    assertAuthenticationRequired($response);
};

$tests['authentication infrastructure failures are not disguised as HTTP 401'] = function () use ($app): void {
    $failure = new RuntimeException('Redis unavailable');
    $service = new FakeUserAuthServices(null, $failure);
    try {
        (new CrmebAuthTokenMiddleware($service, $app))->handle(
            requestWithHeaders(['Authorization' => 'Bearer valid-token']),
            function (): void {
            }
        );
    } catch (Throwable $exception) {
        assertSame($failure, $exception);
        return;
    }

    throw new RuntimeException('Expected infrastructure failure to propagate');
};

$tests['chamber exception handler renders stable transaction envelopes'] = function () use ($app): void {
    $request = requestWithHeaders([]);
    $response = (new ChamberExceptionHandle($app))->render($request, new MemberTransactionException(
        403,
        'member_disabled',
        'Member is disabled'
    ));
    assertSame(403, $response->getCode());
    $data = $response->getData();
    assertSame(403, $data['status']);
    assertSame('Member is disabled', $data['msg']);
    assertSame(['reason' => 'member_disabled', 'field_errors' => []], $data['data']);
    assertSame($data['request_id'], $response->getHeader('X-Request-Id'));
};

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, "PASS {$name}\n");
    } catch (Throwable $exception) {
        $failures++;
        fwrite(STDERR, "FAIL {$name}: " . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    }
}

fwrite(STDOUT, sprintf("%d tests, %d failures\n", count($tests), $failures));
exit($failures === 0 ? 0 : 1);

function validAuthInfo(): array
{
    return [
        'user' => [
            'uid' => 42,
            'phone' => '13800138000',
            'nickname' => 'Private Name',
        ],
        'tokenData' => [
            'uid' => 42,
            'type' => 'api',
            'token' => 'must-not-enter-context',
        ],
    ];
}

function memberRow(): array
{
    return [
        'id' => 7,
        'tenant_id' => 3,
        'uid' => 42,
        'first_channel_id' => 5,
        'current_channel_id' => 5,
        'referrer_uid' => 0,
        'invite_code' => 'ABCDEFGH',
        'attribution_locked_time' => 1700000000,
        'tier' => 1,
        'status' => 1,
        'verification_status' => 0,
        'current_verification_id' => 0,
        'join_time' => 1700000000,
        'tier_expire_time' => 0,
        'current_membership_term_id' => 0,
        'membership_version' => 0,
        'is_del' => 0,
    ];
}

function profileRow(): array
{
    return [
        'id' => 11,
        'tenant_id' => 3,
        'member_id' => 7,
        'uid' => 42,
        'profile_status' => 1,
        'is_del' => 0,
        'real_name' => 'Private Name',
    ];
}

function requestWithHeaders(array $headers): Request
{
    return (new Request())->withHeader($headers);
}

function assertAuthenticationRequired($response): void
{
    assertSame(401, $response->getCode());
    $data = $response->getData();
    assertSame(401, $data['status']);
    assertSame('Authentication required', $data['msg']);
    assertSame([
        'reason' => 'authentication_required',
        'field_errors' => [],
    ], $data['data']);
    assertSame($data['request_id'], $response->getHeader('X-Request-Id'));
    assertSame(true, is_string($response->getHeader('X-Correlation-Id')));
}

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

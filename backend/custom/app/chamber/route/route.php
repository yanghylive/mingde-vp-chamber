<?php

use app\chamber\middleware\ChamberCorsMiddleware;
use app\chamber\middleware\CrmebAdminAuthTokenMiddleware;
use app\chamber\middleware\CrmebAuthTokenMiddleware;
use app\chamber\middleware\RequestTraceMiddleware;
use app\chamber\middleware\TenantContextMiddleware;
use think\facade\Route;
use think\Response;

$preflight = function () {
    return Response::create('ok')->code(200);
};

Route::options('health', $preflight)
    ->middleware(RequestTraceMiddleware::class)
    ->middleware(ChamberCorsMiddleware::class);

Route::options('v1/bootstrap', $preflight)
    ->middleware(RequestTraceMiddleware::class)
    ->middleware(ChamberCorsMiddleware::class);

Route::options('v1/me/bootstrap', $preflight)
    ->middleware(RequestTraceMiddleware::class)
    ->middleware(ChamberCorsMiddleware::class);

foreach ([
    'v1/me/profile',
    'v1/me/assets',
    'v1/me/assets/:asset_id/content',
    'v1/me/graduate-verifications',
    'v1/me/membership',
    'v1/membership/plans',
    'v1/membership/checkouts',
    'admin/v1/member-assets/:asset_id/content',
    'admin/v1/graduate-verifications',
    'admin/v1/graduate-verifications/:application_id',
    'admin/v1/graduate-verifications/:application_id/reviews',
] as $route) {
    Route::options($route, $preflight)
        ->middleware(RequestTraceMiddleware::class)
        ->middleware(ChamberCorsMiddleware::class);
}

Route::get('health', 'HealthController/index')
    ->middleware(RequestTraceMiddleware::class)
    ->middleware(ChamberCorsMiddleware::class);

Route::group('v1', function () {
    Route::get('bootstrap', 'BootstrapController/index');
})->middleware(RequestTraceMiddleware::class)
    ->middleware(ChamberCorsMiddleware::class)
    ->middleware(TenantContextMiddleware::class, true);

Route::post('v1/me/bootstrap', 'MemberBootstrapController/store')
    ->middleware(RequestTraceMiddleware::class)
    ->middleware(ChamberCorsMiddleware::class)
    ->middleware(CrmebAuthTokenMiddleware::class)
    ->middleware(TenantContextMiddleware::class, true);

Route::group('v1/me', function () {
    Route::get('profile', 'MemberProfileController/show');
    Route::patch('profile', 'MemberProfileController/update');
    Route::post('assets', 'MemberAssetController/store');
    Route::get('assets/:asset_id/content', 'MemberAssetController/content')
        ->pattern(['asset_id' => '\\d+']);
    Route::get('graduate-verifications', 'GraduateVerificationController/show');
    Route::post('graduate-verifications', 'GraduateVerificationController/store');
    Route::get('membership', 'MembershipSummaryController/show');
})->middleware(RequestTraceMiddleware::class)
    ->middleware(ChamberCorsMiddleware::class)
    ->middleware(CrmebAuthTokenMiddleware::class)
    ->middleware(TenantContextMiddleware::class, true);

Route::group('v1/membership', function () {
    Route::get('plans', 'MembershipPlanController/index');
    Route::post('checkouts', 'MembershipCheckoutController/store');
})->middleware(RequestTraceMiddleware::class)
    ->middleware(ChamberCorsMiddleware::class)
    ->middleware(CrmebAuthTokenMiddleware::class)
    ->middleware(TenantContextMiddleware::class, true);

Route::group('admin/v1', function () {
    Route::get('member-assets/:asset_id/content', 'MemberAssetAdminController/content')
        ->pattern(['asset_id' => '\\d+']);
    Route::get('graduate-verifications', 'GraduateVerificationAdminController/index');
    Route::get('graduate-verifications/:application_id', 'GraduateVerificationAdminController/show')
        ->pattern(['application_id' => '\\d+']);
    Route::post(
        'graduate-verifications/:application_id/reviews',
        'GraduateVerificationReviewController/store'
    )->pattern(['application_id' => '\\d+']);
})->middleware(RequestTraceMiddleware::class)
    ->middleware(ChamberCorsMiddleware::class)
    ->middleware(CrmebAdminAuthTokenMiddleware::class)
    ->middleware(TenantContextMiddleware::class, true);

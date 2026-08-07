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
    'v1/me/event-registrations',
    'v1/me/event-registrations/:registration_id',
    'v1/membership/plans',
    'v1/membership/checkouts',
    'v1/events',
    'v1/events/:event_id',
    'v1/events/:event_id/registrations',
    'v1/events/:event_id/checkins',
    'admin/v1/member-assets/:asset_id/content',
    'admin/v1/graduate-verifications',
    'admin/v1/graduate-verifications/:application_id',
    'admin/v1/graduate-verifications/:application_id/reviews',
    'admin/v1/events',
    'admin/v1/events/:event_id',
    'admin/v1/events/:event_id/publish',
    'admin/v1/events/:event_id/cancel',
    'admin/v1/events/:event_id/checkin-token',
    'admin/v1/events/:event_id/checkins/manual',
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
    Route::get('event-registrations', 'EventRegistrationController/index');
    Route::get('event-registrations/:registration_id', 'EventRegistrationController/show')
        ->pattern(['registration_id' => '\\d+']);
})->middleware(RequestTraceMiddleware::class)
    ->middleware(ChamberCorsMiddleware::class)
    ->middleware(CrmebAuthTokenMiddleware::class)
    ->middleware(TenantContextMiddleware::class, true);

// 小薇认知教练（每位会员隔离实例，共享 DISCERN 文化）
Route::group('v1/coaching', function () {
    Route::get('today', 'CoachingController/today');
    Route::post('morning', 'CoachingController/morning');
    Route::post('respond', 'CoachingController/respond');
    Route::post('evening', 'CoachingController/evening');
    Route::get('status', 'CoachingController/status');
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

Route::group('v1/events', function () {
    Route::get('', 'EventController/index');
    Route::get(':event_id', 'EventController/show')
        ->pattern(['event_id' => '\\d+']);
    Route::post(':event_id/registrations', 'EventRegistrationController/store')
        ->pattern(['event_id' => '\\d+']);
    Route::post(':event_id/checkins', 'EventCheckinController/store')
        ->pattern(['event_id' => '\\d+']);
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
    Route::get('events', 'EventAdminController/index');
    Route::post('events', 'EventAdminController/store');
    Route::get('events/:event_id', 'EventAdminController/show')
        ->pattern(['event_id' => '\\d+']);
    Route::patch('events/:event_id', 'EventAdminController/update')
        ->pattern(['event_id' => '\\d+']);
    Route::post('events/:event_id/publish', 'EventAdminController/publish')
        ->pattern(['event_id' => '\\d+']);
    Route::post('events/:event_id/cancel', 'EventAdminController/cancel')
        ->pattern(['event_id' => '\\d+']);
    Route::post('events/:event_id/checkin-token', 'EventAdminController/checkinToken')
        ->pattern(['event_id' => '\\d+']);
    Route::post('events/:event_id/checkins/manual', 'EventAdminController/manualCheckin')
        ->pattern(['event_id' => '\\d+']);
})->middleware(RequestTraceMiddleware::class)
    ->middleware(ChamberCorsMiddleware::class)
    ->middleware(CrmebAdminAuthTokenMiddleware::class)
    ->middleware(TenantContextMiddleware::class, true);

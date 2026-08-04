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
    'v1/me/event-registrations/:registration_id/refunds',
    'v1/membership/plans',
    'v1/membership/checkouts',
    'v1/events',
    'v1/events/:event_id',
    'v1/events/:event_id/registrations',
    'v1/events/:event_id/checkins',
    'v1/me/friends',
    'v1/me/friends/:friend_id/accept',
    'v1/me/distribution',
    'v1/me/points',
    'v1/me/points/ledger',
    'v1/points/paths',
    'v1/site-config',
    'admin/v1/site-config',
    'v1/me/stats',
    'v1/me/orders',
    'v1/me/notifications',
    'v1/products',
    'v1/products/:product_id/exchange',
    'v1/experts/:expert_id',
    'v1/experts/:expert_id/slots',
    'v1/experts/:expert_id/appointments',
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
    'admin/v1/experts',
    'admin/v1/experts/profile',
    'admin/v1/experts/:expert_id/profile',
    'admin/v1/notifications',
    'admin/v1/notifications/:notification_id',
    'admin/v1/experts/:expert_id/pricing',
    'admin/v1/members',
    'admin/v1/members/:member_id',
    'admin/v1/members/orders',
    'admin/v1/points-paths',
    'admin/v1/slots',
    'admin/v1/slots/:slot_id',
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
    Route::get('experts', 'ExpertsController/index');
    Route::get('products', 'ProductsController/index');
    Route::get('points/paths', 'PointsPathsController/index');
    Route::get('site-config', 'SiteConfigController/index');
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
    Route::post('event-registrations/:registration_id/refunds', 'EventRegistrationController/refund')
        ->pattern(['registration_id' => '\\d+']);
    Route::get('friends', 'MemberFriendController/index');
    Route::post('friends/:friend_id/accept', 'MemberFriendController/accept')
        ->pattern(['friend_id' => '\\d+']);
    Route::get('distribution', 'MemberDistributionController/show');
    Route::get('points', 'MemberPointsController/show');
    Route::get('points/ledger', 'MemberPointsController/ledger');
    Route::get('stats', 'MemberStatsController/show');
    Route::get('orders', 'ProductExchangeController/orders');
    Route::get('notifications', 'NotificationController/index');
})->middleware(RequestTraceMiddleware::class)
    ->middleware(ChamberCorsMiddleware::class)
    ->middleware(CrmebAuthTokenMiddleware::class)
    ->middleware(TenantContextMiddleware::class, true);

Route::group('v1/products', function () {
    Route::post(':product_id/exchange', 'ProductExchangeController/exchange')
        ->pattern(['product_id' => '\\d+']);
})->middleware(RequestTraceMiddleware::class)
    ->middleware(ChamberCorsMiddleware::class)
    ->middleware(CrmebAuthTokenMiddleware::class)
    ->middleware(TenantContextMiddleware::class, true);

Route::group('v1/experts', function () {
    Route::get(':expert_id', 'ExpertScheduleController/show')
        ->pattern(['expert_id' => '\\d+']);
    Route::get(':expert_id/slots', 'ExpertScheduleController/slots')
        ->pattern(['expert_id' => '\\d+']);
    Route::post(':expert_id/appointments', 'ExpertScheduleController/appointments')
        ->pattern(['expert_id' => '\\d+']);
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
        ->pattern(['event_id' => '\d+']);
    Route::get('experts', 'ExpertPricingAdminController/index');
    Route::get('experts/profile', 'ExpertProfileAdminController/index');
    Route::patch('experts/:expert_id/profile', 'ExpertProfileAdminController/update');
    Route::get('notifications', 'NotificationAdminController/index');
    Route::post('notifications', 'NotificationAdminController/store');
    Route::patch('notifications/:notification_id', 'NotificationAdminController/update');
    Route::delete('notifications/:notification_id', 'NotificationAdminController/delete');
    Route::get('members', 'MemberAdminController/index');
    Route::get('members/orders', 'MemberAdminController/orders');
    Route::patch('members/:member_id', 'MemberAdminController/update')
        ->pattern(['member_id' => '\d+']);
    Route::get('points-paths', 'PointsPathsAdminController/index');
    Route::put('points-paths', 'PointsPathsAdminController/update');
    Route::get('slots', 'SlotAdminController/index');
    Route::post('slots', 'SlotAdminController/store');
    Route::delete('slots/:slot_id', 'SlotAdminController/delete')
        ->pattern(['slot_id' => '\d+']);
    Route::get('site-config', 'SiteConfigAdminController/index');
    Route::put('site-config', 'SiteConfigAdminController/update');
    Route::get('experts/:expert_id/pricing', 'ExpertPricingAdminController/show')
        ->pattern(['expert_id' => '\d+']);
    Route::patch('experts/:expert_id/pricing', 'ExpertPricingAdminController/update')
        ->pattern(['expert_id' => '\d+']);
})->middleware(RequestTraceMiddleware::class)
    ->middleware(ChamberCorsMiddleware::class)
    ->middleware(CrmebAdminAuthTokenMiddleware::class)
    ->middleware(TenantContextMiddleware::class, true);

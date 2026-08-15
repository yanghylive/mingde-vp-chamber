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

Route::options('v1/site-config', $preflight)
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
    'v1/me/stats',
    'v1/me/orders',
    'v1/me/notifications',
    'v1/points/paths',
    'v1/products/:product_id/exchange',
    'v1/products',
    'v1/experts',
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
    'admin/v1/members',
    'admin/v1/members/:member_id',
    'admin/v1/members/orders',
    'admin/v1/members/:member_id/points/adjust',
    'admin/v1/site-config',
    'admin/v1/slots',
    'admin/v1/slots/:slot_id',
    'admin/v1/points-paths',
    'admin/v1/experts',
    'admin/v1/experts/profile',
    'admin/v1/experts/:expert_id/profile',
    'admin/v1/experts/:expert_id/pricing',
    'admin/v1/ai-twins',
    'admin/v1/ai-twins/:member_id',
    'admin/v1/ai-twins/:member_id/memories',
    'admin/v1/ai-twins/:member_id/memories/:memory_id',
    'admin/v1/ai-twins/:member_id/chats',
    'admin/v1/ai-twins/:member_id/knowledge',
    'admin/v1/ai-twins/:member_id/knowledge/:knowledge_id',
    'v1/ai-twin/me',
    'v1/ai-twin/train',
    'v1/ai-twin/train/history',
    'v1/ai-twin/:expert_member_id/profile',
    'v1/ai-twin/:expert_member_id/chat',
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
    Route::get('site-config', 'SiteConfigController/index');
    Route::post('client/errors', 'ClientErrorController/store');
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
    Route::post('points/consume', 'MemberPointsController/consume');
    Route::get('stats', 'MemberStatsController/show');
    Route::get('orders', 'ProductExchangeController/orders');
    Route::get('notifications', 'NotificationController/index');
})->middleware(RequestTraceMiddleware::class)
    ->middleware(ChamberCorsMiddleware::class)
    ->middleware(CrmebAuthTokenMiddleware::class)
    ->middleware(TenantContextMiddleware::class, true);

Route::group('v1/ai-twin', function () {
    Route::get('me', 'AiTwinController/me');
    Route::post('train', 'AiTwinController/train');
    Route::get('train/history', 'AiTwinController/trainHistory');
    Route::get(':expert_member_id/profile', 'AiTwinController/profile')
        ->pattern(['expert_member_id' => '\\d+']);
    Route::post(':expert_member_id/chat', 'AiTwinController/chat')
        ->pattern(['expert_member_id' => '\\d+']);
})->middleware(RequestTraceMiddleware::class)
    ->middleware(ChamberCorsMiddleware::class)
    ->middleware(CrmebAuthTokenMiddleware::class)
    ->middleware(TenantContextMiddleware::class, true);

Route::group('v1/products', function () {
    Route::get('/', 'ProductController/index');
    Route::post(':product_id/exchange', 'ProductExchangeController/exchange')
        ->pattern(['product_id' => '\\d+']);
})->middleware(RequestTraceMiddleware::class)
    ->middleware(ChamberCorsMiddleware::class)
    ->middleware(CrmebAuthTokenMiddleware::class)
    ->middleware(TenantContextMiddleware::class, true);

Route::group('v1/points', function () {
    Route::get('paths', 'PointsPathsController/index');
})->middleware(RequestTraceMiddleware::class)
    ->middleware(ChamberCorsMiddleware::class)
    ->middleware(CrmebAuthTokenMiddleware::class)
    ->middleware(TenantContextMiddleware::class, true);

Route::group('v1/experts', function () {
    Route::get('/', 'ExpertController/index');
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
    Route::get('members', 'MemberAdminController/index');
    Route::patch('members/:member_id', 'MemberAdminController/update')
        ->pattern(['member_id' => '\\d+']);
    Route::get('members/orders', 'MemberAdminController/orders');
    Route::post('members/:member_id/points/adjust', 'MemberAdminController/adjustPoints')
        ->pattern(['member_id' => '\\d+']);
    // ---- 站点配置 / 大咖档期 / 积分获取路径 / 大咖库（P2） ----
    Route::get('site-config', 'SiteConfigAdminController/index');
    Route::put('site-config', 'SiteConfigAdminController/update');
    Route::get('slots', 'SlotAdminController/index');
    Route::post('slots', 'SlotAdminController/store');
    Route::delete('slots/:slot_id', 'SlotAdminController/delete')
        ->pattern(['slot_id' => '\\d+']);
    Route::get('points-paths', 'PointsPathsAdminController/index');
    Route::put('points-paths', 'PointsPathsAdminController/update');
    // 大咖库占位：experts/profile 必须先于 :expert_id 路由注册（避免被参数路由吞掉）
    Route::get('experts/profile', 'ExpertController/profile');
    Route::get('experts', 'ExpertController/index');
    Route::patch('experts/:expert_id/profile', 'ExpertController/updateProfile')
        ->pattern(['expert_id' => '\\d+']);
    Route::get('experts/:expert_id/pricing', 'ExpertController/showPricing')
        ->pattern(['expert_id' => '\\d+']);
    Route::patch('experts/:expert_id/pricing', 'ExpertController/updatePricing')
        ->pattern(['expert_id' => '\\d+']);
    // ---- AI 智能分身训练板块 ----
    Route::get('ai-twins', 'AiTwinAdminController/index');
    Route::get('ai-twins/:member_id', 'AiTwinAdminController/show')
        ->pattern(['member_id' => '\\d+']);
    Route::put('ai-twins/:member_id', 'AiTwinAdminController/update')
        ->pattern(['member_id' => '\\d+']);
    Route::get('ai-twins/:member_id/memories', 'AiTwinAdminController/memories')
        ->pattern(['member_id' => '\\d+']);
    Route::delete('ai-twins/:member_id/memories/:memory_id', 'AiTwinAdminController/deleteMemory')
        ->pattern(['member_id' => '\\d+', 'memory_id' => '\\d+']);
    Route::get('ai-twins/:member_id/chats', 'AiTwinAdminController/chats')
        ->pattern(['member_id' => '\\d+']);
    Route::get('ai-twins/:member_id/knowledge', 'AiTwinAdminController/knowledge')
        ->pattern(['member_id' => '\\d+']);
    Route::post('ai-twins/:member_id/knowledge', 'AiTwinAdminController/addKnowledge')
        ->pattern(['member_id' => '\\d+']);
    Route::post('ai-twins/:member_id/knowledge/upload', 'AiTwinAdminController/uploadKnowledge')
        ->pattern(['member_id' => '\\d+']);
    Route::delete('ai-twins/:member_id/knowledge/:knowledge_id', 'AiTwinAdminController/deleteKnowledge')
        ->pattern(['member_id' => '\\d+', 'knowledge_id' => '\\d+']);
})->middleware(RequestTraceMiddleware::class)
    ->middleware(ChamberCorsMiddleware::class)
    ->middleware(CrmebAdminAuthTokenMiddleware::class)
    ->middleware(TenantContextMiddleware::class, true);

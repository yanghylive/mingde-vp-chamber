'use strict';

var assert = require('assert');
var fs = require('fs');
var path = require('path');
var activityUi = require('../chamber/activity-ui');

function test(name, callback) {
  try {
    callback();
    process.stdout.write('PASS ' + name + '\n');
  } catch (error) {
    process.stderr.write('FAIL ' + name + '\n');
    throw error;
  }
}

test('normalizes the frozen activity list envelope', function () {
  var result = activityUi.normalizeEventList({
    data: {
      items: [
        {
          id: 7,
          event_type: 'growth',
          title: 'AI 产业工作坊',
          status: 1,
          tickets: [{ id: 9, name: '会员票', price: '20.00', integral_price: 30, remaining: 2, eligible: true }],
        },
      ],
      page: { page: 1, limit: 10, total: 11, total_pages: 2, has_more: true },
    },
  });
  assert.strictEqual(result.items[0].title, 'AI 产业工作坊');
  assert.strictEqual(result.items[0].tickets[0].price, '20.00');
  assert.deepStrictEqual(result.page, { page: 1, limit: 10, total: 11, total_pages: 2, has_more: true });
});

test('builds an exact registration price snapshot', function () {
  assert.deepStrictEqual(
    activityUi.createRegistrationPayload({ id: 9, price: '20.00', integral_price: 30, eligible: true }),
    { ticket_id: 9, expected_amount: '20.00', expected_integral: 30 },
  );
});

test('keeps ineligible and existing registrations disabled', function () {
  assert.strictEqual(
    activityUi.ticketActionState(
      { id: 9, eligible: false, ineligible_reason: 'points_required' },
      { id: 7, registered: false },
    ).reason,
    '积分不足',
  );
  assert.strictEqual(
    activityUi.ticketActionState({ id: 9, eligible: true }, { id: 7, registered: true, registration_status: 'registered' })
      .enabled,
    false,
  );
  ['cancelled', 'refunded'].forEach(function (status) {
    assert.strictEqual(
      activityUi.ticketActionState({ id: 9, eligible: true }, { id: 7, registered: false, registration_status: status }).enabled,
      false,
    );
  });
});

test('surfaces refund progress ahead of the base registration status', function () {
  assert.deepStrictEqual(activityUi.registrationStatusMeta('registered', 'refund_pending'), {
    label: '退款处理中',
    tone: 'warning',
  });
  assert.deepStrictEqual(activityUi.registrationStatusMeta('registered', 'partially_refunded'), {
    label: '已部分退款',
    tone: 'warning',
  });
});

test('routes only registrations with a real CRMEB order number to cashier', function () {
  assert.strictEqual(activityUi.paymentPath('ch-order/100'), '/pages/goods/cashier/index?order_id=ch-order%2F100&from_type=order');
  assert.strictEqual(activityUi.paymentPath(''), '');
});

test('extracts signed check-in tokens without accepting short QR content', function () {
  var token = 'abcdefghijklmnopqrstuvwx';
  assert.strictEqual(activityUi.extractCheckinToken('https://example.test/checkin?token=' + token), token);
  assert.strictEqual(activityUi.extractCheckinToken('short'), '');
});

test('never exposes a refund command before its real API exists', function () {
  var availability = activityUi.refundAvailability(
    { id: 3, status: 'registered' },
    { mode: 'full_before_deadline', deadline_time: 2000 },
    1000,
  );
  assert.strictEqual(availability.policy_eligible, true);
  assert.strictEqual(availability.available, false);
  assert.match(availability.reason, /尚未开放/);
});

test('keeps chamber-pages empty (legacy pages retired) and new event UI wired', function () {
  var pages = JSON.parse(fs.readFileSync(path.join(__dirname, '../chamber-pages.json'), 'utf8'));
  assert.strictEqual(pages.length, 0);

  var events = fs.readFileSync(path.join(__dirname, '../pages/events/index.vue'), 'utf8');
  assert.match(events, /joined/);
  assert.match(events, /toggle\(/);
  assert.match(events, /navigateTo\(\{ url: '\/pages\/events\/detail\/index/);
});

test('uses only implemented user activity endpoints', function () {
  var api = fs.readFileSync(path.join(__dirname, '../api/chamber/event.js'), 'utf8');
  assert.match(api, /\/chamber\/v1\/events/);
  assert.match(api, /\/chamber\/v1\/me\/event-registrations/);
  assert.doesNotMatch(api, /refunds|cancel-registration/);
});

test('keeps the shared activity-ui module wired to the new event pages', function () {
  var activityUiSource = fs.readFileSync(path.join(__dirname, '../chamber/activity-ui.js'), 'utf8');
  var membership = fs.readFileSync(path.join(__dirname, '../pages/membership/index.vue'), 'utf8');
  var detail = fs.readFileSync(path.join(__dirname, '../pages/events/detail/index.vue'), 'utf8');
  var checkin = fs.readFileSync(path.join(__dirname, '../pages/events/checkin/index.vue'), 'utf8');
  assert.match(activityUiSource, /expected_amount/);
  // 会籍购买已改微信直连支付（APIv3，不再跳 CRMEB 收银台）——验证新支付链路接线
  assert.match(membership, /wechatPayOrder|requestWechatPayment/);
  assert.match(detail, /onRegister\(/);
  assert.match(checkin, /checkin|scanQRCode/);
});

test('falls back to address copy when navigation coordinates are missing', function () {
  var detail = fs.readFileSync(path.join(__dirname, '../pages/events/detail/index.vue'), 'utf8');
  assert.match(detail, /if \(lat && lng\)/);
  assert.match(detail, /暂未配置导航坐标，可复制地址自行搜索/);
  assert.match(detail, /setClipboardData/);
});


'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const activityAdmin = require('../src/chamber/activity-admin');

const root = path.resolve(__dirname, '..');
function test(name, callback) {
  try {
    callback();
    process.stdout.write(`PASS ${name}\n`);
  } catch (error) {
    process.stderr.write(`FAIL ${name}: ${error.message}\n`);
    process.exitCode = 1;
  }
}

test('default form has one enabled free ticket', () => {
  const form = activityAdmin.createForm();
  assert.strictEqual(form.event_type, 'growth');
  assert.strictEqual(form.tickets.length, 1);
  assert.strictEqual(form.tickets[0].price, '0.00');
  assert.strictEqual(form.tickets[0].status, 1);
});

test('form serialization emits OpenAPI-safe values', () => {
  const form = activityAdmin.createForm();
  form.title = ' AI 校友行业会 ';
  form.tags_text = 'AI, 校友，AI';
  form.start_time = new Date('2026-08-01T10:00:00Z');
  form.end_time = new Date('2026-08-01T12:00:00Z');
  form.signup_start_time = new Date('2026-07-28T10:00:00Z');
  form.signup_end_time = new Date('2026-07-31T10:00:00Z');
  form.eligibility.allowed_channel_ids = '2, 4, 2';
  form.eligibility.required_roles = 'graduate, committee';
  form.tickets[0].price = '19.9';
  form.tickets[0].sale_start_time = form.signup_start_time;
  form.tickets[0].sale_end_time = form.signup_end_time;

  const payload = activityAdmin.serializeForm(form);
  assert.strictEqual(payload.title, 'AI 校友行业会');
  assert.deepStrictEqual(payload.tags, ['AI', '校友']);
  assert.deepStrictEqual(payload.eligibility.allowed_channel_ids, [2, 4]);
  assert.deepStrictEqual(payload.eligibility.required_roles, ['graduate', 'committee']);
  assert.strictEqual(payload.tickets[0].price, '19.90');
  assert.strictEqual(payload.start_time, 1785578400);
});

test('validation rejects crossed times and incomplete ticket sales', () => {
  const form = activityAdmin.createForm();
  form.title = '错误时间活动';
  form.start_time = new Date('2026-08-01T12:00:00Z');
  form.end_time = new Date('2026-08-01T10:00:00Z');
  form.signup_start_time = new Date('2026-07-30T10:00:00Z');
  form.signup_end_time = new Date('2026-07-29T10:00:00Z');
  const errors = activityAdmin.validateForm(form);
  assert(errors.some((item) => item.includes('活动结束时间')));
  assert(errors.some((item) => item.includes('报名截止时间')));
  assert(errors.some((item) => item.includes('票档销售时间')));
});

test('event detail round trips into an editable form', () => {
  const form = activityAdmin.eventToForm({
    id: 42,
    event_no: 'EVT-42',
    event_type: 'industry',
    title: '行业会',
    status: 0,
    start_time: 1785578400,
    end_time: 1785585600,
    signup_start_time: 1785232800,
    signup_end_time: 1785492000,
    location: { name: '会议中心', address: '青年大街', longitude: '123.000000', latitude: '41.000000' },
    eligibility: { allowed_channel_ids: [1], min_points: 20, required_roles: ['graduate'] },
    refund_policy: { mode: 'none', deadline_time: 0, percent: 0, description: '' },
    tickets: [{
      name: '普通票', price: '20.00', integral_price: 0, capacity: 30, min_tier: 1,
      sale_start_time: 1785232800, sale_end_time: 1785492000, status: 1, sort: 0,
      eligibility: { allowed_channel_ids: [], min_points: 0, required_roles: [] },
      refund_policy: { mode: 'none', deadline_time: 0, percent: 0, description: '' },
    }],
  });
  assert.strictEqual(form.id, 42);
  assert.strictEqual(form.location_name, '会议中心');
  assert(form.start_time instanceof Date);
  assert.strictEqual(activityAdmin.serializeForm(form).tickets[0].price, '20.00');
});

test('action matrix prevents illegal lifecycle operations', () => {
  assert(activityAdmin.can('edit', { status: 0 }));
  assert(activityAdmin.can('publish', { status: 'draft' }));
  assert(!activityAdmin.can('edit', { status: 1 }));
  assert(activityAdmin.can('cancel', { status: 'published' }));
  assert(activityAdmin.can('checkin', { status: 3 }));
  assert(!activityAdmin.can('checkin', { status: 4 }));
});

test('API module uses the tenant-scoped admin read and write operations', () => {
  const api = fs.readFileSync(path.join(root, 'src/api/chamber/events.js'), 'utf8');
  const expectedFragments = [
    "const ADMIN_PATH = '/chamber/admin/v1/events'",
    "url: ADMIN_PATH, method: 'get', params",
    "url: `${ADMIN_PATH}/${eventId}`, method: 'get'",
    "method: 'patch'",
    '`${ADMIN_PATH}/${eventId}/publish`',
    '`${ADMIN_PATH}/${eventId}/cancel`',
    '`${ADMIN_PATH}/${eventId}/checkin-token`',
    '`${ADMIN_PATH}/${eventId}/checkins/manual`',
    "{ 'Idempotency-Key': key }",
  ];
  expectedFragments.forEach((fragment) => assert(api.includes(fragment), `missing API contract: ${fragment}`));
  assert(!api.includes('/chamber/v1/events'));
  assert(!api.includes('/registrations/search'));
});

test('workbench exposes creation lifecycle and both check-in modes', () => {
  const page = fs.readFileSync(path.join(root, 'src/pages/chamber/events/index.vue'), 'utf8');
  ['openCreate', 'openDetail', 'saveDraft', 'confirmPublish', 'submitCancel', 'issueToken', 'submitManualCheckin'].forEach((method) => {
    assert(page.includes(method), `missing workbench operation: ${method}`);
  });
  assert(!page.includes('chamber.admin.event-drafts'));
  assert(!page.includes('_localDraft'));
  assert(page.includes('return this.loadList()'));
  assert(page.includes('writeOperationsEnabled: false'));
  assert(page.includes('v-if="writeOperationsEnabled"'));
  assert(page.includes("v-if=\"writeOperationsEnabled && can('checkin', scope.row)\""));
  assert(page.includes("navigator.clipboard"));
  assert(page.includes("import QRCode from 'qrcodejs2'"));
  assert(page.includes('renderCheckinQr'));
});

test('router protects the activity workbench with the manage permission', () => {
  const router = fs.readFileSync(path.join(root, 'src/router/modules/chamber.js'), 'utf8');
  assert(router.includes("path: 'events'"));
  assert(router.includes("name: 'chamber_events'"));
  assert(router.includes("auth: ['chamber.event.manage']"));
  assert(router.includes("import('@/pages/chamber/events/index')"));
});

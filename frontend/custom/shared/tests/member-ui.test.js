'use strict';

var assert = require('assert');
var fs = require('fs');
var path = require('path');
var memberUi = require('../member-ui');
var userCenterOverlay = require('../../uniapp/overlays/apply-user-center-entry');

function test(name, callback) {
  try {
    callback();
    process.stdout.write('PASS ' + name + '\n');
  } catch (error) {
    process.stderr.write('FAIL ' + name + '\n');
    throw error;
  }
}

test('normalizes list fields without duplicates', function () {
  assert.deepStrictEqual(memberUi.normalizeList('供应链\n法务，供应链;融资'), ['供应链', '法务', '融资']);
});

test('accepts private relative object keys only', function () {
  assert.strictEqual(memberUi.isValidObjectKey('verification/2026/proof_01.png'), true);
  assert.strictEqual(memberUi.isValidObjectKey('https://cdn.example/proof.png'), false);
  assert.strictEqual(memberUi.isValidObjectKey('/verification/proof.png'), false);
  assert.strictEqual(memberUi.isValidObjectKey('verification/../proof.png'), false);
  assert.strictEqual(memberUi.isValidObjectKey('verification//proof.png'), false);
});

test('accepts only JPEG and PNG candidates for new proof uploads', function () {
  assert.strictEqual(
    memberUi.validateProofUploadCandidate({ path: 'wxfile://tmp/proof.JPG', type: 'image/jpeg' }).valid,
    true,
  );
  assert.strictEqual(
    memberUi.validateProofUploadCandidate({ tempFilePath: '/tmp/proof.png', name: 'proof.png' }).value.mime_type,
    'image/png',
  );
  assert.strictEqual(
    memberUi.validateProofUploadCandidate({ path: '/tmp/proof.pdf', type: 'application/pdf' }).valid,
    false,
  );
  assert.strictEqual(
    memberUi.validateProofUploadCandidate({ path: '/tmp/proof.gif', type: 'image/gif' }).valid,
    false,
  );
  assert.strictEqual(memberUi.validateProofUploadCandidate({ path: '/tmp/proof' }).valid, false);
  var genericImage = memberUi.validateProofUploadCandidate({
    path: 'wxfile://tmp/proof.jpg?token=opaque',
    name: 'proof',
    type: 'image',
  });
  assert.strictEqual(genericImage.valid, true);
  assert.strictEqual(genericImage.value.file_path, 'wxfile://tmp/proof.jpg?token=opaque');
});

test('graduate verification picker filters message files to JPEG and PNG', function () {
  var source = fs.readFileSync(
    path.join(__dirname, '../../uniapp/pages/chamber/graduate_verification/index.vue'),
    'utf8',
  );
  assert.match(source, /extension:\s*\['jpg', 'jpeg', 'png'\]/);
  assert.match(source, /memberUi\.validateProofUploadCandidate\(file\)/);
  assert.match(source, /uni\.openDocument\(/);
  assert.doesNotMatch(source, /pdf/i);
});

test('builds the profile whitelist and omits an empty avatar key', function () {
  var result = memberUi.buildProfilePatch({
    real_name: ' 林明 ',
    avatar_object_key: '',
    graduation_year: '2008',
    resources: '供应链\n法务',
    needs: '',
    interests: ['跑步'],
    expertise: '品牌',
    privacy: { real_name: 'members', company_name: 'public' },
  });

  assert.strictEqual(result.valid, true);
  assert.strictEqual(result.value.real_name, '林明');
  assert.strictEqual(result.value.graduation_year, 2008);
  assert.deepStrictEqual(result.value.resources, ['供应链', '法务']);
  assert.strictEqual(Object.prototype.hasOwnProperty.call(result.value, 'avatar_object_key'), false);
  assert.strictEqual(result.value.privacy.real_name, 'members');
  assert.strictEqual(result.value.privacy.company_name, 'public');
  assert.strictEqual(result.value.privacy.bio, 'private');
});

test('rejects fields outside the frozen profile limits', function () {
  var result = memberUi.buildProfilePatch({
    real_name: '',
    avatar_object_key: 'https://example.com/avatar.png',
    graduation_year: '1899',
    company_name: new Array(122).join('x'),
  });
  assert.strictEqual(result.valid, false);
  assert.strictEqual(result.errors.real_name, '请填写姓名');
  assert.ok(result.errors.avatar_object_key);
  assert.ok(result.errors.graduation_year);
  assert.ok(result.errors.company_name);
});

test('builds a resubmission with immutable proof object keys', function () {
  var result = memberUi.buildVerificationSubmission(
    {
      class_name: 'EMBA 2008',
      graduation_year: '2008',
      graduation_at: '2008-07-01',
      proof_object_keys: ['verification/2008/a.png', 'verification/2008/a.png', 'verification/2008/b.pdf'],
    },
    { id: 41, can_resubmit: true },
  );
  assert.strictEqual(result.valid, true);
  assert.strictEqual(result.value.supersedes_id, 41);
  assert.strictEqual(result.value.proof_object_keys.length, 2);
  assert.strictEqual(result.value.graduation_at, Date.UTC(2008, 6, 1) / 1000);

  var invalidDate = memberUi.buildVerificationSubmission(
    {
      class_name: 'EMBA 2008',
      graduation_year: '2008',
      graduation_at: '2008-02-30',
      proof_object_keys: ['verification/2008/a.png'],
    },
    null,
  );
  assert.strictEqual(invalidDate.valid, false);
  assert.strictEqual(invalidDate.errors.graduation_at, '毕业日期格式不正确');
});

test('requires proof material for graduate verification', function () {
  var result = memberUi.buildVerificationSubmission({ class_name: 'MBA 2020', graduation_year: 2020 }, null);
  assert.strictEqual(result.valid, false);
  assert.strictEqual(result.errors.proof_object_keys, '请至少添加一份证明材料');
});

test('maps structured field errors to page field messages', function () {
  assert.deepStrictEqual(
    memberUi.fieldErrorsToMap([
      { field: 'proof_object_keys[0]', code: 'invalid_format' },
      { field: 'graduation_year', code: 'out_of_range' },
      { field: 'proof_object_keys[1]', code: 'required' },
    ]),
    {
      'proof_object_keys[0]': '格式不正确',
      proof_object_keys: '格式不正确',
      graduation_year: '超出允许范围',
      'proof_object_keys[1]': '请填写此项',
    },
  );
});

test('normalizes proof asset metadata and keeps key-only compatibility', function () {
  assert.deepStrictEqual(
    memberUi.proofAssetsFromApplication({
      proof_assets: [
        {
          id: 71,
          object_key: 'verification/2008/diploma.pdf',
          original_name: '毕业证.pdf',
          mime_type: 'application/pdf',
          size: 2048,
          available: false,
        },
      ],
      proof_object_keys: ['verification/2008/diploma.pdf', 'verification/2008/photo.png'],
    }),
    [
      {
        id: 71,
        object_key: 'verification/2008/diploma.pdf',
        original_name: '毕业证.pdf',
        mime_type: 'application/pdf',
        size: 2048,
        available: false,
      },
      {
        id: 0,
        object_key: 'verification/2008/photo.png',
        original_name: 'photo.png',
        mime_type: '',
        size: 0,
        available: false,
      },
    ],
  );
  assert.strictEqual(memberUi.humanFileSize(2048), '2.0 KB');
});

test('exposes only valid review transitions', function () {
  assert.deepStrictEqual(
    memberUi.reviewActionsForStatus('pending').map(function (item) {
      return item.value;
    }),
    ['approve', 'return', 'reject'],
  );
  assert.deepStrictEqual(
    memberUi.reviewActionsForStatus('approved').map(function (item) {
      return item.value;
    }),
    ['revoke'],
  );
  assert.deepStrictEqual(memberUi.reviewActionsForStatus('rejected'), []);
});

test('requires a note for non-approval review actions', function () {
  assert.strictEqual(memberUi.buildReviewRequest('approve', '').valid, true);
  assert.strictEqual(memberUi.buildReviewRequest('return', '').valid, false);
  assert.deepStrictEqual(memberUi.buildReviewRequest('reject', '材料无法识别').value, {
    action: 'reject',
    note: '材料无法识别',
  });
});

test('normalizes the frozen Chamber admin list envelope', function () {
  var result = memberUi.normalizeAdminList({
    data: { items: [{ id: 1 }], total: 12, page: 2, per_page: 10 },
  });
  assert.deepStrictEqual(result, {
    list: [{ id: 1 }],
    count: 12,
    page: 2,
    limit: 10,
  });
});

test('keeps compatibility with the earlier CRMEB list envelope', function () {
  var result = memberUi.normalizeAdminList({
    data: { list: [{ id: 2 }], count: 4, page: 1, limit: 20 },
  });
  assert.deepStrictEqual(result, {
    list: [{ id: 2 }],
    count: 4,
    page: 1,
    limit: 20,
  });
});

test('creates valid operation keys and stable payload fingerprints', function () {
  var key = memberUi.createIdempotencyKey('profile-save', 1720000000000, 0.5);
  assert.match(key, /^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/);
  assert.strictEqual(
    memberUi.payloadFingerprint({ b: 1, a: { d: 2, c: 3 } }),
    memberUi.payloadFingerprint({ a: { c: 3, d: 2 }, b: 1 }),
  );
});

test('derives the Chamber service origin from the CRMEB admin base URL', function () {
  assert.strictEqual(
    memberUi.resolveServiceOrigin('https://admin.example.com/adminapi', 'https://fallback.test'),
    'https://admin.example.com',
  );
  assert.strictEqual(
    memberUi.resolveServiceOrigin('/adminapi', 'https://shop.example.com/'),
    'https://shop.example.com',
  );
});

test('frontend API adapters keep the frozen Chamber endpoint paths', function () {
  var root = path.resolve(__dirname, '../..');
  var uniApi = fs.readFileSync(path.join(root, 'uniapp/api/chamber/member.js'), 'utf8');
  var adminApi = fs.readFileSync(path.join(root, 'admin/src/api/chamber/graduateVerification.js'), 'utf8');

  assert.match(uniApi, /\/chamber\/v1\/me\/profile/);
  assert.match(uniApi, /\/chamber\/v1\/me\/bootstrap/);
  assert.match(uniApi, /\/chamber\/v1\/me\/graduate-verifications/);
  assert.match(uniApi, /\/chamber\/v1\/me\/assets/);
  assert.match(uniApi, /uni\.uploadFile/);
  assert.match(uniApi, /graduate_verification_proof/);
  assert.match(uniApi, /if \(!token && checkLogin\(\)\) token = store\.state\.app\.token/);
  assert.match(uniApi, /createIdempotencyKey\('member-bootstrap'\)/);
  assert.match(uniApi, /invite_code: initializedInviteCode/);
  assert.match(uniApi, /chamber_invite_code/);
  assert.match(uniApi, /MEMBER_ASSET_PATH\}\/\$\{assetId\}\/content/);
  assert.doesNotMatch(uniApi, /original_name/);
  assert.match(adminApi, /\/chamber\/admin\/v1\/graduate-verifications/);
  assert.match(adminApi, /\/chamber\/admin\/v1\/member-assets/);
  assert.match(adminApi, /MEMBER_ASSET_PATH\}\/\$\{assetId\}\/content/);
  assert.match(adminApi, /params: \{ application_id: applicationId \}/);
  assert.match(adminApi, /Idempotency-Key/);

  var profilePage = fs.readFileSync(path.join(root, 'uniapp/pages/chamber/profile/index.vue'), 'utf8');
  var verificationPage = fs.readFileSync(
    path.join(root, 'uniapp/pages/chamber/graduate_verification/index.vue'),
    'utf8',
  );
  var adminPage = fs.readFileSync(
    path.join(root, 'admin/src/pages/chamber/graduateVerification/index.vue'),
    'utf8',
  );
  assert.match(adminPage, /graduateVerificationAssetContent\(asset\.id, applicationId\)/);
  var memberEntry = fs.readFileSync(
    path.join(root, 'uniapp/components/chamberMemberEntry/index.vue'),
    'utf8',
  );
  assert.match(profilePage, /ensureMemberInitialized\(this\.inviteCode\)/);
  assert.match(verificationPage, /ensureMemberInitialized\(this\.inviteCode\)/);
  assert.match(memberEntry, /ensureMemberInitialized\(\)/);
  assert.doesNotMatch(profilePage, /v-model="form\.avatar_object_key"/);
  assert.match(
    fs.readFileSync(path.join(root, 'admin/src/router/modules/chamber.js'), 'utf8'),
    /beforeEnter: superAdministratorOnly/,
  );
  assert.doesNotMatch(profilePage + verificationPage + adminPage, /padStart/);
  assert.doesNotMatch(verificationPage, /proof_object_keys[^\n]*(?:v-model|<input)/);
});

test('user center overlay remains deterministic against the pinned CRMEB source', function () {
  var upstream = fs.readFileSync(
    path.resolve(__dirname, '../../../../backend/crmeb/template/uni-app/pages/user/index.vue'),
    'utf8',
  );
  var once = userCenterOverlay.applySource(upstream);
  var twice = userCenterOverlay.applySource(once);

  assert.strictEqual(once, twice);
  assert.strictEqual((once.match(/<chamber-member-entry><\/chamber-member-entry>/g) || []).length, 1);
  assert.strictEqual((once.match(/import ChamberMemberEntry/g) || []).length, 1);
  assert.strictEqual((once.match(/    ChamberMemberEntry,/g) || []).length, 1);
});

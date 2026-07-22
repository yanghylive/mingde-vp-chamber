'use strict';

var assert = require('assert');
var tenantBrand = require('../tenant-brand');
var vue2TenantBrand = require('../vue2-tenant-brand');

var tests = [];

function test(name, run) {
  tests.push({ name: name, run: run });
}

test('uses stable defaults without mutating the exported object', function () {
  var before = JSON.stringify(tenantBrand.DEFAULT_TENANT_BRAND);
  var result = tenantBrand.normalizeTenantBrand(null);

  assert.deepStrictEqual(result, tenantBrand.DEFAULT_TENANT_BRAND);
  result.displayName = 'changed';
  assert.strictEqual(JSON.stringify(tenantBrand.DEFAULT_TENANT_BRAND), before);
});

test('unwraps CRMEB-style responses and normalizes aliases', function () {
  var result = tenantBrand.normalizeTenantBrand({
    status: 200,
    data: {
      tenant_brand: {
        tenant_id: 17,
        brand_name: '  VP Chamber  ',
        short_name: 'VP',
        brand_logo: '/uploads/vp.png',
        primary_color: '#2a7',
        secondary_color: '#1b2c3d',
        customer_phone: '024-12345678',
      },
    },
  });

  assert.strictEqual(result.tenantKey, '17');
  assert.strictEqual(result.displayName, 'VP Chamber');
  assert.strictEqual(result.shortName, 'VP');
  assert.strictEqual(result.logoUrl, '/uploads/vp.png');
  assert.strictEqual(result.primaryColor, '#22AA77');
  assert.strictEqual(result.accentColor, '#1B2C3D');
  assert.strictEqual(result.servicePhone, '024-12345678');
});

test('allows relative and HTTP URLs but rejects other schemes', function () {
  var relative = tenantBrand.normalizeTenantBrand({
    logo: 'static/logo.png',
    favicon: '/assets/favicon.ico',
  });
  var https = tenantBrand.normalizeTenantBrand({
    logo: 'https://cdn.example.com/logo.png',
    favicon: '//cdn.example.com/favicon.ico',
  });
  var rejected = tenantBrand.normalizeTenantBrand(
    {
      logo: 'data:image/png;base64,abc',
      favicon: 'ftp://files.example.com/favicon.ico',
    },
    {
      logoUrl: '/safe-logo.png',
      faviconUrl: '/safe-favicon.ico',
    },
  );

  assert.strictEqual(relative.logoUrl, 'static/logo.png');
  assert.strictEqual(relative.faviconUrl, '/assets/favicon.ico');
  assert.strictEqual(https.logoUrl, 'https://cdn.example.com/logo.png');
  assert.strictEqual(https.faviconUrl, '//cdn.example.com/favicon.ico');
  assert.strictEqual(rejected.logoUrl, '/safe-logo.png');
  assert.strictEqual(rejected.faviconUrl, '/safe-favicon.ico');
  assert.strictEqual(
    tenantBrand.normalizeTenantBrand({ logo: '\\\\cdn.example.com\\logo.png' }).logoUrl,
    tenantBrand.DEFAULT_TENANT_BRAND.logoUrl,
  );
});

test('rejects script URLs and invalid colors', function () {
  var result = tenantBrand.normalizeTenantBrand(
    {
      logo: 'javascript:alert(1)',
      primary_color: 'tomato',
    },
    {
      logoUrl: '/safe-logo.png',
      primaryColor: '#123456',
    },
  );

  assert.strictEqual(result.logoUrl, '/safe-logo.png');
  assert.strictEqual(result.primaryColor, '#123456');
});

test('bootstraps remote data and falls back when loading fails', async function () {
  var remote = await tenantBrand.bootstrapTenantBrand({
    defaults: { displayName: 'Fallback' },
    fetchRemote: function () {
      return Promise.resolve({ data: { brand_name: 'Remote' } });
    },
  });
  var fallback = await tenantBrand.bootstrapTenantBrand({
    defaults: { displayName: 'Fallback' },
    fetchRemote: function () {
      return Promise.reject(new Error('offline'));
    },
  });

  assert.strictEqual(remote.source, 'remote');
  assert.strictEqual(remote.brand.displayName, 'Remote');
  assert.strictEqual(fallback.source, 'default');
  assert.strictEqual(fallback.brand.displayName, 'Fallback');
  assert.strictEqual(fallback.error.message, 'offline');
});

test('installs one reactive-shaped state for Vue 2 and UniApp', async function () {
  function FakeVue(options) {
    this.$data = options.data;
  }
  FakeVue.prototype = {};

  var installed = vue2TenantBrand.installTenantBrand(FakeVue, {
    fetchRemote: function () {
      return { data: { brand_name: 'Shared Brand' } };
    },
  });

  assert.strictEqual(FakeVue.prototype.$tenantBrand, installed.state);
  assert.strictEqual(installed.state.tenantBrandReady, false);
  await installed.ready;
  assert.strictEqual(installed.state.tenantBrandReady, true);
  assert.strictEqual(installed.state.tenantBrand.displayName, 'Shared Brand');
});

(async function run() {
  for (var index = 0; index < tests.length; index += 1) {
    await tests[index].run();
    process.stdout.write('PASS ' + tests[index].name + '\n');
  }
  process.stdout.write('PASS ' + tests.length + ' tenant brand tests\n');
})().catch(function (error) {
  process.stderr.write((error && error.stack) || String(error));
  process.stderr.write('\n');
  process.exitCode = 1;
});

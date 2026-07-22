'use strict';

var tenantBrand = require('./tenant-brand');

function installTenantBrand(Vue, options) {
  if (typeof Vue !== 'function') {
    throw new TypeError('A Vue 2 constructor is required');
  }

  var settings = options || {};
  var initialBrand = tenantBrand.normalizeTenantBrand(null, settings.defaults);
  var viewModel = new Vue({
    data: {
      tenantBrand: initialBrand,
      tenantBrandReady: false,
      tenantBrandSource: 'default',
      tenantBrandError: null,
    },
  });
  var state = viewModel.$data;

  if (Vue.prototype && settings.installPrototype !== false) {
    Vue.prototype.$tenantBrand = state;
  }

  var ready = tenantBrand.bootstrapTenantBrand(settings).then(function (result) {
    state.tenantBrand = result.brand;
    state.tenantBrandSource = result.source;
    state.tenantBrandError = result.error;
    state.tenantBrandReady = true;
    return state;
  });

  return {
    state: state,
    ready: ready,
  };
}

module.exports = {
  installTenantBrand: installTenantBrand,
};

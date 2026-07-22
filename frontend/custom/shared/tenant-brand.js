(function (root, factory) {
  var api = factory();

  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  } else {
    root.MingdeTenantBrand = api;
  }
})(
  typeof globalThis !== 'undefined'
    ? globalThis
    : typeof self !== 'undefined'
      ? self
      : typeof window !== 'undefined'
        ? window
        : this,
  function () {
    'use strict';

    var DEFAULT_TENANT_BRAND = {
      tenantKey: 'default',
      displayName: '校友商会',
      shortName: '商会',
      logoUrl: '',
      faviconUrl: '',
      primaryColor: '#1F6B52',
      accentColor: '#D6A33A',
      servicePhone: '',
      serviceEmail: '',
      copyright: '',
    };

    var FIELD_ALIASES = {
      tenantKey: ['tenantKey', 'tenant_key', 'tenantId', 'tenant_id'],
      displayName: ['displayName', 'display_name', 'brandName', 'brand_name', 'name'],
      shortName: ['shortName', 'short_name', 'brandShortName', 'brand_short_name'],
      logoUrl: ['logoUrl', 'logo_url', 'brandLogo', 'brand_logo', 'logo'],
      faviconUrl: ['faviconUrl', 'favicon_url', 'favicon'],
      primaryColor: ['primaryColor', 'primary_color', 'brandColor', 'brand_color'],
      accentColor: ['accentColor', 'accent_color', 'secondaryColor', 'secondary_color'],
      servicePhone: ['servicePhone', 'service_phone', 'customerPhone', 'customer_phone'],
      serviceEmail: ['serviceEmail', 'service_email', 'customerEmail', 'customer_email'],
      copyright: ['copyright', 'copyrightText', 'copyright_text'],
    };

    function isObject(value) {
      return value !== null && typeof value === 'object' && !Array.isArray(value);
    }

    function hasOwn(value, key) {
      return Object.prototype.hasOwnProperty.call(value, key);
    }

    function pickObject() {
      for (var index = 0; index < arguments.length; index += 1) {
        if (isObject(arguments[index])) return arguments[index];
      }
      return {};
    }

    function unwrapResponse(input) {
      var value = isObject(input) ? input : {};

      for (var depth = 0; depth < 2; depth += 1) {
        var keys = Object.keys(value);
        var looksLikeEnvelope =
          keys.length === 1 ||
          hasOwn(value, 'code') ||
          hasOwn(value, 'status') ||
          hasOwn(value, 'success') ||
          hasOwn(value, 'message') ||
          hasOwn(value, 'msg');

        if (!looksLikeEnvelope || !isObject(value.data)) break;
        value = value.data;
      }

      return pickObject(value.brand, value.tenantBrand, value.tenant_brand, value.config, value);
    }

    function readFirst(source, aliases) {
      for (var index = 0; index < aliases.length; index += 1) {
        if (hasOwn(source, aliases[index]) && source[aliases[index]] !== null) {
          return source[aliases[index]];
        }
      }
      return undefined;
    }

    function cleanText(value, fallback, maxLength) {
      if (value === undefined) return fallback;
      if (typeof value !== 'string' && typeof value !== 'number') return fallback;

      var result = String(value).trim();
      if (!result) return fallback;
      return result.slice(0, maxLength);
    }

    function cleanUrl(value, fallback) {
      var result = cleanText(value, fallback, 2048);
      if (result === fallback) return fallback;
      if (/[\u0000-\u001f\u007f]/.test(result) || /\\/.test(result)) return fallback;
      if (/^https?:\/\//i.test(result) || /^\/\//.test(result)) return result;
      if (/^[a-z][a-z0-9+.-]*:/i.test(result)) return fallback;
      return result;
    }

    function cleanColor(value, fallback) {
      var result = cleanText(value, fallback, 16);
      var shortHex = /^#([0-9a-f]{3})$/i.exec(result);
      var longHex = /^#([0-9a-f]{6})$/i.exec(result);

      if (shortHex) {
        return (
          '#' +
          shortHex[1]
            .split('')
            .map(function (character) {
              return character + character;
            })
            .join('')
        ).toUpperCase();
      }

      return longHex ? ('#' + longHex[1]).toUpperCase() : fallback;
    }

    function normalizeSource(source, fallback) {
      return {
        tenantKey: cleanText(readFirst(source, FIELD_ALIASES.tenantKey), fallback.tenantKey, 80),
        displayName: cleanText(readFirst(source, FIELD_ALIASES.displayName), fallback.displayName, 80),
        shortName: cleanText(readFirst(source, FIELD_ALIASES.shortName), fallback.shortName, 40),
        logoUrl: cleanUrl(readFirst(source, FIELD_ALIASES.logoUrl), fallback.logoUrl),
        faviconUrl: cleanUrl(readFirst(source, FIELD_ALIASES.faviconUrl), fallback.faviconUrl),
        primaryColor: cleanColor(readFirst(source, FIELD_ALIASES.primaryColor), fallback.primaryColor),
        accentColor: cleanColor(readFirst(source, FIELD_ALIASES.accentColor), fallback.accentColor),
        servicePhone: cleanText(readFirst(source, FIELD_ALIASES.servicePhone), fallback.servicePhone, 40),
        serviceEmail: cleanText(readFirst(source, FIELD_ALIASES.serviceEmail), fallback.serviceEmail, 160),
        copyright: cleanText(readFirst(source, FIELD_ALIASES.copyright), fallback.copyright, 200),
      };
    }

    function normalizeTenantBrand(remoteValue, defaults) {
      var fallback = DEFAULT_TENANT_BRAND;

      if (isObject(defaults)) {
        fallback = normalizeSource(unwrapResponse(defaults), DEFAULT_TENANT_BRAND);
      }

      return normalizeSource(unwrapResponse(remoteValue), fallback);
    }

    function serializeError(error) {
      if (!error) return null;
      return {
        name: cleanText(error.name, 'Error', 80),
        message: cleanText(error.message || error, 'Unknown tenant brand error', 500),
      };
    }

    function bootstrapTenantBrand(options) {
      var settings = isObject(options) ? options : {};
      var fallback = normalizeTenantBrand(null, settings.defaults);

      if (typeof settings.fetchRemote !== 'function') {
        return Promise.resolve({
          brand: fallback,
          source: 'default',
          error: null,
        });
      }

      return Promise.resolve()
        .then(function () {
          return settings.fetchRemote();
        })
        .then(function (remoteValue) {
          return {
            brand: normalizeTenantBrand(remoteValue, fallback),
            source: 'remote',
            error: null,
          };
        })
        .catch(function (error) {
          return {
            brand: fallback,
            source: 'default',
            error: serializeError(error),
          };
        });
    }

    function toCssVariables(brand) {
      var normalized = normalizeTenantBrand(brand);
      return {
        '--tenant-primary-color': normalized.primaryColor,
        '--tenant-accent-color': normalized.accentColor,
      };
    }

    return {
      DEFAULT_TENANT_BRAND: DEFAULT_TENANT_BRAND,
      normalizeTenantBrand: normalizeTenantBrand,
      bootstrapTenantBrand: bootstrapTenantBrand,
      toCssVariables: toCssVariables,
    };
  },
);

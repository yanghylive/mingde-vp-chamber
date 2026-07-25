(function (root, factory) {
  var api = factory();

  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  } else {
    root.MingdeMemberUi = api;
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

    var VISIBILITY_OPTIONS = [
      { value: 'private', label: '仅自己' },
      { value: 'members', label: '认证会员' },
      { value: 'friends', label: '好友' },
      { value: 'public', label: '公开' },
    ];

    var PROFILE_FIELDS = [
      { key: 'avatar_object_key', label: '头像', maxLength: 255 },
      { key: 'real_name', label: '姓名', maxLength: 40 },
      { key: 'class_name', label: '班级', maxLength: 80 },
      { key: 'graduation_year', label: '毕业年份' },
      { key: 'industry', label: '行业', maxLength: 80 },
      { key: 'company_name', label: '公司', maxLength: 120 },
      { key: 'job_title', label: '职务', maxLength: 80 },
      { key: 'main_business', label: '主营业务', maxLength: 500 },
      { key: 'province', label: '省份', maxLength: 40 },
      { key: 'city', label: '城市', maxLength: 40 },
      { key: 'bio', label: '个人简介', maxLength: 1000 },
      { key: 'resources', label: '可提供资源' },
      { key: 'needs', label: '当前需求' },
      { key: 'interests', label: '兴趣方向' },
      { key: 'expertise', label: '专业能力' },
    ];

    var TEXT_FIELD_LIMITS = {
      real_name: 40,
      class_name: 80,
      industry: 80,
      company_name: 120,
      job_title: 80,
      main_business: 500,
      province: 40,
      city: 40,
      bio: 1000,
    };

    var LIST_FIELD_LIMITS = {
      resources: { maxItems: 30, maxLength: 100 },
      needs: { maxItems: 30, maxLength: 100 },
      interests: { maxItems: 30, maxLength: 60 },
      expertise: { maxItems: 30, maxLength: 60 },
    };

    var STATUS_META = {
      draft: { label: '草稿', tone: 'info' },
      pending: { label: '待审核', tone: 'warning' },
      approved: { label: '已认证', tone: 'success' },
      returned: { label: '已退回', tone: 'warning' },
      rejected: { label: '未通过', tone: 'danger' },
      revoked: { label: '已撤销', tone: 'danger' },
    };

    var REVIEW_ACTIONS = {
      approve: { label: '通过', tone: 'success', noteRequired: false },
      return: { label: '退回补充', tone: 'warning', noteRequired: true },
      reject: { label: '拒绝', tone: 'danger', noteRequired: true },
      revoke: { label: '撤销认证', tone: 'danger', noteRequired: true },
    };

    var FIELD_ERROR_MESSAGES = {
      required: '请填写此项',
      min_properties: '请至少填写一项',
      invalid_type: '数据类型不正确',
      invalid_format: '格式不正确',
      invalid_value: '内容不正确',
      invalid_encoding: '内容编码不正确',
      unknown_field: '不支持此字段',
      too_long: '内容过长',
      too_many_items: '项目数量过多',
      out_of_range: '超出允许范围',
      duplicate: '内容重复',
    };

    var OBJECT_KEY_PATTERN =
      /^(?!https?:\/\/)(?!\/)(?!.*\/\/)(?!.*(?:^|\/)\.{1,2}(?:\/|$))(?!.*\/$)[A-Za-z0-9][A-Za-z0-9._/-]*$/;

    function isObject(value) {
      return value !== null && typeof value === 'object' && !Array.isArray(value);
    }

    function hasOwn(value, key) {
      return Object.prototype.hasOwnProperty.call(value, key);
    }

    function cleanText(value) {
      if (value === null || value === undefined) return '';
      return String(value).trim();
    }

    function unwrapData(input) {
      if (!isObject(input)) return {};
      if (isObject(input.data)) return input.data;
      return input;
    }

    function visibilityValue(value) {
      var normalized = cleanText(value);
      for (var index = 0; index < VISIBILITY_OPTIONS.length; index += 1) {
        if (VISIBILITY_OPTIONS[index].value === normalized) return normalized;
      }
      return 'private';
    }

    function createPrivacy(source) {
      var input = isObject(source) ? source : {};
      var result = {};
      PROFILE_FIELDS.forEach(function (field) {
        result[field.key] = visibilityValue(input[field.key]);
      });
      return result;
    }

    function normalizeList(value) {
      var source = Array.isArray(value) ? value : cleanText(value) ? String(value).split(/[\n,，;；]/) : [];
      var seen = {};
      var result = [];

      source.forEach(function (item) {
        var normalized = cleanText(item);
        if (!normalized || hasOwn(seen, normalized)) return;
        seen[normalized] = true;
        result.push(normalized);
      });

      return result;
    }

    function isValidObjectKey(value) {
      var normalized = cleanText(value);
      return normalized.length > 0 && normalized.length <= 255 && OBJECT_KEY_PATTERN.test(normalized);
    }

    function fieldErrorsToMap(input) {
      var errors = Array.isArray(input) ? input : [];
      var result = {};

      errors.forEach(function (item) {
        if (!isObject(item)) return;
        var field = cleanText(item.field);
        var code = cleanText(item.code);
        if (!field || hasOwn(result, field)) return;

        var message = FIELD_ERROR_MESSAGES[code] || '请检查此项';
        result[field] = message;

        var rootField = field.split(/[.\[]/, 1)[0];
        if (rootField && !hasOwn(result, rootField)) result[rootField] = message;
      });

      return result;
    }

    function fileNameFromObjectKey(objectKey) {
      var normalized = cleanText(objectKey);
      if (!normalized) return '';
      var parts = normalized.split('/');
      return parts[parts.length - 1] || normalized;
    }

    function normalizeMemberAsset(input) {
      var data = unwrapData(input);
      var id = Number(data.id || 0);
      var objectKey = cleanText(data.object_key);
      if (!objectKey) return null;
      var validId = Number.isInteger(id) && id > 0;

      return {
        id: validId ? id : 0,
        object_key: objectKey,
        original_name: cleanText(data.original_name) || fileNameFromObjectKey(objectKey),
        mime_type: cleanText(data.mime_type),
        size: Math.max(0, Number(data.size) || 0),
        available: typeof data.available === 'boolean' ? data.available : validId,
      };
    }

    function proofAssetsFromApplication(input) {
      var application = isObject(input) ? input : {};
      var assets = [];
      var seen = {};

      if (Array.isArray(application.proof_assets)) {
        application.proof_assets.forEach(function (item) {
          var asset = normalizeMemberAsset(item);
          if (!asset || hasOwn(seen, asset.object_key)) return;
          seen[asset.object_key] = true;
          assets.push(asset);
        });
      }

      normalizeList(application.proof_object_keys).forEach(function (objectKey) {
        if (hasOwn(seen, objectKey)) return;
        seen[objectKey] = true;
        assets.push({
          id: 0,
          object_key: objectKey,
          original_name: fileNameFromObjectKey(objectKey),
          mime_type: '',
          size: 0,
          available: false,
        });
      });

      return assets;
    }

    function humanFileSize(value) {
      var size = Math.max(0, Number(value) || 0);
      if (!size) return '';
      if (size < 1024) return Math.round(size) + ' B';
      if (size < 1024 * 1024) return (size / 1024).toFixed(size < 10 * 1024 ? 1 : 0) + ' KB';
      return (size / (1024 * 1024)).toFixed(size < 10 * 1024 * 1024 ? 1 : 0) + ' MB';
    }

    function profileFormFromData(input) {
      var data = unwrapData(input);
      var form = {
        real_name: cleanText(data.real_name),
        avatar_object_key: cleanText(data.avatar_object_key),
        class_name: cleanText(data.class_name),
        graduation_year: data.graduation_year ? String(data.graduation_year) : '',
        industry: cleanText(data.industry),
        company_name: cleanText(data.company_name),
        job_title: cleanText(data.job_title),
        main_business: cleanText(data.main_business),
        province: cleanText(data.province),
        city: cleanText(data.city),
        bio: cleanText(data.bio),
        resources: normalizeList(data.resources).join('\n'),
        needs: normalizeList(data.needs).join('\n'),
        interests: normalizeList(data.interests).join('\n'),
        expertise: normalizeList(data.expertise).join('\n'),
        privacy: createPrivacy(data.privacy),
      };
      return form;
    }

    function buildProfilePatch(input) {
      var form = isObject(input) ? input : {};
      var patch = {};
      var errors = {};

      Object.keys(TEXT_FIELD_LIMITS).forEach(function (key) {
        var value = cleanText(form[key]);
        if (key === 'real_name' && !value) errors[key] = '请填写姓名';
        if (value.length > TEXT_FIELD_LIMITS[key]) {
          errors[key] = '最多 ' + TEXT_FIELD_LIMITS[key] + ' 个字符';
        }
        patch[key] = value;
      });

      var avatarKey = cleanText(form.avatar_object_key);
      if (avatarKey) {
        if (!isValidObjectKey(avatarKey)) {
          errors.avatar_object_key = '头像文件标识格式不正确';
        } else {
          patch.avatar_object_key = avatarKey;
        }
      }

      var yearText = cleanText(form.graduation_year);
      if (yearText) {
        var year = Number(yearText);
        if (!Number.isInteger(year) || year < 1900 || year > 2106) {
          errors.graduation_year = '毕业年份应在 1900 至 2106 之间';
        } else {
          patch.graduation_year = year;
        }
      }

      Object.keys(LIST_FIELD_LIMITS).forEach(function (key) {
        var values = normalizeList(form[key]);
        var limits = LIST_FIELD_LIMITS[key];
        if (values.length > limits.maxItems) {
          errors[key] = '最多填写 ' + limits.maxItems + ' 项';
        } else {
          for (var index = 0; index < values.length; index += 1) {
            if (values[index].length > limits.maxLength) {
              errors[key] = '每项最多 ' + limits.maxLength + ' 个字符';
              break;
            }
          }
        }
        patch[key] = values;
      });

      patch.privacy = createPrivacy(form.privacy);

      return {
        valid: Object.keys(errors).length === 0,
        errors: errors,
        value: patch,
      };
    }

    function dateToUnixSeconds(value) {
      var normalized = cleanText(value);
      if (!normalized) return 0;
      var parts = /^(\d{4})-(\d{2})-(\d{2})$/.exec(normalized);
      if (!parts) return 0;

      var year = Number(parts[1]);
      var month = Number(parts[2]);
      var day = Number(parts[3]);
      var timestamp = Date.UTC(year, month - 1, day);
      var date = new Date(timestamp);
      if (
        !Number.isFinite(timestamp) ||
        date.getUTCFullYear() !== year ||
        date.getUTCMonth() !== month - 1 ||
        date.getUTCDate() !== day
      ) {
        return 0;
      }

      return Math.floor(timestamp / 1000);
    }

    function buildVerificationSubmission(input, latestApplication) {
      var form = isObject(input) ? input : {};
      var latest = isObject(latestApplication) ? latestApplication : {};
      var value = {};
      var errors = {};
      var className = cleanText(form.class_name);
      var year = Number(cleanText(form.graduation_year));
      var proofKeys = normalizeList(form.proof_object_keys);

      if (!className) errors.class_name = '请填写班级';
      if (className.length > 80) errors.class_name = '班级最多 80 个字符';
      value.class_name = className;

      if (!Number.isInteger(year) || year < 1900 || year > 2106) {
        errors.graduation_year = '毕业年份应在 1900 至 2106 之间';
      } else {
        value.graduation_year = year;
      }

      var graduationAt = dateToUnixSeconds(form.graduation_at);
      if (cleanText(form.graduation_at) && !graduationAt) {
        errors.graduation_at = '毕业日期格式不正确';
      } else if (graduationAt) {
        value.graduation_at = graduationAt;
      }

      if (!proofKeys.length) {
        errors.proof_object_keys = '请至少添加一份证明材料';
      } else if (proofKeys.length > 10) {
        errors.proof_object_keys = '证明材料最多 10 份';
      } else {
        for (var index = 0; index < proofKeys.length; index += 1) {
          if (!isValidObjectKey(proofKeys[index])) {
            errors.proof_object_keys = '第 ' + (index + 1) + ' 份材料标识格式不正确';
            break;
          }
        }
      }
      value.proof_object_keys = proofKeys;

      var supersedesId = Number(form.supersedes_id || 0);
      if (!supersedesId && latest.can_resubmit) supersedesId = Number(latest.id || 0);
      if (Number.isInteger(supersedesId) && supersedesId > 0) value.supersedes_id = supersedesId;

      return {
        valid: Object.keys(errors).length === 0,
        errors: errors,
        value: value,
      };
    }

    function verificationStatusMeta(status) {
      var key = cleanText(status);
      var meta = STATUS_META[key] || { label: '未知状态', tone: 'info' };
      return { value: key, label: meta.label, tone: meta.tone };
    }

    function reviewActionsForStatus(status) {
      var keys = status === 'pending' ? ['approve', 'return', 'reject'] : status === 'approved' ? ['revoke'] : [];
      return keys.map(function (key) {
        var item = REVIEW_ACTIONS[key];
        return {
          value: key,
          label: item.label,
          tone: item.tone,
          noteRequired: item.noteRequired,
        };
      });
    }

    function buildReviewRequest(action, note) {
      var normalizedAction = cleanText(action);
      var normalizedNote = cleanText(note);
      var errors = {};
      var definition = REVIEW_ACTIONS[normalizedAction];

      if (!definition) errors.action = '请选择有效的审核动作';
      if (definition && definition.noteRequired && !normalizedNote) errors.note = '请填写审核意见';
      if (normalizedNote.length > 500) errors.note = '审核意见最多 500 个字符';

      var value = { action: normalizedAction };
      if (normalizedNote) value.note = normalizedNote;
      return {
        valid: Object.keys(errors).length === 0,
        errors: errors,
        value: value,
      };
    }

    function normalizeAdminList(input) {
      var data = unwrapData(input);
      var list = Array.isArray(data.items) ? data.items : Array.isArray(data.list) ? data.list : [];
      var count = Number(hasOwn(data, 'total') ? data.total : data.count);
      return {
        list: list,
        count: Number.isFinite(count) && count >= 0 ? count : 0,
        page: Number(data.page) || 1,
        limit: Number(hasOwn(data, 'per_page') ? data.per_page : data.limit) || 20,
      };
    }

    function stableValue(value) {
      if (Array.isArray(value)) return value.map(stableValue);
      if (!isObject(value)) return value;
      var result = {};
      Object.keys(value)
        .sort()
        .forEach(function (key) {
          result[key] = stableValue(value[key]);
        });
      return result;
    }

    function payloadFingerprint(value) {
      return JSON.stringify(stableValue(value));
    }

    function createIdempotencyKey(prefix, now, randomValue) {
      var safePrefix =
        cleanText(prefix)
          .replace(/[^A-Za-z0-9._:-]/g, '-')
          .slice(0, 40) || 'chamber';
      var timestamp = Number.isFinite(Number(now)) ? Number(now) : Date.now();
      var random = Number.isFinite(Number(randomValue)) ? Number(randomValue) : Math.random();
      var entropy = Math.floor(Math.abs(random % 1) * 2176782336)
        .toString(36)
        .slice(0, 6);
      while (entropy.length < 6) entropy = '0' + entropy;
      return (safePrefix + ':' + Math.floor(timestamp).toString(36) + ':' + entropy).slice(0, 128);
    }

    function resolveServiceOrigin(apiBaseUrl, fallbackOrigin) {
      var base = cleanText(apiBaseUrl);
      var fallback = cleanText(fallbackOrigin).replace(/\/+$/, '');
      var absolute = /^(https?):\/\/([^/]+)/i.exec(base);
      if (absolute) return absolute[1].toLowerCase() + '://' + absolute[2];
      var protocolRelative = /^\/\/([^/]+)/.exec(base);
      if (protocolRelative) {
        var protocol = /^(https?):/i.exec(fallback);
        return (protocol ? protocol[1].toLowerCase() : 'https') + '://' + protocolRelative[1];
      }
      return fallback;
    }

    return {
      VISIBILITY_OPTIONS: VISIBILITY_OPTIONS,
      PROFILE_FIELDS: PROFILE_FIELDS,
      STATUS_META: STATUS_META,
      REVIEW_ACTIONS: REVIEW_ACTIONS,
      FIELD_ERROR_MESSAGES: FIELD_ERROR_MESSAGES,
      normalizeList: normalizeList,
      isValidObjectKey: isValidObjectKey,
      fieldErrorsToMap: fieldErrorsToMap,
      normalizeMemberAsset: normalizeMemberAsset,
      proofAssetsFromApplication: proofAssetsFromApplication,
      humanFileSize: humanFileSize,
      createPrivacy: createPrivacy,
      profileFormFromData: profileFormFromData,
      buildProfilePatch: buildProfilePatch,
      buildVerificationSubmission: buildVerificationSubmission,
      verificationStatusMeta: verificationStatusMeta,
      reviewActionsForStatus: reviewActionsForStatus,
      buildReviewRequest: buildReviewRequest,
      normalizeAdminList: normalizeAdminList,
      payloadFingerprint: payloadFingerprint,
      createIdempotencyKey: createIdempotencyKey,
      resolveServiceOrigin: resolveServiceOrigin,
    };
  },
);

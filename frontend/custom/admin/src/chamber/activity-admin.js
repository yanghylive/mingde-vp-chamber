(function (root, factory) {
  var api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.ChamberActivityAdmin = api;
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  var EVENT_TYPES = [
    { value: 'growth', label: '成长活动' },
    { value: 'industry', label: '行业交流' },
    { value: 'public_welfare', label: '公益活动' },
  ];

  var STATUS_META = {
    0: { key: 'draft', label: '草稿', tone: 'info' },
    1: { key: 'published', label: '报名中', tone: 'success' },
    2: { key: 'registration_closed', label: '报名截止', tone: 'warning' },
    3: { key: 'ended', label: '已结束', tone: 'info' },
    4: { key: 'cancelled', label: '已取消', tone: 'danger' },
    draft: { key: 'draft', label: '草稿', tone: 'info' },
    published: { key: 'published', label: '报名中', tone: 'success' },
    registration_closed: { key: 'registration_closed', label: '报名截止', tone: 'warning' },
    ended: { key: 'ended', label: '已结束', tone: 'info' },
    cancelled: { key: 'cancelled', label: '已取消', tone: 'danger' },
  };

  function defaultEligibility() {
    return { allowed_channel_ids: [], min_points: 0, required_roles: [] };
  }

  function defaultRefundPolicy() {
    return { mode: 'none', deadline_time: 0, percent: 0, description: '' };
  }

  function createTicket() {
    return {
      name: '普通票',
      price: '0.00',
      integral_price: 0,
      product_id: 0,
      product_attr_unique: '',
      capacity: 0,
      min_tier: 1,
      eligibility: defaultEligibility(),
      refund_policy: defaultRefundPolicy(),
      sale_start_time: '',
      sale_end_time: '',
      status: 1,
      sort: 0,
    };
  }

  function createForm() {
    return {
      id: 0,
      event_no: '',
      event_type: 'growth',
      title: '',
      cover_image: '',
      summary: '',
      detail: '',
      tags_text: '',
      speakers: [],
      start_time: '',
      end_time: '',
      signup_start_time: '',
      signup_end_time: '',
      location_name: '',
      address: '',
      longitude: '',
      latitude: '',
      min_tier: 1,
      eligibility: defaultEligibility(),
      refund_policy: defaultRefundPolicy(),
      checkin_reward_points: 0,
      checkin_reward_contribution: 0,
      tickets: [createTicket()],
    };
  }

  function clone(value) {
    return JSON.parse(JSON.stringify(value));
  }

  function toUnixSeconds(value) {
    if (value === '' || value === null || typeof value === 'undefined') return 0;
    if (typeof value === 'number' && Number.isFinite(value)) {
      return Math.floor(value > 9999999999 ? value / 1000 : value);
    }
    var milliseconds = value instanceof Date ? value.getTime() : Date.parse(value);
    return Number.isFinite(milliseconds) ? Math.floor(milliseconds / 1000) : 0;
  }

  function fromUnixSeconds(value) {
    var seconds = Number(value);
    return seconds > 0 ? new Date(seconds * 1000) : '';
  }

  function uniqueTokens(value) {
    var source = Array.isArray(value) ? value : String(value || '').split(/[,，\n]/);
    return source
      .map(function (item) { return String(item).trim(); })
      .filter(function (item, index, values) { return item && values.indexOf(item) === index; });
  }

  function positiveIds(value) {
    return uniqueTokens(value).map(Number).filter(function (item, index, values) {
      return Number.isInteger(item) && item > 0 && values.indexOf(item) === index;
    });
  }

  function money(value) {
    var parsed = Number(value || 0);
    return Number.isFinite(parsed) && parsed >= 0 ? parsed.toFixed(2) : '0.00';
  }

  function serializeEligibility(value) {
    var source = value || {};
    return {
      allowed_channel_ids: positiveIds(source.allowed_channel_ids),
      min_points: Math.max(0, Number(source.min_points) || 0),
      required_roles: uniqueTokens(source.required_roles),
    };
  }

  function serializeRefundPolicy(value) {
    var source = value || {};
    var mode = ['none', 'full_before_deadline', 'partial_before_deadline'].indexOf(source.mode) >= 0
      ? source.mode
      : 'none';
    return {
      mode: mode,
      deadline_time: mode === 'none' ? 0 : toUnixSeconds(source.deadline_time),
      percent: mode === 'none' ? 0 : Math.max(0, Math.min(100, Number(source.percent) || 0)),
      description: String(source.description || '').trim(),
    };
  }

  function serializeTicket(value) {
    var source = value || {};
    return {
      name: String(source.name || '').trim(),
      price: money(source.price),
      integral_price: Math.max(0, Number(source.integral_price) || 0),
      product_id: Math.max(0, Number(source.product_id) || 0),
      product_attr_unique: String(source.product_attr_unique || '').trim(),
      capacity: Math.max(0, Number(source.capacity) || 0),
      min_tier: Math.max(1, Math.min(4, Number(source.min_tier) || 1)),
      eligibility: serializeEligibility(source.eligibility),
      refund_policy: serializeRefundPolicy(source.refund_policy),
      sale_start_time: toUnixSeconds(source.sale_start_time),
      sale_end_time: toUnixSeconds(source.sale_end_time),
      status: Number(source.status) === 0 ? 0 : 1,
      sort: Math.max(0, Number(source.sort) || 0),
    };
  }

  function serializeForm(form) {
    var source = form || {};
    return {
      event_type: source.event_type || 'growth',
      title: String(source.title || '').trim(),
      cover_image: String(source.cover_image || '').trim(),
      summary: String(source.summary || '').trim(),
      detail: String(source.detail || '').trim(),
      tags: uniqueTokens(source.tags_text || source.tags),
      speakers: (source.speakers || []).map(function (speaker) {
        return {
          name: String(speaker.name || '').trim(),
          title: String(speaker.title || '').trim(),
          organization: String(speaker.organization || '').trim(),
          avatar: String(speaker.avatar || '').trim(),
        };
      }),
      start_time: toUnixSeconds(source.start_time),
      end_time: toUnixSeconds(source.end_time),
      signup_start_time: toUnixSeconds(source.signup_start_time),
      signup_end_time: toUnixSeconds(source.signup_end_time),
      location_name: String(source.location_name || '').trim(),
      address: String(source.address || '').trim(),
      longitude: String(source.longitude || '').trim(),
      latitude: String(source.latitude || '').trim(),
      min_tier: Math.max(1, Math.min(4, Number(source.min_tier) || 1)),
      eligibility: serializeEligibility(source.eligibility),
      refund_policy: serializeRefundPolicy(source.refund_policy),
      checkin_reward_points: Math.max(0, Number(source.checkin_reward_points) || 0),
      checkin_reward_contribution: Math.max(0, Number(source.checkin_reward_contribution) || 0),
      tickets: (source.tickets || []).map(serializeTicket),
    };
  }

  function validateForm(form) {
    var payload = serializeForm(form);
    var errors = [];
    if (!payload.title) errors.push('活动标题不能为空');
    if (!payload.start_time || !payload.end_time) errors.push('活动开始和结束时间不能为空');
    if (payload.start_time && payload.end_time && payload.start_time >= payload.end_time) errors.push('活动结束时间必须晚于开始时间');
    if (!payload.signup_start_time || !payload.signup_end_time) errors.push('报名开始和截止时间不能为空');
    if (payload.signup_start_time && payload.signup_end_time && payload.signup_start_time >= payload.signup_end_time) errors.push('报名截止时间必须晚于报名开始时间');
    if (payload.signup_end_time && payload.start_time && payload.signup_end_time > payload.start_time) errors.push('报名截止时间不能晚于活动开始时间');
    if (!payload.tickets.length) errors.push('至少配置一种票档');
    payload.tickets.forEach(function (ticket, index) {
      if (!ticket.name) errors.push('第 ' + (index + 1) + ' 个票档名称不能为空');
      if (!ticket.sale_start_time || !ticket.sale_end_time || ticket.sale_start_time >= ticket.sale_end_time) {
        errors.push('第 ' + (index + 1) + ' 个票档销售时间无效');
      }
      if (ticket.refund_policy.mode !== 'none' && !ticket.refund_policy.deadline_time) {
        errors.push('第 ' + (index + 1) + ' 个票档需设置退款截止时间');
      }
      if (ticket.refund_policy.mode === 'partial_before_deadline' && ticket.refund_policy.percent <= 0) {
        errors.push('第 ' + (index + 1) + ' 个票档需设置有效退款比例');
      }
    });
    return errors;
  }

  function eventToForm(event) {
    var source = event || {};
    var form = createForm();
    form.id = Number(source.id) || 0;
    form.event_no = source.event_no || '';
    form.event_type = source.event_type || source.type || form.event_type;
    form.title = source.title || '';
    form.cover_image = source.cover_image || '';
    form.summary = source.summary || '';
    form.detail = source.detail || '';
    form.tags_text = (source.tags || []).join(', ');
    form.speakers = clone(source.speakers || []);
    form.start_time = fromUnixSeconds(source.start_time || source.start_at);
    form.end_time = fromUnixSeconds(source.end_time || source.end_at);
    form.signup_start_time = fromUnixSeconds(source.signup_start_time);
    form.signup_end_time = fromUnixSeconds(source.signup_end_time);
    var location = source.location || {};
    form.location_name = source.location_name || location.name || '';
    form.address = source.address || location.address || '';
    form.longitude = source.longitude || location.longitude || '';
    form.latitude = source.latitude || location.latitude || '';
    form.min_tier = Number(source.min_tier) || 1;
    form.eligibility = Object.assign(defaultEligibility(), clone(source.eligibility || {}));
    form.refund_policy = Object.assign(defaultRefundPolicy(), clone(source.refund_policy || {}));
    form.refund_policy.deadline_time = fromUnixSeconds(form.refund_policy.deadline_time);
    form.checkin_reward_points = Number(source.checkin_reward_points) || 0;
    form.checkin_reward_contribution = Number(source.checkin_reward_contribution) || 0;
    form.tickets = (source.tickets || []).map(function (ticket) {
      var result = Object.assign(createTicket(), clone(ticket));
      result.sale_start_time = fromUnixSeconds(ticket.sale_start_time);
      result.sale_end_time = fromUnixSeconds(ticket.sale_end_time);
      result.eligibility = Object.assign(defaultEligibility(), clone(ticket.eligibility || {}));
      result.refund_policy = Object.assign(defaultRefundPolicy(), clone(ticket.refund_policy || {}));
      result.refund_policy.deadline_time = fromUnixSeconds(result.refund_policy.deadline_time);
      return result;
    });
    if (!form.tickets.length) form.tickets = [createTicket()];
    return form;
  }

  function statusMeta(status) {
    return STATUS_META[status] || { key: 'unknown', label: '未知', tone: 'info' };
  }

  function can(action, event) {
    var key = statusMeta(event && event.status).key;
    if (action === 'edit' || action === 'publish') return key === 'draft';
    if (action === 'cancel') return ['published', 'registration_closed'].indexOf(key) >= 0;
    if (action === 'checkin') return ['published', 'registration_closed', 'ended'].indexOf(key) >= 0;
    return false;
  }

  function normalizeList(response) {
    var data = response && response.data ? response.data : {};
    return {
      items: Array.isArray(data.items) ? data.items : [],
      page: data.page || { page: 1, limit: 20, total: 0 },
    };
  }

  function generateIdempotencyKey(action, identity) {
    var random = typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
      ? crypto.randomUUID()
      : Date.now().toString(36) + '-' + Math.random().toString(36).slice(2);
    return ['chamber-admin', action, identity || 'new', random].join(':');
  }

  return {
    EVENT_TYPES: EVENT_TYPES,
    STATUS_META: STATUS_META,
    can: can,
    createForm: createForm,
    createTicket: createTicket,
    eventToForm: eventToForm,
    fromUnixSeconds: fromUnixSeconds,
    generateIdempotencyKey: generateIdempotencyKey,
    normalizeList: normalizeList,
    serializeForm: serializeForm,
    statusMeta: statusMeta,
    toUnixSeconds: toUnixSeconds,
    uniqueTokens: uniqueTokens,
    validateForm: validateForm,
  };
});

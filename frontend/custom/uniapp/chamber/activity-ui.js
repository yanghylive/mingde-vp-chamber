(function (root, factory) {
  var api = factory();

  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  } else {
    root.MingdeActivityUi = api;
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

    var EVENT_TYPES = {
      growth: '成长活动',
      industry: '产业活动',
      public_welfare: '公益活动',
    };
    var EVENT_STATUSES = {
      0: { label: '草稿', tone: 'muted' },
      1: { label: '报名中', tone: 'success' },
      2: { label: '报名结束', tone: 'warning' },
      3: { label: '已结束', tone: 'muted' },
      4: { label: '已取消', tone: 'danger' },
    };
    var REGISTRATION_STATUSES = {
      pending_payment: { label: '待支付', tone: 'warning' },
      registered: { label: '报名成功', tone: 'success' },
      cancelled: { label: '已取消', tone: 'muted' },
      refunded: { label: '已退款', tone: 'muted' },
      waitlisted: { label: '候补中', tone: 'warning' },
      completed: { label: '已完成', tone: 'success' },
    };
    var ORDER_STATUS_OVERRIDES = {
      refund_pending: { label: '退款处理中', tone: 'warning' },
      partially_refunded: { label: '已部分退款', tone: 'warning' },
      refunded: { label: '已退款', tone: 'muted' },
    };
    var QUALIFICATION_REASONS = {
      event_not_open: '活动暂未开放',
      signup_not_open: '报名尚未开始',
      signup_closed: '报名已经截止',
      event_started: '活动已经开始',
      event_full: '名额已满',
      membership_tier_required: '当前会籍等级不满足要求',
      membership_verification_required: '完成毕业认证后可报名',
      channel_not_eligible: '当前商会渠道不可报名',
      points_required: '积分不足',
      role_required: '当前会员身份不满足要求',
    };

    function isObject(value) {
      return value !== null && typeof value === 'object' && !Array.isArray(value);
    }

    function cleanText(value) {
      return value === null || value === undefined ? '' : String(value).trim();
    }

    function positiveInteger(value) {
      var parsed = Number(value);
      return Number.isInteger(parsed) && parsed > 0 ? parsed : 0;
    }

    function unwrapData(input) {
      return isObject(input) && isObject(input.data) ? input.data : isObject(input) ? input : {};
    }

    function normalizePage(input) {
      var page = isObject(input) ? input : {};
      return {
        page: Math.max(1, positiveInteger(page.page) || 1),
        limit: Math.max(1, positiveInteger(page.limit) || 20),
        total: Math.max(0, Number(page.total) || 0),
        total_pages: Math.max(0, Number(page.total_pages) || 0),
        has_more: Boolean(page.has_more),
      };
    }

    function normalizeEvent(input) {
      var event = isObject(input) ? input : {};
      var location = isObject(event.location) ? event.location : {};
      var tickets = Array.isArray(event.tickets) ? event.tickets : [];
      return Object.assign({}, event, {
        id: positiveInteger(event.id),
        event_type: cleanText(event.event_type || event.type),
        title: cleanText(event.title) || '未命名活动',
        summary: cleanText(event.summary),
        detail: cleanText(event.detail),
        cover_image: cleanText(event.cover_image),
        tags: Array.isArray(event.tags) ? event.tags.map(cleanText).filter(Boolean) : [],
        speakers: Array.isArray(event.speakers) ? event.speakers.filter(isObject) : [],
        start_time: Math.max(0, Number(event.start_time || event.start_at) || 0),
        end_time: Math.max(0, Number(event.end_time || event.end_at) || 0),
        signup_start_time: Math.max(0, Number(event.signup_start_time) || 0),
        signup_end_time: Math.max(0, Number(event.signup_end_time) || 0),
        location: {
          name: cleanText(location.name),
          address: cleanText(location.address),
          longitude: cleanText(location.longitude),
          latitude: cleanText(location.latitude),
        },
        status: Number(event.status),
        registered: Boolean(event.registered),
        registration_status: cleanText(event.registration_status),
        tickets: tickets.map(normalizeTicket),
      });
    }

    function normalizeTicket(input) {
      var ticket = isObject(input) ? input : {};
      var policy = isObject(ticket.refund_policy) ? ticket.refund_policy : {};
      var remaining = ticket.remaining === null ? null : Math.max(0, Number(ticket.remaining) || 0);
      return Object.assign({}, ticket, {
        id: positiveInteger(ticket.id),
        name: cleanText(ticket.name) || '标准票',
        price: money(ticket.price),
        integral_price: Math.max(0, Number(ticket.integral_price) || 0),
        remaining: remaining,
        eligible: Boolean(ticket.eligible),
        ineligible_reason: cleanText(ticket.ineligible_reason),
        refund_policy: {
          mode: cleanText(policy.mode) || 'none',
          deadline_time: Math.max(0, Number(policy.deadline_time) || 0),
          percent: Math.max(0, Math.min(100, Number(policy.percent) || 0)),
          description: cleanText(policy.description),
        },
      });
    }

    function normalizeEventList(input) {
      var data = unwrapData(input);
      return {
        items: (Array.isArray(data.items) ? data.items : []).map(normalizeEvent),
        page: normalizePage(data.page),
      };
    }

    function normalizeRegistration(input) {
      var registration = unwrapData(input);
      return Object.assign({}, registration, {
        id: positiveInteger(registration.id),
        event_id: positiveInteger(registration.event_id),
        ticket_id: positiveInteger(registration.ticket_id),
        registration_no: cleanText(registration.registration_no),
        status: cleanText(registration.status),
        amount: money(registration.amount),
        integral_amount: Math.max(0, Number(registration.integral_amount) || 0),
        order_no: cleanText(registration.order_no),
        order_status: cleanText(registration.order_status),
        payment_required: Boolean(registration.payment_required),
        checked_in: Boolean(registration.checked_in),
        created_at: Math.max(0, Number(registration.created_at) || 0),
        paid_at: Math.max(0, Number(registration.paid_at) || 0),
      });
    }

    function normalizeRegistrationList(input) {
      var data = unwrapData(input);
      return {
        items: (Array.isArray(data.items) ? data.items : []).map(function (item) {
          return normalizeRegistration({ data: item });
        }),
        page: normalizePage(data.page),
      };
    }

    function money(value) {
      var amount = Number(value);
      return Number.isFinite(amount) && amount >= 0 ? amount.toFixed(2) : '0.00';
    }

    function typeLabel(value) {
      return EVENT_TYPES[cleanText(value)] || '商会活动';
    }

    function eventStatusMeta(value) {
      return EVENT_STATUSES[Number(value)] || { label: '状态未知', tone: 'muted' };
    }

    function registrationStatusMeta(value, orderStatus) {
      var override = ORDER_STATUS_OVERRIDES[cleanText(orderStatus)];
      if (override) return override;
      return REGISTRATION_STATUSES[cleanText(value)] || { label: '状态未知', tone: 'muted' };
    }

    function ticketPriceLabel(ticket) {
      var normalized = normalizeTicket(ticket);
      var parts = [];
      if (Number(normalized.price) > 0) parts.push('¥' + normalized.price);
      if (normalized.integral_price > 0) parts.push(normalized.integral_price + ' 积分');
      return parts.length ? parts.join(' + ') : '免费';
    }

    function ticketActionState(ticket, event) {
      var normalized = normalizeTicket(ticket);
      var current = normalizeEvent(event);
      if (!normalized.id) return { enabled: false, label: '请选择票种', reason: '请选择一个有效票种' };
      if (current.registered || current.registration_status) {
        return { enabled: false, label: registrationStatusMeta(current.registration_status).label, reason: '请在我的活动中查看报名' };
      }
      if (!normalized.eligible) {
        return {
          enabled: false,
          label: '暂不可报名',
          reason: QUALIFICATION_REASONS[normalized.ineligible_reason] || '当前条件不满足报名要求',
        };
      }
      return { enabled: true, label: Number(normalized.price) > 0 ? '确认报名并付款' : '确认报名', reason: '' };
    }

    function createRegistrationPayload(ticket) {
      var normalized = normalizeTicket(ticket);
      if (!normalized.id) throw new Error('请选择有效票种');
      return {
        ticket_id: normalized.id,
        expected_amount: normalized.price,
        expected_integral: normalized.integral_price,
      };
    }

    function paymentPath(orderNo) {
      var normalized = cleanText(orderNo);
      return normalized ? '/pages/goods/cashier/index?order_id=' + encodeURIComponent(normalized) + '&from_type=order' : '';
    }

    function extractCheckinToken(value) {
      var raw = cleanText(value);
      if (!raw) return '';
      var match = /[?&#]token=([^&#]+)/.exec(raw);
      if (match) {
        try {
          raw = decodeURIComponent(match[1]);
        } catch (error) {
          return '';
        }
      }
      return raw.length >= 24 && raw.length <= 256 ? raw : '';
    }

    function refundAvailability(registration, policy, now) {
      var item = normalizeRegistration({ data: registration });
      var refundPolicy = isObject(policy) ? policy : {};
      var eligibleStatus = item.status === 'registered' || item.status === 'completed';
      var deadline = Math.max(0, Number(refundPolicy.deadline_time) || 0);
      var inPolicy = refundPolicy.mode !== 'none' && (!deadline || Number(now) <= deadline);
      return {
        available: false,
        policy_eligible: eligibleStatus && inPolicy,
        reason: eligibleStatus && inPolicy ? '线上退款申请接口尚未开放' : '当前报名不满足退款政策',
      };
    }

    return {
      EVENT_TYPES: EVENT_TYPES,
      QUALIFICATION_REASONS: QUALIFICATION_REASONS,
      normalizeEvent: normalizeEvent,
      normalizeEventList: normalizeEventList,
      normalizeRegistration: normalizeRegistration,
      normalizeRegistrationList: normalizeRegistrationList,
      typeLabel: typeLabel,
      eventStatusMeta: eventStatusMeta,
      registrationStatusMeta: registrationStatusMeta,
      ticketPriceLabel: ticketPriceLabel,
      ticketActionState: ticketActionState,
      createRegistrationPayload: createRegistrationPayload,
      paymentPath: paymentPath,
      extractCheckinToken: extractCheckinToken,
      refundAvailability: refundAvailability,
    };
  },
);

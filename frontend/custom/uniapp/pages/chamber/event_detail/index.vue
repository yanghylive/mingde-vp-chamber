<template>
  <view class="detail-page">
    <view v-if="loading && !event.id" class="state-panel"><text class="state-title">正在读取活动详情</text></view>
    <view v-else-if="loadError && !event.id" class="state-panel">
      <text class="state-title">活动详情读取失败</text>
      <text class="state-message">{{ loadError }}</text>
      <button class="secondary-button" @click="loadEvent">重新加载</button>
    </view>

    <template v-else-if="event.id">
      <image v-if="event.cover_image" class="hero-image" :src="assetUrl(event.cover_image)" mode="aspectFill" />
      <view v-else class="hero-image hero-fallback"><text>{{ eventUi.typeLabel(event.event_type) }}</text></view>

      <view class="event-head">
        <view class="eyebrow-row">
          <text>{{ eventUi.typeLabel(event.event_type) }}</text>
          <text class="status" :class="'tone-' + eventUi.eventStatusMeta(event.status).tone">
            {{ eventUi.eventStatusMeta(event.status).label }}
          </text>
        </view>
        <text class="event-title">{{ event.title }}</text>
        <text v-if="event.summary" class="event-summary">{{ event.summary }}</text>
      </view>

      <view class="facts-section">
        <view class="fact-row">
          <text class="fact-label">时间</text>
          <text class="fact-value">{{ formatRange(event.start_time, event.end_time) }}</text>
        </view>
        <view class="fact-row">
          <text class="fact-label">地点</text>
          <view class="fact-value location-value">
            <text>{{ locationLabel }}</text>
            <button v-if="canOpenMap" class="text-button" @click="openMap">导航</button>
          </view>
        </view>
        <view v-if="event.tags.length" class="tag-row">
          <text v-for="tag in event.tags" :key="tag" class="tag">{{ tag }}</text>
        </view>
      </view>

      <view class="section tickets-section">
        <view class="section-head">
          <text class="section-title">选择票种</text>
          <text v-if="event.registered" class="registration-state">
            {{ eventUi.registrationStatusMeta(event.registration_status).label }}
          </text>
        </view>
        <view v-if="!event.tickets.length" class="empty-inline">票种尚未开放</view>
        <view
          v-for="ticket in event.tickets"
          :key="ticket.id"
          class="ticket-row"
          :class="{ selected: selectedTicketId === ticket.id, disabled: !ticket.eligible || event.registered }"
          @click="selectTicket(ticket)"
        >
          <view class="selection-mark"><text v-if="selectedTicketId === ticket.id">✓</text></view>
          <view class="ticket-main">
            <view class="ticket-head">
              <text class="ticket-name">{{ ticket.name }}</text>
              <text class="ticket-price">{{ eventUi.ticketPriceLabel(ticket) }}</text>
            </view>
            <text class="ticket-meta">
              {{ ticket.remaining === null ? '不限名额' : '剩余 ' + ticket.remaining + ' 个名额' }}
            </text>
            <text v-if="ticketAction(ticket).reason" class="ticket-reason">{{ ticketAction(ticket).reason }}</text>
            <text v-else-if="ticket.refund_policy.description" class="ticket-policy">
              {{ ticket.refund_policy.description }}
            </text>
          </view>
        </view>
      </view>

      <view v-if="event.speakers.length" class="section speakers-section">
        <text class="section-title">分享嘉宾</text>
        <view v-for="(speaker, index) in event.speakers" :key="speaker.name + index" class="speaker-row">
          <image v-if="speaker.avatar" class="speaker-avatar" :src="assetUrl(speaker.avatar)" mode="aspectFill" />
          <view v-else class="speaker-avatar avatar-fallback">{{ speaker.name.slice(0, 1) }}</view>
          <view class="speaker-main">
            <text class="speaker-name">{{ speaker.name }}</text>
            <text class="speaker-title">{{ speakerSubtitle(speaker) }}</text>
          </view>
        </view>
      </view>

      <view v-if="event.detail" class="section content-section">
        <text class="section-title">活动详情</text>
        <rich-text class="event-content" :nodes="event.detail" />
      </view>

      <view class="action-bar">
        <button class="secondary-action" @click="openMyRegistrations">我的活动</button>
        <button
          class="primary-action"
          :loading="registering"
          :disabled="!selectedAction.enabled || registering"
          @click="register"
        >
          {{ selectedAction.label }}
        </button>
      </view>
    </template>
  </view>
</template>

<script>
import { HTTP_REQUEST_URL } from '@/config/app';
import { ensureMemberInitialized } from '@/api/chamber/member.js';
import { createEventRegistration, getEvent } from '@/api/chamber/event.js';
import eventUi from '@/chamber/activity-ui.js';
import memberUi from '@/chamber/shared/member-ui.js';

export default {
  data() {
    return {
      eventUi,
      eventId: 0,
      event: eventUi.normalizeEvent({}),
      selectedTicketId: 0,
      loading: false,
      registering: false,
      loadError: '',
      pendingKey: '',
      pendingFingerprint: '',
      createdRegistration: null,
      loaded: false,
    };
  },
  computed: {
    selectedTicket() {
      return this.event.tickets.find((ticket) => ticket.id === this.selectedTicketId) || null;
    },
    selectedAction() {
      return eventUi.ticketActionState(this.selectedTicket, this.event);
    },
    locationLabel() {
      return this.event.location.name || this.event.location.address || '地点待公布';
    },
    canOpenMap() {
      const latitude = Number(this.event.location.latitude);
      const longitude = Number(this.event.location.longitude);
      return Boolean(this.event.location.latitude && this.event.location.longitude)
        && Number.isFinite(latitude)
        && Number.isFinite(longitude)
        && !(latitude === 0 && longitude === 0);
    },
  },
  onLoad(options) {
    this.eventId = Number(options && options.id);
    this.loadEvent();
  },
  onShow() {
    if (this.loaded && this.createdRegistration) this.loadEvent();
  },
  methods: {
    loadEvent() {
      if (!Number.isInteger(this.eventId) || this.eventId <= 0) {
        this.loadError = '活动编号无效';
        return Promise.resolve();
      }
      this.loading = true;
      this.loadError = '';
      return ensureMemberInitialized()
        .then(() => getEvent(this.eventId))
        .then((response) => {
          this.event = eventUi.normalizeEvent(response.data || {});
          const current = this.event.tickets.find((ticket) => ticket.id === this.selectedTicketId);
          const preferred = current || this.event.tickets.find((ticket) => ticket.eligible) || this.event.tickets[0];
          this.selectedTicketId = preferred ? preferred.id : 0;
          this.loaded = true;
        })
        .catch((error) => {
          this.loadError = this.errorMessage(error, '暂时无法读取活动详情');
        })
        .finally(() => {
          this.loading = false;
        });
    },
    selectTicket(ticket) {
      if (this.event.registered) return;
      this.selectedTicketId = ticket.id;
    },
    ticketAction(ticket) {
      return eventUi.ticketActionState(ticket, this.event);
    },
    speakerSubtitle(speaker) {
      return [speaker.title, speaker.organization].filter(Boolean).join(' · ');
    },
    register() {
      if (this.registering || !this.selectedAction.enabled) return;
      let payload;
      try {
        payload = eventUi.createRegistrationPayload(this.selectedTicket);
      } catch (error) {
        uni.showToast({ title: error.message, icon: 'none' });
        return;
      }
      const fingerprint = memberUi.payloadFingerprint(payload);
      if (!this.pendingKey || this.pendingFingerprint !== fingerprint) {
        this.pendingKey = memberUi.createIdempotencyKey('event-register');
        this.pendingFingerprint = fingerprint;
      }
      this.registering = true;
      createEventRegistration(this.event.id, payload, this.pendingKey)
        .then((response) => {
          const registration = eventUi.normalizeRegistration(response);
          this.createdRegistration = registration;
          this.pendingKey = '';
          this.pendingFingerprint = '';
          if (registration.payment_required) {
            this.confirmPayment(registration);
            return;
          }
          uni.showToast({ title: '报名成功', icon: 'success' });
          setTimeout(() => this.openRegistration(registration.id), 500);
        })
        .catch((error) => {
          uni.showToast({ title: this.errorMessage(error, '报名失败，请稍后重试'), icon: 'none' });
        })
        .finally(() => {
          this.registering = false;
        });
    },
    confirmPayment(registration) {
      const path = eventUi.paymentPath(registration.order_no);
      if (!path) {
        uni.showToast({ title: '报名已创建，请在我的活动查看订单', icon: 'none' });
        return;
      }
      uni.showModal({
        title: '报名已锁定',
        content: '需完成支付后报名才会生效。',
        confirmText: '去付款',
        cancelText: '稍后处理',
        success: (result) => {
          if (result.confirm) uni.navigateTo({ url: path });
          else this.openRegistration(registration.id);
        },
      });
    },
    openRegistration(registrationId) {
      uni.navigateTo({ url: '/pages/chamber/event_registration/index?id=' + Number(registrationId) });
    },
    openMyRegistrations() {
      uni.navigateTo({ url: '/pages/chamber/event_registrations/index' });
    },
    openMap() {
      if (!this.canOpenMap) return;
      uni.openLocation({
        latitude: Number(this.event.location.latitude),
        longitude: Number(this.event.location.longitude),
        name: this.event.location.name,
        address: this.event.location.address,
      });
    },
    assetUrl(value) {
      const path = String(value || '').trim();
      if (!path || /^(?:https?:|data:|wxfile:|blob:)/i.test(path)) return path;
      return String(HTTP_REQUEST_URL).replace(/\/+$/, '') + '/' + path.replace(/^\/+/, '');
    },
    formatRange(start, end) {
      return this.formatDateTime(start) + ' - ' + this.formatDateTime(end);
    },
    formatDateTime(timestamp) {
      if (!timestamp) return '待公布';
      const date = new Date(Number(timestamp) * 1000);
      const pad = (value) => (Number(value) < 10 ? '0' : '') + Number(value);
      return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
    },
    errorMessage(error, fallback) {
      return error && (error.msg || error.message) ? error.msg || error.message : fallback;
    },
  },
};
</script>

<style lang="scss" scoped>
.detail-page { min-height: 100vh; padding-bottom: calc(132rpx + env(safe-area-inset-bottom)); background: #f4f6f5; color: #17211d; }
.hero-image { display: flex; width: 100%; height: 430rpx; align-items: center; justify-content: center; background: #e5ebe8; }
.hero-fallback { background: #dcebe4; color: #176b52; font-size: 34rpx; font-weight: 600; }
.event-head, .facts-section, .section { background: #fff; }
.event-head { padding: 34rpx 32rpx 30rpx; border-bottom: 1rpx solid #dfe5e1; }
.eyebrow-row, .section-head, .ticket-head, .location-value { display: flex; align-items: center; justify-content: space-between; }
.eyebrow-row { color: #65716c; font-size: 24rpx; }
.tone-success { color: #167455; }.tone-warning { color: #9a6510; }.tone-danger { color: #b33a36; }.tone-muted { color: #78837e; }
.event-title { display: block; margin-top: 14rpx; font-size: 42rpx; font-weight: 650; line-height: 1.35; }
.event-summary { display: block; margin-top: 18rpx; color: #64706a; font-size: 27rpx; line-height: 1.7; }
.facts-section { padding: 4rpx 32rpx 24rpx; }
.fact-row { display: flex; padding: 22rpx 0; border-bottom: 1rpx solid #edf0ee; font-size: 26rpx; }
.fact-label { flex: 0 0 96rpx; color: #7a8580; }
.fact-value { min-width: 0; flex: 1; line-height: 1.5; }
.location-value { align-items: flex-start; }
.text-button { height: 52rpx; margin: -10rpx 0 -10rpx 20rpx; padding: 0 8rpx; background: transparent; border: 0; color: #176b52; font-size: 24rpx; line-height: 52rpx; }
.text-button::after { border: 0; }
.tag-row { display: flex; flex-wrap: wrap; gap: 12rpx; padding-top: 22rpx; }
.tag { padding: 7rpx 14rpx; background: #eef3f0; border-radius: 4rpx; color: #52615a; font-size: 22rpx; }
.section { margin-top: 18rpx; padding: 30rpx 32rpx; }
.section-title { font-size: 31rpx; font-weight: 600; }
.registration-state { color: #167455; font-size: 24rpx; }
.ticket-row { display: flex; gap: 20rpx; padding: 26rpx 0; border-bottom: 1rpx solid #e7ece9; }
.ticket-row:last-child { border-bottom: 0; }
.ticket-row.disabled { opacity: .7; }
.selection-mark { display: flex; width: 38rpx; height: 38rpx; margin-top: 2rpx; align-items: center; justify-content: center; border: 2rpx solid #a6b0ab; border-radius: 50%; color: #fff; font-size: 24rpx; }
.ticket-row.selected .selection-mark { background: #176b52; border-color: #176b52; }
.ticket-main { min-width: 0; flex: 1; }
.ticket-name { font-size: 28rpx; font-weight: 600; }
.ticket-price { color: #a55220; font-size: 27rpx; font-weight: 600; }
.ticket-meta, .ticket-policy, .ticket-reason { display: block; margin-top: 10rpx; color: #77827d; font-size: 23rpx; line-height: 1.5; }
.ticket-reason { color: #9a6510; }
.empty-inline { padding: 38rpx 0; color: #77827d; font-size: 25rpx; text-align: center; }
.speaker-row { display: flex; padding: 24rpx 0; align-items: center; border-bottom: 1rpx solid #e7ece9; }
.speaker-row:last-child { border-bottom: 0; }
.speaker-avatar { display: flex; width: 88rpx; height: 88rpx; align-items: center; justify-content: center; border-radius: 50%; background: #dcebe4; color: #176b52; font-size: 30rpx; }
.speaker-main { min-width: 0; margin-left: 22rpx; flex: 1; }
.speaker-name, .speaker-title { display: block; }
.speaker-name { font-size: 28rpx; font-weight: 600; }.speaker-title { margin-top: 8rpx; color: #74807a; font-size: 23rpx; }
.event-content { display: block; margin-top: 24rpx; overflow: hidden; color: #37433d; font-size: 27rpx; line-height: 1.8; }
.action-bar { position: fixed; right: 0; bottom: 0; left: 0; z-index: 10; display: flex; gap: 18rpx; padding: 18rpx 28rpx calc(18rpx + env(safe-area-inset-bottom)); background: #fff; border-top: 1rpx solid #dfe5e1; }
.secondary-action, .primary-action { height: 82rpx; border-radius: 6rpx; font-size: 28rpx; line-height: 82rpx; }
.secondary-action { flex: 0 0 190rpx; background: #fff; border: 1rpx solid #176b52; color: #176b52; }
.primary-action { min-width: 0; flex: 1; background: #176b52; color: #fff; }
.primary-action[disabled] { background: #aeb8b3; color: #fff; opacity: 1; }
.state-panel { display: flex; min-height: 600rpx; padding: 90rpx 42rpx; align-items: center; justify-content: center; flex-direction: column; text-align: center; }
.state-title { font-size: 31rpx; font-weight: 600; }.state-message { margin-top: 16rpx; color: #74807a; font-size: 25rpx; }
.secondary-button { height: 76rpx; margin-top: 30rpx; padding: 0 34rpx; background: #fff; border: 1rpx solid #176b52; border-radius: 6rpx; color: #176b52; font-size: 27rpx; line-height: 74rpx; }
</style>

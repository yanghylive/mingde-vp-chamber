<template>
  <view class="registration-page">
    <view v-if="loading && !registration.id" class="state-panel"><text class="state-title">正在读取报名详情</text></view>
    <view v-else-if="loadError && !registration.id" class="state-panel">
      <text class="state-title">报名详情读取失败</text>
      <text class="state-message">{{ loadError }}</text>
      <button class="secondary-button" @click="loadRegistration">重新加载</button>
    </view>

    <template v-else-if="registration.id">
      <view class="status-band" :class="'tone-' + statusMeta.tone">
        <view>
          <text class="status-caption">报名状态</text>
          <text class="status-title">{{ statusMeta.label }}</text>
        </view>
        <text class="registration-no">{{ registration.registration_no }}</text>
      </view>

      <view class="section event-section" @click="openEvent">
        <text class="section-label">活动</text>
        <view class="event-link">
          <text class="event-title">{{ event.title || '活动 #' + registration.event_id }}</text>
          <text class="arrow">›</text>
        </view>
        <text v-if="event.id" class="event-meta">{{ formatRange(event.start_time, event.end_time) }}</text>
      </view>

      <view class="section">
        <text class="section-title">报名信息</text>
        <view class="detail-row"><text class="detail-label">票种</text><text>{{ ticketName }}</text></view>
        <view class="detail-row"><text class="detail-label">费用</text><text>{{ costLabel }}</text></view>
        <view class="detail-row"><text class="detail-label">提交时间</text><text>{{ formatDateTime(registration.created_at) }}</text></view>
        <view v-if="registration.paid_at" class="detail-row"><text class="detail-label">支付时间</text><text>{{ formatDateTime(registration.paid_at) }}</text></view>
        <view v-if="registration.order_no" class="detail-row"><text class="detail-label">订单号</text><text class="order-no">{{ registration.order_no }}</text></view>
        <view class="detail-row"><text class="detail-label">签到</text><text>{{ registration.checked_in ? '已签到' : '未签到' }}</text></view>
      </view>

      <view v-if="refundPolicy" class="section">
        <text class="section-title">退款政策</text>
        <text class="policy-description">{{ refundDescription }}</text>
        <text v-if="refundPolicy.deadline_time" class="policy-deadline">
          申请截止：{{ formatDateTime(refundPolicy.deadline_time) }}
        </text>
        <button v-if="refundState.policy_eligible" class="disabled-action" disabled>
          线上退款暂未开放
        </button>
      </view>

      <view class="section operation-section">
        <text class="section-title">可用操作</text>
        <view class="operation-actions">
          <button v-if="registration.payment_required" class="primary-button" @click="pay">继续付款</button>
          <button v-if="canCheckin" class="primary-button" :loading="checkingIn" :disabled="checkingIn" @click="scanCheckin">
            扫码签到
          </button>
          <text v-if="!registration.payment_required && !canCheckin" class="operation-note">
            {{ operationMessage }}
          </text>
        </view>
      </view>
    </template>
  </view>
</template>

<script>
import { ensureMemberInitialized } from '@/api/chamber/member.js';
import { createEventCheckin, getEvent, getMyEventRegistration } from '@/api/chamber/event.js';
import eventUi from '@/chamber/activity-ui.js';
import memberUi from '@/chamber/shared/member-ui.js';

export default {
  data() {
    return {
      eventUi,
      registrationId: 0,
      registration: eventUi.normalizeRegistration({}),
      event: eventUi.normalizeEvent({}),
      loading: false,
      checkingIn: false,
      loadError: '',
      loaded: false,
    };
  },
  computed: {
    statusMeta() {
      return eventUi.registrationStatusMeta(this.registration.status, this.registration.order_status);
    },
    ticket() {
      return this.event.tickets.find((item) => item.id === this.registration.ticket_id) || null;
    },
    ticketName() {
      return this.ticket ? this.ticket.name : '票种 #' + this.registration.ticket_id;
    },
    costLabel() {
      const parts = [];
      if (Number(this.registration.amount) > 0) parts.push('¥' + this.registration.amount);
      if (this.registration.integral_amount > 0) parts.push(this.registration.integral_amount + ' 积分');
      return parts.length ? parts.join(' + ') : '免费';
    },
    refundPolicy() {
      if (this.ticket && this.ticket.refund_policy) return this.ticket.refund_policy;
      return this.event.refund_policy || null;
    },
    refundState() {
      return eventUi.refundAvailability(this.registration, this.refundPolicy, Math.floor(Date.now() / 1000));
    },
    refundDescription() {
      if (!this.refundPolicy || this.refundPolicy.mode === 'none') return '该票种不支持退款';
      if (this.refundPolicy.description) return this.refundPolicy.description;
      return this.refundPolicy.mode === 'full_before_deadline'
        ? '截止时间前可申请全额退款'
        : '截止时间前可按活动政策申请部分退款';
    },
    canCheckin() {
      return this.registration.status === 'registered' && !this.registration.checked_in;
    },
    operationMessage() {
      if (this.registration.checked_in) return '已完成签到';
      if (this.registration.status === 'pending_payment') return '完成支付后可查看后续操作';
      if (this.registration.status === 'completed') return '本次活动已完成';
      return '当前没有可执行操作';
    },
  },
  onLoad(options) {
    this.registrationId = Number(options && options.id);
  },
  onShow() {
    this.loadRegistration();
  },
  methods: {
    loadRegistration() {
      if (!Number.isInteger(this.registrationId) || this.registrationId <= 0) {
        this.loadError = '报名编号无效';
        return Promise.resolve();
      }
      if (this.loading) return Promise.resolve();
      this.loading = true;
      this.loadError = '';
      return ensureMemberInitialized()
        .then(() => getMyEventRegistration(this.registrationId))
        .then((response) => {
          this.registration = eventUi.normalizeRegistration(response);
          return getEvent(this.registration.event_id);
        })
        .then((response) => {
          this.event = eventUi.normalizeEvent(response.data || {});
          this.loaded = true;
        })
        .catch((error) => {
          if (this.registration.id && error && error.status === 404) {
            this.loaded = true;
            return;
          }
          this.loadError = this.errorMessage(error, '暂时无法读取报名详情');
        })
        .finally(() => {
          this.loading = false;
        });
    },
    pay() {
      const path = eventUi.paymentPath(this.registration.order_no);
      if (!path) {
        uni.showToast({ title: '订单号不可用，请刷新后重试', icon: 'none' });
        return;
      }
      uni.navigateTo({ url: path });
    },
    scanCheckin() {
      if (this.checkingIn) return;
      // #ifdef H5
      if (!this.$wechat || typeof this.$wechat.wechatEvevt !== 'function') {
        uni.showToast({ title: '当前环境无法扫码，请在微信中打开', icon: 'none' });
        return;
      }
      this.$wechat
        .wechatEvevt('scanQRCode', { needResult: 1, scanType: ['qrCode'] })
        .then((result) => this.handleScanResult(result.resultStr || result.path))
        .catch((error) => {
          if (!error || !/cancel/i.test(error.errMsg || error.message || '')) {
            uni.showToast({ title: '无法读取签到二维码', icon: 'none' });
          }
        });
      return;
      // #endif
      // #ifdef MP || APP-PLUS
      uni.scanCode({
        onlyFromCamera: true,
        success: (result) => this.handleScanResult(result.result || result.path),
        fail: (error) => {
          if (!error || !/cancel/i.test(error.errMsg || '')) uni.showToast({ title: '无法读取签到二维码', icon: 'none' });
        },
      });
      // #endif
    },
    handleScanResult(value) {
      const token = eventUi.extractCheckinToken(value);
      if (!token) {
        uni.showToast({ title: '签到二维码无效', icon: 'none' });
        return;
      }
      this.submitCheckin(token);
    },
    submitCheckin(token) {
      this.checkingIn = true;
      const key = memberUi.createIdempotencyKey('event-checkin');
      createEventCheckin(this.registration.event_id, { token, registration_id: this.registration.id }, key)
        .then(() => {
          uni.showToast({ title: '签到成功', icon: 'success' });
          return this.loadRegistration();
        })
        .catch((error) => {
          uni.showToast({ title: this.errorMessage(error, '签到失败，请稍后重试'), icon: 'none' });
        })
        .finally(() => {
          this.checkingIn = false;
        });
    },
    openEvent() {
      uni.navigateTo({ url: '/pages/chamber/event_detail/index?id=' + this.registration.event_id });
    },
    formatRange(start, end) {
      return this.formatDateTime(start) + ' - ' + this.formatDateTime(end);
    },
    formatDateTime(timestamp) {
      if (!timestamp) return '-';
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
.registration-page { min-height: 100vh; padding-bottom: 30rpx; background: #f4f6f5; color: #17211d; }
.status-band { display: flex; min-height: 190rpx; padding: 34rpx 32rpx; align-items: center; justify-content: space-between; background: #fff; border-bottom: 6rpx solid #d5ddd9; }
.status-band.tone-success { border-color: #167455; }.status-band.tone-warning { border-color: #b98324; }.status-band.tone-danger { border-color: #b33a36; }.status-band.tone-muted { border-color: #87918c; }
.status-caption, .status-title { display: block; }.status-caption { color: #77827d; font-size: 24rpx; }.status-title { margin-top: 9rpx; font-size: 40rpx; font-weight: 650; }
.registration-no { max-width: 360rpx; color: #77827d; font-size: 22rpx; text-align: right; word-break: break-all; }
.section { margin-top: 18rpx; padding: 30rpx 32rpx; background: #fff; }
.section-title, .section-label { display: block; font-size: 30rpx; font-weight: 600; }.section-label { color: #77827d; font-size: 23rpx; font-weight: 400; }
.event-link { display: flex; margin-top: 12rpx; align-items: center; justify-content: space-between; }.event-title { min-width: 0; flex: 1; font-size: 31rpx; font-weight: 600; line-height: 1.45; }.arrow { color: #8a948f; font-size: 38rpx; }
.event-meta { display: block; margin-top: 10rpx; color: #707b76; font-size: 23rpx; }
.detail-row { display: flex; min-height: 84rpx; align-items: center; justify-content: space-between; gap: 28rpx; border-bottom: 1rpx solid #e9edea; font-size: 25rpx; text-align: right; }
.detail-row:last-child { border-bottom: 0; }.detail-label { flex: 0 0 130rpx; color: #77827d; text-align: left; }.order-no { max-width: 520rpx; word-break: break-all; }
.policy-description, .policy-deadline { display: block; margin-top: 18rpx; color: #5e6a64; font-size: 25rpx; line-height: 1.65; }.policy-deadline { color: #8b641f; font-size: 23rpx; }
.disabled-action { height: 72rpx; margin-top: 24rpx; background: #edf0ee; border: 0; border-radius: 6rpx; color: #7a8580; font-size: 25rpx; line-height: 72rpx; opacity: 1; }
.operation-actions { margin-top: 24rpx; }.primary-button { height: 82rpx; background: #176b52; border-radius: 6rpx; color: #fff; font-size: 28rpx; line-height: 82rpx; }.operation-note { display: block; padding: 22rpx 0; color: #77827d; font-size: 25rpx; text-align: center; }
.state-panel { display: flex; min-height: 600rpx; padding: 90rpx 42rpx; align-items: center; justify-content: center; flex-direction: column; text-align: center; }
.state-title { font-size: 31rpx; font-weight: 600; }.state-message { margin-top: 16rpx; color: #74807a; font-size: 25rpx; }
.secondary-button { height: 76rpx; margin-top: 30rpx; padding: 0 34rpx; background: #fff; border: 1rpx solid #176b52; border-radius: 6rpx; color: #176b52; font-size: 27rpx; line-height: 74rpx; }
</style>

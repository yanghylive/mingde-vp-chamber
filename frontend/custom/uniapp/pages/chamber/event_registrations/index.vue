<template>
  <view class="registrations-page">
    <scroll-view class="status-tabs" scroll-x>
      <view class="status-tabs-inner">
        <button
          v-for="item in statusOptions"
          :key="item.value"
          class="status-tab"
          :class="{ active: filters.status === item.value }"
          @click="changeStatus(item.value)"
        >{{ item.label }}</button>
      </view>
    </scroll-view>

    <view v-if="loading && !items.length" class="state-panel"><text class="state-title">正在读取报名记录</text></view>
    <view v-else-if="loadError && !items.length" class="state-panel">
      <text class="state-title">报名记录读取失败</text>
      <text class="state-message">{{ loadError }}</text>
      <button class="secondary-button" @click="reload">重新加载</button>
    </view>
    <view v-else-if="!items.length" class="state-panel">
      <text class="state-title">暂无报名记录</text>
      <button class="secondary-button" @click="openEvents">浏览活动</button>
    </view>

    <view v-else class="registration-list">
      <view v-for="item in items" :key="item.id" class="registration-row">
        <view class="row-head" @click="openRegistration(item.id)">
          <view class="row-title-wrap">
            <text class="event-title">{{ eventTitle(item.event_id) }}</text>
            <text class="registration-no">{{ item.registration_no }}</text>
          </view>
          <text class="status" :class="'tone-' + eventUi.registrationStatusMeta(item.status, item.order_status).tone">
            {{ eventUi.registrationStatusMeta(item.status, item.order_status).label }}
          </text>
        </view>
        <view class="row-facts" @click="openRegistration(item.id)">
          <text>{{ costLabel(item) }}</text>
          <text>{{ formatDateTime(item.created_at) }}</text>
        </view>
        <view class="row-actions">
          <button class="text-action" @click="openEvent(item.event_id)">查看活动</button>
          <button class="text-action" @click="openRegistration(item.id)">报名详情</button>
          <button v-if="item.payment_required" class="pay-action" @click="pay(item)">继续付款</button>
        </view>
      </view>
      <view class="load-more">
        <text v-if="loading">正在加载</text>
        <text v-else-if="page.has_more">上拉加载更多</text>
        <text v-else>已显示全部记录</text>
      </view>
    </view>
  </view>
</template>

<script>
import { ensureMemberInitialized } from '@/api/chamber/member.js';
import { getEvent, getMyEventRegistrations } from '@/api/chamber/event.js';
import eventUi from '@/chamber/activity-ui.js';

export default {
  data() {
    return {
      eventUi,
      statusOptions: [
        { value: '', label: '全部' },
        { value: 'pending_payment', label: '待支付' },
        { value: 'registered', label: '已报名' },
        { value: 'completed', label: '已完成' },
        { value: 'refunded', label: '已退款' },
        { value: 'cancelled', label: '已取消' },
      ],
      filters: { status: '', page: 1, limit: 10 },
      items: [],
      eventsById: {},
      page: { page: 1, limit: 10, total: 0, total_pages: 0, has_more: false },
      loading: false,
      reloadQueued: false,
      loadError: '',
      loaded: false,
    };
  },
  onShow() {
    this.reload();
  },
  onPullDownRefresh() {
    this.reload().finally(() => uni.stopPullDownRefresh());
  },
  onReachBottom() {
    if (this.page.has_more) this.load(this.page.page + 1);
  },
  methods: {
    reload() {
      this.filters.page = 1;
      return this.load(1, true);
    },
    load(page, replace) {
      if (this.loading) {
        if (replace) this.reloadQueued = true;
        return Promise.resolve();
      }
      this.loading = true;
      this.loadError = '';
      const query = Object.assign({}, this.filters, { page });
      return ensureMemberInitialized()
        .then(() => getMyEventRegistrations(query))
        .then((response) => {
          const result = eventUi.normalizeRegistrationList(response);
          this.items = replace ? result.items : this.items.concat(result.items);
          this.page = result.page;
          this.filters.page = result.page.page;
          this.loaded = true;
          return this.hydrateEvents(result.items);
        })
        .catch((error) => {
          this.loadError = this.errorMessage(error, '暂时无法读取报名记录');
        })
        .finally(() => {
          this.loading = false;
          if (this.reloadQueued) {
            this.reloadQueued = false;
            this.reload();
          }
        });
    },
    hydrateEvents(items) {
      const ids = Array.from(new Set(items.map((item) => item.event_id))).filter((id) => id && !this.eventsById[id]);
      return Promise.all(
        ids.map((id) =>
          getEvent(id)
            .then((response) => {
              this.$set(this.eventsById, id, eventUi.normalizeEvent(response.data || {}));
            })
            .catch(() => null),
        ),
      );
    },
    changeStatus(value) {
      if (this.filters.status === value) return;
      this.filters.status = value;
      this.reload();
    },
    eventTitle(eventId) {
      return this.eventsById[eventId] ? this.eventsById[eventId].title : '活动 #' + eventId;
    },
    costLabel(item) {
      const parts = [];
      if (Number(item.amount) > 0) parts.push('¥' + item.amount);
      if (item.integral_amount > 0) parts.push(item.integral_amount + ' 积分');
      return parts.length ? parts.join(' + ') : '免费报名';
    },
    pay(item) {
      const path = eventUi.paymentPath(item.order_no);
      if (!path) {
        uni.showToast({ title: '订单号不可用，请刷新后重试', icon: 'none' });
        return;
      }
      uni.navigateTo({ url: path });
    },
    openEvents() {
      uni.navigateTo({ url: '/pages/chamber/events/index' });
    },
    openEvent(eventId) {
      uni.navigateTo({ url: '/pages/chamber/event_detail/index?id=' + Number(eventId) });
    },
    openRegistration(registrationId) {
      uni.navigateTo({ url: '/pages/chamber/event_registration/index?id=' + Number(registrationId) });
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
.registrations-page { min-height: 100vh; background: #f4f6f5; color: #17211d; }
.status-tabs { position: sticky; top: 0; z-index: 5; width: 100%; background: #fff; border-bottom: 1rpx solid #dfe5e1; white-space: nowrap; }
.status-tabs-inner { display: flex; padding: 0 18rpx; }
.status-tab { flex: 0 0 auto; height: 84rpx; margin: 0 8rpx; padding: 0 16rpx; background: transparent; border: 0; border-radius: 0; color: #68736e; font-size: 26rpx; line-height: 84rpx; }
.status-tab::after { border: 0; }.status-tab.active { color: #176b52; border-bottom: 5rpx solid #176b52; font-weight: 600; }
.registration-list { background: #fff; }
.registration-row { padding: 28rpx 30rpx 20rpx; border-bottom: 16rpx solid #f4f6f5; }
.row-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 24rpx; }
.row-title-wrap { min-width: 0; flex: 1; }.event-title, .registration-no { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.event-title { font-size: 30rpx; font-weight: 600; }.registration-no { margin-top: 9rpx; color: #818b86; font-size: 22rpx; }
.status { flex: 0 0 auto; font-size: 24rpx; }.tone-success { color: #167455; }.tone-warning { color: #9a6510; }.tone-danger { color: #b33a36; }.tone-muted { color: #78837e; }
.row-facts { display: flex; margin-top: 24rpx; padding: 20rpx 0; justify-content: space-between; border-top: 1rpx solid #edf0ee; color: #65716c; font-size: 24rpx; }
.row-actions { display: flex; min-height: 66rpx; padding-top: 8rpx; align-items: center; justify-content: flex-end; gap: 12rpx; }
.text-action, .pay-action { height: 62rpx; margin: 0; padding: 0 20rpx; border-radius: 6rpx; font-size: 24rpx; line-height: 60rpx; }
.text-action { background: #fff; border: 1rpx solid #cad2ce; color: #4e5d56; }.pay-action { background: #176b52; border: 1rpx solid #176b52; color: #fff; }
.state-panel { display: flex; min-height: 500rpx; padding: 80rpx 42rpx; align-items: center; justify-content: center; flex-direction: column; text-align: center; }
.state-title { font-size: 31rpx; font-weight: 600; }.state-message { margin-top: 16rpx; color: #74807a; font-size: 25rpx; }
.secondary-button { height: 76rpx; margin-top: 30rpx; padding: 0 34rpx; background: #fff; border: 1rpx solid #176b52; border-radius: 6rpx; color: #176b52; font-size: 27rpx; line-height: 74rpx; }
.load-more { height: 104rpx; color: #89938e; font-size: 23rpx; line-height: 104rpx; text-align: center; }
</style>

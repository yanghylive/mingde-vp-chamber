<template>
  <view class="events-page">
    <scroll-view class="type-tabs" scroll-x>
      <view class="type-tabs-inner">
        <button
          v-for="item in typeOptions"
          :key="item.value"
          class="type-tab"
          :class="{ active: filters.event_type === item.value }"
          @click="changeType(item.value)"
        >
          {{ item.label }}
        </button>
      </view>
    </scroll-view>

    <view v-if="loading && !items.length" class="state-panel">
      <text class="state-title">正在读取活动</text>
    </view>
    <view v-else-if="loadError && !items.length" class="state-panel">
      <text class="state-title">活动读取失败</text>
      <text class="state-message">{{ loadError }}</text>
      <button class="secondary-button" @click="reload">重新加载</button>
    </view>
    <view v-else-if="!items.length" class="state-panel">
      <text class="state-title">暂无可报名活动</text>
      <text class="state-message">新活动发布后会显示在这里</text>
    </view>

    <view v-else class="event-list">
      <view v-for="event in items" :key="event.id" class="event-row" @click="openEvent(event.id)">
        <image v-if="event.cover_image" class="event-cover" :src="assetUrl(event.cover_image)" mode="aspectFill" />
        <view v-else class="event-cover cover-fallback">
          <text>{{ eventUi.typeLabel(event.event_type) }}</text>
        </view>
        <view class="event-main">
          <view class="event-meta">
            <text class="event-type">{{ eventUi.typeLabel(event.event_type) }}</text>
            <text class="status" :class="'tone-' + eventUi.eventStatusMeta(event.status).tone">
              {{ eventUi.eventStatusMeta(event.status).label }}
            </text>
          </view>
          <text class="event-title">{{ event.title }}</text>
          <text class="event-time">{{ formatRange(event.start_time, event.end_time) }}</text>
          <text class="event-location">{{ locationLabel(event) }}</text>
          <view class="event-foot">
            <text v-if="event.registered" class="registered">
              {{ eventUi.registrationStatusMeta(event.registration_status).label }}
            </text>
            <text v-else class="price">{{ lowestPrice(event) }}</text>
            <text class="arrow">›</text>
          </view>
        </view>
      </view>
      <view class="load-more">
        <text v-if="loading">正在加载</text>
        <text v-else-if="page.has_more">上拉加载更多</text>
        <text v-else>已显示全部活动</text>
      </view>
    </view>
  </view>
</template>

<script>
import { HTTP_REQUEST_URL } from '@/config/app';
import { ensureMemberInitialized } from '@/api/chamber/member.js';
import { getEvents } from '@/api/chamber/event.js';
import eventUi from '@/chamber/activity-ui.js';

export default {
  data() {
    return {
      eventUi,
      typeOptions: [
        { value: '', label: '全部' },
        { value: 'growth', label: '成长' },
        { value: 'industry', label: '产业' },
        { value: 'public_welfare', label: '公益' },
      ],
      filters: { event_type: '', page: 1, limit: 10 },
      items: [],
      page: { page: 1, limit: 10, total: 0, total_pages: 0, has_more: false },
      loading: false,
      reloadQueued: false,
      loadError: '',
    };
  },
  onLoad() {
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
        .then(() => getEvents(query))
        .then((response) => {
          const result = eventUi.normalizeEventList(response);
          this.items = replace ? result.items : this.items.concat(result.items);
          this.page = result.page;
          this.filters.page = result.page.page;
        })
        .catch((error) => {
          this.loadError = this.errorMessage(error, '暂时无法读取活动');
        })
        .finally(() => {
          this.loading = false;
          if (this.reloadQueued) {
            this.reloadQueued = false;
            this.reload();
          }
        });
    },
    changeType(value) {
      if (this.filters.event_type === value) return;
      this.filters.event_type = value;
      this.reload();
    },
    openEvent(eventId) {
      uni.navigateTo({ url: '/pages/chamber/event_detail/index?id=' + Number(eventId) });
    },
    assetUrl(value) {
      const path = String(value || '').trim();
      if (!path || /^(?:https?:|data:|wxfile:|blob:)/i.test(path)) return path;
      return String(HTTP_REQUEST_URL).replace(/\/+$/, '') + '/' + path.replace(/^\/+/, '');
    },
    locationLabel(event) {
      const location = event.location || {};
      return location.name || location.address || '地点待公布';
    },
    lowestPrice(event) {
      const tickets = Array.isArray(event.tickets) ? event.tickets : [];
      if (!tickets.length) return '票种待公布';
      const ordered = tickets.slice().sort((left, right) => {
        const leftValue = Number(left.price) * 100000 + Number(left.integral_price);
        const rightValue = Number(right.price) * 100000 + Number(right.integral_price);
        return leftValue - rightValue;
      });
      return eventUi.ticketPriceLabel(ordered[0]) + ' 起';
    },
    formatRange(start, end) {
      const startDate = this.formatDate(start);
      const endDate = this.formatDate(end);
      return startDate === endDate ? startDate : startDate + ' - ' + endDate;
    },
    formatDate(timestamp) {
      if (!timestamp) return '时间待公布';
      const date = new Date(Number(timestamp) * 1000);
      const pad = (value) => (Number(value) < 10 ? '0' : '') + Number(value);
      return `${date.getMonth() + 1}月${date.getDate()}日 ${pad(date.getHours())}:${pad(date.getMinutes())}`;
    },
    errorMessage(error, fallback) {
      return error && (error.msg || error.message) ? error.msg || error.message : fallback;
    },
  },
};
</script>

<style lang="scss" scoped>
.events-page { min-height: 100vh; background: #f4f6f5; color: #17211d; }
.type-tabs { position: sticky; top: 0; z-index: 5; width: 100%; background: #fff; border-bottom: 1rpx solid #dfe5e1; white-space: nowrap; }
.type-tabs-inner { display: flex; min-width: 100%; padding: 0 20rpx; }
.type-tab { flex: 0 0 auto; height: 84rpx; margin: 0 10rpx; padding: 0 18rpx; background: transparent; border: 0; border-radius: 0; color: #68736e; font-size: 27rpx; line-height: 84rpx; }
.type-tab::after { border: 0; }
.type-tab.active { color: #176b52; border-bottom: 5rpx solid #176b52; font-weight: 600; }
.event-list { background: #fff; }
.event-row { display: flex; gap: 24rpx; min-height: 244rpx; padding: 28rpx 30rpx; border-bottom: 1rpx solid #e4e9e6; }
.event-cover { flex: 0 0 218rpx; width: 218rpx; height: 164rpx; border-radius: 6rpx; background: #e9eeeb; }
.cover-fallback { display: flex; align-items: center; justify-content: center; background: #dcebe4; color: #176b52; font-size: 25rpx; }
.event-main { min-width: 0; flex: 1; }
.event-meta, .event-foot { display: flex; align-items: center; justify-content: space-between; }
.event-type { color: #65716c; font-size: 23rpx; }
.status, .registered { font-size: 23rpx; }
.tone-success, .registered { color: #167455; }
.tone-warning { color: #9a6510; }
.tone-danger { color: #b33a36; }
.tone-muted { color: #7a8580; }
.event-title { display: -webkit-box; overflow: hidden; margin-top: 9rpx; font-size: 31rpx; font-weight: 600; line-height: 1.38; -webkit-box-orient: vertical; -webkit-line-clamp: 2; }
.event-time, .event-location { display: block; overflow: hidden; margin-top: 9rpx; color: #68736e; font-size: 23rpx; line-height: 1.35; text-overflow: ellipsis; white-space: nowrap; }
.event-foot { margin-top: 12rpx; }
.price { color: #a55220; font-size: 25rpx; font-weight: 600; }
.arrow { color: #909b96; font-size: 34rpx; line-height: 1; }
.state-panel { display: flex; min-height: 420rpx; padding: 80rpx 42rpx; align-items: center; justify-content: center; flex-direction: column; text-align: center; }
.state-title { font-size: 31rpx; font-weight: 600; }
.state-message { margin-top: 16rpx; color: #74807a; font-size: 25rpx; }
.secondary-button { height: 76rpx; margin-top: 30rpx; padding: 0 34rpx; background: #fff; border: 1rpx solid #176b52; border-radius: 6rpx; color: #176b52; font-size: 27rpx; line-height: 74rpx; }
.load-more { height: 104rpx; color: #89938e; font-size: 23rpx; line-height: 104rpx; text-align: center; }
</style>

<template>
  <view class="appointments-page">
    <page-header title="我的预约" eyebrow="大咖档期预约记录" />
    <view v-if="loading" class="empty">加载中…</view>
    <view v-else-if="list.length === 0" class="empty">暂无预约记录</view>
    <view v-else class="card list">
      <view v-for="(item, idx) in list" :key="item.id" class="{{'entry' + (idx > 0 ? ' entry-bd' : '')}}">
        <view class="en-top">
          <view class="en-expert">
            <text class="en-avatar">{{ (item.expert_name || '?').charAt(0) }}</text>
            <view class="en-expert-info">
              <text class="en-name">{{ item.expert_name || '大咖' }}</text>
              <text class="en-mode">{{ item.mode === 'offline' ? '线下' : '线上' }} · {{ timeText(item.start_time) }}</text>
            </view>
          </view>
          <text class="{{'en-status ' + (item.status === 'confirmed' ? 'st-ok' : 'st-cancel')}}">{{ statusText(item.status) }}</text>
        </view>
        <view class="en-bottom">
          <text class="en-points">{{ item.points_cost }} 积分</text>
          <button v-if="item.status === 'confirmed'" class="en-cancel" size="mini" @tap="cancel(item)">取消预约</button>
        </view>
      </view>
    </view>
  </view>
</template>

<script>
import PageHeader from '@/components/PageHeader.vue'
import chamber from '@/api/chamber'
import { toDate } from '@/common/format'

export default {
  components: { PageHeader },
  data() {
    return {
      list: [],
      loading: true
    }
  },
  onShow() {
    this.load()
  },
  methods: {
    load() {
      this.loading = true
      chamber
        .myAppointments()
        .then((items) => {
          this.list = items || []
        })
        .catch(() => {})
        .finally(() => {
          this.loading = false
        })
    },
    timeText(ts) {
      return ts ? toDate(ts) : ''
    },
    statusText(s) {
      return s === 'confirmed' ? '已确认' : '已取消'
    },
    cancel(item) {
      uni.showModal({
        title: '取消预约',
        content: '取消后将退还 ' + item.points_cost + ' 积分，档期将重新开放。确定取消吗？',
        confirmText: '确认取消',
        cancelText: '再想想',
        success: (res) => {
          if (!res.confirm) return
          chamber
            .cancelAppointment(item.id)
            .then(() => {
              uni.showToast({ title: '已取消，积分已退还', icon: 'success' })
              this.load()
            })
            .catch((e) => {
              uni.showToast({ title: (e && e.msg) || '取消失败', icon: 'none' })
            })
        }
      })
    }
  }
}
</script>

<style lang="scss">
.appointments-page {
  min-height: 100vh;
  background: #f7f6f3;
  padding-bottom: 60rpx;
}
.empty {
  text-align: center;
  color: #c0c6d0;
  font-size: 26rpx;
  padding: 120rpx 0;
}
.card {
  margin: 24rpx;
  background: #ffffff;
  border-radius: 20rpx;
  padding: 8rpx 28rpx;
}
.entry {
  padding: 24rpx 0;
}
.entry-bd {
  border-top: 1rpx solid #f0efe9;
}
.en-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.en-expert {
  display: flex;
  align-items: center;
}
.en-avatar {
  width: 64rpx;
  height: 64rpx;
  border-radius: 50%;
  background: #1c1a17;
  color: #e7c77f;
  font-size: 28rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-right: 20rpx;
}
.en-name {
  font-size: 28rpx;
  color: #23211d;
  font-weight: 500;
  display: block;
}
.en-mode {
  font-size: 22rpx;
  color: #9aa0ab;
  margin-top: 6rpx;
  display: block;
}
.en-status {
  font-size: 22rpx;
  padding: 6rpx 16rpx;
  border-radius: 20rpx;
}
.st-ok {
  color: #1d9e75;
  background: #e1f5ee;
}
.st-cancel {
  color: #9aa0ab;
  background: #f1efe8;
}
.en-bottom {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 16rpx;
}
.en-points {
  font-size: 24rpx;
  color: #b8860b;
}
.en-cancel {
  margin: 0;
  font-size: 24rpx;
  color: #e24b4a;
  background: #fcebeb;
  border-radius: 24rpx;
  line-height: 2.2;
}
</style>

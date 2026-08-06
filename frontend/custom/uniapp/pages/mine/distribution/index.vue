<template>
  <view class="distribution-page">
    <page-header title="我的分销码" eyebrow="推荐新会员注册，共赢积分" />
    <view class="code-card glass-dark">
      <view class="dc-label">我的分销码</view>
      <view class="dc-code">{{ code || (loading ? '···' : '——') }}</view>
      <view class="dc-hint">{{ code ? '分享该码，好友注册时填写即可绑定推荐关系' : '分销码生成中，请稍后再试' }}</view>
      <view class="dc-copy {{!code || loading ? 'dc-copy-disabled' : ''}}" @tap="copyCode">
        {{ copied ? '已复制 OK' : '复制分销码' }}
      </view>
    </view>

    <view class="stats card">
      <view class="stat">
        <text class="stat-num">{{ info.invite_count || 0 }}</text>
        <text class="stat-label">推荐人数</text>
      </view>
      <view class="stat">
        <text class="stat-num">{{ info.points_earned || 0 }}</text>
        <text class="stat-label">已得积分</text>
      </view>
    </view>
    <!-- 推荐记录（对齐 H5） -->
    <view class="sec-head">
      <view class="sh-row">
        <view class="sh-icon"><image class="ic ic-md" src="/static/icons/ic-users-white.png" mode="aspectFit" /></view>
        <view>
          <text class="sh-title">推荐记录</text>
          <text class="sh-sub">每一份邀请，都被记住</text>
        </view>
      </view>
    </view>
    <view v-if="recordsLoading" class="empty">记录加载中…</view>
    <view v-else-if="records.length === 0" class="empty">暂无推荐记录</view>
    <view v-else class="card records">
      <view v-for="(r, i) in records" :key="i" class="record">
        <view class="rc-avatar">{{ (r.nickname || r.real_name || '友').slice(0, 1) }}</view>
        <view class="rc-info">
          <text class="rc-name">{{ r.nickname || r.real_name || '明德会员' }}</text>
          <text class="rc-time">{{ r.created_at ? toDateStr(r.created_at) : '' }}</text>
        </view>
        <text class="{{'rc-status' + (r.status === 'accepted' || r.rewarded ? ' rc-ok' : '')}}">{{ r.status === 'accepted' || r.rewarded ? '已到账' : '待确认' }}</text>
      </view>
    </view>

  </view>
</template>

<script>
import PageHeader from '@/components/PageHeader.vue'
import chamber from '@/api/chamber'
import { checkLogin } from '@/libs/login'

export default {
  components: { PageHeader },
  data() {
    return {
      code: '',
      info: {},
      loading: true,
      records: [],
      recordsLoading: true,
      copied: false
    }
  },
  onLoad() {
    if (!checkLogin()) {
      uni.navigateTo({ url: '/pages/login/index' })
      return
    }
    this.loadData()
      this.loadRecords()
  },
  methods: {
    loadRecords() {
      chamber
        .meDistribution()
        .then((info) => {
          const recs = (info && info.records) || []
          this.records = Array.isArray(recs) ? recs : []
        })
        .catch(() => {})
        .finally(() => {
          this.recordsLoading = false
        })
    },
    toDateStr(ts) {
      const d = toDate(ts)
      return d ? d.slice(0, 10) : ''
    },
    async loadData() {
      try {
        this.info = (await chamber.meDistribution()) || {}
        this.code = this.info.code || ''
      } catch (e) {}
      this.loading = false
    },
    copyCode() {
      if (!this.code || this.loading) return
      uni.setClipboardData({
        data: this.code,
        success: () => {
          this.copied = true
          setTimeout(() => (this.copied = false), 2000)
        }
      })
    }
  }
}
</script>

<style lang="scss">
.distribution-page {
  padding: 48rpx 40rpx 60rpx;
}
.code-card {
  border-radius: 32rpx;
  padding: 60rpx 40rpx;
  
  display: flex;
  flex-direction: column;
  align-items: center;
  box-shadow: 0 16rpx 40rpx rgba(39, 59, 89, 0.3);
}
.dc-label {
  color: rgba(255, 255, 255, 0.6);
  font-size: 24rpx;
}
.dc-code {
  font-size: 56rpx;
  font-weight: 800;
  letter-spacing: 6rpx;
  color: #ffd78f;
  margin: 24rpx 0;
}
.dc-hint {
  color: rgba(255, 255, 255, 0.5);
  font-size: 22rpx;
  text-align: center;
}
.dc-copy {
  margin-top: 40rpx;
  padding: 20rpx 80rpx;
  border-radius: 999rpx;
  background: linear-gradient(135deg, #d98a2d, #b8751d);
  color: #fff;
  font-size: 28rpx;
  font-weight: 700;
}
.dc-copy-disabled {
  opacity: 0.5;
}
.stats {
  display: flex;
  margin-top: 24rpx;
  padding: 32rpx 16rpx;
}
.stat {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8rpx;
}
.stat-num {
  font-size: 36rpx;
  font-weight: 800;
  color: #b8751d;
}
.stat-label {
  font-size: 22rpx;
  color: #8a94a3;
}
</style>
.sec-head {
  margin: 36rpx 4rpx 20rpx;
}
.sh-row {
  display: flex;
  align-items: center;
  gap: 14rpx;
}
.sh-icon {
  width: 56rpx;
  height: 56rpx;
  border-radius: 16rpx;
  background: linear-gradient(135deg, #d98a2d, #b8751d);
  display: flex;
  align-items: center;
  justify-content: center;
}
.sh-title {
  display: block;
  font-size: 34rpx;
  font-weight: 700;
  color: #17325b;
}
.sh-sub {
  display: block;
  font-size: 20rpx;
  color: #8994a6;
  margin-top: 4rpx;
}
.records {
  padding: 8rpx 0;
}
.record {
  display: flex;
  align-items: center;
  gap: 20rpx;
  padding: 24rpx 32rpx;
  border-bottom: 1rpx solid #edf0f4;
}
.record:last-child {
  border-bottom: none;
}
.rc-avatar {
  width: 72rpx;
  height: 72rpx;
  border-radius: 20rpx;
  background: #e9f0f9;
  color: #285181;
  font-size: 28rpx;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.rc-info {
  flex: 1;
  min-width: 0;
}
.rc-name {
  display: block;
  font-size: 26rpx;
  font-weight: 600;
  color: #273b59;
}
.rc-time {
  display: block;
  font-size: 20rpx;
  color: #9aa3b0;
  margin-top: 4rpx;
}
.rc-status {
  font-size: 20rpx;
  color: #c57620;
  background: #f6ead6;
  padding: 6rpx 16rpx;
  border-radius: 999rpx;
}
.rc-ok {
  color: #4c8a3f;
  background: #f0f7ec;
}
</style>
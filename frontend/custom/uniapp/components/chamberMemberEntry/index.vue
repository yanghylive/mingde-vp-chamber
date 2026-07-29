<template>
  <view class="chamber-entry">
    <view class="entry-head">
      <text>商会服务</text>
    </view>
    <view class="entry-row" hover-class="entry-row-hover" @tap="open('/pages/chamber/profile/index')">
      <text class="entry-icon iconfont icon-bianji"></text>
      <text class="entry-label">会员资料</text>
      <text class="entry-arrow iconfont icon-xiangyou"></text>
    </view>
    <view
      class="entry-row"
      hover-class="entry-row-hover"
      @tap="open('/pages/chamber/graduate_verification/index')"
    >
      <text class="entry-icon iconfont icon-shenhe"></text>
      <text class="entry-label">毕业认证</text>
      <text class="entry-arrow iconfont icon-xiangyou"></text>
    </view>
    <view class="entry-row" hover-class="entry-row-hover" @tap="open('/pages/chamber/events/index')">
      <text class="entry-icon iconfont icon-rilitubiao"></text>
      <text class="entry-label">活动中心</text>
      <text class="entry-arrow iconfont icon-xiangyou"></text>
    </view>
    <view class="entry-row" hover-class="entry-row-hover" @tap="open('/pages/chamber/event_registrations/index')">
      <text class="entry-icon iconfont icon-dingdan"></text>
      <text class="entry-label">我的活动</text>
      <text class="entry-arrow iconfont icon-xiangyou"></text>
    </view>
  </view>
</template>

<script>
import { ensureMemberInitialized } from '@/api/chamber/member.js';

export default {
  name: 'ChamberMemberEntry',
  data() {
    return {
      opening: false,
    };
  },
  methods: {
    open(url) {
      if (this.opening) return;
      this.opening = true;
      ensureMemberInitialized()
        .then(() => uni.navigateTo({ url }))
        .catch((error) => {
          uni.showToast({
            title: error && error.msg ? error.msg : '商会服务暂时不可用',
            icon: 'none',
          });
        })
        .finally(() => {
          this.opening = false;
        });
    },
  },
};
</script>

<style lang="scss" scoped>
.chamber-entry {
  margin-top: 16rpx;
  padding: 0 32rpx;
  background: #ffffff;
  color: #26322d;
}

.entry-head {
  height: 82rpx;
  border-bottom: 1rpx solid #edf0ee;
  font-size: 30rpx;
  font-weight: 600;
  line-height: 82rpx;
}

.entry-row {
  display: flex;
  min-height: 94rpx;
  align-items: center;
  border-bottom: 1rpx solid #edf0ee;
}

.entry-row:last-child {
  border-bottom: 0;
}

.entry-row-hover {
  background: #f5f8f6;
}

.entry-icon {
  width: 54rpx;
  color: #176b52;
  font-size: 34rpx;
}

.entry-label {
  flex: 1;
  font-size: 28rpx;
}

.entry-arrow {
  color: #9aa49f;
  font-size: 26rpx;
}
</style>

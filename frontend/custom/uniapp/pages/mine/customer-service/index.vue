<template>
  <view class="cs-page">
    <page-header title="客服微信" />
    <view class="card main-card">
      <view class="cs-icon">客</view>
      <view class="cs-title">专属客服</view>
      <view class="cs-sub">扫码添加客服微信，获取一对一服务</view>
      <view class="cs-info">
        <view class="csi-row"><text class="csi-dot" />1v1 专属服务</view>
        <view class="csi-row"><text class="csi-dot" />服务时间 09:00-18:00 · 工作日在线</view>
      </view>

      <!-- 二维码（配置驱动，无配置显示占位） -->
      <image
        v-if="qrUrl"
        :src="qrUrl"
        class="cs-qr"
        mode="aspectFit"
      />
      <view v-else class="cs-qr cs-qr-placeholder">
        <text class="qrp-text">客服二维码</text>
        <text class="qrp-sub">待配置</text>
      </view>

      <view v-if="wechatId" class="cs-wechat">
        <text class="cw-label">微信号：</text>
        <text class="cw-value">{{ wechatId }}</text>
        <view class="cw-copy" @tap="copyWechat">复制</view>
      </view>
    </view>
  </view>
</template>

<script>
import PageHeader from '@/components/PageHeader.vue'
import { fetchSiteConfig } from '@/common/site-config'

export default {
  components: { PageHeader },
  data() {
    return {
      qrUrl: '',
      wechatId: ''
    }
  },
  onLoad() {
    fetchSiteConfig().then((cfg) => {
      if (!cfg) return
      const cs = cfg.customer_service || cfg.customerService || {}
      if (cs.qr_image || cs.qrImage) {
        let url = cs.qr_image || cs.qrImage
        if (url && !/^https?:\/\//.test(url)) url = 'https://md.kaypal.cn' + url
        this.qrUrl = url
      }
      this.wechatId = cs.wechat_id || cs.wechatId || ''
    })
  },
  methods: {
    copyWechat() {
      if (!this.wechatId) return
      uni.setClipboardData({ data: this.wechatId })
    }
  }
}
</script>

<style lang="scss">
.cs-page {
  padding: 60rpx 40rpx;
}
.main-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 60rpx 40rpx;
}
.cs-icon {
  font-size: 72rpx;
}
.cs-info {
  margin-top: 16rpx;
  display: flex;
  flex-direction: column;
  gap: 10rpx;
}
.csi-row {
  display: flex;
  align-items: center;
  gap: 10rpx;
  font-size: 22rpx;
  color: rgba(255, 255, 255, 0.8);
}
.csi-dot {
  width: 12rpx;
  height: 12rpx;
  border-radius: 50%;
  background: #eba94e;
}
.cs-title {
  font-size: 34rpx;
  font-weight: 800;
  color: #273b59;
  margin-top: 20rpx;
}
.cs-sub {
  font-size: 24rpx;
  color: #8a94a3;
  margin-top: 12rpx;
}
.cs-qr {
  width: 360rpx;
  height: 360rpx;
  border-radius: 24rpx;
  margin-top: 40rpx;
  background: #fff;
  border: 2rpx solid #f0ddc2;
}
.cs-qr-placeholder {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  background: #f7f5f0;
}
.qrp-text {
  font-size: 28rpx;
  color: #8a94a3;
}
.qrp-sub {
  font-size: 22rpx;
  color: #c0c6d0;
  margin-top: 8rpx;
}
.cs-wechat {
  display: flex;
  align-items: center;
  gap: 12rpx;
  margin-top: 36rpx;
  font-size: 28rpx;
}
.cw-label {
  color: #516580;
}
.cw-value {
  color: #273b59;
  font-weight: 600;
}
.cw-copy {
  padding: 8rpx 24rpx;
  border-radius: 999rpx;
  background: #f6ead6;
  color: #b8751d;
  font-size: 24rpx;
  font-weight: 600;
}
</style>

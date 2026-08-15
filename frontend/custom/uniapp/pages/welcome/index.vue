<template>
  <view class="welcome-page">
    <swiper class="welcome-swiper" :current="current" @change="onSwiper" circular>
      <!-- 第 1 屏：积分 -->
      <swiper-item>
        <view class="w-screen">
          <view class="ws-glyph glyph-points">分</view>
          <text class="ws-title">1000 积分启动基金</text>
          <text class="ws-desc">注册即送 1000 积分\n报名活动、大咖互动都能赚\n还能在积分商城兑换好礼</text>
        </view>
      </swiper-item>
      <!-- 第 2 屏：大咖 -->
      <swiper-item>
        <view class="w-screen">
          <view class="ws-glyph glyph-expert">咖</view>
          <text class="ws-title">链接行业大咖</text>
          <text class="ws-desc">导师 / 教练 / 行业领袖\n1v1 线上或线下预约\nAI 分身 24 小时在线应答</text>
        </view>
      </swiper-item>
      <!-- 第 3 屏：AI -->
      <swiper-item>
        <view class="w-screen">
          <view class="ws-glyph glyph-ai">AI</view>
          <text class="ws-title">专属 AI 助手</text>
          <text class="ws-desc">商会知识问答 · 活动推荐 · 一键预约\n你的企业智囊团，随时在线</text>
        </view>
      </swiper-item>
    </swiper>

    <!-- 指示点 -->
    <view class="w-dots">
      <view v-for="(_, i) in 3" :key="i" class="{{'w-dot' + (i === current ? ' w-dot-active' : '')}}" />
    </view>

    <!-- 底部按钮 -->
    <view class="w-footer">
      <view v-if="current < 2" class="w-btn w-btn-next" @tap="next">下一步</view>
      <view v-else class="w-btn w-btn-enter" @tap="enter">开始探索</view>
      <view class="w-skip" @tap="enter">跳过</view>
    </view>
  </view>
</template>

<script>
import { track } from '@/libs/track'

export default {
  data() {
    return {
      current: 0
    }
  },
  methods: {
    onSwiper(e) {
      this.current = e.detail.current || 0
    },
    next() {
      this.current = Math.min(2, this.current + 1)
    },
    enter() {
      try {
        uni.setStorageSync('welcome_seen', '1')
      } catch (e) {}
      track('onboard_complete', { screens: this.current + 1 })
      uni.reLaunch({ url: '/pages/index/index' })
    }
  }
}
</script>

<style lang="scss">
.welcome-page {
  min-height: 100vh;
  background: linear-gradient(160deg, #101c33 0%, #1a2b4d 55%, #243a63 100%);
  display: flex;
  flex-direction: column;
  position: relative;
}
.welcome-swiper {
  flex: 1;
  width: 100%;
}
.w-screen {
  height: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 0 80rpx;
}
.ws-glyph {
  width: 160rpx;
  height: 160rpx;
  border-radius: 40rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 72rpx;
  font-weight: 700;
  color: #fff;
  margin-bottom: 56rpx;
  box-shadow: 0 16rpx 40rpx rgba(0, 0, 0, 0.35);
}
.glyph-points {
  background: linear-gradient(135deg, #c87922, #eba94e);
}
.glyph-expert {
  background: linear-gradient(135deg, #2b5fd9, #5a8bf0);
}
.glyph-ai {
  background: linear-gradient(135deg, #7a5cd6, #a48ae8);
}
.ws-title {
  font-size: 44rpx;
  font-weight: 700;
  color: #fff;
  margin-bottom: 32rpx;
  text-align: center;
}
.ws-desc {
  font-size: 28rpx;
  line-height: 1.8;
  color: rgba(255, 255, 255, 0.75);
  text-align: center;
  white-space: pre-line;
}
.w-dots {
  display: flex;
  justify-content: center;
  gap: 16rpx;
  margin-bottom: 40rpx;
}
.w-dot {
  width: 14rpx;
  height: 14rpx;
  border-radius: 7rpx;
  background: rgba(255, 255, 255, 0.25);
}
.w-dot-active {
  width: 36rpx;
  background: #eba94e;
}
.w-footer {
  padding: 0 80rpx 80rpx;
}
.w-btn {
  height: 96rpx;
  border-radius: 48rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 32rpx;
  font-weight: 600;
  margin-bottom: 24rpx;
}
.w-btn-next {
  background: rgba(255, 255, 255, 0.14);
  color: #fff;
  border: 1rpx solid rgba(255, 255, 255, 0.25);
}
.w-btn-enter {
  background: linear-gradient(135deg, #c87922, #eba94e);
  color: #fff;
}
.w-skip {
  text-align: center;
  font-size: 26rpx;
  color: rgba(255, 255, 255, 0.5);
  padding: 8rpx;
}
</style>

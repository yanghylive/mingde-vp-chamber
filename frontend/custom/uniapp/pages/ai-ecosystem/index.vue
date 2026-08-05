<template>
  <view class="ai-page">
    <view class="head glass-dark">
      <text class="h-icon">AI</text>
      <text class="h-title">AI 生态</text>
      <text class="h-sub">明德 AI 智能生态，为企业家赋能</text>
    </view>

    <view class="list">
      <view
        v-for="c in cards"
        :key="c.key"
        class="ai-card card"
        @tap="goChat(c)"
      >
        <view :class="['ac-icon', 'tone-' + c.key]">
          <text>{{ c.icon }}</text>
        </view>
        <view class="ac-info">
          <text class="ac-title">{{ c.title }}</text>
          <text class="ac-desc">{{ c.desc }}</text>
        </view>
        <text class="ac-arrow">></text>
      </view>
    </view>

    <view class="foot-note">AI 助手基于大模型生成，内容仅供参考</view>
  </view>
</template>

<script>
import { fetchSiteConfig } from '@/common/site-config'

const CARDS = [
  { key: 'mentor', icon: '师‍学', title: 'AI 导师', desc: '创业答疑、企业管理建议', topic: '你是一位资深创业导师' },
  { key: 'company', icon: '企', title: '名企咨询', desc: '对标名企，AI 给出经营策略', topic: '你是一位名企战略顾问' },
  { key: 'toolbox', icon: '具', title: 'AI 工具箱', desc: '常用 AI 工具使用指南', topic: '你是 AI 工具使用专家' },
  { key: 'companion', icon: '陪', title: 'AI 陪跑', desc: '项目陪跑，全程伴飞', topic: '你是企业家成长陪跑教练' }
]

export default {
  data() {
    return {
      cards: CARDS
    }
  },
  onLoad() {
    fetchSiteConfig().then((cfg) => {
      if (!cfg) return
      const entries = cfg.ai_entries || []
      if (Array.isArray(entries) && entries.length) {
        const byKey = {}
        for (const e of entries) if (e && e.key) byKey[e.key] = e
        this.cards = CARDS.map((c) => {
          const cfgEntry = byKey[c.key]
          return cfgEntry && cfgEntry.title ? Object.assign({}, c, { title: cfgEntry.title }) : c
        })
      }
    })
  },
  methods: {
    goChat(c) {
      uni.navigateTo({ url: '/pages/chat/index?topic=' + encodeURIComponent(c.topic || c.title || '') })
    }
  }
}
</script>

<style lang="scss">
.ai-page {
  padding: 32rpx;
}
.head {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 60rpx 40rpx;
  
}
.h-icon {
  font-size: 80rpx;
}
.h-title {
  font-size: 36rpx;
  font-weight: 800;
  color: #fff;
  margin-top: 16rpx;
}
.h-sub {
  font-size: 24rpx;
  color: rgba(255, 255, 255, 0.6);
  margin-top: 10rpx;
}
.list {
  margin-top: 24rpx;
  display: flex;
  flex-direction: column;
  gap: 20rpx;
}
.ai-card {
  display: flex;
  align-items: center;
  gap: 24rpx;
  padding: 28rpx;
}
.ac-icon {
  width: 96rpx;
  height: 96rpx;
  border-radius: 26rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 44rpx;
  flex-shrink: 0;
  background: #fff0dc;
}
.ac-info {
  flex: 1;
  min-width: 0;
}
.ac-title {
  font-size: 30rpx;
  font-weight: 700;
  color: #273b59;
  display: block;
}
.ac-desc {
  font-size: 24rpx;
  color: #8a94a3;
  display: block;
  margin-top: 8rpx;
}
.ac-arrow {
  color: #c0c6d0;
  font-size: 36rpx;
}
.foot-note {
  text-align: center;
  font-size: 22rpx;
  color: #c0c6d0;
  padding: 48rpx 0 20rpx;
}
</style>

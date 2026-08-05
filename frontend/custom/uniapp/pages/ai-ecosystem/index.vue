<template>
  <view class="ai-page">
    <view class="head glass-dark">
      <text class="h-icon">AI</text>
      <text class="h-title">AI 生态</text>
      <text class="h-sub">明德 AI 智能生态，为企业家赋能</text>
    </view>

    <view class="grid">
      <view
        v-for="c in cards"
        :key="c.key"
        class="{{'ai-card card' + (active === c.key ? ' ai-card-open' : '')}}"
        @tap="toggle(c.key)"
      >
        <view class="ac-head">
          <view class="{{'ac-icon tone-' + c.key}}">
            <text>{{ c.icon }}</text>
          </view>
          <view class="ac-info">
            <text class="ac-title">{{ c.title }}</text>
            <text class="ac-desc">{{ c.desc }}</text>
          </view>
          <text class="ac-caret">{{ active === c.key ? '^' : 'v' }}</text>
        </view>
        <view v-if="active === c.key" class="ac-open">
          <text class="ac-detail">{{ c.detail }}</text>
          <view class="ac-points">
            <text v-for="p in c.points" :key="p" class="ac-point">{{ p }}</text>
          </view>
          <view class="btn-primary ac-go" @tap.stop="goChat(c)">
            <view class="ic ic-sm ic-message-circle-white" />
            <text>与 AI 助手对话</text>
          </view>
        </view>
      </view>
    </view>

    <view class="foot-note">AI 助手基于大模型生成，内容仅供参考</view>
  </view>
</template>

<script>
import { fetchSiteConfig } from '@/common/site-config'

const CARDS = [
  { key: 'mentor', icon: '师', title: 'AI 导师', desc: '创业答疑、企业管理建议', topic: '你是一位资深创业导师',
    detail: '聚焦创业难题与经营决策，从战略到执行给出可落地的建议。', points: ['战略诊断', '经营复盘', '决策推演'] },
  { key: 'company', icon: '企', title: '名企咨询', desc: '对标名企，AI 给出经营策略', topic: '你是一位名企战略顾问',
    detail: '借鉴标杆企业打法，输出适合你企业阶段的经营策略与方法论。', points: ['标杆对标', '增长策略', '组织建设'] },
  { key: 'toolbox', icon: '具', title: 'AI 工具箱', desc: '常用 AI 工具使用指南', topic: '你是 AI 工具使用专家',
    detail: '文案生成、PPT 制作、数据分析，常用 AI 工具一键调用指南。', points: ['文案生成', 'PPT 制作', '数据分析'] },
  { key: 'companion', icon: '陪', title: 'AI 陪跑', desc: '项目陪跑，全程伴飞', topic: '你是企业家成长陪跑教练',
    detail: '把你的项目目标交给 AI 陪跑，分阶段跟进、复盘、迭代。', points: ['目标拆解', '阶段跟进', '复盘迭代'] }
]

export default {
  data() {
    return {
      cards: CARDS,
      active: ''
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
    toggle(key) {
      this.active = this.active === key ? '' : key
    },
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
.ac-cta {
  display: inline-flex;
  align-items: center;
  gap: 8rpx;
  margin-top: 14rpx;
  font-size: 22rpx;
  font-weight: 600;
  color: #24507f;
  background: #eaf0f8;
  padding: 10rpx 22rpx;
  border-radius: 999rpx;
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
.grid {
  display: flex;
  flex-wrap: wrap;
  gap: 20rpx;
  margin-top: 8rpx;
}
.ai-card {
  width: calc(50% - 10rpx);
  padding: 28rpx;
  box-sizing: border-box;
}
.ai-card-open {
  width: 100%;
}
.ac-head {
  display: flex;
  align-items: center;
  gap: 16rpx;
}
.ac-caret {
  font-size: 26rpx;
  color: #a0a9b6;
  margin-left: auto;
  font-weight: 700;
}
.ac-open {
  margin-top: 24rpx;
  padding-top: 24rpx;
  border-top: 1rpx solid #eef1f5;
}
.ac-detail {
  display: block;
  font-size: 22rpx;
  line-height: 1.7;
  color: #5a6b80;
}
.ac-points {
  display: flex;
  flex-wrap: wrap;
  gap: 12rpx;
  margin-top: 20rpx;
}
.ac-point {
  font-size: 20rpx;
  color: #6b7889;
  background: #eef0f3;
  padding: 6rpx 18rpx;
  border-radius: 999rpx;
}
.ac-go {
  margin-top: 24rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10rpx;
}
</style>
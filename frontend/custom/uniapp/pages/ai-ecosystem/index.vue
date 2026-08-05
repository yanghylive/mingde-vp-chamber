<template>
  <view class="ai-page">
    <page-header title="AI 生态" eyebrow="让 AI 成为企业第二增长引擎">
      <view class="hdr-badge">
        <view class="ic ic-xs ic-sparkles-blue" />
        <text>AI 时代</text>
      </view>
    </page-header>

    <view class="hero glass-dark">
      <view class="hero-deco1" />
      <view class="hero-deco2" />
      <view class="hero-row">
        <view class="hero-icon-box">
          <view class="ic ic-md ic-bot-white" />
        </view>
        <view class="hero-text">
          <view class="hero-badge"><text>明德 AI 生态</text></view>
          <text class="hero-title">拥抱 AI，重塑增长</text>
          <text class="hero-sub">咨询 · 工具 · 陪跑 · 课程，一站式 AI 落地路径</text>
        </view>
      </view>
    </view>

    <view class="grid">
      <view
        v-for="c in cards"
        :key="c.key"
        class="{{'ai-card card' + (active === c.key ? ' ai-card-open' : '')}}"
        @tap="toggle(c.key)"
      >
        <view class="ac-top">
          <view class="{{'ac-icon tone-' + c.key}}">
            <view class="ic ic-md {{c.icon}}" />
          </view>
          <view class="ac-title-row">
            <text class="ac-title">{{ c.title }}</text>
            <view class="{{'ic ic-xs ic-chevron-down-gray ac-caret' + (active === c.key ? ' ac-caret-open' : '')}}" />
          </view>
          <text class="ac-desc">{{ c.desc }}</text>
        </view>
        <view v-if="active === c.key" class="ac-open">
          <text class="ac-detail">{{ c.detail }}</text>
          <view class="ac-points">
            <text v-for="p in c.points" :key="p" class="ac-point">{{ p }}</text>
          </view>
          <view class="btn-primary ac-go" @tap.stop="goChat(c)">
            <view class="ic ic-xs ic-message-circle-white" />
            <text>与 AI 助手对话</text>
          </view>
        </view>
      </view>
    </view>

    <view class="cta-card card">
      <view class="cta-row">
        <view class="cta-icon">
          <view class="ic ic-md ic-bot-gray" />
        </view>
        <view class="cta-text">
          <text class="cta-title">有任何问题？</text>
          <text class="cta-desc">24h 在线的明德 AI 助手，问答 / 预约 / 课程推荐</text>
        </view>
        <view class="btn-primary cta-btn" @tap="goChatDirect">
          <text>立即对话</text>
        </view>
      </view>
    </view>

    <view class="foot-note">明德恒智AI企商汇 · PBC 企业家事业共同体 · AI 生态</view>
  </view>
</template>

<script>
import PageHeader from '@/components/PageHeader.vue'
import { fetchSiteConfig } from '@/common/site-config'

const CARDS = [
  { key: 'consult', icon: 'ic-building-2-blue', title: '名企 AI 咨询', desc: '企业 AI 转型诊断方案', topic: '名企 AI 咨询',
    detail: '面向企业的 AI 转型咨询：从战略诊断、场景盘点到落地路线图，输出可执行的 AI 应用方案，帮助企业家厘清「AI 能为我带来什么」与「第一步怎么走」。',
    points: ['AI 成熟度诊断', '业务场景盘点', '落地路线图', '行业案例对标'] },
  { key: 'toolbox', icon: 'ic-wrench-gold', title: '现有工具箱', desc: '提效工具一键调用', topic: '现有工具箱',
    detail: '沉淀商会内最实用的 AI 提效工具集：文案生成、PPT 制作、数据分析、会议纪要、知识库问答等，一键调用，让日常经营效率翻倍。',
    points: ['AI 文案与企划', '智能数据分析', '会议纪要助手', '知识库问答'] },
  { key: 'coach', icon: 'ic-rocket-green', title: '陪跑搭建', desc: '专属 AI 系统陪跑', topic: '陪跑搭建',
    detail: '为会员企业搭建专属 AI 应用系统，并提供长期陪跑服务：从需求梳理、系统搭建到员工培训与迭代优化，确保 AI 真正用起来、见效果。',
    points: ['专属 AI 系统搭建', '场景定制开发', '团队培训赋能', '长期迭代陪跑'] },
  { key: 'community', icon: 'ic-graduation-cap-purple', title: '圈子·课程', desc: 'AI 活动与训练营', topic: '圈子·课程',
    detail: '面向会员的 AI 学习圈子与实战课程：主题沙龙、案例拆解、训练营与认证体系，在实战中提升 AI 认知与应用能力，与同频者共同进化。',
    points: ['AI 主题沙龙', '企业案例拆解', '实战训练营', '认证与荣誉'] }
]

export default {
  components: { PageHeader },
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
        this.cards = CARDS.map((c, i) => {
          const e = entries[i]
          return e && typeof e.title === 'string' && e.title.trim() ? Object.assign({}, c, { title: e.title.trim() }) : c
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
    },
    goChatDirect() {
      uni.navigateTo({ url: '/pages/chat/index' })
    }
  }
}
</script>

<style lang="scss">
.ai-page {
  padding: 32rpx;
}

/* Header badge */
.hdr-badge {
  display: flex;
  align-items: center;
  gap: 4rpx;
  background: #e9f0fb;
  color: #28517f;
  font-size: 20rpx;
  font-weight: 600;
  padding: 6rpx 16rpx;
  border-radius: 999rpx;
}

/* Hero */
.hero {
  position: relative;
  overflow: hidden;
  border-radius: 56rpx;
  padding: 40rpx;
}
.hero-deco1 {
  position: absolute;
  right: -56rpx;
  top: -72rpx;
  width: 288rpx;
  height: 288rpx;
  border-radius: 50%;
  border: 2rpx solid rgba(243, 188, 106, 0.25);
}
.hero-deco2 {
  position: absolute;
  right: 48rpx;
  top: 40rpx;
  width: 160rpx;
  height: 160rpx;
  border-radius: 50%;
  border: 2rpx solid rgba(243, 188, 106, 0.15);
}
.hero-row {
  position: relative;
  z-index: 2;
  display: flex;
  align-items: center;
  gap: 24rpx;
}
.hero-icon-box {
  width: 96rpx;
  height: 96rpx;
  border-radius: 28rpx;
  background: rgba(255, 255, 255, 0.12);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.hero-text {
  flex: 1;
  min-width: 0;
}
.hero-badge {
  display: inline-flex;
  background: rgba(255, 255, 255, 0.1);
  color: #f6c77e;
  font-size: 20rpx;
  font-weight: 600;
  padding: 4rpx 16rpx;
  border-radius: 999rpx;
  border: 1rpx solid rgba(255, 255, 255, 0.15);
}
.hero-title {
  display: block;
  font-size: 40rpx;
  font-weight: 600;
  color: #fff;
  margin-top: 16rpx;
}
.hero-sub {
  display: block;
  font-size: 22rpx;
  line-height: 40rpx;
  color: rgba(191, 219, 254, 0.7);
  margin-top: 8rpx;
}

/* Grid */
.grid {
  display: flex;
  flex-wrap: wrap;
  gap: 24rpx;
  margin-top: 40rpx;
}
.ai-card {
  width: calc(50% - 12rpx);
  padding: 32rpx;
  box-sizing: border-box;
}
.ai-card-open {
  width: 100%;
}

/* Card top section */
.ac-top {
  display: flex;
  flex-direction: column;
}
.ac-icon {
  width: 88rpx;
  height: 88rpx;
  border-radius: 28rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 40rpx;
  font-weight: 700;
  flex-shrink: 0;
}
.tone-consult {
  background: #e9f0fb;
  color: #28517f;
}
.tone-toolbox {
  background: #fff0dc;
  color: #bd7627;
}
.tone-coach {
  background: #e8f3ef;
  color: #3f715f;
}
.tone-community {
  background: #f4ebf6;
  color: #76517e;
}
.ac-title-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 24rpx;
}
.ac-title {
  font-size: 28rpx;
  font-weight: 700;
  color: #24395a;
}
.ac-caret {
  transition: transform 0.2s;
}
.ac-caret-open {
  transform: rotate(180deg);
}
.ac-desc {
  display: block;
  font-size: 20rpx;
  line-height: 32rpx;
  color: #8a94a3;
  margin-top: 8rpx;
}

/* Card expanded section */
.ac-open {
  margin-top: 24rpx;
  padding-top: 24rpx;
  border-top: 1rpx solid #eef1f5;
}
.ac-detail {
  display: block;
  font-size: 22rpx;
  line-height: 40rpx;
  color: #5a6b80;
}
.ac-points {
  display: flex;
  flex-wrap: wrap;
  gap: 12rpx;
  margin-top: 24rpx;
}
.ac-point {
  font-size: 20rpx;
  color: #6b7889;
  background: #eef0f3;
  padding: 6rpx 18rpx;
  border-radius: 999rpx;
}
.ac-go {
  margin-top: 32rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10rpx;
}

/* Bottom CTA */
.cta-card {
  margin-top: 48rpx;
  border: 1rpx solid #f0ddc2;
  background: linear-gradient(135deg, #fffaf2, #fff);
  padding: 32rpx;
}
.cta-row {
  display: flex;
  align-items: center;
  gap: 24rpx;
}
.cta-icon {
  width: 88rpx;
  height: 88rpx;
  border-radius: 28rpx;
  background: #e9f0fb;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.cta-text {
  flex: 1;
  min-width: 0;
}
.cta-title {
  display: block;
  font-size: 28rpx;
  font-weight: 700;
  color: #273b59;
}
.cta-desc {
  display: block;
  font-size: 20rpx;
  line-height: 32rpx;
  color: #8a94a3;
  margin-top: 4rpx;
}
.cta-btn {
  flex-shrink: 0;
  padding: 16rpx 32rpx;
  border-radius: 24rpx;
  font-size: 24rpx;
}

/* Footer */
.foot-note {
  text-align: center;
  font-size: 20rpx;
  color: #a7afbb;
  padding: 48rpx 0 20rpx;
}
</style>

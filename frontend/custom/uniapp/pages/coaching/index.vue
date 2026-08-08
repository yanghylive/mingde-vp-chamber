<template>
  <view class="coaching-page">
    <!-- 页头 -->
    <view class="coach-head">
      <view class="ph">
        <view class="ph-row">
          <view class="ph-eyebrow">
            <image class="ic ic-xs" src="/static/icons/ic-crown-gold.png" mode="aspectFit" />
            明德恒智AI企商汇
          </view>
        </view>
        <view class="ph-title-row">
          <text class="ph-title">{{ brand.name || '小薇' }}</text>
          <text class="ph-date">{{ date }}</text>
        </view>
        <view class="ph-sub">每天 3 条灵魂追问，帮你打破旧习惯、整合行为模式</view>
      </view>
    </view>

    <!-- 断档/控速提示 -->
    <view v-if="cooldownMode" class="cooldown-banner">
      <text class="cooldown-text">小薇降低门槛啦——今天回一个数字（0-10 今日状态）或一句话就够</text>
    </view>

    <!-- 今日 3 问卡片 -->
    <view class="section">
      <view class="sec-head">
        <view class="sh-row">
          <view class="sh-icon">
            <image class="ic ic-md" src="/static/icons/ic-sparkles-gold.png" mode="aspectFit" />
          </view>
          <view>
            <text class="sh-title">今日认知刷新</text>
            <text class="sh-sub">早安 · 3 条灵魂追问</text>
          </view>
        </view>
      </view>

      <view v-if="loading" class="empty"><text class="empty-text">小薇正在准备今天的追问…</text></view>

      <view v-else-if="!morning" class="card glass-card empty-card">
        <text class="empty-title">今日追问还没生成</text>
        <text class="empty-sub">点击生成，小薇为你定制今天的认知刷新</text>
        <view class="gen-btn" @tap="genMorning">生成今日 3 问</view>
      </view>

      <view v-else class="card glass-card">
        <view class="q-list">
          <view v-for="(q, i) in morning.questions" :key="i" class="q-item">
            <view class="q-num">{{ i + 1 }}</view>
            <text class="q-text">{{ q }}</text>
          </view>
        </view>
        <view class="opt-row">
          <view class="opt-label">微优化</view>
          <text class="opt-text">{{ morning.micro_optimization }}</text>
        </view>
        <view class="opt-row">
          <view class="opt-label chal">小挑战</view>
          <text class="opt-text">{{ morning.challenge }}</text>
        </view>
        <view v-if="morning.challenge_criteria" class="criteria">完成标准：{{ morning.challenge_criteria }}</view>
        <view class="closing">{{ morning.closing }}</view>
      </view>
    </view>

    <!-- 回答区 -->
    <view v-if="morning" class="section">
      <view class="sec-head">
        <view class="sh-row">
          <view class="sh-icon">
            <image class="ic ic-md" src="/static/icons/ic-message-circle-gold.png" mode="aspectFit" />
          </view>
          <view>
            <text class="sh-title">回应小薇</text>
            <text class="sh-sub">写下你的回答，晚间自动复盘</text>
          </view>
        </view>
      </view>

      <view v-if="respondStatus > 0" class="card glass-card done-card">
        <view class="done-mark">
          <image class="ic ic-md" src="/static/icons/ic-check-circle-green.png" mode="aspectFit" />
        </view>
        <text class="done-title">今日已回传</text>
        <text class="done-sub">连续回应 {{ streak }} 天 · 小薇已收到</text>
        <view class="done-btn" @tap="resetAnswers">修改回应</view>
      </view>

      <view v-else class="card glass-card">
        <view v-for="(q, i) in morning.questions" :key="i" class="answer-item">
          <text class="answer-q">{{ i + 1 }}. {{ q }}</text>
          <textarea class="answer-input" :value="answers[i] || ''" :placeholder="'回答 ' + (i + 1)" @input="onAnswer(i, $event)" />
        </view>
        <view class="chal-result">
          <text class="answer-q">今日小挑战完成了吗？</text>
          <view class="chal-options">
            <view :class="['chal-opt', challengeResult === 'done' ? 'active' : '']" @tap="challengeResult = 'done'">完成了</view>
            <view :class="['chal-opt', challengeResult === 'partial' ? 'active' : '']" @tap="challengeResult = 'partial'">部分完成</view>
            <view :class="['chal-opt', challengeResult === 'none' ? 'active' : '']" @tap="challengeResult = 'none'">没完成</view>
          </view>
        </view>
        <view class="note-item">
          <text class="answer-q">一句话笔记（可选，也可只回一个数字 0-10）</text>
          <input class="note-input" v-model="note" placeholder="例如：状态 7，今天推进了 X" />
        </view>
        <view class="submit-btn" @tap="submitResponse">提交回应</view>
      </view>
    </view>

    <!-- 晚间复盘 -->
    <view v-if="morning" class="section">
      <view class="sec-head">
        <view class="sh-row">
          <view class="sh-icon">
            <image class="ic ic-md" src="/static/icons/ic-moon-gold.png" mode="aspectFit" />
          </view>
          <view>
            <text class="sh-title">晚间复盘</text>
            <text class="sh-sub">对照今日挑战，小薇给你复盘</text>
          </view>
        </view>
      </view>

      <view v-if="evening" class="card glass-card review-card">
        <text class="review-summary">{{ evening.summary }}</text>
        <view v-if="evening.praise" class="review-line praise">
          <view class="review-dot"></view>
          <text>{{ evening.praise }}</text>
        </view>
        <view v-if="evening.blocker" class="review-line">
          <view class="review-dot gray"></view>
          <text>{{ evening.blocker }}</text>
        </view>
        <view class="review-tomorrow">{{ evening.tomorrow_hint }}</view>
      </view>

      <view v-else class="card glass-card empty-card">
        <text class="empty-sub">完成挑战后，晚间点这里生成复盘</text>
        <view class="gen-btn" @tap="genEvening">生成晚间复盘</view>
      </view>
    </view>

    <view class="foot-safe"></view>
  </view>
</template>

<script>
import chamber from '@/api/chamber'

export default {
  data() {
    return {
      brand: {},
      date: '',
      morning: null,
      responses: null,
      respondStatus: 0,
      evening: null,
      streak: 0,
      cooldownMode: false,
      loading: true,
      answers: [],
      challengeResult: 'none',
      note: '',
      submitting: false,
    }
  },

  onShow() {
    this.loadToday()
  },

  methods: {
    async loadToday() {
      this.loading = true
      try {
        const res = await chamber.coachingToday()
        const d = res.data || {}
        this.brand = d.brand || {}
        this.date = d.date || ''
        this.morning = d.morning || null
        this.responses = d.responses || null
        this.respondStatus = d.respond_status || 0
        this.evening = d.evening_review || null
        this.streak = d.streak || 0
        this.cooldownMode = !!d.cooldown_mode
        if (d.morning) {
          this.answers = (d.morning.questions || []).map(() => '')
        }
        this.loading = false
      } catch (e) {
        this.loading = false
        console.log('loadToday fail', e)
      }
    },

    async genMorning() {
      uni.showLoading({ title: '小薇思考中…' })
      try {
        const res = await chamber.coachingMorning({})
        this.morning = res.data || null
        this.answers = (this.morning.questions || []).map(() => '')
        this.respondStatus = 0
        uni.hideLoading()
        uni.showToast({ title: '今日 3 问已生成', icon: 'success' })
      } catch (e) {
        uni.hideLoading()
        uni.showToast({ title: '生成失败，稍后再试', icon: 'none' })
      }
    },

    onAnswer(i, e) {
      this.$set(this.answers, i, e.detail.value)
    },

    resetAnswers() {
      this.respondStatus = 0
      this.answers = (this.morning.questions || []).map(() => '')
      this.challengeResult = 'none'
      this.note = ''
    },

    async submitResponse() {
      if (this.submitting) return
      const hasAnswer = (this.answers || []).some((a) => a && a.trim())
      const hasNote = this.note && this.note.trim()
      const hasResult = this.challengeResult !== 'none'
      if (!hasAnswer && !hasNote && !hasResult) {
        // 最低门槛：允许只回一个数字
        if (!this.note.trim()) {
          uni.showToast({ title: '回一句话或一个数字（0-10）', icon: 'none' })
          return
        }
      }
      this.submitting = true
      try {
        const res = await chamber.coachingRespond({
          method: 'POST',
          data: {
            answers: (this.answers || []).map((a) => (a || '').trim()).filter((a) => a !== ''),
            challenge_result: this.challengeResult,
            note: this.note.trim(),
          },
        })
        this.respondStatus = (res.data && res.data.respond_status) || 2
        this.streak = (res.data && res.data.streak) || 0
        this.responses = res.data && res.data.responses
        uni.showToast({ title: '已回应小薇', icon: 'success' })
      } catch (e) {
        uni.showToast({ title: '提交失败，稍后再试', icon: 'none' })
      }
      this.submitting = false
    },

    async genEvening() {
      uni.showLoading({ title: '小薇复盘思考中…' })
      try {
        const res = await chamber.coachingEvening({})
        this.evening = res.data || null
        uni.hideLoading()
        uni.showToast({ title: '复盘已生成', icon: 'success' })
      } catch (e) {
        uni.hideLoading()
        uni.showToast({ title: '复盘生成失败', icon: 'none' })
      }
    },
  },
}
</script>

<style lang="scss" scoped>
.coaching-page {
  min-height: 100vh;
  background: linear-gradient(180deg, #f6f1e8 0%, #f4f6f9 30%, #eef2f7 100%);
  padding: 0 32rpx;
  box-sizing: border-box;
}

.coach-head {
  padding: 24rpx 0 8rpx;
}

.ph-eyebrow {
  display: flex;
  align-items: center;
  gap: 8rpx;
  font-size: 20rpx;
  color: #a9651e;
  font-weight: 600;
}

.ph-title-row {
  display: flex;
  align-items: baseline;
  gap: 16rpx;
  margin-top: 10rpx;
}

.ph-title {
  font-size: 44rpx;
  font-weight: 800;
  color: #17325b;
}

.ph-date {
  font-size: 22rpx;
  color: #8d97a6;
}

.ph-sub {
  font-size: 22rpx;
  color: #8a94a3;
  margin-top: 8rpx;
}

.cooldown-banner {
  background: #f6ead6;
  border-radius: 16rpx;
  padding: 20rpx 24rpx;
  margin: 16rpx 0;
}

.cooldown-text {
  font-size: 22rpx;
  color: #a9651e;
  line-height: 1.6;
}

.section {
  margin-top: 32rpx;
}

.sec-head {
  margin-bottom: 16rpx;
}

.sh-row {
  display: flex;
  align-items: center;
  gap: 14rpx;
}

.sh-icon {
  width: 64rpx;
  height: 64rpx;
  border-radius: 20rpx;
  background: #fff0dc;
  display: flex;
  align-items: center;
  justify-content: center;
}

.sh-title {
  display: block;
  font-size: 30rpx;
  font-weight: 700;
  color: #17325b;
}

.sh-sub {
  display: block;
  font-size: 20rpx;
  color: #8a94a3;
  margin-top: 4rpx;
}

.card {
  border-radius: 28rpx;
  padding: 28rpx;
  box-sizing: border-box;
}

.glass-card {
  background: rgba(255, 255, 255, 0.92);
  border: 1rpx solid rgba(180, 198, 215, 0.25);
  box-shadow: 0 8rpx 32rpx rgba(30, 50, 86, 0.06);
}

.q-item {
  display: flex;
  gap: 16rpx;
  align-items: flex-start;
  margin-bottom: 22rpx;
}

.q-num {
  width: 40rpx;
  height: 40rpx;
  border-radius: 50%;
  background: linear-gradient(135deg, #d99b49, #a9651e);
  color: #fff;
  font-size: 22rpx;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  margin-top: 2rpx;
}

.q-text {
  flex: 1;
  font-size: 26rpx;
  color: #203454;
  line-height: 1.7;
}

.opt-row {
  display: flex;
  gap: 14rpx;
  align-items: flex-start;
  margin-top: 14rpx;
  padding-top: 14rpx;
  border-top: 1rpx dashed #e6ebf1;
}

.opt-label {
  font-size: 22rpx;
  font-weight: 700;
  color: #c57620;
  background: #fff2df;
  border-radius: 10rpx;
  padding: 4rpx 14rpx;
  flex-shrink: 0;
}

.opt-label.chal {
  color: #3b6d11;
  background: #eaf3de;
}

.opt-text {
  flex: 1;
  font-size: 24rpx;
  color: #33455f;
  line-height: 1.6;
}

.criteria {
  font-size: 20rpx;
  color: #8a94a3;
  margin-top: 14rpx;
  background: #f4f7fb;
  border-radius: 12rpx;
  padding: 12rpx 16rpx;
}

.closing {
  font-size: 22rpx;
  color: #a9651e;
  margin-top: 16rpx;
  font-weight: 500;
}

.empty-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 48rpx 28rpx;
}

.empty-title {
  font-size: 28rpx;
  font-weight: 700;
  color: #33455f;
}

.empty-sub {
  font-size: 22rpx;
  color: #8a94a3;
  margin-top: 10rpx;
  text-align: center;
}

.gen-btn {
  margin-top: 24rpx;
  background: linear-gradient(90deg, #c87922, #eba94e);
  color: #fff;
  font-size: 26rpx;
  font-weight: 600;
  border-radius: 999rpx;
  padding: 18rpx 48rpx;
  box-shadow: 0 8rpx 24rpx rgba(185, 110, 29, 0.25);
}

.answer-item {
  margin-bottom: 20rpx;
}

.answer-q {
  font-size: 24rpx;
  color: #33455f;
  font-weight: 500;
  display: block;
  margin-bottom: 10rpx;
}

.answer-input {
  width: 100%;
  height: 120rpx;
  background: #f4f7fb;
  border-radius: 16rpx;
  padding: 16rpx;
  box-sizing: border-box;
  font-size: 24rpx;
  color: #203454;
  border: 1rpx solid #e6ebf1;
}

.chal-result {
  margin-top: 8rpx;
}

.chal-options {
  display: flex;
  gap: 16rpx;
  margin-top: 12rpx;
}

.chal-opt {
  flex: 1;
  text-align: center;
  font-size: 22rpx;
  padding: 14rpx 0;
  border-radius: 999rpx;
  background: #f4f7fb;
  color: #5c6b80;
  border: 1rpx solid #e6ebf1;
}

.chal-opt.active {
  background: #fff0dc;
  color: #a9651e;
  border-color: #e8c48a;
  font-weight: 600;
}

.note-item {
  margin-top: 20rpx;
}

.note-input {
  width: 100%;
  height: 80rpx;
  background: #f4f7fb;
  border-radius: 16rpx;
  padding: 0 16rpx;
  box-sizing: border-box;
  font-size: 24rpx;
  border: 1rpx solid #e6ebf1;
}

.submit-btn {
  margin-top: 28rpx;
  background: linear-gradient(90deg, #c87922, #eba94e);
  color: #fff;
  font-size: 28rpx;
  font-weight: 700;
  text-align: center;
  padding: 22rpx 0;
  border-radius: 999rpx;
  box-shadow: 0 10rpx 28rpx rgba(185, 110, 29, 0.25);
}

.done-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 44rpx 28rpx;
}

.done-mark {
  width: 88rpx;
  height: 88rpx;
  border-radius: 50%;
  background: #eaf3de;
  display: flex;
  align-items: center;
  justify-content: center;
}

.done-title {
  font-size: 30rpx;
  font-weight: 700;
  color: #2c3e50;
  margin-top: 16rpx;
}

.done-sub {
  font-size: 22rpx;
  color: #8a94a3;
  margin-top: 8rpx;
}

.done-btn {
  margin-top: 24rpx;
  font-size: 24rpx;
  color: #a9651e;
  border: 1rpx solid #e8c48a;
  border-radius: 999rpx;
  padding: 12rpx 40rpx;
}

.review-card {
  padding: 32rpx;
}

.review-summary {
  font-size: 26rpx;
  color: #203454;
  line-height: 1.8;
}

.review-line {
  display: flex;
  gap: 12rpx;
  align-items: flex-start;
  margin-top: 18rpx;
  font-size: 24rpx;
  color: #33455f;
  line-height: 1.7;
}

.review-line.praise {
  color: #3b6d11;
}

.review-dot {
  width: 14rpx;
  height: 14rpx;
  border-radius: 50%;
  background: #97c459;
  margin-top: 10rpx;
  flex-shrink: 0;
}

.review-dot.gray {
  background: #c0c6d0;
}

.review-tomorrow {
  margin-top: 20rpx;
  font-size: 22rpx;
  color: #a9651e;
  background: #fff8ec;
  border-radius: 12rpx;
  padding: 14rpx 18rpx;
}

.empty {
  padding: 60rpx 0;
  text-align: center;
}

.empty-text {
  font-size: 24rpx;
  color: #8a94a3;
}

.foot-safe {
  height: 80rpx;
}
</style>

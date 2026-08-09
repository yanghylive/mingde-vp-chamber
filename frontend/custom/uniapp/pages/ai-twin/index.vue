<template>
  <view class="ai-train-page">
    <!-- 我的分身状态卡 -->
    <view class="status-card">
      <view class="status-row">
        <text class="status-title">{{ twin.persona_name || '我的 AI 分身' }}</text>
        <text class="status-tag" :class="statusCls">{{ statusLabel }}</text>
      </view>
      <view class="status-meta">
        <text class="meta-item">训练进度 {{ twin.training_progress || 0 }}%</text>
        <text class="meta-item">记忆 {{ twin.memory_count || 0 }} 条</text>
        <text class="meta-item">对话价 {{ twin.chat_points_cost || 20 }} 分/次</text>
      </view>
      <view class="progress-bar">
        <view class="progress-fill" :style="{ width: (twin.training_progress || 0) + '%' }"></view>
      </view>
      <view class="status-tip">
        和训练师聊天，它会记住你的身份、风格、观点和口头禅，自动沉淀成记忆。聊得越多，分身越像你。
      </view>
    </view>

    <!-- 训练对话区 -->
    <view class="chat-area">
      <scroll-view scroll-y class="chat-scroll" :scroll-into-view="scrollInto" scroll-with-animation>
        <view v-if="!messages.length" class="chat-empty">
          <view class="empty-title">你好，我是你的分身训练师</view>
          <view class="empty-sub">先介绍一下你自己吧——职业、经历、擅长的领域，我们慢慢聊</view>
        </view>
        <view v-for="(m, i) in messages" :key="i" :id="'msg-' + i" class="msg-row {{m.role === 'user' ? 'row-user' : 'row-ai'}}">
          <view class="bubble {{m.role === 'user' ? 'bubble-user' : 'bubble-ai'}}">
            <text class="bubble-text">{{ m.content }}</text>
          </view>
        </view>
        <view v-if="sending" class="msg-row row-ai">
          <view class="bubble bubble-ai">
            <text class="bubble-text typing">训练师思考中...</text>
          </view>
        </view>
      </scroll-view>
      <view class="input-bar">
        <input
          v-model="draft"
          class="chat-input"
          placeholder="聊聊你的经历、观点、口头禅..."
          confirm-type="send"
          :disabled="sending"
          @confirm="send"
        />
        <view class="send-btn {{sending ? 'send-disabled' : ''}}" @tap="send">{{ sending ? '…' : '发送' }}</view>
      </view>
    </view>
  </view>
</template>

<script>
import { HTTP_REQUEST_URL } from '@/config/app'
import { checkLogin } from '@/libs/login'

export default {
  data() {
    return {
      twin: {},
      draft: '',
      sending: false,
      messages: [],
      scrollInto: ''
    }
  },
  computed: {
    statusLabel() {
      const s = Number(this.twin.training_status || 0)
      const map = { 0: '未训练', 1: '训练中', 2: '已就绪' }
      return map[s] || '未训练'
    },
    statusCls() {
      const s = Number(this.twin.training_status || 0)
      return s === 2 ? 'tag-ready' : (s === 1 ? 'tag-training' : 'tag-idle')
    }
  },
  onShow() {
    if (!checkLogin()) return
    this.loadTwin()
  },
  methods: {
    loadTwin() {
      const token = uni.getStorageSync('token') || ''
      uni.request({
        url: HTTP_REQUEST_URL + '/chamber/v1/ai-twin/me',
        method: 'GET',
        header: token ? { 'Authori-zation': 'Bearer ' + token } : {},
        success: (res) => {
          if (res.statusCode === 200 && res.data && res.data.code === 0) {
            this.twin = res.data.data || {}
          }
        }
      })
    },
    send() {
      const content = this.draft.trim()
      if (!content || this.sending) return
      if (!checkLogin()) return
      this.draft = ''
      this.messages.push({ role: 'user', content })
      this.sending = true
      this.scrollToBottom()

      const token = uni.getStorageSync('token') || ''
      uni.request({
        url: HTTP_REQUEST_URL + '/chamber/v1/ai-twin/train',
        method: 'POST',
        header: token ? { 'Content-Type': 'application/json', 'Authori-zation': 'Bearer ' + token } : { 'Content-Type': 'application/json' },
        data: { message: content },
        success: (res) => {
          if (res.statusCode === 200 && res.data && res.data.code === 0) {
            const d = res.data.data || {}
            if (d.reply) this.messages.push({ role: 'assistant', content: d.reply })
            this.twin.training_progress = d.progress
            this.twin.training_status = d.trained ? 2 : 1
            this.twin.memory_count = d.memory_count
          } else {
            const msg = (res.data && res.data.msg) ? res.data.msg : '请求失败'
            this.messages.push({ role: 'assistant', content: msg })
          }
        },
        fail: () => {
          this.messages.push({ role: 'assistant', content: '网络异常，请稍后再试。' })
        },
        complete: () => {
          this.sending = false
          this.scrollToBottom()
        }
      })
    },
    scrollToBottom() {
      setTimeout(() => {
        this.scrollInto = 'msg-' + (this.messages.length - 1)
      }, 100)
    }
  }
}
</script>

<style scoped>
.ai-train-page {
  min-height: 100vh;
  background: #f7f6f3;
  display: flex;
  flex-direction: column;
}
.status-card {
  margin: 20rpx 24rpx;
  padding: 28rpx 32rpx;
  background: linear-gradient(135deg, #1c1a17, #2e2a24);
  border-radius: 20rpx;
}
.status-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.status-title {
  color: #c9a45c;
  font-size: 34rpx;
  font-weight: 600;
}
.status-tag {
  font-size: 22rpx;
  padding: 6rpx 18rpx;
  border-radius: 20rpx;
}
.tag-ready { background: rgba(103, 194, 58, 0.2); color: #67c23a; }
.tag-training { background: rgba(233, 185, 100, 0.2); color: #e9b964; }
.tag-idle { background: rgba(255, 255, 255, 0.12); color: rgba(255, 255, 255, 0.6); }
.status-meta {
  margin-top: 16rpx;
  display: flex;
  gap: 24rpx;
}
.meta-item {
  color: rgba(255, 255, 255, 0.65);
  font-size: 22rpx;
}
.progress-bar {
  margin-top: 20rpx;
  height: 10rpx;
  background: rgba(255, 255, 255, 0.12);
  border-radius: 6rpx;
  overflow: hidden;
}
.progress-fill {
  height: 100%;
  background: linear-gradient(90deg, #c9a45c, #e9b964);
  border-radius: 6rpx;
  transition: width 0.4s;
}
.status-tip {
  margin-top: 20rpx;
  color: rgba(255, 255, 255, 0.45);
  font-size: 22rpx;
  line-height: 1.6;
}
.chat-area {
  flex: 1;
  display: flex;
  flex-direction: column;
  margin: 0 24rpx 24rpx;
  background: #fff;
  border-radius: 20rpx;
  overflow: hidden;
  min-height: 400rpx;
}
.chat-scroll {
  flex: 1;
  padding: 28rpx;
  max-height: 65vh;
}
.chat-empty {
  padding: 80rpx 40rpx;
  text-align: center;
}
.empty-title {
  font-size: 30rpx;
  color: #2e2a24;
  font-weight: 600;
}
.empty-sub {
  margin-top: 16rpx;
  font-size: 24rpx;
  color: #999;
  line-height: 1.6;
}
.msg-row {
  display: flex;
  margin-bottom: 24rpx;
}
.row-user { justify-content: flex-end; }
.row-ai { justify-content: flex-start; }
.bubble {
  max-width: 80%;
  padding: 18rpx 24rpx;
  border-radius: 16rpx;
  font-size: 28rpx;
  line-height: 1.6;
}
.bubble-user {
  background: #1c1a17;
  color: #fff;
  border-bottom-right-radius: 4rpx;
}
.bubble-ai {
  background: #f2efe8;
  color: #2e2a24;
  border-bottom-left-radius: 4rpx;
}
.typing { color: #999; }
.input-bar {
  display: flex;
  align-items: center;
  padding: 16rpx 20rpx;
  border-top: 1rpx solid #f0eeea;
  gap: 16rpx;
}
.chat-input {
  flex: 1;
  background: #f5f4f1;
  border-radius: 40rpx;
  padding: 16rpx 28rpx;
  font-size: 28rpx;
}
.send-btn {
  padding: 16rpx 36rpx;
  background: linear-gradient(135deg, #c9a45c, #e9b964);
  color: #1c1a17;
  border-radius: 40rpx;
  font-size: 28rpx;
  font-weight: 600;
}
.send-disabled { opacity: 0.5; }
</style>

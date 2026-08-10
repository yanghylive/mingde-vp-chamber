<template>
  <view class="chat-page">
    <view class="chat-top">
      <view class="ct-left">
        <text class="ct-title">{{ expertId ? '大咖 AI 对话' : (topic || 'AI 助手') }}</text>
        <text class="ct-sub">24h 在线 · 问答 / 课程推荐 / 一键预约</text>
      </view>
      <view v-if="messages.length > 0" class="ct-clear" @tap="clearChat">清空</view>
    </view>

    <scroll-view scroll-y class="msg-list" :scroll-into-view="scrollInto">
      <view v-if="messages.length === 0" class="chat-empty">
        <text class="ce-icon">AI</text>
        <text class="ce-text">{{ topic ? '我是' + topic + '，有什么可以帮你？' : '你好，我是明德 AI 助手' }}</text>
      </view>
      <view
        v-for="m in messages"
        :key="m.id"
        class="{{'msg' + (m.role === 'user' ? ' msg-user' : ' msg-ai')}}"
        :id="'msg-' + m.id"
      >
        <view class="bubble">{{ m.content }}<text v-if="m.streaming" class="cursor">▍</text></view>
      </view>
    </scroll-view>

    <scroll-view v-if="messages.length <= 2 && !sending" scroll-x enable-flex class="suggest-bar">
      <view v-for="s in suggestions" :key="s" class="suggest-chip" @tap="sendSuggestion(s)">{{ s }}</view>
    </scroll-view>

    <view class="input-bar">
      <input
        v-model="draft"
        class="msg-input"
        placeholder="输入你的问题…"
        placeholder-class="ph"
        confirm-type="send"
        @confirm="send"
      />
      <view class="send-btn {{sending ? 'send-btn-disabled' : ''}}" @tap="send">
        {{ sending ? '…' : '发送' }}
      </view>
    </view>
  </view>
</template>

<script>
import { HTTP_REQUEST_URL } from '@/config/app'

let idSeq = 1

export default {
  data() {
    return {
      topic: '',
      expertId: null,
      draft: '',
      sending: false,
      messages: [],
      scrollInto: '',
      suggestions: ['介绍一下这个活动', '帮我推荐一门课程', '适合线上还是线下 1v1？', '大咖能帮我解决什么问题？']
    }
  },
  onLoad(options) {
    this.topic = decodeURIComponent(options.topic || '')
    if (options.expert) this.expertId = Number(options.expert)
    if (options.expert_id) this.expertId = Number(options.expert_id)
    // 恢复历史对话（按 expert 维度持久化，对齐 H5）
    const key = 'chat_' + (this.expertId || 'default')
    const saved = uni.getStorageSync(key)
    if (saved) {
      try {
        const list = JSON.parse(saved)
        if (Array.isArray(list) && list.length) this.messages = list
      } catch (e) {}
    }
    this.chatKey = key
  },
  persist() {
    try {
      uni.setStorageSync(this.chatKey || 'chat_default', JSON.stringify(this.messages.slice(-50)))
    } catch (e) {}
  },
  clearChat() {
    uni.showModal({
      title: '清空对话',
      content: '确定清空当前对话历史？',
      success: (res) => {
        if (res.confirm) {
          uni.removeStorageSync(this.chatKey || 'chat_default')
          this.messages = []
        }
      }
    })
  },
  methods: {
    sendSuggestion(text) {
      this.draft = text
      this.send()
    },
    send() {
      const content = this.draft.trim()
      if (!content || this.sending) return
      this.draft = ''
      this.pushMsg('user', content)
      this.sending = true
      this.pushMsg('assistant', '', true)

      const token = uni.getStorageSync('token') || ''
      const headers = { 'Content-Type': 'application/json', Accept: 'text/event-stream' }
      if (token) headers['Authori-zation'] = 'Bearer ' + token

      // 大咖 AI 分身对话：真实接口（非流式，按分身积分价计费）
      if (this.expertId) {
        const twinHeaders = { 'Content-Type': 'application/json' }
        if (token) twinHeaders['Authori-zation'] = 'Bearer ' + token
        wx.request({
          url: HTTP_REQUEST_URL + '/chamber/v1/ai-twin/' + this.expertId + '/chat',
          method: 'POST',
          header: twinHeaders,
          data: { message: content },
          success: (res) => {
            if (res.statusCode >= 400) {
              const body = res.data
              const msg = (body && body.msg) ? body.msg : '请求失败（' + res.statusCode + '）'
              this.finishAssistant(msg)
            } else {
              this.finishAssistant(extractAnswer(res.data))
            }
          },
          fail: () => {
            this.finishAssistant('抱歉，服务暂时不可用，请稍后再试。')
          },
          complete: () => {
            this.sending = false
            this.persist()
          }
        })
        return
      }

      const task = wx.request({
        url: HTTP_REQUEST_URL + '/chamber/v1/chat',
        method: 'POST',
        header: headers,
        data: { message: content, expert_id: this.expertId || undefined },
        enableChunked: true,
        success: (res) => {
          // 流式结束时（或非流式完整响应）
          if (res.statusCode >= 400) {
            this.finishAssistant('请求失败（' + res.statusCode + '），请稍后再试')
          } else if (!this.streamReceived) {
            // 未收到分块 -> 一次性响应
            this.finishAssistant(extractAnswer(res.data))
          } else {
            this.finishAssistant(this.assistantText)
          }
        },
        fail: () => {
          this.finishAssistant('抱歉，服务暂时不可用，请稍后再试。')
        },
        complete: () => {
          this.sending = false
        }
      })

      this.streamReceived = false
      this.assistantText = ''
      let buf = ''
      task.onChunkReceived((res) => {
        let text = ''
        if (typeof res.data === 'string') {
          text = res.data
        } else if (res.data && res.data.byteLength !== undefined) {
          text = utf8Decode(res.data)
        }
        buf += text
        let idx
        while ((idx = buf.indexOf('\n')) !== -1) {
          const line = buf.slice(0, idx).trim()
          buf = buf.slice(idx + 1)
          if (!line || !line.startsWith('data:')) continue
          const payload = line.slice(5).trim()
          if (!payload) continue
          try {
            const obj = JSON.parse(payload)
            const delta = typeof obj === 'string' ? obj : obj.content || obj.text || (obj.type === 'text' && obj.content)
            if (typeof delta === 'string' && delta) {
              this.streamReceived = true
              this.assistantText += delta
              this.renderAssistant(this.assistantText)
            }
          } catch (e) {
            // 非 JSON 分块忽略
          }
        }
      })
    },
    pushMsg(role, content, streaming) {
      const msg = { id: idSeq++, role, content, streaming: !!streaming }
      this.messages.push(msg)
      this.persist()
      this.scrollTo(msg.id)
    },
    renderAssistant(text) {
      const last = this.messages[this.messages.length - 1]
      if (last && last.role === 'assistant') {
        last.content = text
        this.scrollTo(last.id)
      }
    },
    finishAssistant(text) {
      const last = this.messages[this.messages.length - 1]
      if (last && last.role === 'assistant') {
        last.content = text
        last.streaming = false
        this.scrollTo(last.id)
      }
    },
    scrollTo(id) {
      this.$nextTick(() => {
        this.scrollInto = 'msg-' + id
      })
    }
  }
}

/** UTF-8 ArrayBuffer -> 字符串（兼容小程序无 TextDecoder） */
function utf8Decode(buf) {
  const bytes = new Uint8Array(buf)
  let out = ''
  let i = 0
  while (i < bytes.length) {
    const b = bytes[i]
    if (b < 0x80) {
      out += String.fromCharCode(b)
      i += 1
    } else if (b >= 0xc0 && b < 0xe0) {
      out += String.fromCharCode(((b & 0x1f) << 6) | (bytes[i + 1] & 0x3f))
      i += 2
    } else if (b >= 0xe0 && b < 0xf0) {
      out += String.fromCharCode(((b & 0x0f) << 12) | ((bytes[i + 1] & 0x3f) << 6) | (bytes[i + 2] & 0x3f))
      i += 3
    } else {
      i += 4
    }
  }
  return out
}

/** 从统一响应提取 AI 文本（兼容 content/answer/reply/message/text/data） */
function extractAnswer(body) {
  if (!body) return '（无回复）'
  if (typeof body === 'string') return body
  const d = body.data !== undefined ? body.data : body
  if (typeof d === 'string') return d
  if (typeof d === 'object' && d) {
    for (const k of ['content', 'answer', 'reply', 'message', 'text']) {
      if (typeof d[k] === 'string' && d[k]) return d[k]
    }
    if (typeof d.content === 'object' && d.content && d.content.text) return String(d.content.text)
  }
  return '（无回复）'
}
</script>

<style lang="scss">
.chat-page {
  display: flex;
  flex-direction: column;
  height: 100vh;
  background: #f7f5f0;
}
.msg-list {
  flex: 1;
  padding: 32rpx;
  box-sizing: border-box;
}
.chat-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 120rpx 40rpx;
  gap: 24rpx;
}
.ce-icon {
  font-size: 96rpx;
}
.ce-text {
  font-size: 26rpx;
  color: #8a94a3;
  text-align: center;
}
.msg {
  margin-bottom: 24rpx;
  display: flex;
}
.msg-user {
  justify-content: flex-end;
}
.msg-ai {
  justify-content: flex-start;
}
.bubble {
  max-width: 80%;
  padding: 22rpx 28rpx;
  border-radius: 24rpx;
  font-size: 28rpx;
  line-height: 1.6;
}
.msg-user .bubble {
  background: linear-gradient(135deg, #d98a2d, #b8751d);
  color: #fff;
  border-bottom-right-radius: 8rpx;
}
.msg-ai .bubble {
  background: #fff;
  color: #273b59;
  border-bottom-left-radius: 8rpx;
  box-shadow: 0 4rpx 12rpx rgba(39, 59, 89, 0.04);
}
.cursor {
  animation: blink 1s infinite;
}
@keyframes blink {
  0%, 100% { opacity: 1; }
  50% { opacity: 0; }
}
.input-bar {
  display: flex;
  align-items: center;
  gap: 20rpx;
  padding: 20rpx 32rpx calc(20rpx + env(safe-area-inset-bottom));
  background: #fff;
  box-shadow: 0 -8rpx 24rpx rgba(39, 59, 89, 0.06);
}
.msg-input {
  flex: 1;
  background: #f7f5f0;
  border-radius: 999rpx;
  padding: 20rpx 28rpx;
  font-size: 28rpx;
  color: #273b59;
}
.ph {
  color: #c0c6d0;
}
.send-btn {
  padding: 18rpx 44rpx;
  border-radius: 999rpx;
  background: linear-gradient(135deg, #d98a2d, #b8751d);
  color: #fff;
  font-size: 28rpx;
  font-weight: 700;
}
.send-btn-disabled {
  opacity: 0.5;
}
.ct-left {
  flex: 1;
  min-width: 0;
}
.ct-sub {
  display: block;
  font-size: 20rpx;
  color: #a06a2d;
  margin-top: 4rpx;
}
.chat-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: env(safe-area-inset-top) 32rpx 4rpx;
  background: #fff;
  box-shadow: 0 8rpx 24rpx rgba(39, 59, 89, 0.04);
  z-index: 5;
}
.ct-title {
  font-size: 32rpx;
  font-weight: 700;
  color: #17233d;
}
.ct-clear {
  font-size: 24rpx;
  color: #8a94a3;
  padding: 10rpx 20rpx;
  background: #f1f4f8;
  border-radius: 999rpx;
}
.suggest-bar {
  white-space: nowrap;
  padding: 16rpx 24rpx 0;
  box-sizing: border-box;
}
.suggest-chip {
  display: inline-block;
  margin-right: 16rpx;
  padding: 12rpx 24rpx;
  font-size: 22rpx;
  color: #516580;
  background: rgba(255, 255, 255, 0.7);
  border-radius: 999rpx;
  flex-shrink: 0;
  white-space: nowrap;
}
</style>
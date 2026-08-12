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
            // 未收到分块 -> 一次性响应（可能是 SSE 文本，也可能是 JSON）
            const raw = res.data
            let text = ''
            if (typeof raw === 'string' && raw.indexOf('data:') !== -1) {
              // 老平台不支持 enableChunked：一次性拿到整个 SSE，逐行解析
              const lines = raw.split('\n')
              for (const ln of lines) {
                const l = ln.trim()
                if (!l.startsWith('data:')) continue
                const payload = l.slice(5).trim()
                if (!payload || payload === '[DONE]') continue
                try {
                  const obj = JSON.parse(payload)
                  const delta = typeof obj === 'string' ? obj : obj.content || obj.text || (obj.type === 'text' && obj.content)
                  if (typeof delta === 'string' && delta) text += delta
                } catch (e) {}
              }
            }
            this.finishAssistant(text || extractAnswer(raw))
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
      // 兼容性：老基础库/部分平台不支持 enableChunked + onChunkReceived，
      // 不支持时降级为普通请求（success 一次性拿到完整 SSE 文本再解析）
      if (typeof task.onChunkReceived === 'function') {
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
            // 写操作确认事件：弹确认框，用户点头后调 /confirm 执行
            if (obj && obj.type === 'confirm' && obj.data && obj.data.confirm_id) {
              this.handleConfirm(obj.data)
              continue
            }
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
      }
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
    },
    /** 写操作确认：AI 请求报名/兑换/预约 → 弹确认框 → 用户点头 → 调 /confirm 执行 */
    handleConfirm(data) {
      const names = {
        register_event: '报名活动',
        exchange_product: '积分兑换商品',
        book_appointment: '预约大咖'
      }
      const label = names[data.name] || '执行操作'
      const args = data.args || {}
      let detail = ''
      if (data.name === 'register_event') detail = '报名活动 #' + (args.event_id || '?')
      else if (data.name === 'exchange_product') detail = '兑换商品 #' + (args.product_id || '?') + '（' + (args.points_cost || 0) + ' 积分）'
      else if (data.name === 'book_appointment') detail = '预约大咖 #' + (args.expert_id || '?') + '（' + (args.mode === 'offline' ? '线下' : '线上') + '）'

      uni.showModal({
        title: '确认' + label,
        content: detail + '\n确定由 AI 助手为你执行吗？',
        confirmText: '确认执行',
        cancelText: '取消',
        success: (res) => {
          if (!res.confirm) {
            this.finishAssistant('已取消' + label)
            return
          }
          // 用户确认 → 调服务端执行
          const token = uni.getStorageSync('token') || ''
          const headers = { 'Content-Type': 'application/json' }
          if (token) headers['Authori-zation'] = 'Bearer ' + token
          wx.request({
            url: HTTP_REQUEST_URL + '/chamber/v1/chat/confirm',
            method: 'POST',
            header: headers,
            data: { confirm_id: data.confirm_id },
            success: (r) => {
              const b = r.data || {}
              if (r.statusCode >= 200 && r.statusCode < 300 && b.ok) {
                this.finishAssistant('✅ ' + label + '成功' + (b.data && b.data.status ? '（' + b.data.status + '）' : ''))
              } else {
                this.finishAssistant('❌ ' + label + '失败：' + (b.msg || b.error || ('HTTP ' + r.statusCode)))
              }
            },
            fail: () => {
              this.finishAssistant('❌ ' + label + '失败：网络异常，请重试')
            }
          })
        }
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
  background: #f0ede6;
}

/* ====== 页头：白底卡 + 层次感 ====== */
.chat-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: calc(env(safe-area-inset-top) + 16rpx) 32rpx 20rpx;
  background: linear-gradient(180deg, #fff 0%, #faf8f4 100%);
  border-bottom: 1rpx solid rgba(200, 185, 155, 0.25);
  z-index: 5;
  position: relative;

  &::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 32rpx;
    right: 32rpx;
    height: 1rpx;
    background: linear-gradient(90deg, transparent, rgba(180, 160, 120, 0.15), transparent);
  }
}

.ct-left {
  flex: 1;
  min-width: 0;
}

.ct-title {
  font-size: 34rpx;
  font-weight: 700;
  color: #2c1e0a;
  letter-spacing: 1rpx;
}

.ct-sub {
  display: block;
  font-size: 22rpx;
  color: #a08550;
  margin-top: 6rpx;
  letter-spacing: 0.5rpx;
}

.ct-clear {
  font-size: 24rpx;
  color: #8a9aa8;
  padding: 12rpx 24rpx;
  background: #eee9df;
  border-radius: 999rpx;
  border: 1rpx solid rgba(160, 140, 100, 0.12);
}

/* ====== 消息列表区 ====== */
.msg-list {
  flex: 1;
  padding: 28rpx 28rpx 12rpx;
  box-sizing: border-box;
}

/* ====== 空状态欢迎区（卡片容器） ====== */
.chat-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 80rpx 32rpx 60rpx;
  gap: 20rpx;
  margin: 16rpx 8rpx;
  background: #fff;
  border-radius: 28rpx;
  box-shadow:
    0 4rpx 20rpx rgba(139, 115, 68, 0.06),
    inset 0 1rpx 0 rgba(255, 255, 255, 0.8);
}

.ce-icon {
  font-size: 88rpx;
  font-weight: 300;
  color: transparent;
  background: linear-gradient(135deg, #d98a2d 0%, #b8751d 50%, #8b6530 100%);
  background-clip: text;
  -webkit-background-clip: text;
  width: 144rpx;
  height: 144rpx;
  line-height: 144rpx;
  text-align: center;
  border-radius: 40rpx;
  background-color: #faf6ee;
  background-image: linear-gradient(135deg, #d98a2d, #c48a30);
  -webkit-background-clip: text;
  box-shadow: 0 8rpx 28rpx rgba(217, 138, 45, 0.12);
}

.ce-text {
  font-size: 27rpx;
  color: #7a7870;
  text-align: center;
  line-height: 1.6;
  max-width: 480rpx;
}

/* ====== 消息气泡 ====== */
.msg {
  margin-bottom: 20rpx;
  display: flex;
}
.msg-user { justify-content: flex-end; }
.msg-ai { justify-content: flex-start; }

.bubble {
  max-width: 76%;
  padding: 22rpx 28rpx;
  border-radius: 24rpx;
  font-size: 28rpx;
  line-height: 1.65;
  word-break: break-word;
}
.msg-user .bubble {
  background: linear-gradient(135deg, #d98a2d, #b8751d);
  color: #fff;
  border-bottom-right-radius: 8rpx;
}
.msg-ai .bubble {
  background: #fff;
  color: #2c2c28;
  border-bottom-left-radius: 8rpx;
  box-shadow: 0 4rpx 14rpx rgba(39, 35, 28, 0.05);
}

.cursor {
  animation: blink 1s infinite;
  font-weight: 300;
}
@keyframes blink {
  0%, 100% { opacity: 1; }
  50% { opacity: 0; }
}

/* ====== 建议快捷词栏 ====== */
.suggest-bar {
  white-space: nowrap;
  padding: 14rpx 28rpx 6rpx;
  box-sizing: border-box;
  background: transparent;
}

.suggest-chip {
  display: inline-block;
  margin-right: 16rpx;
  margin-bottom: 8rpx;
  padding: 14rpx 26rpx;
  font-size: 23rpx;
  color: #5a5548;
  background: #fff;
  border-radius: 999rpx;
  flex-shrink: 0;
  white-space: nowrap;
  border: 1rpx solid rgba(180, 165, 130, 0.18);
  box-shadow: 0 2rpx 8rpx rgba(139, 115, 68, 0.04);
  transition: transform 0.15s ease;

  &:active {
    transform: scale(0.96);
    background: #faf6ee;
  }
}

/* ====== 输入栏 ====== */
.input-bar {
  display: flex;
  align-items: center;
  gap: 18rpx;
  padding: 18rpx 28rpx calc(18rpx + env(safe-area-inset-bottom));
  background: #fff;
  border-top: 1rpx solid rgba(200, 185, 155, 0.2);
  box-shadow: 0 -6rpx 28rpx rgba(44, 30, 10, 0.04);
}

.msg-input {
  flex: 1;
  background: #f5f2ea;
  border-radius: 999rpx;
  padding: 20rpx 28rpx;
  font-size: 28rpx;
  color: #2c2c28;
  border: 1rpx solid rgba(180, 165, 130, 0.12);
}

.ph {
  color: #b5ad9a;
}

.send-btn {
  padding: 18rpx 42rpx;
  border-radius: 999rpx;
  background: linear-gradient(135deg, #d98a2d, #b8751d);
  color: #fff;
  font-size: 27rpx;
  font-weight: 700;
  letter-spacing: 2rpx;
  box-shadow: 0 4rpx 14rpx rgba(217, 138, 45, 0.25);

  &:active {
    opacity: 0.85;
    transform: scale(0.97);
  }
}

.send-btn-disabled {
  opacity: 0.45;
}
</style>
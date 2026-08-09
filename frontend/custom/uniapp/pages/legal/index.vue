<template>
  <view class="legal-page">
    <view class="legal-head">
      <text class="legal-title">{{ isPrivacy ? '隐私政策' : '用户服务协议' }}</text>
      <text class="legal-sub">明德恒智AI企商汇 · 更新日期 {{ updated }}</text>
    </view>

    <view class="legal-body">
      <block v-if="isPrivacy">
        <text class="sec">一、引言</text>
        <text class="para">
          明德恒智AI企商汇（以下简称"本平台"）重视您的个人信息与隐私保护。本政策说明我们如何收集、使用、存储和保护您的信息。使用本平台即表示您已阅读并同意本政策；如不同意，请勿使用本平台服务。
        </text>

        <text class="sec">二、我们收集的信息</text>
        <text class="para">
          1. 账号信息：您注册或登录时提供的手机号码，以及我们为您生成的用户标识；\n
          2. 会员信息：您主动填写的姓名、头像、企业信息、认证资料等；\n
          3. 行为信息：您使用本平台时的操作记录，如活动报名、积分变动、AI 服务使用记录；\n
          4. 设备信息：为保障服务安全，我们可能收集设备型号、系统版本、网络状态等基础信息。
        </text>

        <text class="sec">三、信息的使用</text>
        <text class="para">
          我们仅将上述信息用于：为您提供会员、活动、积分与 AI 认知服务；服务通知与消息触达；安全风控与异常排查；法律法规要求的用途。我们不会向无关第三方出售您的个人信息。
        </text>

        <text class="sec">四、信息的存储与保护</text>
        <text class="para">
          您的信息存储于中华人民共和国境内服务器，我们采取加密传输、访问控制、日志审计等措施保护数据安全。如发生数据安全事件，我们将依法及时告知。
        </text>

        <text class="sec">五、您的权利</text>
        <text class="para">
          您有权查询、更正、删除您的个人信息，或注销账号。如需行使上述权利，可通过本平台「我的-设置」或联系运营方办理。我们将在 15 个工作日内响应。
        </text>

        <text class="sec">六、未成年人保护</text>
        <text class="para">
          本平台服务面向企业经营者，不面向未成年人。若我们发现在未获监护人同意的情况下收集了未成年人信息，将尽快删除。
        </text>

        <text class="sec">七、政策更新</text>
        <text class="para">
          本政策可能适时更新，更新后将在本页面公布。重大变更将以显著方式通知您。
        </text>
      </block>

      <block v-else>
        <text class="sec">一、服务说明</text>
        <text class="para">
          本平台为明德恒智AI企商汇提供的企业家线上服务平台，包含会员服务、活动报名、积分权益及 AI 认知提升服务（AI 生成内容仅供参考，不构成任何专业建议）。
        </text>

        <text class="sec">二、账号与登录</text>
        <text class="para">
          1. 您应妥善保管账号信息，因账号保管不善造成的损失由您自行承担；\n
          2. 不得利用本平台从事违法活动或侵犯他人合法权益；\n
          3. 您确认登录后使用本平台服务，即视为您本人操作。
        </text>

        <text class="sec">三、会员服务与付费</text>
        <text class="para">
          1. 付费会员权益以购买时展示的权益说明为准；\n
          2. 会员服务为虚拟服务，购买后按约定提供相应期间的服务；\n
          3. 具体退款规则以购买页面的说明为准。
        </text>

        <text class="sec">四、AI 服务说明</text>
        <text class="para">
          1. AI 服务生成的内容由人工智能模型生成，可能存在不准确或遗漏，请理性参考；\n
          2. 您不得利用 AI 服务生成违法、侵权或违背公序良俗的内容；\n
          3. AI 生成内容页面均标注「AI 生成」字样。
        </text>

        <text class="sec">五、活动与积分</text>
        <text class="para">
          1. 活动报名以页面展示的时间、地点、规则为准；\n
          2. 积分仅限本平台使用，不可兑换现金，具体以平台规则为准。
        </text>

        <text class="sec">六、免责声明</text>
        <text class="para">
          因不可抗力、网络故障、第三方服务中断等导致的服务异常，本平台不承担由此造成的间接损失。您在使用服务过程中产生的风险由您自行承担。
        </text>

        <text class="sec">七、协议变更</text>
        <text class="para">
          本协议可能适时更新，更新后将在本页面公布。您继续使用本平台服务，视为接受更新后的协议。
        </text>
      </block>
    </view>

    <view class="legal-foot">
      <text class="foot-text">明德恒智AI企商汇</text>
    </view>
  </view>
</template>

<script>
export default {
  data() {
    return {
      type: 'agreement',
      updated: '2026年8月9日'
    }
  },
  computed: {
    isPrivacy() {
      return this.type === 'privacy'
    }
  },
  onLoad(options) {
    if (options && options.type === 'privacy') {
      this.type = 'privacy'
    }
    uni.setNavigationBarTitle({
      title: this.isPrivacy ? '隐私政策' : '用户服务协议'
    })
  }
}
</script>

<style lang="scss">
.legal-page {
  min-height: 100vh;
  background: #fff;
  padding: 40rpx 40rpx 80rpx;
  box-sizing: border-box;
}
.legal-head {
  margin-bottom: 40rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
}
.legal-title {
  font-size: 44rpx;
  font-weight: 700;
  color: #1b2a4a;
}
.legal-sub {
  font-size: 24rpx;
  color: #8a94a3;
  margin-top: 12rpx;
}
.legal-body {
  background: #f7f9fc;
  border-radius: 24rpx;
  padding: 32rpx;
}
.sec {
  display: block;
  font-size: 30rpx;
  font-weight: 600;
  color: #1b2a4a;
  margin: 28rpx 0 12rpx;
}
.sec:first-child {
  margin-top: 0;
}
.para {
  display: block;
  font-size: 26rpx;
  line-height: 1.8;
  color: #4a5568;
}
.legal-foot {
  margin-top: 48rpx;
  text-align: center;
}
.foot-text {
  font-size: 22rpx;
  color: #a0a8b5;
}
</style>

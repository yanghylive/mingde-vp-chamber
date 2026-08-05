<script>
import store from './store'

export default {
  globalData: {
    siteConfig: null
  },
  onLaunch() {
    // 启动时静默拉取站点配置（会员端各页展示用）
    // 登录态从 storage 恢复
    const token = uni.getStorageSync('token')
    if (token) {
      store.commit('setToken', token)
    }
    const userInfo = uni.getStorageSync('userInfo')
    if (userInfo) {
      store.commit('setUserInfo', userInfo)
    }
  }
}
</script>

<style>
/* 全局基础样式 */
page {
  background: #f7f5f0;
  color: #273b59;
  font-size: 28rpx;
  line-height: 1.5;
}

/* 通用卡片 */
.card {
  background: #ffffff;
  border-radius: 24rpx;
  box-shadow: 0 4rpx 24rpx rgba(39, 59, 89, 0.05);
}

/* 主按钮 */
.btn-primary {
  background: linear-gradient(135deg, #d98a2d, #b8751d);
  color: #fff;
  border-radius: 999rpx;
  font-size: 30rpx;
  font-weight: 600;
  text-align: center;
  padding: 22rpx 0;
  border: none;
}

.btn-primary::after {
  border: none;
}

/* 次按钮 */
.btn-secondary {
  background: #f1ede4;
  color: #516580;
  border-radius: 999rpx;
  font-size: 30rpx;
  font-weight: 600;
  text-align: center;
  padding: 22rpx 0;
  border: none;
}

.btn-secondary::after {
  border: none;
}

/* 金色渐变文字 */
.gradient-text {
  background: linear-gradient(135deg, #d98a2d, #b8751d);
  -webkit-background-clip: text;
  background-clip: text;
  -webkit-text-fill-color: transparent;
}

/* 安全区 */
.safe-bottom {
  padding-bottom: constant(safe-area-inset-bottom);
  padding-bottom: env(safe-area-inset-bottom);
}
</style>

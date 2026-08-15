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
  },
  // 全局分享（S6）：小程序分享能力开关 + 默认文案
  onShareAppMessage() {
    return {
      title: '明德恒智AI企商汇 · 企业家事业共同体',
      path: '/pages/index/index'
    }
  }
}
</script>

<style>
@import './common/icons.scss';
/* ============ 全局基础（对齐 H5 设计系统） ============ */
page {
  /* H5 同款：金色光晕 + 蓝色光晕 + 蓝灰渐变 */
  background:
    radial-gradient(circle at 14% 8%, rgba(246, 184, 94, 0.18), transparent 30%),
    radial-gradient(circle at 88% 18%, rgba(54, 103, 161, 0.17), transparent 34%),
    linear-gradient(165deg, #e9eef6 0%, #f8f9fb 48%, #e7edf5 100%);
  color: #17233d;
  font-size: 28rpx;
  line-height: 1.5;
  font-family: Inter, "PingFang SC", "Microsoft YaHei", system-ui, -apple-system, sans-serif;
}

/* ============ luxury-card 等效（H5 渐变边框玻璃卡） ============ */
.card {
  position: relative;
  overflow: hidden;
  border: 1rpx solid rgba(183, 201, 221, 0.35);
  background: linear-gradient(145deg, rgba(255, 255, 255, 0.8), rgba(244, 248, 252, 0.59));
  box-shadow:
    0 20px 46px rgba(16, 43, 80, 0.1),
    0 4px 12px rgba(16, 43, 80, 0.045),
    inset 0 1px 0 rgba(255, 255, 255, 0.72),
    inset 0 -1px 0 rgba(82, 112, 146, 0.07);
  -webkit-backdrop-filter: blur(24px) saturate(145%);
  backdrop-filter: blur(24px) saturate(145%);
}

/* 卡片顶部高光线（H5 luxury-card::before） */
.card::before {
  content: "";
  position: absolute;
  top: 0;
  left: 8%;
  width: 52%;
  height: 1rpx;
  background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.86), rgba(255, 255, 255, 0.22), transparent);
  pointer-events: none;
}

/* ============ glass-dark（深色卡：会员卡/等级卡） ============ */
.glass-dark {
  position: relative;
  overflow: hidden;
  border: 1rpx solid rgba(236, 171, 82, 0.3);
  background: linear-gradient(145deg, rgba(12, 37, 72, 0.91), rgba(23, 66, 108, 0.78));
  box-shadow:
    0 24px 54px rgba(8, 29, 60, 0.27),
    0 3px 10px rgba(8, 29, 60, 0.1),
    inset 0 1px 0 rgba(255, 255, 255, 0.12),
    inset 0 -1px 0 rgba(0, 0, 0, 0.14);
  -webkit-backdrop-filter: blur(24px) saturate(135%);
  backdrop-filter: blur(24px) saturate(135%);
}

/* 深色卡右上金线（H5 glass-dark::after） */
.glass-dark::after {
  content: "";
  position: absolute;
  top: 0;
  right: 9%;
  width: 46%;
  height: 1rpx;
  background: linear-gradient(90deg, transparent, rgba(255, 225, 180, 0.36), transparent);
  pointer-events: none;
}

/* ============ glass-control（玻璃控件：输入/容器） ============ */
.glass-control {
  border: 1rpx solid rgba(177, 195, 214, 0.35);
  background: linear-gradient(145deg, rgba(255, 255, 255, 0.76), rgba(247, 250, 253, 0.56));
  box-shadow:
    0 10px 24px rgba(17, 47, 86, 0.075),
    inset 0 1px 0 rgba(255, 255, 255, 0.69),
    inset 0 -1px 0 rgba(68, 99, 133, 0.055);
  -webkit-backdrop-filter: blur(18px) saturate(140%);
  backdrop-filter: blur(18px) saturate(140%);
}

/* 选中态：深蓝玻璃 + 金边 */
.glass-control-active {
  border: 1rpx solid rgba(222, 150, 57, 0.4);
  background: linear-gradient(145deg, rgba(16, 48, 88, 0.95), rgba(29, 73, 117, 0.85));
  box-shadow:
    0 12px 26px rgba(14, 45, 82, 0.19),
    0 0 0 1px rgba(180, 117, 39, 0.07),
    inset 0 1px 0 rgba(255, 255, 255, 0.12),
    inset 0 -1px 0 rgba(3, 22, 45, 0.13);
  color: #fff;
  -webkit-backdrop-filter: blur(18px) saturate(145%);
  backdrop-filter: blur(18px) saturate(145%);
}

/* ============ 按钮（对齐 H5 Button 组件） ============ */
.btn-primary {
  background: linear-gradient(90deg, #c87922, #eba94e);
  color: #fff;
  border-radius: 24rpx;
  font-size: 28rpx;
  font-weight: 600;
  text-align: center;
  padding: 22rpx 0;
  border: 1rpx solid rgba(230, 168, 84, 0.55);
  box-shadow:
    0 10px 24px rgba(185, 110, 29, 0.2),
    inset 0 1px 0 rgba(255, 245, 221, 0.42),
    inset 0 -1px 0 rgba(120, 64, 12, 0.12);
  -webkit-backdrop-filter: blur(16px);
  backdrop-filter: blur(16px);
}

.btn-primary::after {
  border: none;
}

.btn-secondary {
  background: rgba(255, 255, 255, 0.58);
  color: #15305b;
  border-radius: 24rpx;
  font-size: 28rpx;
  font-weight: 600;
  text-align: center;
  padding: 22rpx 0;
  border: 1rpx solid rgba(185, 201, 218, 0.4);
  box-shadow:
    0 8px 20px rgba(18, 46, 82, 0.08),
    inset 0 1px 0 rgba(255, 255, 255, 0.76),
    inset 0 -1px 0 rgba(91, 121, 153, 0.07);
  -webkit-backdrop-filter: blur(20px);
  backdrop-filter: blur(20px);
}

.btn-secondary::after {
  border: none;
}

/* ============ 金色渐变文字 ============ */
.gradient-text {
  background: linear-gradient(135deg, #eba94e, #c87922);
  -webkit-background-clip: text;
  background-clip: text;
  -webkit-text-fill-color: transparent;
}

.gold-text {
  color: #c87922;
}

.px-4 {
  padding-left: 32rpx;
  padding-right: 32rpx;
}
.box-sizing {
  box-sizing: border-box;
}

/* 安全区 */
.safe-bottom {
  padding-bottom: constant(safe-area-inset-bottom);
  padding-bottom: env(safe-area-inset-bottom);
}
</style>

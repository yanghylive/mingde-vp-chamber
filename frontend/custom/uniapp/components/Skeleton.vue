<template>
  <view class="skeleton">
    <!-- 列表骨架 -->
    <block v-if="type === 'list'">
      <view v-for="i in rows" :key="i" class="sk-row card">
        <view class="sk-block sk-img" />
        <view class="sk-lines">
          <view class="sk-line sk-line-title" />
          <view class="sk-line sk-line-mid" />
          <view class="sk-line sk-line-short" />
        </view>
      </view>
    </block>

    <!-- 卡片/宫格骨架 -->
    <block v-else-if="type === 'grid'">
      <view class="sk-grid">
        <view v-for="i in rows" :key="i" class="sk-card card">
          <view class="sk-block sk-big" />
          <view class="sk-line sk-line-mid" />
          <view class="sk-line sk-line-short" />
        </view>
      </view>
    </block>

    <!-- 默认列表行 -->
    <block v-else>
      <view v-for="i in rows" :key="i" class="sk-row card">
        <view class="sk-line sk-line-title" />
      </view>
    </block>
  </view>
</template>

<script>
export default {
  name: 'Skeleton',
  props: {
    type: { type: String, default: 'list' },
    rows: { type: Number, default: 4 }
  }
}
</script>

<style lang="scss">
/* shimmer 骨架屏 */
.sk-row {
  display: flex;
  gap: 20rpx;
  padding: 28rpx;
  margin-bottom: 20rpx;
}
.sk-img {
  width: 96rpx;
  height: 96rpx;
  border-radius: 20rpx;
  flex-shrink: 0;
}
.sk-lines {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 14rpx;
  padding-top: 8rpx;
}
.sk-line {
  height: 24rpx;
  border-radius: 8rpx;
}
.sk-line-title {
  width: 60%;
}
.sk-line-mid {
  width: 85%;
}
.sk-line-short {
  width: 40%;
}
.sk-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 20rpx;
}
.sk-card {
  width: calc(50% - 10rpx);
  padding: 24rpx;
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  gap: 14rpx;
}
.sk-big {
  height: 180rpx;
  border-radius: 16rpx;
}
.sk-block,
.sk-line {
  background: linear-gradient(90deg, #f2f0eb 25%, #e9e6df 37%, #f2f0eb 63%);
  background-size: 400% 100%;
  animation: shimmer 1.4s ease infinite;
}
@keyframes shimmer {
  0% {
    background-position: 100% 50%;
  }
  100% {
    background-position: 0 50%;
  }
}
</style>

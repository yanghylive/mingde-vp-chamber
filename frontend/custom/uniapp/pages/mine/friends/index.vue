<template>
  <view class="friends-page">
    <page-header title="我的好友" eyebrow="按等级 / 地区 / 行业筛选" />
    <!-- 筛选模式（对齐 H5：全部/等级/地区/行业） -->
    <view class="chips">
      <view
        v-for="m in modes"
        :key="m.key"
        class="{{'chip' + (mode === m.key ? ' chip-active' : '')}}"
        @tap="switchMode(m.key)"
      >
        {{ m.label }}
      </view>
    </view>

    <!-- 模式值 chips -->
    <view v-if="mode !== 'all'" class="chips">
      <view
        v-for="v in optionList"
        :key="v"
        class="{{'chip chip-sub' + (value === v ? ' chip-active' : '')}}"
        @tap="value = v; applyFilter()"
      >
        {{ v }}
      </view>
    </view>

    <view v-if="loading" class="empty">加载中…</view>
    <view v-else-if="filtered.length === 0" class="empty-wrap">
        <view class="empty-icon"><view class="ic ic-md ic-users-gray" /></view>
        <text class="empty-title">同频者，终将相遇</text>
        <text class="empty-sub">好友需要 L2 及以上会员，当前为 L1。请到会员中心开通。</text>
      </view>
    <view v-else class="list">
      <view v-for="f in filtered" :key="f.id" class="friend card">
        <view class="f-avatar">{{ (f.nickname || f.real_name || '友').slice(0, 1) }}</view>
        <view class="f-info">
          <view class="f-name-row">
            <text class="f-name">{{ f.nickname || f.real_name || '明德会员' }}</text>
            <text v-if="f.tier" class="f-tier">L{{ f.tier }}</text>
          </view>
          <text class="f-meta">{{ f.region && f.industry ? f.region + ' · ' + f.industry : (f.region || f.industry || f.company || '明德精英') }}</text>
        </view>
        <view class="{{'f-status' + (f.status === 'accepted' ? ' f-accepted' : ' f-pending')}}">
          {{ f.status === 'accepted' ? '已通过' : '待确认' }}
        </view>
      </view>
    </view>
  </view>
</template>

<script>
import PageHeader from '@/components/PageHeader.vue'
import chamber from '@/api/chamber'
import { checkLogin } from '@/libs/login'

export default {
  components: { PageHeader },
  data() {
    return {
      friends: [],
      filtered: [],
      loading: true,
      mode: 'all',
      value: '',
      modes: [
        { key: 'all', label: '全部' },
        { key: 'tier', label: '等级' },
        { key: 'region', label: '地区' },
        { key: 'industry', label: '行业' }
      ],
      tierOptions: ['L1', 'L2', 'L3', 'L4'],
      regionOptions: [],
      industryOptions: []
    }
  },
  computed: {
    optionList() {
      if (this.mode === 'tier') return ['全部'].concat(this.tierOptions)
      if (this.mode === 'region') return ['全部'].concat(this.regionOptions)
      if (this.mode === 'industry') return ['全部'].concat(this.industryOptions)
      return []
    }
  },
  onLoad() {
    if (!checkLogin()) {
      uni.navigateTo({ url: '/pages/login/index' })
      return
    }
    this.loadData()
  },
  methods: {
    async loadData() {
      try {
        this.friends = await chamber.meFriends()
      } catch (e) {}
      this.applyFilter()
      this.loading = false
    },
    switchMode(key) {
      this.mode = key
      this.value = ''
      if (key === 'all') {
        this.filtered = this.friends
      } else {
        // 加载对应选项（去重）
        if (key === 'region') {
          this.regionOptions = Array.from(new Set(this.friends.map((f) => f.region).filter(Boolean)))
        } else if (key === 'industry') {
          this.industryOptions = Array.from(new Set(this.friends.map((f) => f.industry).filter(Boolean)))
        }
        this.applyFilter()
      }
    },
    applyFilter() {
      const key = this.mode
      const v = this.value
      this.filtered = this.friends.filter((f) => {
        if (key === 'tier') return !v || Number(f.tier) === Number(v.replace('L', ''))
        if (key === 'region') return !v || f.region === v
        if (key === 'industry') return !v || f.industry === v
        return true
      })
    }
  }
}
</script>

<style lang="scss">
.friends-page {
  padding: 24rpx 32rpx 60rpx;
}
.chips {
  display: flex;
  gap: 16rpx;
  margin-bottom: 24rpx;
  overflow-x: auto;
  white-space: nowrap;
}
.chip {
  flex-shrink: 0;
  padding: 14rpx 30rpx;
  border-radius: 999rpx;
  background: #fff;
  color: #516580;
  font-size: 26rpx;
  box-shadow: 0 4rpx 12rpx rgba(39, 59, 89, 0.04);
}
.chip-sub {
  background: #f1f4f8;
  box-shadow: none;
  padding: 10rpx 24rpx;
  font-size: 22rpx;
}
.chip-active {
  background: linear-gradient(135deg, #d98a2d, #b8751d);
  color: #fff;
  font-weight: 600;
}
.empty {
  text-align: center;
  padding: 100rpx 0;
  color: #c0c6d0;
  font-size: 26rpx;
}
.list {
  display: flex;
  flex-direction: column;
  gap: 20rpx;
}
.friend {
  display: flex;
  align-items: center;
  gap: 20rpx;
  padding: 26rpx;
}
.f-avatar {
  width: 88rpx;
  height: 88rpx;
  border-radius: 24rpx;
  background: linear-gradient(135deg, #fff0dc, #f6e2c2);
  color: #b8751d;
  font-size: 36rpx;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.f-info {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 6rpx;
}
.f-name-row {
  display: flex;
  align-items: center;
  gap: 10rpx;
}
.f-tier {
  font-size: 18rpx;
  font-weight: 700;
  color: #b8751d;
  background: #f6ead6;
  padding: 2rpx 12rpx;
  border-radius: 8rpx;
}
.f-name {
  font-size: 28rpx;
  font-weight: 600;
  color: #273b59;
}
.f-meta {
  font-size: 22rpx;
  color: #8a94a3;
}
.f-status {
  font-size: 22rpx;
  padding: 8rpx 20rpx;
  border-radius: 999rpx;
}
.f-accepted {
  color: #4c8a3f;
  background: #f0f7ec;
}
.f-pending {
  color: #c57620;
  background: #f6ead6;
}
</style>

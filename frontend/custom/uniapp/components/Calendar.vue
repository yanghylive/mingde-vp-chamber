<template>
  <view class="calendar">
    <!-- 月份切换 -->
    <view class="cal-head">
      <view class="cal-nav" @tap="prevMonth"></view>
      <view class="cal-title" @tap="gotoToday">
        <text>{{ viewYear }}年{{ viewMonth }}月</text>
        <text v-if="!isCurrentMonth" class="cal-today-tag">回到今天</text>
      </view>
      <view class="cal-count">本月 {{ monthCount }} 场</view>
      <view class="cal-nav" @tap="nextMonth">></view>
    </view>

    <!-- 星期头 -->
    <view class="cal-week">
      <view v-for="w in weekdays" :key="w" class="cal-wd">{{ w }}</view>
    </view>

    <!-- 日期网格 -->
    <view class="cal-grid">
      <view
        v-for="(d, i) in days"
        :key="i"
        :class="[
          'cal-cell',
          !d.inMonth && 'cal-cell-out',
          d.isToday && 'cal-cell-today',
          d.isSelected && 'cal-cell-selected'
        ]"
        @tap="selectDay(d)"
      >
        <text :class="['cal-num', d.isSelected && 'cal-num-selected']">{{ d.num }}</text>
        <view v-if="d.inMonth && d.hasEvents" :class="['cal-dot', d.isSelected && 'cal-dot-selected']" />
      </view>
    </view>
  </view>
</template>

<script>
import { toDate } from '@/common/format'

const WEEKDAYS = ['日', '一', '二', '三', '四', '五', '六']

function pad(n) {
  return n < 10 ? '0' + n : '' + n
}

function dayKey(ts) {
  return toDate(ts)
}

export default {
  name: 'Calendar',
  props: {
    events: {
      type: Array,
      default: () => []
    },
    monthCount: {
      type: Number,
      default: 0
    }
  },
  data() {
    const now = new Date()
    return {
      weekdays: WEEKDAYS,
      viewYear: now.getFullYear(),
      viewMonth: now.getMonth() + 1,
      selectedKey: ''
    }
  },
  computed: {
    isCurrentMonth() {
      const now = new Date()
      return this.viewYear === now.getFullYear() && this.viewMonth === now.getMonth() + 1
    },
    eventsByDate() {
      const map = {}
      for (const ev of this.events || []) {
        const k = dayKey(ev.start_time)
        if (!k) continue
        if (!map[k]) map[k] = []
        map[k].push(ev)
      }
      return map
    },
    days() {
      const y = this.viewYear
      const m = this.viewMonth
      const first = new Date(y, m - 1, 1)
      const firstWeekday = first.getDay()
      const daysInMonth = new Date(y, m, 0).getDate()
      const prevDays = new Date(y, m - 1, 0).getDate()
      const todayKey = dayKey(Date.now() / 1000)
      const list = []
      for (let i = 0; i < 42; i++) {
        const cellNum = i - firstWeekday + 1
        let num = cellNum
        let inMonth = true
        let key = ''
        if (cellNum <= 0) {
          num = prevDays + cellNum
          inMonth = false
          key = `${y}-${pad(m - 1)}-${pad(num)}`
        } else if (cellNum > daysInMonth) {
          num = cellNum - daysInMonth
          inMonth = false
          key = `${y}-${pad(m + 1)}-${pad(num)}`
        } else {
          key = `${y}-${pad(m)}-${pad(num)}`
        }
        list.push({
          num,
          inMonth,
          key,
          isToday: key === todayKey,
          isSelected: key === this.selectedKey,
          hasEvents: !!this.eventsByDate[key]
        })
      }
      return list
    }
  },
  methods: {
    prevMonth() {
      if (this.viewMonth === 1) {
        this.viewYear -= 1
        this.viewMonth = 12
      } else {
        this.viewMonth -= 1
      }
      this.selectedKey = ''
      this.$emit('select', [])
    },
    nextMonth() {
      if (this.viewMonth === 12) {
        this.viewYear += 1
        this.viewMonth = 1
      } else {
        this.viewMonth += 1
      }
      this.selectedKey = ''
      this.$emit('select', [])
    },
    gotoToday() {
      const now = new Date()
      this.viewYear = now.getFullYear()
      this.viewMonth = now.getMonth() + 1
      this.selectedKey = ''
      this.$emit('select', [])
    },
    selectDay(d) {
      if (!d.inMonth) {
        // 点击补位日期 → 跳到对应月
        this.viewMonth = Number(d.key.slice(5, 7))
        this.viewYear = Number(d.key.slice(0, 4))
      }
      if (this.selectedKey === d.key) {
        this.selectedKey = ''
        this.$emit('select', [])
        return
      }
      this.selectedKey = d.key
      this.$emit('select', this.eventsByDate[d.key] || [])
    }
  }
}
</script>

<style lang="scss">
.calendar {
  background: #fff;
  border-radius: 28rpx;
  padding: 28rpx 24rpx;
  box-shadow: 0 4rpx 24rpx rgba(39, 59, 89, 0.05);
}
.cal-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 20rpx;
}
.cal-nav {
  width: 56rpx;
  height: 56rpx;
  border-radius: 50%;
  background: #f0f3f7;
  color: #60708a;
  font-size: 32rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 600;
}
.cal-title {
  display: flex;
  align-items: center;
  gap: 14rpx;
  font-size: 26rpx;
  font-weight: 800;
  color: #203a5c;
}
.cal-count {
  font-size: 20rpx;
  color: #97a1af;
  margin-right: 16rpx;
}
.cal-today-tag {
  font-size: 20rpx;
  color: #b8751d;
  background: #f6ead6;
  padding: 4rpx 12rpx;
  border-radius: 999rpx;
  font-weight: 500;
}
.cal-week {
  display: flex;
}
.cal-wd {
  flex: 1;
  text-align: center;
  font-size: 22rpx;
  font-weight: 600;
  color: #9aa3b0;
  padding: 8rpx 0;
}
.cal-grid {
  display: flex;
  flex-wrap: wrap;
}
.cal-cell {
  width: 14.285%;
  height: 88rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  position: relative;
  border-radius: 16rpx;
}
.cal-num {
  font-size: 26rpx;
  font-weight: 600;
  color: #31455f;
}
.cal-cell-out .cal-num {
  color: #d4d9e0;
}
.cal-cell-today {
  background: #fff0dc;
}
.cal-cell-today .cal-num {
  color: #a9651e;
}
.cal-cell-selected {
  background: #183d6d;
}
.cal-num-selected {
  color: #fff !important;
}
.cal-dot {
  position: absolute;
  bottom: 12rpx;
  width: 8rpx;
  height: 8rpx;
  border-radius: 50%;
  background: #d98a2d;
}
.cal-dot-selected {
  background: #f5c77f;
}
</style>

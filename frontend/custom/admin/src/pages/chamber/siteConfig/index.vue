<template>
  <div class="site-config-workbench">
    <el-alert
      class="info-alert"
      title="站点通用配置：客服二维码、会员等级权益、积分兑换比例、AI 生态入口、首页 5 宫格。保存后会员端立即生效。"
      type="info"
      show-icon
      :closable="false"
    />

    <el-alert v-if="loadError" class="load-alert" type="error" :title="loadError" show-icon :closable="false">
      <el-button slot="description" size="mini" @click="loadConfig">重新加载</el-button>
    </el-alert>

    <el-card shadow="never" class="panel" v-loading="loading">
      <!-- 1. 客服微信 -->
      <div class="section">
        <h2 class="section-title">① 客服微信</h2>
        <el-form label-width="110px" class="form">
          <el-form-item label="二维码图片 URL">
            <el-input v-model="cfg.customer_service.qr_image" placeholder="https://... 客服二维码图片地址（可空）" clearable>
              <el-button slot="append" @click="pasteQr">粘贴</el-button>
            </el-input>
          </el-form-item>
          <el-form-item label="微信号">
            <el-input v-model="cfg.customer_service.wechat_id" placeholder="客服微信号（展示在二维码下方）" maxlength="40" clearable />
          </el-form-item>
        </el-form>
      </div>

      <!-- 2. 会员等级权益 -->
      <div class="section">
        <div class="section-head">
          <h2 class="section-title">② 会员等级权益（L1-L4）</h2>
          <span class="section-hint">修改等级名 / 权益列表，会员中心与首页同步</span>
        </div>
        <div v-for="(lv, i) in cfg.member_ladder" :key="lv.tier" class="ladder-row">
          <span class="ladder-tag">L{{ lv.tier }}</span>
          <el-input v-model="lv.name" placeholder="等级名称" style="width: 160px" maxlength="12" />
          <div class="rights-editor">
            <div v-for="(r, ri) in lv.rights" :key="ri" class="right-row">
              <el-input v-model="lv.rights[ri]" placeholder="权益项" maxlength="30" size="small" style="width: 220px" />
              <el-button type="danger" size="mini" icon="el-icon-delete" @click="removeRight(i, ri)" />
            </div>
            <el-button size="mini" icon="el-icon-plus" @click="addRight(i)">添加权益</el-button>
          </div>
        </div>
      </div>

      <!-- 3. 积分兑换比例 -->
      <div class="section">
        <h2 class="section-title">③ 积分兑换比例</h2>
        <el-form label-width="110px" class="form">
          <el-form-item label="1 元 = 积分">
            <el-input-number v-model="cfg.points_ratio.points_per_yuan" :min="1" :max="1000" :step="1" />
            <span class="section-hint" style="margin-left: 10px">如 10 = 1 元抵 10 积分；修改后商城差价计算同步</span>
          </el-form-item>
        </el-form>
      </div>

      <!-- 4. AI 生态入口 -->
      <div class="section">
        <h2 class="section-title">④ AI 生态入口（4 个）</h2>
        <div v-for="(e, i) in cfg.ai_entries" :key="i" class="entry-row">
          <span class="ladder-tag">{{ i + 1 }}</span>
          <el-input v-model="e.title" placeholder="入口标题" style="width: 200px" maxlength="20" />
          <el-input v-model="e.topic" placeholder="对话主题（传给 AI）" style="width: 220px" maxlength="30" />
        </div>
      </div>

      <!-- 5. 首页 5 宫格 -->
      <div class="section">
        <h2 class="section-title">⑤ 首页 5 宫格</h2>
        <div v-for="(g, i) in cfg.home_grids" :key="i" class="entry-row">
          <span class="ladder-tag">{{ i + 1 }}</span>
          <el-input v-model="g.label" placeholder="宫格标题" style="width: 160px" maxlength="8" />
          <el-input v-model="g.to" placeholder="跳转路径（如 /events）" style="width: 200px" maxlength="40" />
        </div>
      </div>

      <div class="foot">
        <el-button type="primary" :loading="saving" @click="saveConfig">保存全部配置</el-button>
        <el-button @click="loadConfig">恢复页面</el-button>
        <span class="section-hint">保存后会员端立即生效</span>
      </div>
    </el-card>
  </div>
</template>

<script>
import { siteConfigGet, siteConfigSave } from '@/api/chamber/siteConfig';
import { Message } from 'element-ui';

const DEFAULT_CFG = {
  customer_service: { qr_image: '', wechat_id: '' },
  member_ladder: [
    { tier: 1, name: '入门会员', rights: ['基础活动报名', '会员列表查看', '官方活动月历', '活动签到获取积分', '会员基础资料'] },
    { tier: 2, name: '进阶会员', rights: ['开放好友申请', '大咖预约（线上 1v1）', '精选活动优先席位', '成长测评报告', '积分兑换商城'] },
    { tier: 3, name: '三阶毕业生', rights: ['好友资料全开放', '分销码权益', '大咖预约（线下 1v1）', '闭门私享会席位', '专属成长档案'] },
    { tier: 4, name: '核心伙伴', rights: ['项目路演优先', 'AI 陪跑席位', '名企 AI 咨询', '理事圆桌闭门会', '生态共创资源池'] },
  ],
  points_ratio: { points_per_yuan: 10 },
  ai_entries: [
    { title: '名企 AI 咨询', topic: '名企 AI 咨询' },
    { title: '现有工具箱', topic: '工具箱' },
    { title: '陪跑搭建', topic: '陪跑搭建' },
    { title: '圈子·课程', topic: '圈子课程' },
  ],
  home_grids: [
    { label: '官方活动', to: '/events' },
    { label: '会员中心', to: '/mine' },
    { label: '积分商城', to: '/mall' },
    { label: '大咖主页', to: '/experts' },
    { label: 'AI生态', to: '/ai-ecosystem' },
  ],
};

export default {
  name: 'ChamberSiteConfig',
  data() {
    return {
      loading: false,
      loadError: '',
      saving: false,
      cfg: JSON.parse(JSON.stringify(DEFAULT_CFG)),
    };
  },
  created() {
    this.loadConfig();
  },
  methods: {
    loadConfig() {
      this.loading = true;
      this.loadError = '';
      siteConfigGet()
        .then((res) => {
          const d = (res && res.data) || {};
          this.cfg = this.mergeDefault(d);
        })
        .catch((e) => {
          this.loadError = (e && e.msg) || '加载失败';
        })
        .finally(() => {
          this.loading = false;
        });
    },
    mergeDefault(d) {
      const out = JSON.parse(JSON.stringify(DEFAULT_CFG));
      if (d && typeof d === 'object') {
        Object.keys(out).forEach((k) => {
          if (d[k] !== undefined && d[k] !== null) out[k] = d[k];
        });
      }
      return out;
    },
    addRight(tierIdx) {
      this.cfg.member_ladder[tierIdx].rights.push('');
    },
    removeRight(tierIdx, rightIdx) {
      this.cfg.member_ladder[tierIdx].rights.splice(rightIdx, 1);
    },
    pasteQr() {
      // 简单提示：直接把剪贴板图片地址粘贴到输入框（无系统剪贴板读权限时用 URL）
      Message.info('请将二维码图片地址粘贴到输入框（支持 https:// 外链）');
    },
    saveConfig() {
      this.saving = true;
      siteConfigSave(this.cfg)
        .then(() => {
          Message.success('站点配置已保存，会员端立即生效');
        })
        .catch((e) => Message.error((e && e.msg) || '保存失败'))
        .finally(() => {
          this.saving = false;
        });
    },
  },
};
</script>

<style scoped>
.site-config-workbench {
  padding: 4px 2px;
}
.info-alert,
.load-alert {
  margin-bottom: 12px;
}
.panel {
  margin-bottom: 12px;
}
.section {
  padding: 18px 20px;
  border-bottom: 1px solid #f0f0f0;
}
.section:last-child {
  border-bottom: none;
}
.section-title {
  margin: 0 0 14px;
  font-size: 15px;
  font-weight: 600;
  color: #303133;
}
.section-head {
  display: flex;
  align-items: baseline;
  gap: 12px;
  margin-bottom: 12px;
}
.section-hint {
  font-size: 12px;
  color: #909399;
}
.form {
  max-width: 720px;
}
.ladder-row {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 12px 0;
  border-top: 1px dashed #eceff3;
}
.ladder-tag {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  border-radius: 10px;
  background: #f3e8d8;
  color: #a96a1e;
  font-size: 13px;
  font-weight: 700;
}
.rights-editor {
  flex: 1;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}
.right-row {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.entry-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 0;
  border-top: 1px dashed #eceff3;
}
.entry-row:first-child {
  border-top: none;
}
.foot {
  padding: 16px 20px;
  display: flex;
  align-items: center;
  gap: 12px;
}
</style>

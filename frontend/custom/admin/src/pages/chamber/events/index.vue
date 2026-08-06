<template>
  <div class="event-workbench">
    <header class="workbench-head">
      <div>
        <h2>活动运营</h2>
        <p>管理活动配置、发布与现场签到</p>
      </div>
      <div class="head-actions">
        <el-button icon="el-icon-refresh" circle title="刷新" :loading="loading" @click="loadList" />
        <el-button v-if="writeOperationsEnabled" type="primary" icon="el-icon-plus" @click="openCreate">新建活动</el-button>
      </div>
    </header>

    <div class="filter-band">
      <el-form :model="filters" inline @submit.native.prevent>
        <el-form-item label="活动类型">
          <el-select v-model="filters.event_type" clearable placeholder="全部类型" @change="search">
            <el-option v-for="item in eventTypes" :key="item.value" :label="item.label" :value="item.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="标签">
          <el-input v-model.trim="filters.tag" clearable maxlength="40" placeholder="按标签筛选" @keyup.enter.native="search" />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" icon="el-icon-search" @click="search">查询</el-button>
          <el-button icon="el-icon-refresh-left" @click="resetFilters">重置</el-button>
        </el-form-item>
      </el-form>
    </div>

    <el-alert v-if="loadError" class="load-alert" type="error" :title="loadError" :closable="false" show-icon />

    <div class="table-band">
      <el-table v-loading="loading" :data="rows" empty-text="暂无可运营活动" row-key="rowKey">
        <el-table-column label="活动" min-width="240">
          <template slot-scope="scope">
            <div class="primary-cell">{{ scope.row.title || '-' }}</div>
            <div class="secondary-cell">{{ scope.row.event_no || '未生成编号' }}</div>
          </template>
        </el-table-column>
        <el-table-column label="类型" width="110">
          <template slot-scope="scope">{{ eventTypeLabel(scope.row.event_type || scope.row.type) }}</template>
        </el-table-column>
        <el-table-column label="活动时间" min-width="190">
          <template slot-scope="scope">
            <div>{{ formatTime(scope.row.start_time || scope.row.start_at) }}</div>
            <div class="secondary-cell">至 {{ formatTime(scope.row.end_time || scope.row.end_at) }}</div>
          </template>
        </el-table-column>
        <el-table-column label="票档" width="80" align="center">
          <template slot-scope="scope">{{ (scope.row.tickets || []).length }}</template>
        </el-table-column>
        <el-table-column label="状态" width="110">
          <template slot-scope="scope">
            <el-tag :type="statusMeta(scope.row.status).tone" effect="plain" size="small">
              {{ statusMeta(scope.row.status).label }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" fixed="right" width="350">
          <template slot-scope="scope">
            <el-button type="text" icon="el-icon-view" @click="openDetail(scope.row)">详情</el-button>
            <el-button v-if="writeOperationsEnabled && can('edit', scope.row)" type="text" icon="el-icon-edit" @click="openEdit(scope.row)">编辑</el-button>
            <el-button v-if="writeOperationsEnabled && can('publish', scope.row)" type="text" icon="el-icon-position" @click="confirmPublish(scope.row)">发布</el-button>
            <el-button v-if="writeOperationsEnabled && can('cancel', scope.row)" type="text" class="danger-action" icon="el-icon-close" @click="openCancel(scope.row)">取消</el-button>
            <el-button v-if="writeOperationsEnabled && can('checkin', scope.row)" type="text" icon="el-icon-circle-check" @click="openCheckin(scope.row)">签到</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div v-if="total" class="pagination-row">
        <pagination :total="total" :page.sync="filters.page" :limit.sync="filters.limit" @pagination="loadList" />
      </div>
    </div>

    <el-dialog
      :visible.sync="editorVisible"
      :title="form.id ? '编辑活动草稿' : '新建活动'"
      width="920px"
      top="5vh"
      custom-class="event-editor-dialog"
      :close-on-click-modal="false"
      @closed="resetEditor"
    >
      <el-form ref="eventForm" :model="form" :rules="formRules" label-position="top" @submit.native.prevent>
        <el-tabs v-model="editorTab">
          <el-tab-pane label="基本信息" name="basic">
            <div class="form-grid two-columns">
              <el-form-item label="活动标题" prop="title" class="span-two">
                <el-input v-model.trim="form.title" maxlength="160" show-word-limit placeholder="输入活动标题" />
              </el-form-item>
              <el-form-item label="活动类型" prop="event_type">
                <el-select v-model="form.event_type" class="full-control">
                  <el-option v-for="item in eventTypes" :key="item.value" :label="item.label" :value="item.value" />
                </el-select>
              </el-form-item>
              <el-form-item label="最低会员等级">
                <el-select v-model="form.min_tier" class="full-control">
                  <el-option v-for="tier in 4" :key="tier" :label="'L' + tier" :value="tier" />
                </el-select>
              </el-form-item>
              <el-form-item label="活动开始" prop="start_time">
                <el-date-picker v-model="form.start_time" type="datetime" class="full-control" placeholder="选择开始时间" />
              </el-form-item>
              <el-form-item label="活动结束" prop="end_time">
                <el-date-picker v-model="form.end_time" type="datetime" class="full-control" placeholder="选择结束时间" />
              </el-form-item>
              <el-form-item label="报名开始" prop="signup_start_time">
                <el-date-picker v-model="form.signup_start_time" type="datetime" class="full-control" placeholder="选择报名开始时间" />
              </el-form-item>
              <el-form-item label="报名截止" prop="signup_end_time">
                <el-date-picker v-model="form.signup_end_time" type="datetime" class="full-control" placeholder="选择报名截止时间" />
              </el-form-item>
              <el-form-item label="封面图片地址" class="span-two">
                <el-input v-model.trim="form.cover_image" maxlength="255" placeholder="https://..." />
              </el-form-item>
              <el-form-item label="活动摘要" class="span-two">
                <el-input v-model="form.summary" type="textarea" :rows="2" maxlength="500" show-word-limit />
              </el-form-item>
              <el-form-item label="活动详情" class="span-two">
                <el-input v-model="form.detail" type="textarea" :rows="6" maxlength="200000" />
              </el-form-item>
              <el-form-item label="标签" class="span-two">
                <el-input v-model="form.tags_text" maxlength="400" placeholder="多个标签用逗号分隔" />
              </el-form-item>
            </div>

            <section class="form-section">
              <div class="section-head">
                <h3>嘉宾</h3>
                <el-button type="text" icon="el-icon-plus" @click="addSpeaker">添加嘉宾</el-button>
              </div>
              <div v-if="form.speakers.length" class="repeat-table">
                <div v-for="(speaker, index) in form.speakers" :key="index" class="speaker-row">
                  <el-input v-model.trim="speaker.name" maxlength="80" placeholder="姓名" />
                  <el-input v-model.trim="speaker.title" maxlength="80" placeholder="职务" />
                  <el-input v-model.trim="speaker.organization" maxlength="120" placeholder="机构" />
                  <el-input v-model.trim="speaker.avatar" maxlength="255" placeholder="头像地址" />
                  <el-button icon="el-icon-delete" circle title="删除嘉宾" @click="removeSpeaker(index)" />
                </div>
              </div>
              <el-empty v-else :image-size="48" description="暂无嘉宾" />
            </section>
          </el-tab-pane>

          <el-tab-pane label="地点与资格" name="eligibility">
            <div class="form-grid two-columns">
              <el-form-item label="场地名称">
                <el-input v-model.trim="form.location_name" maxlength="120" />
              </el-form-item>
              <el-form-item label="详细地址">
                <el-input v-model.trim="form.address" maxlength="255" />
              </el-form-item>
              <el-form-item label="经度">
                <el-input v-model.trim="form.longitude" placeholder="例如 123.431472" />
              </el-form-item>
              <el-form-item label="纬度">
                <el-input v-model.trim="form.latitude" placeholder="例如 41.805698" />
              </el-form-item>
              <el-form-item label="允许渠道 ID">
                <el-input v-model="form.eligibility.allowed_channel_ids" placeholder="多个 ID 用逗号分隔" />
              </el-form-item>
              <el-form-item label="最低积分">
                <el-input-number v-model="form.eligibility.min_points" :min="0" :step="10" class="full-control" />
              </el-form-item>
              <el-form-item label="必需角色" class="span-two">
                <el-input v-model="form.eligibility.required_roles" placeholder="多个角色编码用逗号分隔" />
              </el-form-item>
              <el-form-item label="签到奖励积分">
                <el-input-number v-model="form.checkin_reward_points" :min="0" class="full-control" />
              </el-form-item>
              <el-form-item label="签到奖励贡献值">
                <el-input-number v-model="form.checkin_reward_contribution" :min="0" class="full-control" />
              </el-form-item>
            </div>
          </el-tab-pane>

          <el-tab-pane :label="'票档（' + form.tickets.length + '）'" name="tickets">
            <div class="section-head ticket-head">
              <h3>报名票档</h3>
              <el-button type="primary" plain icon="el-icon-plus" @click="addTicket">添加票档</el-button>
            </div>
            <div v-for="(ticket, index) in form.tickets" :key="index" class="ticket-section">
              <div class="section-head">
                <h3>票档 {{ index + 1 }}</h3>
                <el-button :disabled="form.tickets.length === 1" icon="el-icon-delete" circle title="删除票档" @click="removeTicket(index)" />
              </div>
              <div class="form-grid three-columns">
                <el-form-item label="票档名称" :required="true">
                  <el-input v-model.trim="ticket.name" maxlength="80" />
                </el-form-item>
                <el-form-item label="现金价格">
                  <el-input v-model.trim="ticket.price" placeholder="0.00" />
                </el-form-item>
                <el-form-item label="积分价格">
                  <el-input-number v-model="ticket.integral_price" :min="0" class="full-control" />
                </el-form-item>
                <el-form-item label="名额（0 为不限）">
                  <el-input-number v-model="ticket.capacity" :min="0" class="full-control" />
                </el-form-item>
                <el-form-item label="最低会员等级">
                  <el-select v-model="ticket.min_tier" class="full-control">
                    <el-option v-for="tier in 4" :key="tier" :label="'L' + tier" :value="tier" />
                  </el-select>
                </el-form-item>
                <el-form-item label="启用">
                  <el-switch v-model="ticket.status" :active-value="1" :inactive-value="0" />
                </el-form-item>
                <el-form-item label="售票开始">
                  <el-date-picker v-model="ticket.sale_start_time" type="datetime" class="full-control" />
                </el-form-item>
                <el-form-item label="售票截止">
                  <el-date-picker v-model="ticket.sale_end_time" type="datetime" class="full-control" />
                </el-form-item>
                <el-form-item label="排序">
                  <el-input-number v-model="ticket.sort" :min="0" class="full-control" />
                </el-form-item>
                <el-form-item label="CRMEB 商品 ID">
                  <el-input-number v-model="ticket.product_id" :min="0" class="full-control" />
                </el-form-item>
                <el-form-item label="商品规格唯一值">
                  <el-input v-model.trim="ticket.product_attr_unique" maxlength="20" />
                </el-form-item>
                <el-form-item label="票档最低积分">
                  <el-input-number v-model="ticket.eligibility.min_points" :min="0" class="full-control" />
                </el-form-item>
                <el-form-item label="退款规则">
                  <el-select v-model="ticket.refund_policy.mode" class="full-control">
                    <el-option label="不可退款" value="none" />
                    <el-option label="截止前全额退款" value="full_before_deadline" />
                    <el-option label="截止前按比例退款" value="partial_before_deadline" />
                  </el-select>
                </el-form-item>
                <el-form-item v-if="ticket.refund_policy.mode !== 'none'" label="退款截止">
                  <el-date-picker v-model="ticket.refund_policy.deadline_time" type="datetime" class="full-control" />
                </el-form-item>
                <el-form-item v-if="ticket.refund_policy.mode === 'partial_before_deadline'" label="退款比例">
                  <el-input-number v-model="ticket.refund_policy.percent" :min="1" :max="100" class="full-control" />
                </el-form-item>
                <el-form-item label="退款说明" class="span-three">
                  <el-input v-model="ticket.refund_policy.description" maxlength="500" />
                </el-form-item>
              </div>
            </div>
          </el-tab-pane>
        </el-tabs>
        <el-alert v-if="formError" type="error" :title="formError" :closable="false" show-icon />
      </el-form>
      <div slot="footer">
        <el-button @click="editorVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="saveDraft">保存草稿</el-button>
      </div>
    </el-dialog>

    <el-dialog :visible.sync="detailVisible" title="活动详情" width="760px" top="7vh">
      <div v-loading="detailLoading" class="event-detail">
        <template v-if="detailData">
          <div class="detail-title">
            <div>
              <h3>{{ detailData.title }}</h3>
              <span>{{ detailData.event_no || '-' }}</span>
            </div>
            <el-tag :type="statusMeta(detailData.status).tone" effect="plain">
              {{ statusMeta(detailData.status).label }}
            </el-tag>
          </div>
          <dl class="detail-grid">
            <div><dt>类型</dt><dd>{{ eventTypeLabel(detailData.event_type || detailData.type) }}</dd></div>
            <div><dt>活动时间</dt><dd>{{ formatTime(detailData.start_time || detailData.start_at) }} 至 {{ formatTime(detailData.end_time || detailData.end_at) }}</dd></div>
            <div><dt>报名时间</dt><dd>{{ formatTime(detailData.signup_start_time) }} 至 {{ formatTime(detailData.signup_end_time) }}</dd></div>
            <div><dt>地点</dt><dd>{{ detailLocation(detailData) }}</dd></div>
            <div><dt>最低等级</dt><dd>L{{ detailData.min_tier || 1 }}</dd></div>
            <div><dt>签到奖励</dt><dd>{{ detailData.checkin_reward_points || 0 }} 积分 / {{ detailData.checkin_reward_contribution || 0 }} 贡献值</dd></div>
          </dl>
          <section class="detail-section">
            <h3>摘要</h3>
            <p>{{ detailData.summary || '无' }}</p>
          </section>
          <section class="detail-section">
            <h3>票档</h3>
            <el-table :data="detailData.tickets || []" size="small" empty-text="暂无票档">
              <el-table-column prop="name" label="名称" min-width="130" />
              <el-table-column label="价格" min-width="120">
                <template slot-scope="scope">￥{{ scope.row.price || '0.00' }} / {{ scope.row.integral_price || 0 }} 积分</template>
              </el-table-column>
              <el-table-column prop="capacity" label="名额" width="80" />
              <el-table-column label="销售截止" min-width="140">
                <template slot-scope="scope">{{ formatTime(scope.row.sale_end_time) }}</template>
              </el-table-column>
            </el-table>
          </section>
        </template>
      </div>
    </el-dialog>

    <el-dialog :visible.sync="cancelVisible" title="取消活动" width="520px" :close-on-click-modal="false" @closed="resetCancel">
      <el-alert type="warning" :closable="false" show-icon title="取消后将停止新的报名，请确认后续退款安排。" />
      <el-form label-position="top" class="dialog-form" @submit.native.prevent>
        <el-form-item label="取消原因">
          <el-input v-model="cancelReason" type="textarea" :rows="4" maxlength="500" show-word-limit />
        </el-form-item>
      </el-form>
      <div slot="footer">
        <el-button @click="cancelVisible = false">返回</el-button>
        <el-button type="danger" :loading="cancelling" @click="submitCancel">确认取消</el-button>
      </div>
    </el-dialog>

    <el-drawer :visible.sync="checkinVisible" title="现场签到" :size="drawerSize" :wrapper-closable="false" @closed="resetCheckin">
      <div class="checkin-drawer">
        <div v-if="checkinEvent" class="checkin-heading">
          <strong>{{ checkinEvent.title }}</strong>
          <span>{{ checkinEvent.event_no }}</span>
        </div>
        <el-tabs v-model="checkinTab">
          <el-tab-pane label="扫码令牌" name="token">
            <el-form label-position="top" @submit.native.prevent>
              <el-form-item label="有效期（秒）">
                <el-input-number v-model="tokenTtl" :min="30" :max="3600" :step="30" />
                <el-button type="primary" class="issue-button" :loading="issuingToken" @click="issueToken">签发令牌</el-button>
              </el-form-item>
            </el-form>
            <div v-if="issuedToken" class="token-result">
              <div class="token-meta">
                <span>有效至 {{ formatTime(issuedToken.expires_time) }}</span>
                <el-button type="text" icon="el-icon-document-copy" @click="copyToken">复制</el-button>
              </div>
              <div ref="checkinQr" class="checkin-qr" aria-label="活动签到二维码"></div>
              <code>{{ issuedToken.token }}</code>
            </div>
            <el-empty v-else :image-size="64" description="尚未签发签到令牌" />
          </el-tab-pane>
          <el-tab-pane label="人工签到" name="manual">
            <el-form label-position="top" @submit.native.prevent>
              <el-form-item label="报名记录 ID" :error="manualError">
                <el-input-number v-model="manualForm.registration_id" :min="1" class="full-control" />
              </el-form-item>
              <el-form-item label="操作原因">
                <el-input v-model="manualForm.reason" type="textarea" :rows="4" maxlength="500" show-word-limit />
              </el-form-item>
              <el-button type="primary" :loading="checkingIn" @click="submitManualCheckin">确认签到</el-button>
            </el-form>
          </el-tab-pane>
        </el-tabs>
      </div>
    </el-drawer>
  </div>
</template>

<script>
import {
  cancelEvent,
  createEvent,
  createManualEventCheckin,
  eventDetail,
  eventList,
  issueEventCheckinToken,
  publishEvent,
  updateEvent,
} from '@/api/chamber/events';
import activityAdmin from '@/chamber/activity-admin';
import QRCode from 'qrcodejs2';

export default {
  name: 'ChamberEventWorkbench',
  data() {
    return {
      loading: false,
      writeOperationsEnabled: false,
      loadError: '',
      rows: [],
      total: 0,
      filters: { event_type: '', tag: '', page: 1, limit: 20 },
      eventTypes: activityAdmin.EVENT_TYPES,
      editorVisible: false,
      editorTab: 'basic',
      form: activityAdmin.createForm(),
      formError: '',
      saving: false,
      formRules: {
        title: [{ required: true, message: '请输入活动标题', trigger: 'blur' }],
        event_type: [{ required: true, message: '请选择活动类型', trigger: 'change' }],
        start_time: [{ required: true, message: '请选择活动开始时间', trigger: 'change' }],
        end_time: [{ required: true, message: '请选择活动结束时间', trigger: 'change' }],
        signup_start_time: [{ required: true, message: '请选择报名开始时间', trigger: 'change' }],
        signup_end_time: [{ required: true, message: '请选择报名截止时间', trigger: 'change' }],
      },
      pendingKeys: {},
      pendingFingerprints: {},
      detailVisible: false,
      detailLoading: false,
      detailData: null,
      cancelVisible: false,
      cancelTarget: null,
      cancelReason: '',
      cancelling: false,
      checkinVisible: false,
      checkinEvent: null,
      checkinTab: 'token',
      tokenTtl: 300,
      issuedToken: null,
      issuingToken: false,
      manualForm: { registration_id: 1, reason: '' },
      manualError: '',
      checkingIn: false,
    };
  },
  computed: {
    drawerSize() {
      return typeof document !== 'undefined' && document.documentElement.clientWidth < 768 ? '100%' : '560px';
    },
  },
  mounted() {
    this.loadList();
  },
  methods: {
    rowKey(row) {
      return 'event-' + row.id;
    },
    queryParams() {
      const params = { page: this.filters.page, limit: this.filters.limit };
      if (this.filters.event_type) params.event_type = this.filters.event_type;
      if (this.filters.tag) params.tag = this.filters.tag;
      return params;
    },
    loadList() {
      this.loading = true;
      this.loadError = '';
      return eventList(this.queryParams())
        .then((response) => {
          const result = activityAdmin.normalizeList(response);
          this.rows = result.items;
          this.total = Number(result.page.total) || 0;
        })
        .catch((error) => {
          this.rows = [];
          this.total = 0;
          this.loadError = this.errorMessage(error, '活动列表加载失败');
        })
        .finally(() => { this.loading = false; });
    },
    search() {
      this.filters.page = 1;
      this.loadList();
    },
    resetFilters() {
      this.filters = { event_type: '', tag: '', page: 1, limit: 20 };
      this.loadList();
    },
    openCreate() {
      this.form = activityAdmin.createForm();
      this.editorTab = 'basic';
      this.editorVisible = true;
    },
    openEdit(row) {
      this.formError = '';
      this.editorTab = 'basic';
      this.loading = true;
      eventDetail(row.id)
        .then((response) => {
          this.form = activityAdmin.eventToForm(response.data);
          this.editorVisible = true;
        })
        .catch((error) => { this.$message.error(this.errorMessage(error, '活动详情加载失败')); })
        .finally(() => { this.loading = false; });
    },
    openDetail(row) {
      this.detailData = null;
      this.detailVisible = true;
      this.detailLoading = true;
      eventDetail(row.id)
        .then((response) => { this.detailData = response.data; })
        .catch((error) => {
          this.detailVisible = false;
          this.$message.error(this.errorMessage(error, '活动详情加载失败'));
        })
        .finally(() => { this.detailLoading = false; });
    },
    resetEditor() {
      this.form = activityAdmin.createForm();
      this.formError = '';
      this.saving = false;
      if (this.$refs.eventForm) this.$refs.eventForm.clearValidate();
    },
    addSpeaker() {
      this.form.speakers.push({ name: '', title: '', organization: '', avatar: '' });
    },
    removeSpeaker(index) {
      this.form.speakers.splice(index, 1);
    },
    addTicket() {
      const ticket = activityAdmin.createTicket();
      ticket.sale_start_time = this.form.signup_start_time;
      ticket.sale_end_time = this.form.signup_end_time;
      this.form.tickets.push(ticket);
    },
    removeTicket(index) {
      if (this.form.tickets.length > 1) this.form.tickets.splice(index, 1);
    },
    saveDraft() {
      this.formError = '';
      this.$refs.eventForm.validate((valid) => {
        if (!valid) {
          this.editorTab = 'basic';
          return;
        }
        const errors = activityAdmin.validateForm(this.form);
        if (errors.length) {
          this.formError = errors[0];
          if (errors[0].indexOf('票档') >= 0) this.editorTab = 'tickets';
          return;
        }
        const payload = activityAdmin.serializeForm(this.form);
        const action = this.form.id ? 'update' : 'create';
        const key = this.pendingKey(action, this.form.id || 'new', payload);
        this.saving = true;
        const request = this.form.id
          ? updateEvent(this.form.id, payload, key)
          : createEvent(payload, key);
        request.then(() => {
          this.clearPendingKey(action, this.form.id || 'new');
          this.$message.success('草稿已保存');
          this.editorVisible = false;
          return this.loadList();
        }).catch((error) => {
          this.formError = this.errorMessage(error, '草稿保存失败');
        }).finally(() => { this.saving = false; });
      });
    },
    confirmPublish(row) {
      this.$confirm(`发布“${row.title}”后将对符合条件的会员可见。`, '发布活动', {
        type: 'warning',
        confirmButtonText: '确认发布',
      }).then(() => {
        const key = this.pendingKey('publish', row.id, {});
        return publishEvent(row.id, key).then(() => {
          this.clearPendingKey('publish', row.id);
          this.$message.success('活动已发布');
          return this.loadList();
        });
      }).catch((error) => {
        if (error !== 'cancel' && error !== 'close') this.$message.error(this.errorMessage(error, '活动发布失败'));
      });
    },
    openCancel(row) {
      this.cancelTarget = row;
      this.cancelReason = '';
      this.cancelVisible = true;
    },
    submitCancel() {
      if (!this.cancelTarget) return;
      const payload = { reason: this.cancelReason.trim() };
      const key = this.pendingKey('cancel', this.cancelTarget.id, payload);
      this.cancelling = true;
      cancelEvent(this.cancelTarget.id, payload, key)
        .then(() => {
          this.clearPendingKey('cancel', this.cancelTarget.id);
          this.$message.success('活动已取消');
          this.cancelVisible = false;
          return this.loadList();
        })
        .catch((error) => { this.$message.error(this.errorMessage(error, '活动取消失败')); })
        .finally(() => { this.cancelling = false; });
    },
    resetCancel() {
      this.cancelTarget = null;
      this.cancelReason = '';
      this.cancelling = false;
    },
    openCheckin(row) {
      this.checkinEvent = row;
      this.checkinVisible = true;
    },
    issueToken() {
      if (!this.checkinEvent) return;
      const payload = { ttl_seconds: this.tokenTtl };
      const key = this.pendingKey('checkin-token', this.checkinEvent.id, payload);
      this.issuingToken = true;
      issueEventCheckinToken(this.checkinEvent.id, payload, key)
        .then((response) => {
          this.clearPendingKey('checkin-token', this.checkinEvent.id);
          this.issuedToken = response.data;
          this.$nextTick(this.renderCheckinQr);
          this.$message.success('签到令牌已签发');
        })
        .catch((error) => { this.$message.error(this.errorMessage(error, '令牌签发失败')); })
        .finally(() => { this.issuingToken = false; });
    },
    renderCheckinQr() {
      const target = this.$refs.checkinQr;
      if (!target || !this.issuedToken || !this.issuedToken.token) return;
      target.innerHTML = '';
      new QRCode(target, {
        text: this.issuedToken.token,
        width: 220,
        height: 220,
        colorDark: '#303133',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M,
      });
    },
    copyToken() {
      const value = this.issuedToken && this.issuedToken.token;
      if (!value) return;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(value).then(() => this.$message.success('令牌已复制'));
        return;
      }
      const input = document.createElement('textarea');
      input.value = value;
      document.body.appendChild(input);
      input.select();
      document.execCommand('copy');
      document.body.removeChild(input);
      this.$message.success('令牌已复制');
    },
    submitManualCheckin() {
      this.manualError = '';
      if (!Number.isInteger(Number(this.manualForm.registration_id)) || Number(this.manualForm.registration_id) < 1) {
        this.manualError = '请输入有效的报名记录 ID';
        return;
      }
      if (!this.manualForm.reason.trim()) {
        this.manualError = '人工签到必须填写操作原因';
        return;
      }
      const eventId = this.checkinEvent.id;
      const payload = {
        registration_id: Number(this.manualForm.registration_id),
        reason: this.manualForm.reason.trim(),
      };
      const key = this.pendingKey('manual-checkin', `${eventId}-${this.manualForm.registration_id}`, payload);
      this.checkingIn = true;
      createManualEventCheckin(eventId, payload, key).then((response) => {
        this.clearPendingKey('manual-checkin', `${eventId}-${this.manualForm.registration_id}`);
        this.$message.success(response.data && response.data.replayed ? '该报名已完成签到' : '人工签到成功');
        this.manualForm = { registration_id: 1, reason: '' };
      }).catch((error) => {
        this.manualError = this.errorMessage(error, '人工签到失败');
      }).finally(() => { this.checkingIn = false; });
    },
    resetCheckin() {
      this.checkinEvent = null;
      this.checkinTab = 'token';
      this.tokenTtl = 300;
      this.issuedToken = null;
      this.issuingToken = false;
      this.manualForm = { registration_id: 1, reason: '' };
      this.manualError = '';
      this.checkingIn = false;
    },
    pendingKey(action, identity, payload) {
      const key = `${action}:${identity}`;
      const fingerprint = JSON.stringify(payload || {});
      if (!this.pendingKeys[key] || this.pendingFingerprints[key] !== fingerprint) {
        this.$set(this.pendingKeys, key, activityAdmin.generateIdempotencyKey(action, identity));
        this.$set(this.pendingFingerprints, key, fingerprint);
      }
      return this.pendingKeys[key];
    },
    clearPendingKey(action, identity) {
      this.$delete(this.pendingKeys, `${action}:${identity}`);
      this.$delete(this.pendingFingerprints, `${action}:${identity}`);
    },
    statusMeta(status) {
      return activityAdmin.statusMeta(status);
    },
    can(action, row) {
      return activityAdmin.can(action, row);
    },
    eventTypeLabel(type) {
      const item = this.eventTypes.find((candidate) => candidate.value === type);
      return item ? item.label : '-';
    },
    detailLocation(event) {
      const location = event.location || {};
      const parts = [event.location_name || location.name, event.address || location.address].filter(Boolean);
      return parts.join(' · ') || '-';
    },
    formatTime(value) {
      const seconds = activityAdmin.toUnixSeconds(value);
      if (!seconds) return '-';
      const date = new Date(seconds * 1000);
      const pad = (number) => String(number).padStart(2, '0');
      return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
    },
    errorMessage(error, fallback) {
      if (error && error.errors && typeof error.errors === 'object') {
        const first = Object.keys(error.errors)[0];
        const value = first && error.errors[first];
        if (Array.isArray(value) && value[0]) return value[0];
      }
      return error && error.msg ? error.msg : fallback;
    },
  },
};
</script>

<style scoped>
.event-workbench { padding: 20px; color: #303133; }
.workbench-head, .section-head, .token-meta, .checkin-heading { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
.workbench-head { margin-bottom: 18px; }
.workbench-head h2 { margin: 0; font-size: 22px; line-height: 30px; }
.workbench-head p { margin: 3px 0 0; color: #909399; }
.head-actions { display: flex; align-items: center; gap: 10px; }
.filter-band { padding: 16px 18px 0; border: 1px solid #ebeef5; background: #fff; }
.load-alert { margin-top: 14px; }
.table-band { margin-top: 14px; border: 1px solid #ebeef5; background: #fff; }
.primary-cell { color: #303133; font-weight: 600; line-height: 22px; }
.secondary-cell { margin-top: 2px; color: #909399; font-size: 12px; line-height: 18px; }
.danger-action { color: #f56c6c; }
.pagination-row { display: flex; justify-content: flex-end; padding: 16px; border-top: 1px solid #ebeef5; }
.form-grid { display: grid; gap: 0 18px; }
.two-columns { grid-template-columns: repeat(2, minmax(0, 1fr)); }
.three-columns { grid-template-columns: repeat(3, minmax(0, 1fr)); }
.span-two { grid-column: span 2; }
.span-three { grid-column: span 3; }
.full-control { width: 100%; }
.form-section { margin-top: 18px; padding-top: 14px; border-top: 1px solid #ebeef5; }
.section-head { min-height: 40px; }
.section-head h3 { margin: 0; font-size: 15px; }
.repeat-table { border-top: 1px solid #ebeef5; }
.speaker-row { display: grid; grid-template-columns: 1fr 1fr 1.4fr 1.4fr 40px; gap: 10px; padding: 10px 0; border-bottom: 1px solid #ebeef5; }
.ticket-head { margin-bottom: 8px; }
.ticket-section { padding: 14px 0 2px; border-top: 1px solid #dcdfe6; }
.ticket-section + .ticket-section { margin-top: 12px; }
.dialog-form { margin-top: 18px; }
.detail-title { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; padding-bottom: 16px; border-bottom: 1px solid #ebeef5; }
.detail-title h3 { margin: 0 0 4px; font-size: 19px; }
.detail-title span { color: #909399; }
.detail-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px 24px; margin: 18px 0 0; }
.detail-grid div { min-width: 0; }
.detail-grid dt { margin-bottom: 4px; color: #909399; font-size: 12px; }
.detail-grid dd { margin: 0; line-height: 22px; overflow-wrap: anywhere; }
.detail-section { margin-top: 20px; }
.detail-section h3 { margin: 0 0 10px; font-size: 15px; }
.detail-section p { margin: 0; color: #606266; line-height: 22px; white-space: pre-wrap; }
.checkin-drawer { padding: 0 22px 28px; }
.checkin-heading { align-items: flex-start; flex-direction: column; gap: 4px; padding-bottom: 14px; border-bottom: 1px solid #ebeef5; }
.checkin-heading strong { font-size: 17px; }
.checkin-heading span { color: #909399; }
.issue-button { margin-left: 10px; }
.token-result { margin-top: 18px; padding: 14px; border: 1px solid #dcdfe6; background: #f5f7fa; }
.checkin-qr { display: flex; justify-content: center; width: 244px; min-height: 244px; margin: 14px auto 0; padding: 12px; background: #fff; }
.token-result code { display: block; margin-top: 10px; padding: 12px; overflow-wrap: anywhere; color: #303133; background: #fff; border: 1px solid #ebeef5; }
@media (max-width: 900px) {
  .event-workbench { padding: 12px; }
  .workbench-head { align-items: flex-start; flex-direction: column; }
  .two-columns, .three-columns, .speaker-row, .detail-grid { grid-template-columns: 1fr; }
  .span-two, .span-three { grid-column: span 1; }
}
</style>

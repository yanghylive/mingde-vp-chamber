<template>
  <div class="ai-twin-workbench">
    <el-alert
      type="info"
      show-icon
      :closable="false"
      class="info-alert"
      title="AI 智能分身训练"
      description="大咖在小程序通过对话训练自己的 AI 分身（自动沉淀记忆），管理员可在此查看训练状态、编辑人设、管理记忆、回放训练对话。"
    />

    <el-card shadow="never" class="table-panel" :body-style="{ padding: 0 }">
      <div class="table-head">
        <div>
          <h2>大咖 AI 分身</h2>
          <span>共 {{ rows.length }} 位大咖 · 训练进度满 100% 即「已就绪」</span>
        </div>
        <el-button icon="el-icon-refresh" circle title="刷新" :loading="loading" @click="loadList" />
      </div>

      <el-table v-loading="loading" :data="rows" empty-text="暂无大咖分身" class="twin-table">
        <el-table-column label="大咖" min-width="140">
          <template slot-scope="scope">
            <div class="primary-cell">{{ scope.row.name || '-' }}</div>
            <div class="secondary-cell">会员ID {{ scope.row.member_id }}</div>
          </template>
        </el-table-column>
        <el-table-column label="身份定位" min-width="150" show-overflow-tooltip>
          <template slot-scope="scope">{{ scope.row.persona_role || '-' }}</template>
        </el-table-column>
        <el-table-column label="训练状态" width="110" align="center">
          <template slot-scope="scope">
            <el-tag :type="statusType(scope.row.training_status)" size="mini">
              {{ statusLabel(scope.row.training_status) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="训练进度" width="140" align="center">
          <template slot-scope="scope">
            <el-progress
              :percentage="scope.row.training_progress || 0"
              :stroke-width="8"
              :show-text="false"
            />
            <span class="progress-text">{{ scope.row.training_progress || 0 }}%</span>
          </template>
        </el-table-column>
        <el-table-column label="记忆" width="70" align="center">
          <template slot-scope="scope">
            <el-button type="text" @click="openMemories(scope.row)">查看</el-button>
          </template>
        </el-table-column>
        <el-table-column label="积分价" width="90" align="center">
          <template slot-scope="scope">{{ scope.row.chat_points_cost }} 分/次</template>
        </el-table-column>
        <el-table-column label="对话次数" width="90" align="center">
          <template slot-scope="scope">{{ scope.row.chat_count }}</template>
        </el-table-column>
        <el-table-column label="操作" width="280" align="center" fixed="right">
          <template slot-scope="scope">
            <el-button type="primary" size="mini" @click="openConfig(scope.row)">人设配置</el-button>
            <el-button type="success" size="mini" @click="openKnowledge(scope.row)">知识库</el-button>
            <el-button type="info" size="mini" @click="openReplay(scope.row)">训练回放</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- 人设配置弹窗 -->
    <el-dialog title="人设配置" :visible.sync="configVisible" width="560px" :close-on-click-modal="false">
      <el-form label-width="110px" :model="config" ref="configRef">
        <el-form-item label="大咖">
          <span>{{ currentName }}</span>
        </el-form-item>
        <el-form-item label="分身昵称">
          <el-input v-model="config.persona_name" placeholder="默认用大咖姓名" maxlength="64" />
        </el-form-item>
        <el-form-item label="身份定位">
          <el-input v-model="config.persona_role" placeholder="如：私募投资导师 / AI 增长教练" maxlength="128" />
        </el-form-item>
        <el-form-item label="说话语气">
          <el-input v-model="config.voice_style" placeholder="如：沉稳老练、一针见血" maxlength="128" />
        </el-form-item>
        <el-form-item label="口头禅">
          <el-input v-model="config.catchphrases" placeholder="用逗号分隔，如：落袋为安, 稳字当头" maxlength="200" />
        </el-form-item>
        <el-form-item label="知识库要点">
          <el-input v-model="config.knowledge_base" type="textarea" :rows="3" placeholder="AI 训练总结的核心观点/方法论，可手动补充" maxlength="2000" />
        </el-form-item>
        <el-form-item label="对话积分价">
          <el-input-number v-model="config.chat_points_cost" :min="1" :max="1000" />
          <span class="form-hint">会员跟分身对话一次消耗的积分</span>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="saving" @click="saveConfig">保存</el-button>
          <el-button @click="configVisible = false">取消</el-button>
        </el-form-item>
      </el-form>
    </el-dialog>

    <!-- 记忆管理弹窗 -->
    <el-dialog title="AI 记忆" :visible.sync="memoryVisible" width="640px">
      <div v-if="memoryLoading" class="dialog-loading">加载中...</div>
      <el-empty v-else-if="!memories.length" description="暂无记忆——大咖在小程序对话训练后自动沉淀" />
      <el-table v-else :data="memories" size="small">
        <el-table-column label="分类" width="100">
          <template slot-scope="scope">
            <el-tag size="mini" :type="catType(scope.row.category)">{{ scope.row.category_label }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="记忆内容" min-width="280" show-overflow-tooltip>
          <template slot-scope="scope">{{ scope.row.content }}</template>
        </el-table-column>
        <el-table-column label="来源" width="80" align="center">
          <template slot-scope="scope">{{ scope.row.source === 'manual' ? '手动' : '训练' }}</template>
        </el-table-column>
        <el-table-column label="操作" width="80" align="center">
          <template slot-scope="scope">
            <el-button type="text" class="danger-text" @click="removeMemory(scope.row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-dialog>

    <!-- 训练回放弹窗 -->
    <el-dialog title="训练对话回放" :visible.sync="replayVisible" width="640px">
      <div v-if="replayLoading" class="dialog-loading">加载中...</div>
      <el-empty v-else-if="!replays.length" description="暂无训练对话——大咖尚未开始对话训练" />
      <div v-else class="replay-list">
        <div v-for="chat in replays" :key="chat.id" class="replay-item">
          <div class="replay-time">{{ fmtTime(chat.add_time) }} · 共 {{ chat.message_count }} 条</div>
          <div
            v-for="(msg, i) in chat.messages"
            :key="i"
            class="replay-msg"
            :class="msg.role === 'user' ? 'msg-user' : 'msg-ai'"
          >
            <span class="msg-tag">{{ msg.role === 'user' ? '大咖' : '训练师' }}</span>
            {{ msg.content }}
          </div>
        </div>
      </div>
    </el-dialog>
    <!-- 知识库弹窗 -->
    <el-dialog title="AI 分身知识库" :visible.sync="knowledgeVisible" width="680px" :close-on-click-modal="false">
      <el-alert
        type="success"
        :closable="false"
        class="kb-alert"
        title="把大咖的文档/方法论/经验沉淀成知识条目"
        description="训练时训练师会基于知识库深入提问，会员对话时按问题相关性自动注入——分身回答更有专业深度（借鉴 TencentDB Agent Memory 分层记忆思路）。"
      />
      <el-form label-width="70px" :model="kbForm" class="kb-form">
        <el-form-item label="文档上传">
          <el-upload
            :auto-upload="false"
            :show-file-list="true"
            :limit="1"
            :file-list="kbFiles"
            :on-change="onKbFileChange"
            :on-remove="onKbFileRemove"
            accept=".pdf,.docx,.doc,.txt,.md"
          >
            <el-button size="small" type="warning" plain :loading="kbUploading">上传 PDF / Word 自动解析</el-button>
            <span class="form-hint">解析后填入下方标题/内容，确认后再入库；扫描版 PDF 无法解析</span>
          </el-upload>
          <div v-if="kbUploadResult" class="upload-result">
            <i class="el-icon-document" /> {{ kbUploadResult }}
          </div>
        </el-form-item>
        <el-form-item label="标题" required>
          <el-input v-model="kbForm.title" placeholder="如：价值投资方法论 / 工厂管理经验" maxlength="128" />
        </el-form-item>
        <el-form-item label="内容" required>
          <el-input v-model="kbForm.content" type="textarea" :rows="5" placeholder="粘贴文档要点/方法论/经验（支持 2 万字），也可上传 PDF/Word 自动解析" maxlength="20000" />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="kbSaving" @click="addKnowledge">添加到知识库</el-button>
          <span class="form-hint">建议按主题拆成多条，检索更精准</span>
        </el-form-item>
      </el-form>
      <el-divider content-position="left">已有知识（{{ knowledgeList.length }} 条）</el-divider>
      <div v-if="kbLoading" class="dialog-loading">加载中...</div>
      <el-empty v-else-if="!knowledgeList.length" description="知识库为空——添加第一条知识开始" />
        <div v-else class="kb-list">
        <div v-for="k in knowledgeList" :key="k.id" class="kb-item">
          <div class="kb-title">
            <span class="kb-name">{{ k.title }}</span>
            <span v-if="k.source_file" class="kb-file">📄 {{ k.source_file }}</span>
            <el-button type="text" class="danger-text" @click="removeKnowledge(k)">删除</el-button>
          </div>
          <div class="kb-content">{{ k.content }}</div>
        </div>
      </div>
    </el-dialog>
  </div>
</template>

<script>
import { aiTwinList, aiTwinDetail, aiTwinUpdate, aiTwinMemories, aiTwinDeleteMemory, aiTwinChats, aiTwinKnowledge, aiTwinAddKnowledge, aiTwinDeleteKnowledge, aiTwinUploadKnowledge } from '@/api/chamber/aiTwin';
import { Message, MessageBox } from 'element-ui';

export default {
  name: 'ChamberAiTwin',
  data() {
    return {
      loading: false,
      saving: false,
      rows: [],
      configVisible: false,
      config: {},
      currentMemberId: 0,
      currentName: '',
      memoryVisible: false,
      memoryLoading: false,
      memoryMemberId: 0,
      memories: [],
      replayVisible: false,
      replayLoading: false,
      replays: [],
      knowledgeVisible: false,
      knowledgeLoading: false,
      knowledgeSaving: false,
      knowledgeMemberId: 0,
      knowledgeList: [],
      kbForm: { title: '', content: '', source_file: '' },
      kbFiles: [],
      kbUploading: false,
      kbUploadResult: '',
    };
  },
  created() {
    this.loadList();
  },
  methods: {
    loadList() {
      this.loading = true;
      aiTwinList()
        .then((res) => {
          const data = res.data || {};
          this.rows = Array.isArray(data.items) ? data.items : [];
        })
        .catch((e) => Message.error((e && e.msg) || '加载失败'))
        .finally(() => {
          this.loading = false;
        });
    },
    statusLabel(s) {
      const map = { 0: '未训练', 1: '训练中', 2: '已就绪' };
      return map[s] || '未训练';
    },
    statusType(s) {
      const map = { 0: 'info', 1: 'warning', 2: 'success' };
      return map[s] || 'info';
    },
    catType(c) {
      const map = { identity: 'danger', style: 'warning', fact: 'primary', knowledge: 'success', preference: 'info' };
      return map[c] || 'info';
    },
    fmtTime(t) {
      if (!t) return '-';
      const d = new Date(t * 1000);
      const pad = (n) => String(n).padStart(2, '0');
      return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
    },
    openConfig(row) {
      this.currentMemberId = row.member_id;
      this.currentName = row.name || '';
      this.saving = false;
      aiTwinDetail(row.member_id)
        .then((res) => {
          const d = res.data || {};
          this.config = {
            persona_name: d.persona_name || '',
            persona_role: d.persona_role || '',
            voice_style: d.voice_style || '',
            catchphrases: d.catchphrases || '',
            knowledge_base: d.knowledge_base || '',
            chat_points_cost: d.chat_points_cost || 20,
          };
          this.configVisible = true;
        })
        .catch((e) => Message.error((e && e.msg) || '加载配置失败'));
    },
    saveConfig() {
      this.saving = true;
      aiTwinUpdate(this.currentMemberId, this.config)
        .then(() => {
          Message.success('人设配置已保存');
          this.configVisible = false;
          this.loadList();
        })
        .catch((e) => Message.error((e && e.msg) || '保存失败'))
        .finally(() => {
          this.saving = false;
        });
    },
    openMemories(row) {
      this.memoryMemberId = row.member_id;
      this.memoryVisible = true;
      this.memoryLoading = true;
      this.memories = [];
      aiTwinMemories(row.member_id)
        .then((res) => {
          const data = res.data || {};
          this.memories = Array.isArray(data.items) ? data.items : [];
        })
        .catch((e) => Message.error((e && e.msg) || '加载记忆失败'))
        .finally(() => {
          this.memoryLoading = false;
        });
    },
    removeMemory(mem) {
      MessageBox.confirm('删除后该记忆不再注入 AI 分身，确定删除？', '删除记忆', { type: 'warning' })
        .then(() => {
          aiTwinDeleteMemory(this.memoryMemberId, mem.id)
            .then(() => {
              Message.success('记忆已删除');
              this.memories = this.memories.filter((m) => m.id !== mem.id);
            })
            .catch((e) => Message.error((e && e.msg) || '删除失败'));
        })
        .catch(() => {});
    },
    openReplay(row) {
      this.replayVisible = true;
      this.replayLoading = true;
      this.replays = [];
      aiTwinChats(row.member_id)
        .then((res) => {
          const data = res.data || {};
          this.replays = Array.isArray(data.items) ? data.items : [];
        })
        .catch((e) => Message.error((e && e.msg) || '加载回放失败'))
        .finally(() => {
          this.replayLoading = false;
        });
    },
    openKnowledge(row) {
      this.knowledgeMemberId = row.member_id;
      this.knowledgeVisible = true;
      this.kbForm = { title: '', content: '', source_file: '' };
      this.kbFiles = [];
      this.kbUploadResult = '';
      this.loadKnowledge();
    },
    loadKnowledge() {
      this.knowledgeLoading = true;
      aiTwinKnowledge(this.knowledgeMemberId)
        .then((res) => {
          const data = res.data || {};
          this.knowledgeList = Array.isArray(data.items) ? data.items : [];
        })
        .catch((e) => Message.error((e && e.msg) || '加载知识库失败'))
        .finally(() => {
          this.knowledgeLoading = false;
        });
    },
    onKbFileChange(file) {
      const raw = file.raw;
      if (!raw) return;
      const ext = (raw.name.split('.').pop() || '').toLowerCase();
      if (!['pdf', 'docx', 'doc', 'txt', 'md'].includes(ext)) {
        Message.warning('仅支持 PDF / Word(.docx) / TXT / MD');
        this.kbFiles = [];
        return;
      }
      if (raw.size > 15 * 1024 * 1024) {
        Message.warning('文件过大（限 15MB）');
        this.kbFiles = [];
        return;
      }
      this.kbUploading = true;
      this.kbUploadResult = '';
      const formData = new FormData();
      formData.append('file', raw);
      aiTwinUploadKnowledge(this.knowledgeMemberId, formData)
        .then((res) => {
          const d = res.data || {};
          this.kbForm.title = d.title || raw.name;
          this.kbForm.content = d.content || '';
          this.kbForm.source_file = d.source_file || '';
          const trunc = d.truncated ? '，超长部分已截断（限 2 万字）' : '';
          this.kbUploadResult = `已解析 ${d.length || 0} 字${trunc}，确认内容后点击「添加到知识库」`;
          Message.success('解析成功，内容已填入下方');
        })
        .catch((e) => {
          Message.error((e && e.msg) || '解析失败');
          this.kbFiles = [];
        })
        .finally(() => {
          this.kbUploading = false;
        });
    },
    onKbFileRemove() {
      this.kbFiles = [];
      this.kbUploadResult = '';
    },
    addKnowledge() {
      if (!this.kbForm.title.trim() || !this.kbForm.content.trim()) {
        Message.warning('标题和内容都不能为空');
        return;
      }
      this.knowledgeSaving = true;
      aiTwinAddKnowledge(this.knowledgeMemberId, {
        title: this.kbForm.title.trim(),
        content: this.kbForm.content.trim(),
        category: 'general',
        source: this.kbForm.source_file ? 'file' : 'manual',
        source_file: this.kbForm.source_file,
      })
        .then(() => {
          Message.success('已添加到知识库');
          this.kbForm = { title: '', content: '', source_file: '' };
          this.kbFiles = [];
          this.kbUploadResult = '';
          this.loadKnowledge();
        })
        .catch((e) => Message.error((e && e.msg) || '添加失败'))
        .finally(() => {
          this.knowledgeSaving = false;
        });
    },
    removeKnowledge(k) {
      MessageBox.confirm('删除后该知识不再注入 AI 分身，确定删除？', '删除知识', { type: 'warning' })
        .then(() => {
          aiTwinDeleteKnowledge(this.knowledgeMemberId, k.id)
            .then(() => {
              Message.success('已删除');
              this.knowledgeList = this.knowledgeList.filter((x) => x.id !== k.id);
            })
            .catch((e) => Message.error((e && e.msg) || '删除失败'));
        })
        .catch(() => {});
    },
  },
};
</script>

<style scoped>
.ai-twin-workbench {
  padding: 16px;
}
.info-alert {
  margin-bottom: 12px;
}
.table-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 16px;
  border-bottom: 1px solid #f0f0f0;
}
.table-head h2 {
  margin: 0;
  font-size: 16px;
}
.table-head span {
  color: #909399;
  font-size: 12px;
  margin-left: 8px;
}
.primary-cell {
  font-weight: 600;
}
.secondary-cell {
  color: #909399;
  font-size: 12px;
}
.progress-text {
  font-size: 12px;
  color: #909399;
}
.form-hint {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}
.dialog-loading {
  padding: 40px 0;
  text-align: center;
  color: #909399;
}
.danger-text {
  color: #f56c6c;
}
.replay-item {
  border: 1px solid #ebeef5;
  border-radius: 6px;
  padding: 12px;
  margin-bottom: 12px;
  background: #fafafa;
}
.replay-time {
  font-size: 12px;
  color: #909399;
  margin-bottom: 8px;
}
.replay-msg {
  margin: 6px 0;
  font-size: 13px;
  line-height: 1.6;
}
.kb-alert {
  margin-bottom: 12px;
}
.kb-form {
  margin-top: 12px;
}
.kb-list {
  max-height: 320px;
  overflow-y: auto;
}
.kb-item {
  border: 1px solid #ebeef5;
  border-radius: 6px;
  padding: 10px 14px;
  margin-bottom: 10px;
  background: #fafafa;
}
.kb-title {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.kb-name {
  font-weight: 600;
  font-size: 14px;
}
.kb-file {
  margin-left: 10px;
  font-size: 12px;
  color: #909399;
}
.upload-result {
  margin-top: 6px;
  font-size: 12px;
  color: #67c23a;
}
.kb-content {
  margin-top: 6px;
  font-size: 13px;
  color: #606266;
  line-height: 1.6;
  white-space: pre-wrap;
}
.msg-tag {
  display: inline-block;
  font-size: 11px;
  border-radius: 3px;
  padding: 1px 6px;
  margin-right: 6px;
  color: #fff;
}
.msg-user .msg-tag {
  background: #409eff;
}
.msg-ai .msg-tag {
  background: #67c23a;
}
</style>

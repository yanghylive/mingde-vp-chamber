/**
 * AI 智能分身训练板块（admin）API
 */
import axios from 'axios';
import router from '@/router';
import Setting from '@/setting';
import { getCookies, removeCookies } from '@/libs/util';
import memberUi from '@/chamber/shared/member-ui';

const TWINS_PATH = '/chamber/admin/v1/ai-twins';

function logoutAdmin() {
  localStorage.clear();
  removeCookies('token');
  removeCookies('expires_time');
  removeCookies('uuid');
  router.replace({ name: 'login' }).catch(() => {});
}

function chamberRequest(config) {
  const fallbackOrigin = typeof window !== 'undefined' ? window.location.origin : '';
  const baseURL = memberUi.resolveServiceOrigin(Setting.apiBaseURL, fallbackOrigin);
  const token = getCookies('token');
  const headers = Object.assign({}, config.headers || {});
  if (token) headers.Authorization = 'Bearer ' + token;

  return axios(
    Object.assign({}, config, {
      baseURL,
      headers,
      timeout: 15000,
    }),
  ).then(
    (response) => {
      const body = response.data || {};
      if (body.status === 200 || body.status === 201 || body.code === 0) return body;
      if (body.status === 401 || body.code === 401) logoutAdmin();
      return Promise.reject(body.msg ? body : { status: response.status, msg: '请求失败' });
    },
    (error) => {
      const body = error.response && error.response.data;
      if (body && (body.status === 401 || body.code === 401)) logoutAdmin();
      return Promise.reject(body || { status: 0, msg: error.message || '请求失败' });
    },
  );
}

/** 大咖列表 + 分身状态 */
export function aiTwinList() {
  return chamberRequest({ url: TWINS_PATH, method: 'get' });
}

/** 分身配置详情 */
export function aiTwinDetail(memberId) {
  return chamberRequest({ url: `${TWINS_PATH}/${memberId}`, method: 'get' });
}

/** 保存人设配置 */
export function aiTwinUpdate(memberId, data) {
  return chamberRequest({ url: `${TWINS_PATH}/${memberId}`, method: 'put', data });
}

/** 记忆列表 */
export function aiTwinMemories(memberId) {
  return chamberRequest({ url: `${TWINS_PATH}/${memberId}/memories`, method: 'get' });
}

/** 删除记忆 */
export function aiTwinDeleteMemory(memberId, memoryId) {
  return chamberRequest({ url: `${TWINS_PATH}/${memberId}/memories/${memoryId}`, method: 'delete' });
}

/** 训练对话回放 */
export function aiTwinChats(memberId) {
  return chamberRequest({ url: `${TWINS_PATH}/${memberId}/chats`, method: 'get' });
}

/** 知识库列表 */
export function aiTwinKnowledge(memberId) {
  return chamberRequest({ url: `${TWINS_PATH}/${memberId}/knowledge`, method: 'get' });
}

/** 新增知识条目 */
export function aiTwinAddKnowledge(memberId, data) {
  return chamberRequest({ url: `${TWINS_PATH}/${memberId}/knowledge`, method: 'post', data });
}

/** 上传文档解析为知识草稿（multipart，字段名 file） */
export function aiTwinUploadKnowledge(memberId, formData) {
  return chamberRequest({ url: `${TWINS_PATH}/${memberId}/knowledge/upload`, method: 'post', data: formData, timeout: 60000 });
}

/** 删除知识条目 */
export function aiTwinDeleteKnowledge(memberId, knowledgeId) {
  return chamberRequest({ url: `${TWINS_PATH}/${memberId}/knowledge/${knowledgeId}`, method: 'delete' });
}

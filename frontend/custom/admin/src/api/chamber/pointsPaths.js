/**
 * 积分获取路径管理（admin）API
 */
import axios from 'axios';
import router from '@/router';
import Setting from '@/setting';
import { getCookies, removeCookies } from '@/libs/util';
import memberUi from '@/chamber/shared/member-ui';

const PATHS_PATH = '/chamber/admin/v1/points-paths';

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
      timeout: 15000,
      headers,
    }),
  ).then(
    (response) => {
      const body = response.data || {};
      if (body.status === 200 || body.status === 201 || body.code === 0) return body;
      if (body.status === 401) logoutAdmin();
      return Promise.reject(body.msg ? body : { status: response.status, msg: '请求失败' });
    },
    (error) => {
      const body = error.response && error.response.data;
      if (body && body.status === 401) logoutAdmin();
      return Promise.reject(body || { status: 0, msg: error.message || '请求失败' });
    },
  );
}

/** 读取积分路径（含默认值） */
export function pointsPathsList() {
  return chamberRequest({ url: PATHS_PATH, method: 'get' });
}

/** 保存积分路径（全量覆盖） */
export function pointsPathsSave(items) {
  return chamberRequest({ url: PATHS_PATH, method: 'put', data: { items } });
}

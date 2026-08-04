/**
 * 站点配置（admin）API——客服二维码 / 等级权益 / 积分比例 / AI 入口 / 5 宫格
 */
import axios from 'axios';
import router from '@/router';
import Setting from '@/setting';
import { getCookies, removeCookies } from '@/libs/util';
import memberUi from '@/chamber/shared/member-ui';

const CONFIG_PATH = '/chamber/admin/v1/site-config';

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

/** 读取站点配置 */
export function siteConfigGet() {
  return chamberRequest({ url: CONFIG_PATH, method: 'get' });
}

/** 保存站点配置 */
export function siteConfigSave(data) {
  return chamberRequest({ url: CONFIG_PATH, method: 'put', data });
}

/**
 * 会员管理（admin）API：列表 / 等级调整（L4 人工指定、手动开通·续费·降级）/ 订单查看
 */
import axios from 'axios';
import router from '@/router';
import Setting from '@/setting';
import { getCookies, removeCookies } from '@/libs/util';
import memberUi from '@/chamber/shared/member-ui';

const MEMBERS_PATH = '/chamber/admin/v1/members';

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

/** 会员列表 */
export function memberList() {
  return chamberRequest({ url: MEMBERS_PATH, method: 'get' });
}

/** 等级调整：tier 2/3/4，action=certify 表示 L4 人工指定认证，remark 备注 */
export function memberUpdate(memberId, payload) {
  return chamberRequest({ url: `${MEMBERS_PATH}/${memberId}`, method: 'patch', data: payload });
}

/** 订单列表 */
export function memberOrders() {
  return chamberRequest({ url: `${MEMBERS_PATH}/orders`, method: 'get' });
}

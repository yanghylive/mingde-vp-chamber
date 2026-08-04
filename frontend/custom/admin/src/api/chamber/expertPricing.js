import axios from 'axios';
import router from '@/router';
import Setting from '@/setting';
import { getCookies, removeCookies } from '@/libs/util';
import memberUi from '@/chamber/shared/member-ui';

const EXPERTS_PATH = '/chamber/admin/v1/experts';

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
      timeout: 100000,
      withCredentials: true,
    }),
  ).then(
    (response) => {
      const body = response.data || {};
      // 兼容两种响应结构：老接口 {status:200}，新接口 {code:0}
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

export function expertPricingList(params) {
  return chamberRequest({
    url: EXPERTS_PATH,
    method: 'get',
    params,
  });
}

export function expertPricingDetail(expertId) {
  return chamberRequest({
    url: `${EXPERTS_PATH}/${expertId}/pricing`,
    method: 'get',
  });
}

export function updateExpertPricing(expertId, data) {
  return chamberRequest({
    url: `${EXPERTS_PATH}/${expertId}/pricing`,
    method: 'patch',
    data,
  });
}

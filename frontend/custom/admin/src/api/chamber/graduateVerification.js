import axios from 'axios';
import router from '@/router';
import Setting from '@/setting';
import { getCookies, removeCookies } from '@/libs/util';
import memberUi from '@/chamber/shared/member-ui';

const VERIFICATION_PATH = '/chamber/admin/v1/graduate-verifications';

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
      if (body.status === 200 || body.status === 201) return body;
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

export function graduateVerificationList(params) {
  return chamberRequest({
    url: VERIFICATION_PATH,
    method: 'get',
    params,
  });
}

export function graduateVerificationDetail(applicationId) {
  return chamberRequest({
    url: `${VERIFICATION_PATH}/${applicationId}`,
    method: 'get',
  });
}

export function reviewGraduateVerification(applicationId, data, idempotencyKey) {
  return chamberRequest({
    url: `${VERIFICATION_PATH}/${applicationId}/reviews`,
    method: 'post',
    data,
    headers: { 'Idempotency-Key': idempotencyKey },
  });
}

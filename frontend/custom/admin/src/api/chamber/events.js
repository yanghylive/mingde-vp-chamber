import axios from 'axios';
import router from '@/router';
import Setting from '@/setting';
import { getCookies, removeCookies } from '@/libs/util';
import memberUi from '@/chamber/shared/member-ui';

const ADMIN_PATH = '/chamber/admin/v1/events';

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

  return axios(Object.assign({}, config, {
    baseURL,
    headers,
    timeout: 100000,
    withCredentials: true,
  })).then(
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

function idempotencyHeaders(key) {
  return { 'Idempotency-Key': key };
}

export function eventList(params) {
  return chamberRequest({ url: ADMIN_PATH, method: 'get', params });
}

export function eventDetail(eventId) {
  return chamberRequest({ url: `${ADMIN_PATH}/${eventId}`, method: 'get' });
}

export function createEvent(data, idempotencyKey) {
  return chamberRequest({
    url: ADMIN_PATH,
    method: 'post',
    data,
    headers: idempotencyHeaders(idempotencyKey),
  });
}

export function updateEvent(eventId, data, idempotencyKey) {
  return chamberRequest({
    url: `${ADMIN_PATH}/${eventId}`,
    method: 'patch',
    data,
    headers: idempotencyHeaders(idempotencyKey),
  });
}

export function publishEvent(eventId, idempotencyKey) {
  return chamberRequest({
    url: `${ADMIN_PATH}/${eventId}/publish`,
    method: 'post',
    headers: idempotencyHeaders(idempotencyKey),
  });
}

export function cancelEvent(eventId, data, idempotencyKey) {
  return chamberRequest({
    url: `${ADMIN_PATH}/${eventId}/cancel`,
    method: 'post',
    data,
    headers: idempotencyHeaders(idempotencyKey),
  });
}

export function issueEventCheckinToken(eventId, data, idempotencyKey) {
  return chamberRequest({
    url: `${ADMIN_PATH}/${eventId}/checkin-token`,
    method: 'post',
    data,
    headers: idempotencyHeaders(idempotencyKey),
  });
}

export function createManualEventCheckin(eventId, data, idempotencyKey) {
  return chamberRequest({
    url: `${ADMIN_PATH}/${eventId}/checkins/manual`,
    method: 'post',
    data,
    headers: idempotencyHeaders(idempotencyKey),
  });
}

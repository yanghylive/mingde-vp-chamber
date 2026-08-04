/**
 * 活动管理（admin）API
 */
import axios from 'axios';
import router from '@/router';
import Setting from '@/setting';
import { getCookies, removeCookies } from '@/libs/util';
import memberUi from '@/chamber/shared/member-ui';

const EVENTS_PATH = '/chamber/admin/v1/events';

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

export function eventList(params) {
  return chamberRequest({ url: EVENTS_PATH, method: 'get', params: params || {} });
}

export function eventCreate(data) {
  return chamberRequest({ url: EVENTS_PATH, method: 'post', data });
}

export function eventUpdate(eventId, data) {
  return chamberRequest({ url: `${EVENTS_PATH}/${eventId}`, method: 'patch', data });
}

export function eventPublish(eventId) {
  return chamberRequest({ url: `${EVENTS_PATH}/${eventId}/publish`, method: 'post', data: {} });
}

export function eventCancel(eventId) {
  return chamberRequest({ url: `${EVENTS_PATH}/${eventId}/cancel`, method: 'post', data: {} });
}

export function eventCheckinToken(eventId) {
  return chamberRequest({ url: `${EVENTS_PATH}/${eventId}/checkin-token`, method: 'post', data: {} });
}

import { HTTP_REQUEST_URL, HEADER, TOKENNAME, TIMEOUT } from '@/config/app';
import { checkLogin, toLogin } from '@/libs/login';
import store from '@/store';

const EVENT_PATH = '/chamber/v1/events';
const REGISTRATION_PATH = '/chamber/v1/me/event-registrations';

function authenticatedHeaders(extra) {
  let token = store.state.app.token;
  if (!token && checkLogin()) token = store.state.app.token;
  if (!token) {
    toLogin();
    return null;
  }
  const headers = Object.assign({}, HEADER, extra || {});
  headers[TOKENNAME] = 'Bearer ' + token;
  return headers;
}

function queryString(query) {
  const parts = [];
  Object.keys(query || {}).forEach((key) => {
    const value = query[key];
    if (value === '' || value === null || value === undefined) return;
    parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(String(value)));
  });
  return parts.length ? '?' + parts.join('&') : '';
}

function request(path, method, data, extraHeaders) {
  const headers = authenticatedHeaders(extraHeaders);
  if (!headers) return Promise.reject({ status: 401, msg: '未登录' });

  return new Promise((resolve, reject) => {
    uni.request({
      url: String(HTTP_REQUEST_URL).replace(/\/+$/, '') + path,
      method,
      header: headers,
      data: data || {},
      timeout: TIMEOUT,
      success(response) {
        const body = response.data || {};
        if (body.status === 200 || body.status === 201) {
          resolve(body);
          return;
        }
        if (body.status === 401 || response.statusCode === 401) toLogin();
        reject(body.msg ? body : { status: response.statusCode, msg: '请求失败' });
      },
      fail(error) {
        reject({ status: 0, msg: error.errMsg || '请求失败' });
      },
    });
  });
}

export function getEvents(query) {
  return request(EVENT_PATH + queryString(query), 'GET');
}

export function getEvent(eventId) {
  return request(EVENT_PATH + '/' + Number(eventId), 'GET');
}

export function createEventRegistration(eventId, data, idempotencyKey) {
  return request(EVENT_PATH + '/' + Number(eventId) + '/registrations', 'POST', data, {
    'Idempotency-Key': idempotencyKey,
  });
}

export function getMyEventRegistrations(query) {
  return request(REGISTRATION_PATH + queryString(query), 'GET');
}

export function getMyEventRegistration(registrationId) {
  return request(REGISTRATION_PATH + '/' + Number(registrationId), 'GET');
}

export function createEventCheckin(eventId, data, idempotencyKey) {
  return request(EVENT_PATH + '/' + Number(eventId) + '/checkins', 'POST', data, {
    'Idempotency-Key': idempotencyKey,
  });
}

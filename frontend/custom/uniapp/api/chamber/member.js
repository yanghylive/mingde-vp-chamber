import { HTTP_REQUEST_URL, HEADER, TOKENNAME, TIMEOUT } from '@/config/app';
import { checkLogin, toLogin } from '@/libs/login';
import store from '@/store';

const MEMBER_PROFILE_PATH = '/chamber/v1/me/profile';
const GRADUATE_VERIFICATION_PATH = '/chamber/v1/me/graduate-verifications';

function request(path, method, data, options) {
  const settings = options || {};
  const token = store.state.app.token;
  const headers = Object.assign({}, HEADER, settings.headers || {});

  if (!token && !checkLogin()) {
    toLogin();
    return Promise.reject({ status: 401, msg: '未登录' });
  }

  if (token) headers[TOKENNAME] = 'Bearer ' + token;

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
        if (body.status === 401) toLogin();
        reject(body.msg ? body : { status: response.statusCode, msg: '请求失败' });
      },
      fail(error) {
        reject({ status: 0, msg: error.errMsg || '请求失败' });
      },
    });
  });
}

export function getMemberProfile() {
  return request(MEMBER_PROFILE_PATH, 'GET');
}

export function updateMemberProfile(data, idempotencyKey) {
  return request(MEMBER_PROFILE_PATH, 'PATCH', data, {
    headers: { 'Idempotency-Key': idempotencyKey },
  });
}

export function getGraduateVerification() {
  return request(GRADUATE_VERIFICATION_PATH, 'GET');
}

export function submitGraduateVerification(data, idempotencyKey) {
  return request(GRADUATE_VERIFICATION_PATH, 'POST', data, {
    headers: { 'Idempotency-Key': idempotencyKey },
  });
}

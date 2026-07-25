import { HTTP_REQUEST_URL, HEADER, TOKENNAME, TIMEOUT } from '@/config/app';
import { checkLogin, toLogin } from '@/libs/login';
import store from '@/store';
import memberUi from '@/chamber/shared/member-ui.js';

const MEMBER_BOOTSTRAP_PATH = '/chamber/v1/me/bootstrap';
const MEMBER_PROFILE_PATH = '/chamber/v1/me/profile';
const GRADUATE_VERIFICATION_PATH = '/chamber/v1/me/graduate-verifications';
const MEMBER_ASSET_PATH = '/chamber/v1/me/assets';
const MEMBER_INVITE_STORAGE_KEY = 'chamber_invite_code';
let initializedToken = '';
let initializedInviteCode = '';
let initializationKey = '';
let initializationPromise = null;

function authenticatedToken() {
  let token = store.state.app.token;
  if (!token && checkLogin()) token = store.state.app.token;
  if (token) return token;
  toLogin();
  return '';
}

function authenticatedHeaders(extra, multipart) {
  const token = authenticatedToken();
  if (!token) return null;
  const headers = Object.assign({}, HEADER, extra || {});
  if (multipart) {
    delete headers['content-type'];
    delete headers['Content-Type'];
  }
  headers[TOKENNAME] = 'Bearer ' + token;
  return headers;
}

function responseBody(data) {
  if (data && typeof data === 'object') return data;
  try {
    return data ? JSON.parse(data) : {};
  } catch (error) {
    return {};
  }
}

function request(path, method, data, options) {
  const settings = options || {};
  const headers = authenticatedHeaders(settings.headers);
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
        if (body.status === 401) toLogin();
        reject(body.msg ? body : { status: response.statusCode, msg: '请求失败' });
      },
      fail(error) {
        reject({ status: 0, msg: error.errMsg || '请求失败' });
      },
    });
  });
}

function chamberInviteCode(candidate) {
  const value = String(candidate || '').trim();
  if (!value) return '';
  if (!/^[A-Za-z0-9]{8,16}$/.test(value)) throw new Error('邀请码格式不正确');
  return value;
}

export function rememberChamberInviteCode(inviteCode) {
  const normalized = chamberInviteCode(inviteCode);
  if (normalized) uni.setStorageSync(MEMBER_INVITE_STORAGE_KEY, normalized);
  return normalized;
}

export function ensureMemberInitialized(inviteCode) {
  const token = authenticatedToken();
  if (!token) return Promise.reject({ status: 401, msg: '未登录' });
  if (initializedToken === token && initializationPromise) return initializationPromise;
  let normalizedInviteCode = '';
  try {
    normalizedInviteCode = inviteCode
      ? rememberChamberInviteCode(inviteCode)
      : chamberInviteCode(uni.getStorageSync(MEMBER_INVITE_STORAGE_KEY));
  } catch (error) {
    return Promise.reject({ status: 422, msg: error.message });
  }
  if (initializedToken !== token || initializedInviteCode !== normalizedInviteCode) {
    initializedToken = token;
    initializedInviteCode = normalizedInviteCode;
    initializationKey = memberUi.createIdempotencyKey('member-bootstrap');
    initializationPromise = null;
  }
  if (!initializationPromise) {
    const payload = initializedInviteCode ? { invite_code: initializedInviteCode } : {};
    initializationPromise = request(MEMBER_BOOTSTRAP_PATH, 'POST', payload, {
      headers: { 'Idempotency-Key': initializationKey },
    })
      .then((response) => {
        if (initializedInviteCode) uni.removeStorageSync(MEMBER_INVITE_STORAGE_KEY);
        return response;
      })
      .catch((error) => {
        initializationPromise = null;
        throw error;
      });
  }

  return initializationPromise;
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

export function uploadMemberAsset(filePath, idempotencyKey) {
  const headers = authenticatedHeaders({ 'Idempotency-Key': idempotencyKey }, true);
  if (!headers) return Promise.reject({ status: 401, msg: '未登录' });

  return new Promise((resolve, reject) => {
    uni.uploadFile({
      url: String(HTTP_REQUEST_URL).replace(/\/+$/, '') + MEMBER_ASSET_PATH,
      filePath,
      name: 'file',
      formData: {
        purpose: 'graduate_verification_proof',
      },
      header: headers,
      timeout: TIMEOUT,
      success(response) {
        const body = responseBody(response.data);
        if (body.status === 200 || body.status === 201) {
          resolve(body);
          return;
        }
        if (body.status === 401 || response.statusCode === 401) toLogin();
        reject(body.msg ? body : { status: response.statusCode, msg: '上传失败' });
      },
      fail(error) {
        reject({ status: 0, msg: error.errMsg || '上传失败' });
      },
    });
  });
}

export function downloadMemberAssetContent(assetId) {
  const headers = authenticatedHeaders();
  if (!headers) return Promise.reject({ status: 401, msg: '未登录' });

  return new Promise((resolve, reject) => {
    uni.downloadFile({
      url: `${String(HTTP_REQUEST_URL).replace(/\/+$/, '')}${MEMBER_ASSET_PATH}/${assetId}/content`,
      header: headers,
      timeout: TIMEOUT,
      success(response) {
        if (response.statusCode >= 200 && response.statusCode < 300 && response.tempFilePath) {
          resolve(response.tempFilePath);
          return;
        }
        if (response.statusCode === 401) toLogin();
        reject({ status: response.statusCode, msg: '文件打开失败' });
      },
      fail(error) {
        reject({ status: 0, msg: error.errMsg || '文件打开失败' });
      },
    });
  });
}

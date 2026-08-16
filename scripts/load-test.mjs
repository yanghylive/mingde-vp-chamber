#!/usr/bin/env node
/**
 * 明德恒智AI企商汇 —— 全链路压测脚本（B4）
 *
 * 自包含、零外部依赖（Node >= 18 内置 fetch）。用于：
 *   1. read         —— 读接口吞吐/延迟基线（安全，只读）
 *   2. slot-race    —— 大咖预约并发正确性（同一档期 N 路并发，断言恰好 1 成功）
 *   3. register-race—— 活动报名并发正确性（capacity=1 票档，断言恰好 1 成功）
 *   4. consume-race —— 积分消费并发幂等（同 key 并发，断言余额只扣 1 次）
 *   5. notify       —— 支付回调 mock（文档化契约，观察失败形态）
 *
 * 用法示例：
 *   node scripts/load-test.mjs --mode read --concurrency 20 --requests 500 --tokens "$T1,$T2"
 *   node scripts/load-test.mjs --mode slot-race --expert 1 --slot 999 --tokens "$T1,$T2,...,$T7"
 *   node scripts/load-test.mjs --mode register-race --event 2 --ticket 2 --tokens "$T1,...,$T7"
 *   node scripts/load-test.mjs --mode consume-race --amount 1 --tokens "$T1"
 *   node scripts/load-test.mjs --mode notify --type wechat
 */

const DEFAULT_BASE = 'https://md.kaypal.cn';
const API = '/api/chamber';

// ---------------------------------------------------------------------------
// CLI 参数解析
// ---------------------------------------------------------------------------
function parseArgs(argv) {
  const args = { mode: 'read', base: DEFAULT_BASE, concurrency: 10, requests: 500, duration: 0, tokens: [], expert: 1, slot: 0, event: 0, ticket: 0, product: 0, points: 0, amount: 1, type: 'wechat', verbose: false };
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    const next = () => argv[++i];
    switch (a) {
      case '--mode': args.mode = next(); break;
      case '--base': args.base = next().replace(/\/+$/, ''); break;
      case '--concurrency': args.concurrency = parseInt(next(), 10); break;
      case '--requests': args.requests = parseInt(next(), 10); break;
      case '--duration': args.duration = parseInt(next(), 10); break;
      case '--tokens': args.tokens = next().split(',').map((s) => s.trim()).filter(Boolean); break;
      case '--expert': args.expert = parseInt(next(), 10); break;
      case '--slot': args.slot = parseInt(next(), 10); break;
      case '--event': args.event = parseInt(next(), 10); break;
      case '--ticket': args.ticket = parseInt(next(), 10); break;
      case '--product': args.product = parseInt(next(), 10); break;
      case '--points': args.points = parseInt(next(), 10); break;
      case '--amount': args.amount = parseInt(next(), 10); break;
      case '--type': args.type = next(); break;
      case '--verbose': args.verbose = true; break;
      case '-h': case '--help': printHelp(); process.exit(0);
      default: break;
    }
  }
  return args;
}

function printHelp() {
  console.log(`
全链路压测脚本 B4
  --mode <read|slot-race|register-race|consume-race|notify>
  --base <url>            默认 https://md.kaypal.cn
  --concurrency N         并发度（默认 10）
  --requests N            read 模式总请求数（默认 500）
  --duration S            read 模式时长（秒，与 requests 二选一，duration 优先）
  --tokens t1,t2,...      逗号分隔 Bearer token（鉴权接口轮询使用）
  --expert <id>           预约/详情专家 id（默认 1）
  --slot <id>             slot-race 档期 id
  --event <id>            register-race 活动 id
  --ticket <id>           register-race 票档 id
  --product <id>          consume-race 商品 id（暂未用）
  --points <n>            consume-race 积分（暂未用）
  --amount <n>            consume-race 单次扣分（默认 1）
  --type <t>              notify 支付渠道（默认 wechat）
  --verbose               打印失败请求明细
`);
}

// ---------------------------------------------------------------------------
// 基础 HTTP 工具
// ---------------------------------------------------------------------------
async function http(method, path, { token = '', body = null, headers = {} } = {}) {
  const url = args.base + path;
  const h = { 'Accept': 'application/json', ...headers };
  if (token) h['Authorization'] = 'Bearer ' + token;
  let payload;
  if (body !== null) {
    h['Content-Type'] = 'application/json';
    payload = JSON.stringify(body);
  }
  const t0 = Date.now();
  let res;
  try {
    res = await fetch(url, { method, headers: h, body: payload });
  } catch (e) {
    return { status: 0, ms: Date.now() - t0, ok: false, error: String(e && e.message || e) };
  }
  const text = await res.text();
  const ms = Date.now() - t0;
  let data = null;
  try { data = text ? JSON.parse(text) : null; } catch { data = text.slice(0, 200); }
  return { status: res.status, ms, ok: res.status >= 200 && res.status < 300, error: null, data };
}

function percentile(sorted, p) {
  if (!sorted.length) return 0;
  const idx = Math.min(sorted.length - 1, Math.floor((p / 100) * sorted.length));
  return sorted[idx];
}

function summarize(name, results) {
  const lat = results.map((r) => r.ms).sort((a, b) => a - b);
  const n = results.length;
  if (!n) { console.log(`${name}: 0 请求`); return; }
  const mean = lat.reduce((a, b) => a + b, 0) / n;
  const ok = results.filter((r) => r.ok).length;
  const byStatus = {};
  for (const r of results) {
    const key = r.error ? 'ERR:' + r.error : String(r.status);
    byStatus[key] = (byStatus[key] || 0) + 1;
  }
  console.log(`\n### ${name}`);
  console.log(`  请求 ${n} | 成功 ${ok} | 失败 ${n - ok} | 错误率 ${((1 - ok / n) * 100).toFixed(2)}%`);
  console.log(`  延迟(ms) min=${lat[0]} p50=${percentile(lat, 50)} p90=${percentile(lat, 90)} p99=${percentile(lat, 99)} max=${lat[n - 1]} mean=${mean.toFixed(1)}`);
  console.log(`  状态分布: ${Object.entries(byStatus).map(([k, v]) => `${k}=${v}`).join('  ')}`);
  return { n, ok, mean, p50: percentile(lat, 50), p90: percentile(lat, 90), p99: percentile(lat, 99) };
}

function parallel(tasks, concurrency) {
  return new Promise((resolve) => {
    const out = new Array(tasks.length);
    let next = 0, done = 0;
    const worker = async () => {
      while (next < tasks.length) {
        const i = next++;
        out[i] = await tasks[i]();
      }
      if (++done === Math.min(concurrency, tasks.length)) resolve(out);
    };
    const w = Math.min(concurrency, tasks.length);
    for (let i = 0; i < w; i++) worker();
  });
}

let args;

// ---------------------------------------------------------------------------
// read —— 读接口吞吐/延迟基线
// ---------------------------------------------------------------------------
const READ_ENDPOINTS = [
  { path: `${API}/v1/bootstrap`, auth: false },
  { path: `${API}/v1/site-config`, auth: false },
  { path: `${API}/monitor/health`, auth: false },
  { path: `${API}/v1/events`, auth: true },
  { path: `${API}/v1/experts`, auth: true },
  { path: `${API}/v1/products`, auth: true },
  { path: `${API}/v1/membership/plans`, auth: true },
  { path: `${API}/v1/me/points`, auth: true },
  { path: `${API}/v1/me/stats`, auth: true },
  { path: `${API}/v1/me/profile`, auth: true },
  { path: `${API}/v1/me/notifications`, auth: true },
];

async function runRead() {
  const total = args.requests;
  const byEndpoint = {};
  const all = [];
  const t0 = Date.now();
  const deadline = args.duration > 0 ? t0 + args.duration * 1000 : Infinity;

  const tokenFor = (i) => (args.tokens.length ? args.tokens[i % args.tokens.length] : '');

  let issued = 0;
  const worker = async () => {
    while (issued < total && Date.now() < deadline) {
      const idx = issued++;
      const ep = READ_ENDPOINTS[idx % READ_ENDPOINTS.length];
      const token = ep.auth ? tokenFor(idx) : '';
      const r = await http('GET', ep.path, { token });
      (byEndpoint[ep.path] = byEndpoint[ep.path] || []).push(r);
      all.push(r);
    }
  };
  await parallel(Array.from({ length: args.concurrency }, () => worker), args.concurrency);

  const elapsed = (Date.now() - t0) / 1000;
  console.log(`\n========== READ 模式 ==========`);
  console.log(`并发 ${args.concurrency} | 总请求 ${all.length} | 耗时 ${elapsed.toFixed(1)}s | 吞吐 ${(all.length / elapsed).toFixed(1)} req/s`);
  for (const ep of Object.keys(byEndpoint)) summarize(ep, byEndpoint[ep]);
  summarize('TOTAL', all);
}

// ---------------------------------------------------------------------------
// slot-race —— 预约并发正确性（悲观锁 SELECT ... FOR UPDATE）
// ---------------------------------------------------------------------------
async function runSlotRace() {
  const tokens = args.tokens;
  if (!tokens.length) { console.error('slot-race 需要 --tokens（多个不同 uid 的 token）'); process.exit(1); }
  if (!args.slot) { console.error('slot-race 需要 --slot <slot_id>'); process.exit(1); }
  console.log(`\n========== SLOT-RACE ==========`);
  console.log(`expert=${args.expert} slot=${args.slot} 并发 ${tokens.length} 路（同一档期）`);

  const tasks = tokens.map((t) => () => http('POST', `${API}/v1/experts/${args.expert}/appointments`, { token: t, body: { slot_id: args.slot, mode: 'online' } }));
  const results = await parallel(tasks, tokens.length);
  const ok = results.filter((r) => r.ok).length;
  const conflict = results.filter((r) => r.status === 409).length;
  results.forEach((r, i) => {
    if (args.verbose || !r.ok) {
      console.log(`  [${i}] uid=${i + 5} HTTP ${r.status} ${r.ms}ms  ${r.ok ? 'OK' : (r.data && (r.data.msg || r.data.code || r.data)) || r.error}`);
    }
  });
  console.log(`\n  成功(预约到) ${ok} | 冲突(409) ${conflict} | 其他 ${results.length - ok - conflict}`);
  const pass = ok === 1 && conflict === tokens.length - 1;
  console.log(`  断言: 恰好 1 成功 && ${tokens.length - 1} 冲突  =>  ${pass ? '✅ PASS' : '❌ FAIL'}`);
  process.exitCode = pass ? 0 : 1;
}

// ---------------------------------------------------------------------------
// register-race —— 报名并发正确性（乐观锁 capacity 计数）
// ---------------------------------------------------------------------------
async function runRegisterRace() {
  const tokens = args.tokens;
  if (!tokens.length) { console.error('register-race 需要 --tokens'); process.exit(1); }
  if (!args.event || !args.ticket) { console.error('register-race 需要 --event <id> --ticket <id>'); process.exit(1); }
  console.log(`\n========== REGISTER-RACE ==========`);
  console.log(`event=${args.event} ticket=${args.ticket} 并发 ${tokens.length} 路`);

  const tasks = tokens.map((t) => () => http('POST', `${API}/v1/events/${args.event}/registrations`, {
    token: t,
    headers: { 'Idempotency-Key': `loadtest-reg-${Date.now()}-${Math.random().toString(36).slice(2)}` },
    body: { ticket_id: args.ticket, expected_amount: '0.00', expected_integral: 0 },
  }));
  const results = await parallel(tasks, tokens.length);
  const created = results.filter((r) => r.status === 201).length;
  const ok2xx = results.filter((r) => r.ok).length;
  const conflict = results.filter((r) => r.status === 409).length;
  results.forEach((r, i) => {
    if (args.verbose || !r.ok) {
      console.log(`  [${i}] HTTP ${r.status} ${r.ms}ms  ${r.ok ? 'OK' : (r.data && (r.data.msg || r.data.code || r.data)) || r.error}`);
    }
  });
  console.log(`\n  201(报名成功) ${created} | 2xx ${ok2xx} | 409(已满/已报) ${conflict}`);
  const pass = created === 1 && conflict === tokens.length - 1;
  console.log(`  断言: 恰好 1 报名成功 && ${tokens.length - 1} 冲突  =>  ${pass ? '✅ PASS' : '❌ FAIL'}`);
  process.exitCode = pass ? 0 : 1;
}

// ---------------------------------------------------------------------------
// consume-race —— 积分消费并发幂等（同 key，乐观锁 + 幂等）
// ---------------------------------------------------------------------------
async function runConsumeRace() {
  const tokens = args.tokens;
  if (!tokens.length) { console.error('consume-race 需要 --tokens'); process.exit(1); }
  const token = tokens[0];
  console.log(`\n========== CONSUME-RACE ==========`);
  console.log(`amount=${args.amount} 并发 ${args.concurrency} 路（同一 idempotency_key）`);

  const before = (await http('GET', `${API}/v1/me/points`, { token })).data;
  const balBefore = before && before.data && before.data.points != null ? before.data.points : null;

  const key = `loadtest-consume-${Date.now()}`;
  const tasks = Array.from({ length: args.concurrency }, () => () => http('POST', `${API}/v1/me/points/consume`, { token, body: { amount: args.amount, idempotency_key: key, reason: 'loadtest' } }));
  const results = await parallel(tasks, args.concurrency);
  const ok = results.filter((r) => r.ok).length;
  results.forEach((r, i) => { if (args.verbose || !r.ok) console.log(`  [${i}] HTTP ${r.status} ${r.ms}ms ${r.ok ? 'OK' : (r.data && r.data.msg) || r.error}`); });

  const after = (await http('GET', `${API}/v1/me/points`, { token })).data;
  const balAfter = after && after.data && after.data.points != null ? after.data.points : null;

  console.log(`  成功(2xx) ${ok}/${args.concurrency} | 余额 ${balBefore} -> ${balAfter}`);
  if (balBefore !== null && balAfter !== null) {
    const delta = balBefore - balAfter;
    const pass = delta === args.amount;
    console.log(`  断言: 余额只扣 ${args.amount}（实际扣 ${delta}）  =>  ${pass ? '✅ PASS' : '❌ FAIL（幂等失效，被重复扣费）'}`);
    process.exitCode = pass ? 0 : 1;
  } else {
    console.log(`  ⚠️ 无法读取余额，跳过断言`);
  }
}

// ---------------------------------------------------------------------------
// notify —— 支付回调 mock（文档化契约，观察失败形态）
// ---------------------------------------------------------------------------
async function runNotify() {
  console.log(`\n========== NOTIFY ==========`);
  console.log(`type=${args.type}  （生产 pay_weixin_* 配置为空，预期回调解析失败，仅验证端点可达与失败形态）`);
  const path = `/api/pay/notify/${args.type}`;
  const r = await http('POST', path, { body: { event_type: 'TRANSACTION.SUCCESS', resource: { out_trade_no: 'LOADTEST', transaction_id: 'MOCK', amount: { total: 1 } } } });
  console.log(`  响应 HTTP ${r.status} ${r.ms}ms`);
  console.log(`  响应体: ${typeof r.data === 'string' ? r.data : JSON.stringify(r.data)}`);
  console.log(`  （期望：端点可达，返回 4xx/5xx 明确失败而非 404，说明路由已注册）`);
  process.exitCode = r.status === 404 ? 1 : 0;
}

// ---------------------------------------------------------------------------
// main
// ---------------------------------------------------------------------------
async function main() {
  args = parseArgs(process.argv.slice(2));
  switch (args.mode) {
    case 'read': await runRead(); break;
    case 'slot-race': await runSlotRace(); break;
    case 'register-race': await runRegisterRace(); break;
    case 'consume-race': await runConsumeRace(); break;
    case 'notify': await runNotify(); break;
    default: console.error('未知 mode: ' + args.mode); printHelp(); process.exit(1);
  }
}

main().catch((e) => { console.error('FATAL', e); process.exit(1); });

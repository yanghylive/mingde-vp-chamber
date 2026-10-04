# CRMEB 上游补丁层（patch layer）

> 规则：`backend/crmeb` 子模块**永远保持上游原样只读**（当前锁定 `v6.0.0` / `7dcddfff`）。
> 对上游源码的一切兼容性修改，必须以 `.patch` 文件形式存放在本目录，
> 由 `scripts/prepare-local-crmeb-runtime.sh` 在生成**运行副本**
> （`.build-workspace/crmeb-runtime/`，gitignored）时自动打上。
> 绝不在子模块工作区里直接改代码、绝不产生本地 commit。

## 机制

1. `prepare-local-crmeb-runtime.sh prepare` 把 `backend/crmeb/crmeb/` rsync 到运行副本后，
   按文件名顺序对运行副本执行本目录下全部 `*.patch`（`patch -p1`）。
2. 每个补丁先 `--dry-run` 再正式应用；任何一个打不上就**直接失败**，
   防止上游漂移被静默跳过。
3. 应用记录写入运行副本 `.applied-patches`，便于追溯。

新增补丁时：复制目标文件到 /tmp 改好，用 `diff -u` 生成标准 unified diff，
路径前缀保持 `a/<上游相对路径>`（相对 `backend/crmeb/crmeb/`），并先在 /tmp 验证
`patch -p1 --dry-run` 通过。

## 补丁清单

| 文件 | 目标（相对 backend/crmeb/crmeb） | 说明 |
|---|---|---|
| `0001-sms-verify-skip-slider-when-captcha-type-empty.patch` | `app/api/controller/v1/LoginController.php`（`verify()`） | 短信验证码发送修复：`captchaType` 为空时跳过滑块二次校验，否则小程序端永远发不出短信 |

## 历史补丁处置（2026-10-04）

子模块曾锁定两个**本地提交**（原开发机私有，从未 push，任何远端都取不到，
导致所有新机器 `git submodule update` 失败）：

| 原提交 | 说明 | 处置 |
|---|---|---|
| `7095948e` | chamber 接口前缀/CORS/cookie 兼容（小程序 404 + 跨域） | **已确认被取代，不再需要**：ThinkPHP `auto_multi_app` 自动解析 `/chamber` 前缀；CORS 由 overlay 的 `ChamberCorsMiddleware` 按路由挂载，上游 `AllowOriginMiddleware` 只注册在 adminapi/outapi/kefuapi/pc 路由组，未污染 chamber；nginx 重写规则在 `deployment/local/nginx-vhost.conf`。上游保持原样 |
| `0791fbf` | 短信验证码发送修复（captchaType 为空跳过滑块校验） | **转写为本目录 `0001` 补丁**，语义与原提交说明一致；v6.0.0 原生代码仍有该 bug，已实测补丁可干净应用 |

重锁后 `PROJECT_MANIFEST.json` 的 `upstream.project_commit` 即上游 commit，
不再存在"本地补丁提交"。

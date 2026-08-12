# FansHub 压测（hbsq.bio / HTTPS / WSS）

当前线上入口（与 `uni-999` 兜底配置一致）：

| 用途 | 地址 |
|------|------|
| 站点 / API | `https://hbsq.bio` |
| IM WebSocket | `wss://hbsq.bio/im-ws` → Nginx → `127.0.0.1:17272` |
| IM HTTP | `https://hbsq.bio/im-api` → Nginx → `127.0.0.1:17273` |
| 健康检查 | `GET https://hbsq.bio/im-api/health` |

**不要在未隔离的生产库上直接打到 1 万并发。** 建议：预发 / 从库只读 + 专用测试号，或业务低峰 + 阶梯加压。

## 1. 准备测试 token

库里的 `fa_user_token` 是 HMAC 存盘，**无法导出已有明文 token**，需脚本签发：

```bash
# 机器人账号（推荐，先 seed_bot_users）
php scripts/seed_bot_users.php --count=5000

# 为 5000 个机器人各签 1 个 token（一人一 token，避免 session.replaced 互踢）
php scripts/loadtest/export_tokens.php --reuse-bots --count=5000
```

输出：`scripts/loadtest/tokens.json`（已 gitignore，勿提交）。

测完清理机器人：

```bash
# 先预览（默认不删）
php scripts/purge_bot_users.php --mobile-prefix=10000000001

# 确认删除 seed 手机号段机器人（10000000001 起）
php scripts/purge_bot_users.php --mobile-prefix=10000000001 --confirm
```

⚠️ `--all-bots --confirm` 会删**所有** `is_bot=1` 账号（含运营发包机器人），一般不要用。

## 2. WS 协议（与 App/H5 一致）

连接：

```text
wss://hbsq.bio/im-ws?token={明文token}&device_fp=load-{user_id}
```

握手后服务端自动 `auth.ok`（也可发 `{type:"auth",data:{token,device_fp}}`）。

保活（25s，与客户端一致）：

```json
{"type":"ping","data":{},"req_id":"p-1"}
```

→ 回 `{type:"pong",...}`

## 3. HTTP IM（与 `im.js` 一致）

```http
POST https://hbsq.bio/im-api/im/conversations
Content-Type: application/json
X-Fans-Token: {token}

{"token":"{token}"}
```

成功：`{"code":1,"data":{...}}`

## 4. k6（推荐，Linux 压测机）

安装 [k6](https://k6.io/docs/get-started/installation/) 后：

```bash
# 冒烟 50 在线
k6 run -e VUS=50 -e DURATION=2m scripts/loadtest/k6/ws-online.js

# 阶梯到 1 万 WS（需多台压测机分担，单机通常 2k～5k）
k6 run scripts/loadtest/k6/ws-online.js

# HTTP 会话列表
k6 run -e VUS=200 scripts/loadtest/k6/http-im.js
```

环境变量：

- `WS_BASE` 默认 `wss://hbsq.bio/im-ws`
- `API_BASE` 默认 `https://hbsq.bio`
- `TOKENS` 默认 `scripts/loadtest/tokens.json`

## 5. Node 备选（Windows 本机冒烟）

```powershell
cd scripts\loadtest
npm install
node node\ws-online.mjs --vus=100 --duration=120
```

## 6. 万人怎么拆

| 阶段 | 目标 | 说明 |
|------|------|------|
| A | 100～500 | 鉴权、掉线率 |
| B | 1k～3k | 纯挂线 + ping |
| C | 5k～10k | 多台压测机，每机 2k～3k VU |
| D | 子集写操作 | 发消息/抢包单独小流量测 |

单机文件描述符（Linux）：

```bash
ulimit -n 1048576
```

服务端（IM 机）核对：

- `im-server` WS worker 数、`max_connections`（MySQL）
- Nginx `worker_connections`
- Redis / 带宽（群推送放大）

## 7. 监控

压测同时看：

- `https://hbsq.bio/im-api/health`
- IM 进程 / 17272、17273 连接数
- MySQL 慢查询、Redis 延迟
- 压测机 CPU、网卡、TIME_WAIT

## 8. 注意

1. **一人一 token + 固定 device_fp**，否则 `session.replaced` 互踢。
2. 抢红包 / 牛牛 / 充值提现 **单独场景**，不要和万人在线混测。
3. Windows 上 IM WS 只有 1 worker，**容量测试请在 Linux IM 服务器环境**。
4. `tokens.json` 等同登录凭证，测完可删或过期。

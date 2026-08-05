# FansHub IM（Workerman）

私聊 / 群聊 + 红包（发/抢/退/结算）+ 自动机器人 + Tron 公平开奖。

## 进程架构（必启 3 个）

| 进程 | 命令 | 端口 | 职责 |
|------|------|------|------|
| **WS** | `php start.php start` | **17272** | WebSocket 聊天、PushBus 本地投递 |
| **HTTP** | `php start_admin.php start` | **17273** | `/im/*` 用户 API、`/agent/*` 代聊、`/health` |
| **Cron** | `php start_cron.php start` | 无 | Tron 哈希、过期退款、结算重试、RpAutoBot |

Worker0（WS）仅保留后台代聊 `notify_queue` 消费；**重任务已迁至 Cron**，避免拖死聊天事件循环。

```text
H5 列表/历史 ──HTTP──► :17273
H5 推送/发消息 ──WS───► :17272
Cron 定时任务 ─────────► MySQL / Redis / TronGrid（推送走 PushBus 外部队列）
```

## 依赖

- PHP >= 7.4（推荐 8.1），`ext-pdo`、`ext-redis`、`ext-bcmath`
- MySQL（先执行仓库 `sql/chat_im.sql` 等初始化）
- Redis
- Composer：`composer install`（本目录）

## 安装与配置

```bash
cd im-server
composer install
```

默认读仓库根目录 `.env` 的 `[database]` / `[redis]`。可选覆盖：

```php
// im-server/config/local.php（勿提交密钥）
return [
  'websocket' => ['listen' => 'websocket://0.0.0.0:17272', 'count' => 16],
  'http_api'  => ['count' => 4],
  'cron' => [
    'tron_poll_interval' => 1,  // 可调 3～5 降压
    'refund_interval'    => 5,
    'settle_interval'    => 2,
    'auto_interval'      => 2,
  ],
  'admin_bridge' => ['key' => '你的后台密钥'],
  'redis' => ['host' => '127.0.0.1', 'port' => 6379, 'db' => 2, 'prefix' => 'im:'],
];
```

- Windows：`websocket.count` 强制为 **1**
- Linux：默认 WS `count=8`，HTTP `count=4`（可按 CPU 调整）

## 一键脚本

### Windows（PowerShell）

```powershell
cd im-server\scripts
.\start-all.ps1
.\status.ps1
.\restart-all.ps1
.\stop-all.ps1

# 指定 PHP
.\start-all.ps1 -PhpPath "C:\BtSoft\php\81\php.exe"
```

| 脚本 | 作用 |
|------|------|
| `scripts/start-all.ps1` | 启动 WS + HTTP + Cron（已运行则跳过） |
| `scripts/stop-all.ps1` | 结束相关 php 进程，并清理 17272/17273 占用 |
| `scripts/restart-all.ps1` | 停 → 启 |
| `scripts/status.ps1` | 进程、监听端口、`/health` |

### Linux（Bash）

```bash
cd im-server/scripts
chmod +x *.sh
./start-all.sh      # 使用 start -d
./status.sh
./restart-all.sh
./stop-all.sh

# 指定 PHP
PHP_BIN=/usr/bin/php ./start-all.sh
```

| 脚本 | 作用 |
|------|------|
| `scripts/start-all.sh` | 三个进程 `start -d` |
| `scripts/stop-all.sh` | `php *.php stop` + pkill 兜底 |
| `scripts/restart-all.sh` | 重启 |
| `scripts/status.sh` | 进程 / 端口 / health |

### 手动命令

```bash
# Linux 守护
php start.php start -d
php start_admin.php start -d
php start_cron.php start -d

php start.php stop
php start_admin.php stop
php start_cron.php stop

# Windows 一般用脚本；或前台运行后 Ctrl+C
php start.php start
```

健康检查：

```bash
curl http://127.0.0.1:17273/health
# {"ok":true}
```

## 部署检查清单（另一台服务器）

```bash
git pull
cd im-server
composer install   # 若依赖有变
cd scripts && ./restart-all.sh   # Windows: .\restart-all.ps1
./status.sh                      # 确认 17272/17273 + Cron 进程存在
```

- [ ] `start_cron.php` 已启动（否则自动红包/退款/Tron 停）
- [ ] 防火墙或 Nginx 反代 17272、17273
- [ ] Redis / MySQL 与 `.env` 一致

## 推送策略（万人群）

群消息 / 红包 / 群事件优先 `PushBus::toGroup($gid, ...)`：

1. 跨进程只传 `group_id + payload`（不传万级 uid 列表）
2. 各 Worker 本机用 `ConnMap::filterLocalGroupMembers`（`SISMEMBER g:{gid}:mset`）投递
3. 仍覆盖「在线但未进房」的成员（不是只推观群集合）
4. 旧路径 `pushTargetUserIds` / `max_push_online` 仅用于未读等非频道场景

### 抢包风暴

群内 `redpacket.update` 经 `RedPacketUpdateBus`：**同 packet_id 默认 120ms 合并**为 1 次推送（可配 `redpacket.update_coalesce_ms`）。  
结算 / 开奖 / 领完 / 过期 **立即推**，并取消待合并项。抢包本人仍即时收到 `redpacket.grabbed` 回包。

## HTTP 接口摘要

用户（会员 token）：

```text
POST /im/conversations
POST /im/history
...（见 UserApi）
GET  /health
```

后台代聊（`admin_key`）：

```bash
curl -X POST http://127.0.0.1:17273/agent/send_private \
  -H "Content-Type: application/json" \
  -d '{"admin_key":"...","agent_user_id":1,"to_user_id":2,"content":"你好"}'
```

## 协议（JSON Text，WS）

客户端 → 服务端（节选）：

| type | data |
|------|------|
| `auth` | `{token}` |
| `private.send` | `{to_user_id, content}` |
| `group.send` | `{group_id, content}` |
| `group.view.enter` / `leave` / `ping` | `{group_id}` |
| `redpacket.send` | `{scope_type, group_id\|to_user_id, packet_type, ...}` |
| `redpacket.grab` | `{packet_id}` |
| `ping` | `{}` |

服务端推送：`private.message` / `group.message` / `redpacket.update` / `auth.ok` / `error` 等。

## 红包与 Cron

- **发/抢**：WS 或 HTTP；钱包字段默认 `hongbao`（见 `red_packet` 配置）
- **过期退回 / 结算兜底 / Tron / 自动任务**：仅 **Cron** 进程定时执行
- 热路径最后一包仍会在当前 Worker 短延迟 settle；Cron 为兜底重试

## 排障

| 现象 | 排查 |
|------|------|
| 聊天卡、Tron 抖 | 确认重任务是否误挂回 WS；应只有 Cron 跑 Tron |
| 不自动发红包 / 不退款 | `status` 看 Cron 是否在跑；看 PHP error_log `[CRON]` |
| 17272 起不来 | `stop-all` 后重试；查端口占用 |
| 推送漏 | 客户端是否 `group.view.enter`；Redis `online` / view set |

更完整的业务运维见仓库根目录 [README.md](../README.md)。

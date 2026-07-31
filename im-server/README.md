# FansHub IM（Workerman）

私聊 / 群聊（纯文本）+ 群发红包 / 抢红包（Redis Lua 防超卖）。

## 依赖

- PHP >= 7.4，`ext-pdo`、`ext-redis`、`ext-bcmath`
- MySQL（先执行 `sql/chat_im.sql`）
- Redis
- Composer 包：`workerman/workerman`

## 安装

```bash
cd im-server
composer install
mysql -u用户 -p 库名 < ../sql/chat_im.sql
```

配置默认读取仓库根目录 `.env` 的 `[database]`；可选覆盖：

```php
// im-server/config/local.php
return [
  // Linux 高配：count 建议 ≈ CPU 核数，常见 8，最高可试 16（先确认 MySQL max_connections / Redis）
  'websocket' => ['listen' => 'websocket://0.0.0.0:7272', 'count' => 8],
  'admin_bridge' => ['key' => '你的后台密钥'],
  'redis' => ['host' => '127.0.0.1', 'port' => 6379, 'db' => 2, 'prefix' => 'im:'],
];
```

> 推送已按「用户所在 Worker」定向投递；`count=16` 时不会再把每条消息全量复制到全部进程。

## 启动

用户 H5：**列表/历史优先 HTTP** `:7273/im/*`；**推送/发消息仍走 WS** `:7272`。

```text
POST /im/conversations  {token, limit?}
POST /im/history        {token, conversation_type, conversation_id|group_id|to_user_id, ...}
GET  /health
```

```bash
# WebSocket IM（端口 7272）
php start.php start -d

# HTTP 只读 API + 后台代聊桥（端口 7273）
php start_admin.php start -d
```

Windows 下 `count=1`，前台运行，`Ctrl+C` 停止。Linux 默认 WS `count=8`、HTTP `http_api.count=4`（可在 `local.php` 改）。

### 约 5000 同时在线（Linux）

1. `local.php` 设 `'websocket' => ['count' => 4]`（或按 CPU 核数 4～8）
2. 系统：`ulimit -n 65535`，调高 `somaxconn` / `file-max`
3. 已内置 **Redis 跨进程推送总线**（`PushBus`）：多 Worker 不会漏消息
4. 群推只扇出 **当前在线成员**；成员列表 Redis 缓存 60s
5. 确保 Redis 与 MySQL 可用、与 IM 同机或低延迟旁路

重启 IM：`php start.php restart`（或先 stop 再 start）

## 协议（JSON Text）

客户端 → 服务端：

| type | data |
|------|------|
| `auth` | `{token}` |
| `private.send` | `{to_user_id, content}` |
| `group.send` | `{group_id, content}` |
| `group.create` | `{name, member_ids:[]}` |
| `group.list` | `{}` |
| `conversation.list` | `{limit?}` |
| `history` | `{conversation_type, conversation_id\|to_user_id\|group_id, before_id?, limit?}` |
| `redpacket.send` | `{scope_type, group_id\|to_user_id, packet_type, total_amount, total_count, blessing}` |
| `redpacket.grab` | `{packet_id}` |
| `redpacket.detail` | `{packet_id}` |
| `ping` | `{}` |

服务端推送：`private.message` / `group.message` / `redpacket.update` / `error` / `auth.ok` 等。

## H5 调试页

浏览器打开：`/im/`（即 `public/im/index.html`）  
粘贴 `fans_hub_token` 后连接 `ws://域名:7272`。

## 后台代聊 HTTP

```bash
curl -X POST http://127.0.0.1:7273/agent/send_private \
  -H "Content-Type: application/json" \
  -d '{"admin_key":"change-me-im-admin","agent_user_id":1,"to_user_id":2,"content":"你好"}'
```

需先在 `fa_chat_agent_accounts` 登记托管账号。

## 红包（对接 fa_user.money）

1. **发红包**：事务内 `SELECT … FOR UPDATE` 扣减发送方 `fa_user.money`，写 `fa_user_money_log`，落 `fa_chat_red_packets`，再按普通/拼手气**预拆成「分」**写入 Redis List。
2. **抢红包**：Lua `LPOP` + `SADD` 原子占坑 → MySQL 领取记录 → 增加领取人 `money`。
3. **过期退回**：定时任务将剩余金额退回发送方 `money`，红包 `status=3`。

配置见 `config/app.php` → `red_packet.wallet_field`（默认 `money`）。

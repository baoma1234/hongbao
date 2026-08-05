# 红宝 / FansHub（ThinkPHP FastAdmin + Workerman IM）

本仓库为红宝业务站：后台 FastAdmin、H5 `/888`、IM（WebSocket + HTTP API + Cron）。

远端：`https://github.com/baoma1234/hongbao.git`（默认分支 `main`）。

## 目录速览

| 路径 | 说明 |
|------|------|
| `application/` | ThinkPHP 业务与后台 |
| `public/888/` | H5 入口与静态资源（现网） |
| `public/999/` | uni-app H5 构建产物（并行） |
| `uni-999/` | uni-app 源码（Vue3 + Vite） |
| `im-server/` | Workerman IM（**必启 3 个进程**） |
| `scripts/` | 一次性安装/回补脚本 |
| `tools/build-888-chat.ps1` | 打包 H5 聊天 JS/CSS |

## 环境依赖

- PHP 7.4+ / 8.1（IM 推荐 8.1），扩展：`pdo`、`redis`、`bcmath`、`mbstring`
- MySQL、Redis
- Composer（`im-server/vendor`）
- 根目录 `.env`（数据库等；勿提交密钥）

## 一、IM 运行说明（必读）

IM 分 **三个独立进程**，缺一不可：

| 进程 | 入口 | 端口 | 职责 |
|------|------|------|------|
| WS | `im-server/start.php` | **17272** | 聊天 WebSocket、PushBus |
| HTTP | `im-server/start_admin.php` | **17273** | 会话/历史 API、后台代聊桥 |
| Cron | `im-server/start_cron.php` | 无 | Tron 轮询、过期退款、结算重试、自动发/抢红包 |

> 一期改造后：重任务已从 WS Worker0 迁出。若未启动 `start_cron.php`，退款 / Tron / 自动红包会停，但聊天仍可用。

配置：`im-server/config/app.php`，可被 `im-server/config/local.php`、根目录 `.env` 覆盖。详见 [im-server/README.md](im-server/README.md)。

### Windows（本机常用）

```powershell
cd im-server\scripts
.\start-all.ps1          # 启动三个进程
.\status.ps1             # 查看进程 / 端口 / health
.\restart-all.ps1        # 先停再启
.\stop-all.ps1           # 全部停止
```

指定 PHP：

```powershell
.\start-all.ps1 -PhpPath "C:\BtSoft\php\81\php.exe"
```

手动前台启动（调试）：

```powershell
cd im-server
php start.php start
php start_admin.php start
php start_cron.php start
```

### Linux

```bash
cd im-server/scripts
chmod +x *.sh
./start-all.sh           # 后台 -d
./status.sh
./restart-all.sh
./stop-all.sh
```

或：

```bash
cd im-server
php start.php start -d
php start_admin.php start -d
php start_cron.php start -d
```

健康检查：

```bash
curl http://127.0.0.1:17273/health
```

### 其它服务器同步后

```bash
git pull
cd im-server/scripts && ./restart-all.sh   # 或 Windows: .\restart-all.ps1
```

确保防火墙放行 **17272 / 17273**（或经 Nginx 反代）。

## 二、H5（/888）与 uni-app（/999）

### 现网 H5 `/888`

入口：`public/888/index.php`（`$assetVer` 控制缓存）。

改聊天源码后需打包并升版本：

```powershell
powershell -ExecutionPolicy Bypass -File tools/build-888-chat.ps1
# 再改 public/888/index.php 里 $assetVer
```

### 新端 uni-app `/999`（并行灰度）

源码：`uni-999/` → 构建输出：`public/999/`

```bash
cd uni-999
npm install
npm run dev:h5      # 本地开发（代理 /api、/im-ws）
npm run build:h5    # 编译并复制到 public/999
```

浏览器打开：`/999/`  
已打通：登录（短信）+ IM WebSocket（会话列表 / 聊天骨架）。细节见 `uni-999/README.md`。

## 三、常用运维脚本（仓库 `scripts/`）

| 脚本 | 用途 |
|------|------|
| `scripts/backfill_turnover_from_ledger.php` | 按发红包流水回补 `fans_account.turnover` |
| `scripts/install_withdraw_approve_auth.php` | 提现「审核通过」权限规则 |
| `scripts/install_online_coop_withdraw.php` | 安装「线上合作」提现分区/通道 |
| `scripts/update_default_cs_welcome.php` | 刷新默认客服 88888888 问候语 |

示例：

```bash
php scripts/backfill_turnover_from_ledger.php
php scripts/install_withdraw_approve_auth.php
```

## 四、后台与业务要点

- 提现列表：先 **审核通过**，再出现 **打款**；可点 **流水** 看会员资金流水
- 发红包会计入累计流水（`turnover`）；后台会员编辑可保存累计流水
- 新用户会话默认只固定客服 **88888888**
- 群红包推送：优先推「正在看该群」的在线用户，不再「无人在线则推 ≤200 全员」

## 五、Git

```bash
git pull origin main
# 有意义改动后：commit + git push -u origin HEAD
# 勿提交 .env、im-server/config/local.php、密钥、zip
```

## 六、FastAdmin 基座

后台框架文档：https://doc.fastadmin.net  

本 README 以红宝业务运维为准；IM 细节以 `im-server/README.md` 为准。

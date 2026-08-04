# uni-999（红宝多端）

源码目录：`uni-999/`  
H5 构建输出：`public/999/`（与现网 `/888` 并行）

## 已打通

- 分页骨架：登录 / 大厅 / 闪兑 / 红宝 / 团长 / 我的（与 `/888` Tab 一致）；钱包从「我的」进入
- 登录：`/api/fanshub/sendsms` + `login`（token 存 `fans_hub_token`）
- IM：`uni.connectSocket` → `/im-ws`（或绝对 WS），握手带 token，发 `auth`，拉 `conversation.list` / `history` / 发消息
- 消息列表：未读角标、红包预览、置顶/客服标记、长按置顶
- 聊天室：文字 + 红包收发/详情、私聊备注（标题优先备注）
- 钱包：余额与流水门槛、充值 / 提现（分区通道、线上合作、钱包绑定、支付密码）、资金流水分页、收款地址绑定
- UI：对齐 `/888` 会员中心与钱包视觉（红金边框通道格、分区 Tab、流水列表、支付密码底栏）

## 命令

```bash
cd uni-999
npm install
npm run dev:h5          # 开发 http://localhost:5199/999/
npm run build:h5        # 输出到 ../public/999
```

访问线上产物：`https://你的域名/999/`

## Nginx 提示

需已有（或等价）：

```nginx
location /im-ws {
  proxy_pass http://127.0.0.1:7272;
  proxy_http_version 1.1;
  proxy_set_header Upgrade $http_upgrade;
  proxy_set_header Connection "Upgrade";
}
location /api/ {
  # 现有 ThinkPHP 入口
}
location /999/ {
  alias /path/to/public/999/;
  try_files $uri $uri/ /999/index.html;
}
```

hash 路由下 `try_files` 通常只要能访问到 `index.html` 即可。

## 下一步

- 群设置 / 表情贴纸 / 撤回
- 大厅 / 闪兑 / 团长业务页
- App 打包（`dev:app` / HBuilderX）

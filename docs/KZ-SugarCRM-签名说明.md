# KZ SugarCRM 签名规则记录

> 来源：Documentation — **4. How to generate sign**  
> 代码：`application/common/library/SugarCrm.php`  
> 自检：`SugarCrm::assertOfficialSample()` 必须为 true

---

## 官方样例（必须算对）

| 项 | 值 |
|----|----|
| 参数 | `a=>1` `b=>'222'` `c=>33` |
| 密钥 | `abcde12345`（正式 key 由 KZ 提供） |
| 签名原文 | `a=1&b=222&c=33&sKey=abcde12345` |
| sign | `770560eca1a39eadab5c01af7d1cecf1` |

```text
sign = strtolower( MD5( 按文档顺序拼接的业务参数 + "&sKey=" + 密钥 ) )
```

要点：

1. **All params must follow sequence accordingly**（按接口文档字段顺序，不是随便乱序）
2. 末尾跟 `&sKey={密钥}`（密钥不作为 POST 字段）
3. 再跟 POST 字段 **`sign`**（follow by param[sign]）
4. MD5 结果必须是 **小写**

---

## 本站 plist 实际拼法

文档字段顺序：

`min_upd → max_upd → min_regdt → max_regdt → playername → pages → pageLength`

有值才参与签名。核销查询示例（已去掉 pages / pageLength）：

```text
业务参数：
playername=fhdx888

签名原文：
playername=fhdx888&sKey={KZ密钥}

POST：
playername / sign
```

sKey 配置值 `9eT8zVu3z1ubzUPxkpFlDuZpX6A1q42`：**无空格**（代码构造时仍 `trim` 一次）。

配置（`application/extra/fanshub.php`）：

- `sugarcrm_skey` = KZ Encryption key  
- `sugarcrm_x_env` = `555bioprod`（须与 key 配对）

---

## 关于 Invalid Signature

本地已用官方样例验算通过；用当前配置 key `9eT8zVu3z1ubzUPxkpFlDuZpX6A1q42` + 多种拼法直连线上，均返回 `Invalid Signature`。

请把下面信息发给 KZ，让他们回「服务端期望的 sign」：

```text
X-ENV: 555bioprod
params: playername=fhdx888&pages=1&pageLength=10
sign_string: playername=fhdx888&pages=1&pageLength=10&sKey=9eT8zVu3z1ubzUPxkpFlDuZpX6A1q42
our_sign: dbf777af6d0d9253ca3538133a54d6cb
response: {"c":-1,"m":"Invalid Signature"}
```

日志：`runtime/log/sugarcrm/sugarcrm_YYYYMMDD.log`（含 `sign_string: ...&sKey=***`）

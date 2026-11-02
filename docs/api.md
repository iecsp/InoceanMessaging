# Backend API

机器可读定义见 [OpenAPI 3.1](openapi.json)。本版本的可用集成入口是 Shopware Admin API，使用原生 OAuth2（`POST /api/oauth/token`）取得 Bearer token。分配最小 ACL：

| 权限 | 用途 |
|---|---|
| inocean_messaging:read | 记录、状态、额度与费用 |
| inocean_messaging:send | 入队发送，包含模板发送 |
| inocean_messaging:manage | 同意证据、人工抑制、预览、provider 测试/切换 |
| ino_sms_template:read/create/update/delete | 原生 DAL 模板管理 |
| system_config:read/create/update/delete | 原生 System Config（仅配置管理员） |

权限不隐含门店/租户隔离：这是平台后端权限，允许有此权限的集成访问各 Sales Channel。所有操作必须提供 `scopeId`，不得公开 token。

## 接口

| Method / Path | 说明 |
|---|---|
| POST /api/_action/inocean/messaging/send | 原文或 templateCode 发送，202 |
| GET /api/_action/inocean/messaging/messages?scopeId=&page=1 | 每页 50 条，最多偏移 10000 页 |
| GET /api/_action/inocean/messaging/messages/{id}?scopeId= | 状态，跨 scope 返回 404 |
| GET /api/_action/inocean/messaging/usage?scopeId= | 本月 UTC 费用、分段、状态/provider 分组和配额 |
| POST /api/_action/inocean/messaging/preview | 安全 Twig 预览和分段，不发送 |
| GET /api/_action/inocean/messaging/consent?scopeId=&phone= | 当前抑制及最近 100 条同意记录元数据，不返回证据明文 |
| POST /api/_action/inocean/messaging/consent | 追加授权或退订证据 |
| POST /api/_action/inocean/messaging/suppression | scope/global/provider 人工抑制与有证据解除 |
| POST /api/_action/inocean/messaging/providers/test | 只读 GET 账户探测，不发送 SMS |
| POST /api/_action/inocean/messaging/providers/switch | 设置该 scope 的默认供应商 |

金额是 decimal 字符串，不转换 float，不跨币种相加；未知币种 null。usage 的 segments 是各状态记录的当前估算/供应商分段，费用只有回调实际返回后才有值；不是 provider 发票结算。

## 后端调用示例

以下发送示例仅用于部署后的主动调用；开发测试不得用真实凭据/号码运行。

先记录业务已经取得的交易授权：

```http
POST /api/_action/inocean/messaging/consent
Authorization: Bearer {MANAGE_TOKEN}
Content-Type: application/json

{
  "scopeId": "11111111111111111111111111111111",
  "phone": "+16045550123",
  "type": "transactional_authorization",
  "source": "reservation-service",
  "evidence": "Reservation 123: guest requested SMS confirmation",
  "expiresAt": "2026-12-01T00:00:00Z"
}
```

发送：

```http
POST /api/_action/inocean/messaging/send
Authorization: Bearer {SEND_TOKEN}
Idempotency-Key: reservation-123-confirmed-v1
Content-Type: application/json

{
  "scopeId": "11111111111111111111111111111111",
  "phone": "+16045550123",
  "templateCode": "reservation_confirmed",
  "language": "en-CA",
  "variables": {"businessName": "Restaurant", "reference": "123"}
}
```

返回 `202 {"id":"...","status":"queued","provider":null}`；仅表示已持久化排队。重放相同 key 和相同最终正文返回已有任务当前状态；同 key 不同 payload 返回 409。key 在 scope 内唯一；模板或营销主体变化也会改变最终正文，调用方应使用版本化 key。

原文发送改用 `body` 和 `purpose`（transactional/marketing）；仍强制检查授权，不提供“跳过退订”参数。任意号码发送仅允许拥有平台发送权限的可信后端。

```js
// 放在你的服务端路由中，绝不能在浏览器中暴露 Admin token。
const response = await fetch(`${shopwareUrl}/api/_action/inocean/messaging/send`, {
  method: 'POST',
  headers: {
    Authorization: `Bearer ${serverAdminToken}`,
    'Content-Type': 'application/json',
    'Idempotency-Key': `reservation-${reservation.id}-confirmed-v1`,
  },
  body: JSON.stringify({
    scopeId: salesChannelId,
    phone: reservation.verifiedPhone, // 从已授权业务记录取得
    templateCode: 'reservation_confirmed',
    language: 'en-CA',
    variables: { businessName: 'Restaurant', reference: reservation.id },
  }),
});
if (!response.ok) throw new Error(`SMS enqueue failed: ${response.status}`);
const task = await response.json(); // accepted/queued 不等于 delivered
```

## 原生 DAL 模板 CRUD

实体：`ino_sms_template` 与 `ino_sms_template_translation`。路径 `/api/ino-sms-template`、`/api/search/ino-sms-template`、`/api/ino-sms-template/{id}`。使用 Shopware 原生语言 Header、translations 及 CRUD ACL；不另建管理框架。全局模板 scopeId 为 32 个零，范围模板覆盖同 code 的全局模板。

```json
{
  "id": "33333333333333333333333333333333",
  "scopeId": "11111111111111111111111111111111",
  "code": "order_ready",
  "purpose": "transactional",
  "active": true,
  "allowedVariables": ["businessName", "reference"],
  "translations": {
    "{LANGUAGE_UUID}": {"body": "{{ businessName }}: order {{ reference }} is ready."}
  }
}
```

预览：`{"body":"Hi {{ name|upper }}","allowedVariables":["name"],"variables":{"name":"Alex"}}`。只允许简单名称与 upper/lower 过滤；禁止对象属性、函数、循环、include、条件和任意表达式。语言顺序：请求 locale → en-CA → Shopware 系统语言。为了使用模板测试发送，通过同一 send API 和幂等/授权/额度检查，未提供绕过策略的测试发送。

## 状态与错误

queued → sending → accepted → sent → delivered/failed。其他状态：suppressed、provider_suppressed、rejected、rate_limited、unavailable_rejected、unknown。429 在次数用尽前回到 queued；provider 已接受不等于送达。未知结果绝不自动切换或重发。最后一次 unavailable_rejected 表示 provider 未启用/没有发送号码，且没有可用备用路径。

400：无效输入/模板；401：无 OAuth token；403：ACL；404：无此 scope 的记录；409：缺少授权/策略拒绝/幂等冲突；202：已入队。provider 网络结果不会让入队接口误报送达。

## Store API 预留

`/store-api/inocean/messaging/{send,template/send,status/{id},templates,quota,consent}` 已有路由，但默认拒绝所有请求；不作为本次交付的可调用客户端接口。只有将来的可信身份适配器和业务记录解析器同时接入后才可启用。当前不开发 POS Token、OTP 或客户前端。

## Provider 参考

- [Telnyx Webhook 签名官方实现](https://github.com/team-telnyx/telnyx-go/blob/main/lib/webhook.go)
- [Telnyx 短信错误码](https://support.telnyx.com/en/articles/6505121-telnyx-messaging-error-codes)
- [Plivo Messaging API](https://www.plivo.com/docs/messaging/api/overview)
- [Plivo V3 签名](https://www.plivo.com/docs/voice/concepts/signature-validation)、[官方 SDK 规范化实现](https://github.com/plivo/plivo-python/blob/master/plivo/utils/signature_v3.py)
- [Plivo Messaging 旧 V2 说明](https://www.plivo.com/docs/messaging/concepts/signature-validation)（本插件拒绝 V2）
- [Plivo 短信错误码](https://www.plivo.com/docs/messaging/troubleshooting/error-codes)

## 0.2.0 增量

新增后台 Marketing → Messaging 模板管理和受限会员变量。`send` 可提供 customerId 而省略 phone；`preview` 可在具有 customer:read 的情况下读取会员标量信息，另外需要 scopeId。`GET /api/_action/inocean/messaging/variables` 返回变量名单。预览权限改为 ino_sms_template:read。

会员发送、营销短信和自动退订链接、Customer 自定义订阅字段、公开 GET/POST 退订流程与 HTTPS 配置详见 [会员模板与退订指南](customer-templates.md)。公开退订页面只接受认证加密 token，GET 不修改订阅，POST confirm=yes 才取消营销订阅。

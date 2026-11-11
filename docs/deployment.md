# 安装、配置与运行

## 安装/升级

先在隔离环境备份与验证。要求 PHP >=8.4、sodium、mbstring、pdo_mysql，Shopware ~6.7，MariaDB 11.8 或项目现有 MySQL 8。插件无 SDK 依赖，复用项目 Symfony HttpClient、Twig、Messenger、DAL、System Config。公开退订与 Webhook 使用独立 core route scope，不依赖 Storefront 域名映射或前端登录。

以下命令供部署人员执行，本次开发未对业务库执行：

```sh
bin/console plugin:refresh
bin/console plugin:install --activate InoceanMessaging
bin/console database:migrate InoceanMessaging --all
bin/console inocean:messaging:seed-templates
bin/console cache:clear
```

本地插件可由 Shopware 插件加载器加载，不必修改根 composer.json。Composer 分发时安装 `inocean/messaging`。升级执行 `plugin:update InoceanMessaging` 并运行非破坏性迁移；禁止手工删除表。卸载保留审计和退订记录，重新安装不应抹除用户选择。

默认模板只为已安装的语言写入翻译，不创建 Shopware 核心语言。支持 en-CA、zh-CN、zh-TW、fr-CA；缺失时通过系统语言英语版本回退。先安装语言，再重新执行 seed，已有内容不会被覆盖。

## 环境变量

真实密钥仅来自服务器环境/受保护的 Symfony dotenv 文件/部署秘密管理器，不能写进插件、数据库或前端。

| 变量 | 内容 |
|---|---|
| INO_SMS_ENCRYPTION_KEY | base64 编码的随机 32 字节 Secretbox 密钥 |
| INO_SMS_HASH_KEY | 独立的至少 32 字符随机 HMAC 密钥 |
| INO_SMS_TELNYX_API_KEY | Telnyx API v2 Bearer key |
| INO_SMS_TELNYX_PUBLIC_KEY | Telnyx Webhook base64 Ed25519 public key |
| INO_SMS_PLIVO_AUTH_ID | Plivo 账户 ID |
| INO_SMS_PLIVO_AUTH_TOKEN | Plivo 账户 token，也用于 V3 验签 |
| INO_SMS_PLIVO_WEBHOOK_URL | 外部完整 HTTPS 回调 URL，不带额外 query，必须与 Plivo 配置一致 |
| INO_SMS_SENDER_SCOPES | JSON：注册发送号码到 Sales Channel 的映射 |

映射示例（用真实的 Sales Channel UUID 替换占位值）：

```json
{
  "telnyx:+16045550001": "11111111111111111111111111111111",
  "plivo:+16045550002": "11111111111111111111111111111111"
}
```

为了准确归属 inbound STOP，同一个 provider 的发送号码只能绑定一个 scope。号码无法跨 scope 共享，也不启用号码池。发送前检查映射，不匹配会拒绝。更改归属前必须迁移历史退订；插件不会自动跨商户迁移证据。两家供应商使用各自注册的号码。当前版本每个 provider 一个平台账户密钥，发送号码、启用与额度按 Sales Channel 继承 System Config。

加密密钥必须安全备份。丢失后无法解密；直接替换 HMAC key 会导致历史抑制无法匹配。密钥轮换需要专门的数据迁移，不能简单覆盖环境变量。日志与 API 不输出密钥、完整正文或原始 provider 错误。

## System Config

使用原生插件配置或 `/api/_action/system-config` 写入 `InoceanMessaging.config.*`；携带 Sales Channel ID。原生配置 API 需要自身的 `system_config:*` 权限。

| Key | 默认值 |
|---|---|
| telnyxEnabled / plivoEnabled | false |
| telnyxFrom / plivoFrom | 空；必填，各自注册号码 |
| telnyxProfile | 可选 |
| defaultProvider / backupProvider | telnyx / plivo |
| autoFailover | false |
| timeoutSeconds | 10，限制 1–30 秒；总请求最多 timeout + 5 秒 |
| maxAttempts | 3；仅明确未受理的 429 重试，最多 10 次 |
| monthlySegments | 1000 |
| perMinute | 30 |
| enqueuePerMinute | 120，每个销售渠道滚动 60 秒内新增任务上限，范围 1–10000 |
| maxPending | 1000，每个销售渠道 queued + sending 上限，范围 1–10000 |
| maxSegments | 6 |
| senderIdentity | 空；营销短信必须配置发送主体 |

营销短信自动增加主体及 `Reply STOP to unsubscribe.`，分段/配额计算包含这些内容。所有初始 provider 开关关闭。provider test 仅 GET 账户信息，不发送短信；不返回余额或响应正文。

## Provider Webhooks

Telnyx Messaging Profile 的 inbound/status URL：`https://YOUR_HOST/inocean/messaging/webhook/telnyx`。
验签使用原始 body、timestamp、Ed25519；允许时间偏差 5 分钟。签名无效返回 403，payload 超 64 KiB 返回 413。业务提交后才返回 200；未知关联返回错误，依赖供应商重试。

Plivo application message_url 与发送回调 URL：`https://YOUR_HOST/inocean/messaging/webhook/plivo`。只接受 `application/x-www-form-urlencoded` POST、V3 body-binding 签名及 nonce；不接受只验证 URL 的 V2。Plivo 不同产品的文档仍有 V2 示例，必须在自己的账户确认 Messaging 回调支持 V3 后启用该供应商。只发 V2 的账户会安全拒绝，不能降级验签。

V3 按官方 SDK 规范使用固定外部 URL、`?`、排序表单字段及 `.` + nonce 生成 HMAC-SHA256；当前 URL 不允许 query/fragment。反向代理无需决定验签 URL。事件 ID/消息 ID+状态去重记录不自动删除，以保持重放防护。不要在反向代理日志中记录 consent GET 的 phone 参数；优先通过受保护系统调用并对该路径禁记 query。

供应商需配置自身 STOP/START/HELP 自动回复。插件处理本地抑制和审计，不主动发送额外收费确认短信。同步 Telnyx 40300 与 Plivo 200 的 provider opt-out 错误。HELP 不改变同意；START 不创建营销同意。Telnyx 根据签名 payload 的事件时间防止旧 START 覆盖新 STOP；Plivo START 仅记审计，由可信管理端核对 provider 状态后解除。

## Messenger / FrankenPHP

HTTP 服务与 Worker 分开运行，环境变量和密钥保持一致。复用 Shopware `async`/`failed` transport；数据库 transport 已可使用，不要求 Redis/RabbitMQ。

```sh
bin/console messenger:consume async --time-limit=3600 --memory-limit=256M
```

用 systemd/Supervisor/Kubernetes/Docker restart policy 持续运行。每分钟执行：

```sh
bin/console inocean:messaging:recover
```

DB 任务先提交，随后投递 Messenger；投递失败仍保留 outbox。恢复命令每轮最多投递 500 个到期任务，重复投递安全。429 指数退避 60/120/... 秒，最多 3600 秒；短信重试由任务表控制，不依赖 Messenger 自动重发 HTTP。

并发在现有 sales_channel 行上短暂串行化额度预留，不在网络请求期间持有数据库事务。领取后变为 sending；Worker 崩溃超过 10 分钟时恢复为 unknown，永不自动重发。unknown 需要在 provider 侧核对；没有安全的“强制重发”API。

## 数据保留与排障

```sh
bin/console inocean:messaging:retention --days=90
```

默认为 dry-run。经运维批准后加 `--execute` 才清空终态记录的号码和正文密文；不删除同意证据、HMAC、抑制、费用或 Webhook 去重记录；排除 queued/sending/unknown。同意证据的保留和个人数据删除须依组织政策独立执行，不应顺带删除防止再次营销所需的最小抑制数据。

- 一直 queued：检查 Worker、transport 与 recover cron，确认配额与频率设置。
- suppressed/provider_suppressed：检查同意类型、scope、expiry、STOP 及供应商限制；不得靠切换供应商解除营销抑制。
- sender_scope_not_configured：检查注册号码和 sender scope 映射，格式包含 `+`。
- unknown：检查 provider MDR，勿直接再次发送；5xx/超时无法证明请求未受理。
- 费用为空：初始接受不是最终计费；等待包含金额的状态回调。Plivo 若回调不含币种，currency 保持 null，不猜测。
- Webhook 403：核对签名、时钟、公钥、外部 URL、V3 token、form content-type。
- 无模板翻译：安装语言、seed，或通过 DAL 写入 translations。


## 0.2.2 安全更新部署

先执行插件更新与数据库迁移，再恢复 HTTP/Worker 服务；部署期间暂停相关写入并重启长驻 Worker。新增迁移只增加 consent.customer_id 及索引，不删除历史记录。此次源码修复未对业务库执行更新或迁移。

入队超限返回 HTTP 429 和 Retry-After: 60，不创建任务、不投递队列；相同请求的幂等重放仍可返回原任务。限制按 Sales Channel 汇总，包括所有调用身份；不同渠道不共享额度。没有引入 Redis、额外定时任务或全表计数。此保护限制成功入队数量，不能代替网关层对恶意 HTTP 请求的限流。

客户订阅变更在 DAL 写入事务内同步授权。新生成的客户同意记录保存 customer_id，退订能找回历史号码哈希，当前号码无效也不会丢失已有授权身份。已有客户的原号码可从当前/本次修改前账单号码及历史客户短信中恢复；旧版未关联客户的同意记录不能凭空恢复。如果所有可用来源都缺失，保存返回 400 并回滚，需管理员按原授权证据通过 consent API 核对退订，不能认为界面已成功退订。此次迁移不会自动纠正升级前已经产生的字段/授权不一致。

## 0.3.0 Flow Builder 资源

升级时使用已编译的插件后台资源，部署后重新载入后台。插件本次无新增迁移。

```sh
bin/console plugin:refresh
bin/console plugin:update InoceanMessaging
bin/console assets:install
bin/console cache:clear
```

按现有方式重启长驻 Worker。验收路径和短信模板要求见 [Flow Builder 使用说明](../README.md#030-flow-builder-短信动作)。

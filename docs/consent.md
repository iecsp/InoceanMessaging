# 同意与退订

号码必须为完整 E.164，不依赖 Customer Entity。可信后端负责采集授权证据；仅凭已下单/预约不会自动生成同意。

1. 交易通知：调用 consent API 记录 `transactional_authorization`，必须包含 source/evidence，可以设置 expiresAt。
2. 营销：只有最新、未过期的 `marketing_express` 能授权；`marketing_implied`、`unknown` 只留审计，默认不能营销。必须配置 senderIdentity。
3. 入队和每次尝试均检查授权、全局抑制、scope 抑制及 provider 抑制。营销配额计算包含身份和退订提示。
4. STOP/UNSUBSCRIBE/CANCEL/END/QUIT/UNSUB/STOP ALL：记录营销 opt-out，写入 scope 营销抑制（跨供应商）和当前 provider 全用途限制。provider opt-out 拒绝/回调也同步这两类抑制。
5. START/UNSTOP：Telnyx 仅在事件时间不早于当前抑制状态时移除当前 provider 限制；Plivo 没有可用的签名发生时间，START 仅记审计，需可信后端核对供应商状态后解除 provider 限制；营销抑制和原有 opt-out 仍保留。HELP 只记录审计。回复由 provider 配置管理。
6. 人工退订：manage API 可写营销退订，或 scope/global 全用途 suppression；操作记录带 source/evidence。取消营销不影响另有授权、且未受全用途/provider 限制的交易通知。
7. 再同意：可信管理 API 写新的 `marketing_express` 和新证据，可显式使用 `clearMarketingOptOut: true` 移除 scope 的营销抑制；不会清除全局或 provider 限制。START 不代替此流程。

全局 suppression 的 scope 使用内部零 UUID。普通发送 scope 必须为真实 Sales Channel。全局抑制不会被本地 reconsent 覆盖。

同意证据追加保存且加密；suppression 是当前状态，变化来源另见 consent/attempt/webhook 审计。原始 inbound 正文和手机号不写 Webhook 日志。HMAC 匹配 key 与加密 key 分离。营销/交易授权不能由客户端自由声明；Admin API 凭据仅给有权证明授权的系统。

本版本不提供公开客户订阅页面、OTP 或 POS 身份系统；Store API 不启用。这些可由已有业务系统接入，不能用 access key 代替验证。

技术实现不能替代 CASL/TCPA 或 A2P 10DLC、号码注册、运营商政策审核。生产启用前由业务负责人核实发送场景、证据、模板、安静时段和地区规则；当前插件不包含自动法律判断或通用 quiet-hours 调度。

可信解除全用途或 provider 抑制：suppression API 提供 `active:false`、`purpose:"all"`、相应 provider 和核对 evidence。操作记录认证管理员/集成 ID。不得用此途径清除营销抑制；营销必须走 express reconsent。

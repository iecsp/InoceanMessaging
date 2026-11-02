# 技术调查与架构决定

调查基线：2026-10-31，Shopware 6.7.15.1；宿主 PHP 8.5，部署目标 PHP 8.4 FrankenPHP；现有 compose 是 MySQL 8.0（不是 MariaDB）。不得对当前业务数据库执行迁移验证。仓库无 .git，无 AGENTS.md，无 PHPUnit/PHPStan 开发依赖。

## 已调查原生能力
- `Content/MailTemplate/*`：mail_template 必须关联 mail_template_type，翻译要求 subject/content_html/content_plain，包含附件关系。SMS 没有这些邮件语义，因此不扩展 mail_template，不修改核心表。
- `Framework/Adapter/Twig/StringTemplateRenderer`：克隆平台扩展，并添加 Context 全局变量，不适合不可信 SMS 模板。复用已安装 Twig，建立最小 SandboxExtension；不注入 Shopware 全局变量、对象或函数。只接受允许的标量变量。
- DAL EntityDefinition / EntityTranslationDefinition / repositories：复用实体注册、UUID、语言 FK、Admin CRUD。独立 ino_sms_template 与 translation；业务状态通过 DBAL 事务和行锁维护，防止通用 CRUD 绕过发送及退订策略。
- FlowAction/StorableFlow：可通过原生 FlowAction 扩展；不可直接使用 Flow 配置任意号码，必须接入业务记录解析器。
- SystemConfigService：复用 Sales Channel 级配置继承；供应商密钥仅由环境变量读取。
- Framework/Resources/config/packages/framework.yaml：Shopware 已将 AsyncMessageInterface 路由到 async，并有 failed transport；无需新基础设施。任务表作为 outbox，恢复命令重新投递尚未领取的任务。
- OAuth2 + `_acl`：管理操作由原生 API 身份认证与 ACL 保护。敏感业务实体不暴露 ApiAware 字段写入；管理通过受控 action。
- 现有 InoceanServiceSubscription：PSR-4 Inocean 命名空间、XML DI、attribute routes、ino_ 表前缀。

## 集成缺口与安全边界
当前仓库只有 InoceanLanguagePack 和 InoceanServiceSubscription，没有 POS Operator Token、门店、租户、预约、排队实体，也无 ERP/POS 菜单。不得用 Shopware access key 代替员工授权，不创建第二套租户模型。StoreAccessInterface / BusinessReferenceResolverInterface 必须由真实业务插件实现；默认拒绝。Sales Channel 只作为现有可验证的配置/数据范围，不宣称其等价于租户或门店。跨租户生产验收须待真实模型接入。

## 最小实现
持久化任务 → outbox/Messenger → 领取任务并检查授权、抑制、额度 → 独立 attempt → provider → 状态更新。发送中 Worker 崩溃不得自动重新发送：进入 unknown，人工/回调核对。只对明确未受理的 429 重试；5xx、传输超时、无 message ID 的成功响应都视为 unknown。明确未受理的服务拒绝才允许备用；无效号码和合规拒绝不得切换。每次重试重新执行同意及抑制检查。

数据库：ino_sms_message、ino_sms_attempt、ino_sms_consent（追加审计）、ino_sms_suppression、ino_sms_template、ino_sms_template_translation、ino_sms_webhook_event。usage 由消息/attempt 聚合，不重复建统计表。号码加密，匹配使用独立 keyed HMAC；正文加密；独立密钥由部署提供。状态及审计不保存原始 Webhook 和供应商错误正文。

营销仅接受未过期 express consent；implied 仅记证据，默认不授权。STOP 写商户营销抑制及 provider 全用途抑制；START 对 Telnyx 按事件时间解除来源 provider 的限制；Plivo 仅记录并要求可信核对，不清除营销退订或创建同意。HELP 记录，不自动发送收费短信；确认回复由供应商管理。人工解除须有新的可信证据。

## 0.2.0 增量

新增原生 Administration 模板 CRUD、多语言和会员预览；Customer 订阅使用原生 CustomFieldSet DAL 生命周期注册，会员信息只投影允许的标量变量。消息表增加 nullable customer_id；使用 Customer/Profile 校验守住入队与 Worker 边界。公开退订凭证由现有 Secretbox 认证加密，GET 只展示确认，POST 写营销抑制和客户偏好，绝不据此创建同意。

公开退订及 provider Webhook 使用独立 PublicMessagingRouteScope，并注册精确的 inocean/messaging 前缀，避免无 Storefront 域名的 Headless 后台被域名映射拦截。自定义 scope 无构造依赖，明确关闭 autowire/autoconfigure：Shopware RouteScopeCompilerPass 会在构建早期实例化 scope，不能让其成为尚未解析的 ChildDefinition。该行为按本地 6.7.15.1 源码与实际容器验证，不代表完整 6.8 运行验证。

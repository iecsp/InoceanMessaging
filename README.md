# InoceanMessaging

Shopware 6.7 / PHP 8.4+ 短信后端插件，已按 6.8 升级草案预适配（6.8 要求 PHP 8.5+）。提供短信 API、异步 Worker、后台模板管理，以及 Shopware 原生 Flow Builder 的短信动作。

- Telnyx 主供应商、Plivo 备用，独立注册发送号码。
- OAuth2 / ACL 管理 API；DAL 模板及原生语言翻译。
- 加密持久化任务、Messenger、outbox 恢复、数据库并发领取、幂等。
- 同意证据、营销退订、全局/范围/provider 抑制、签名 Webhook 去重与状态更新。
- GSM-7 / Unicode 分段、Sales Channel 配置与配额、费用聚合。

新增功能：[后台模板、会员变量与退订链接](docs/customer-templates.md)。

文档：[架构调查](docs/architecture.md)、[安装与运维](docs/deployment.md)、[接口与调用示例](docs/api.md)、[同意与退订](docs/consent.md)、[OpenAPI](docs/openapi.json)、[验证记录](docs/validation.md)。

`scopeId` 是已存在的 Shopware Sales Channel UUID，不代表新建的门店或租户。管理 API 面向平台授权的可信后端；拥有相关 ACL 的集成可访问所有 Sales Channel。不得将此凭据交给浏览器/POS，也不宣称实现租户级管理员隔离。

Store API 路由暂为扩展预留，默认全部拒绝；公开 `sw-access-key` 不能发送短信。将来需通过接口接入实际业务身份与业务记录解析，不能直接开放任意号码发送。

## 快速验证（无真实短信）

```sh
php custom/plugins/InoceanMessaging/tests/run.php
```

只执行独立逻辑测试。数据库、并发及内核验证方式见验证文档。所有自动化测试使用 Symfony MockHttpClient。

## 0.1.1 兼容性更新（2026-11-01）

- services/routes 改用 Symfony PHP 配置；保留 Shopware 专用 `config.xml`。
- Composer 支持 `~6.7.0 || ~6.8.0`，HTTP Client 支持 Symfony 7/8。
- 服务 ID、别名、DAL 标签、控制器公开性及无自动重试的短信 HTTP Client 工厂保持一致。
- 实测结果及 6.8 正式版复测范围见 [验证记录](docs/validation.md)。

## 0.2.1 界面语言

模板管理、会员变量标签、Customer 订阅字段、供应商设置和公开退订页面支持英文、简体中文、繁体中文，各自显示对应语言，不再中英并列。语言文件位置、回退和退订页语言选择见 [会员与模板说明](docs/customer-templates.md)。模板正文仍由用户分别维护，不会随管理员界面语言自动改写。


## 0.2.2 安全修复（2026-11-02）

- 客户订阅字段与短信授权在同一 DAL 事务中提交；保留历史授权号码的客户关联。
- 每渠道新增入队速率与积压容量保护；超限返回 429，幂等重放不占额度。
- 新增非破坏性数据库迁移，部署步骤及旧记录处理边界见 [安装与运维](docs/deployment.md)。
- 新增 `tests/security.php`，使用独立数据库、真实 DAL 事务故障注入和模拟供应商。


## 0.3.0 Flow Builder 短信动作

进入 **Automation → Flow Builder → Flow → Action (THEN)**，为携带客户的触发器（例如 **Customer / Password / Changed**）添加 **Send Message / 发送短信 / 傳送簡訊**，选择一份启用的短信模板并保存。配置人员需要 Flow 编辑权限和短信模板读取权限。

动作通过触发事件定位客户与销售渠道，读取客户默认账单地址的 E.164 手机号。模板绑定到选中的记录，须为全局模板或属于当前渠道。正文沿用客户语言和现有模板回退规则。Flow 提供[会员变量和退订链接](docs/customer-templates.md)，请选用只依赖这些变量的模板。

动作成功时将短信加入现有队列，由 Worker 发送。交易短信需要独立的交易授权，营销短信需要明确同意和客户订阅，发送仍受退订、抑制和配额限制。模板不可用、客户与渠道不匹配或入队被拒绝时，Shopware 会将该动作记录为 Flow 执行失败。每次独立触发产生一次发送请求，队列重投继续使用现有消息领取机制。

升级需要刷新插件、更新版本和缓存，并发布插件后台资源、重启长驻 Worker。此版本没有新增数据库迁移。扩展方式见 [Shopware 自定义 Flow 动作指南](https://developer.shopware.com/docs/guides/plugins/plugins/framework/flow/add-flow-builder-action.html)。

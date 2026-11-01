# InoceanMessaging

Shopware 6.7 / PHP 8.4+ 短信后端插件，已按 6.8 升级草案预适配（6.8 要求 PHP 8.5+）。按本次确认范围，仅实现后端 API 与 Worker；不包含 POS、门店/租户系统、Administration 页面或 Flow 配置页面。当前项目中已安装启用；本次兼容性验证未发送真实短信。

- Telnyx 主供应商、Plivo 备用，独立注册发送号码。
- OAuth2 / ACL 管理 API；DAL 模板及原生语言翻译。
- 加密持久化任务、Messenger、outbox 恢复、数据库并发领取、幂等。
- 同意证据、营销退订、全局/范围/provider 抑制、签名 Webhook 去重与状态更新。
- GSM-7 / Unicode 分段、Sales Channel 配置与配额、费用聚合。

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

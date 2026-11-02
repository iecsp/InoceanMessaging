# 0.2.0：后台模板、会员变量与手机退订

后台入口：**Marketing → Messaging**。复用 Shopware 原生组件和 DAL Admin API，支持列表搜索、分页、新建、编辑、删除确认、启用开关、交易/营销分类、全局/销售渠道范围及多语言正文。预览不会发送短信。

语言列表来自已安装 Shopware 语言。保存前填写系统默认语言正文；原有 en-CA / zh-CN / zh-TW / fr-CA 模板继续有效。留空的翻译使用发送服务原有语言回退。删除独立语言翻译不在本版界面范围，删除模板会级联删除其翻译。

## 会员变量

选择会员时同时指定其注册 Sales Channel。服务器读取会员资料，不接受前端覆盖这些保留变量；只有标量值进入 Twig，不能访问完整 Customer 对象、方法或服务。

| 变量 | 来源 |
|---|---|
| customerFirstName | 名 |
| customerLastName | 姓 |
| customerFullName | 完整姓名 |
| customerNumber | Shopware 客户编号 |
| customerCompany | 公司名称 |
| customerLanguage | 客户语言 locale |
| unsubscribeUrl | 发送时生成的真实退订链接 |

在正文输入变量，或点变量按钮插入；按钮会把变量加入 allowedVariables。业务系统仍可提供 businessName、reference 等显式白名单变量。不会开放邮箱、生日、密码、支付信息或任意 customFields。

示例：

```twig
Hi {{ customerFirstName }}, your order {{ reference }} at {{ businessName }} is ready.
Unsubscribe: {{ unsubscribeUrl }}
```

可信后端模板发送：

```json
{
  "scopeId": "{SALES_CHANNEL_UUID}",
  "customerId": "{CUSTOMER_UUID}",
  "templateCode": "order_ready",
  "variables": { "businessName": "Inocean", "reference": "123" }
}
```

发送仍使用 `POST /api/_action/inocean/messaging/send`，携带 OAuth Bearer token 及 Idempotency-Key。customerId 模式还需 customer:read；手机号从该会员默认账单地址读取，必须为 E.164。可省略 phone；如果传入，必须与服务器读取值一致。缺少明确授权、营销偏好未勾选、被退订或手机号在排队后变化时均禁止发送。预览可以在手机号尚未规范化时使用会员姓名，但实际发送不允许。

## Customer 订阅选项

Customer 详情的 **Custom fields / 自定义字段 → Messaging / 短信订阅** 下新增：**是否订阅手机简讯**，机器名 `ino_sms_subscribed`。安装/升级通过原生 custom_field_set.repository 建立字段与 Customer 关联，没有修改 customer 表结构，也不批量给现有客户勾选。

- 勾选表示管理员已确认客人的**明确营销短信同意**，而不是仅完成预约或下单。
- 后台需具备普通 Customer 编辑权限及 `inocean_messaging:manage`；页面角色配置的附加权限中可选“管理短信订阅与同意记录”。普通 customer:update 不足以授予营销同意。
- 勾选会按会员手机号记录 express consent 和实际管理员/集成身份，并解除该 scope 的营销抑制；不能清除全局或 provider 抑制。
- 取消勾选会记营销 opt-out 并建立跨 provider 营销抑制。
- 公开客户端不能通过自定义字段直接同意营销，`allowCustomerWrite=false`；非授权导入也不能自动赋予同意。
- 会员发送任务持久化 customer_id；Worker 发送、重试或切换前重新检查偏好和手机号。
- 链接退订、STOP 和 provider opt-out 会尽可能同步相同 scope/当前账单手机号的 Customer 偏好；短信抑制表始终是最终发送依据。

只取消营销订阅，不会无条件阻止已获得独立授权的必要交易通知。现有客户没有勾选时，会员营销模式默认拒绝。加密/HMAC 密钥须按已有部署文档配置，未配置时不会自动创建有效同意。

## 退订链接配置

在插件配置中填写 **Public HTTPS Shopware base URL for SMS unsubscribe links**：

```text
InoceanMessaging.config.unsubscribeBaseUrl = https://YOUR_PUBLIC_SHOPWARE_HOST
```

必须是客人手机能够访问、且路由到当前 Shopware 的 HTTPS 地址。localhost:81 和仅 Nuxt 前端的地址不能直接使用；如果使用前端域名，需要将 `/inocean/messaging/unsubscribe/*` 转发至 Shopware。不得使用带 query/fragment 或账户信息的 URL。

所有营销短信及 customerId 会员短信都会要求此配置并自动包含退订链接。其他原文交易短信在配置了该地址后也追加链接。模板可用 unsubscribeUrl 决定位置；否则自动追加。营销短信仍包含发送方身份和 STOP 指引。实际链接包含在分段和额度计算内；预览只显示 example.invalid 地址，因此其分段为编辑参考。

链接携带 Secretbox 认证加密凭证，不暴露明文手机号，有效期 366 天，只授予取消该 scope 营销短信的能力。无需注册账号、登录或短信验证码。GET 打开确认页而不改状态；POST 确认后同步抑制、同意审计及 Customer 选项。重复确认不会重复创建同意记录。

页面使用 no-store、no-referrer、CSP、禁止嵌入和 noindex。运维仍应对退订路径的 token 做日志脱敏。轮换现有加密密钥会使旧链接失效，必须按密钥迁移流程处理；不要简单替换服务器密钥。

## 验证与升级

新增 `Migration1793491200CustomerMessaging`，仅给已有 ino_sms_message 增加 nullable customer_id 和索引；所有表继续为 ino_ 前缀。plugin:update 会幂等安装 Customer 原生自定义字段。

`tests/members.php` 在专用临时数据库中运行原有回归，再验证会员 ACL、原生 JSON 更新命令、变量防覆盖、退订 GET/POST、Customer 偏好同步、排队后退订、链接篡改/过期及原生 DAL 模板 CRUD。Customer 更新仓库在逻辑测试中使用测试适配器；模板 CRUD 使用真实 DAL 写入/读取，隔离与模板无关的 app-script 订阅者。

当前验证基线为 Shopware 6.7.15.1 / PHP 8.4，不宣称已在正式 6.8 运行。后台资源用当前 Shopware Vite 构建链构建。所有供应商测试使用 MockHttpClient，不发送真实短信。

## 当前环境配置与公网接入

本次已配置 `unsubscribeBaseUrl=https://www.demo.iecsp.com`。当前该域名的退订路径返回 Nuxt 的 `Category not found`（404），尚未转发到本项目 Shopware；本地 `http://localhost:81/inocean/messaging/unsubscribe/invalid` 正确返回插件的 400 无效凭证页面。

需要在现有反向代理或前端服务器为此路径建立透传。以下是代理与 Shopware 同主机时的 Nginx 示例；不得把 localhost 地址直接放进短信：

```nginx
location ^~ /inocean/messaging/unsubscribe/ {
    proxy_pass http://127.0.0.1:81;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto https;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
}
```

GET 和 POST 都需要透传，保留完整 token 路径及表单 body；禁止缓存、重写为 Nuxt 页面或把 POST 改为 GET。不同部署环境需改为实际 Shopware upstream（Docker 内通常使用服务名及内部端口）。公网 HTTPS 应在现有网关终止，插件本身不依赖某个云厂商。若选择独立的已有 Shopware HTTPS 域名，也可将 unsubscribeBaseUrl 改为那个地址。

可选的 Nuxt 透传实现见 [代理示例](integration/nuxt-unsubscribe-proxy.ts)。这是尚未应用到 POS 项目的接入示例，运行时 upstream 仅保存在服务端配置；不转发浏览器 Cookie、OAuth 凭据或额外客户资料。

用户已确认本次暂不修改 POS 前端；代理示例保留待后续接入。在代理未上线前，指定的公网 www.demo.iecsp.com 退订链接尚不可用，不能据此宣称公网流程已完成。插件内本地 GET/POST 流程和真实应用密钥的 GET 确认页已验证。

当前后台使用 `http://127.0.0.1:81/admin` 与另一套 `http://localhost/admin` 隔离 Cookie。不要同时用 localhost 的不同端口登录两套 Shopware，原生同名 bearerAuth Cookie 会互相覆盖。

## 界面语言（0.2.1）

后台界面跟随管理员的界面语言：英文 `en-GB`、简体中文 `zh-CN`、繁体中文 `zh-TW`。`en-US` 共用英文包，Shopware 常用的 `zh-HK` 共用繁体中文包。菜单、列表、编辑表单、会员变量按钮、校验提示、预览和删除确认均从 `src/Resources/app/administration/src/snippet/` 对应 JSON 读取，不再把中英文并排写入组件。

Customer 订阅选项与公开退订页面使用 `src/Resources/translations/inocean_messaging.{locale}.json`，由 Symfony 原生翻译组件加载；更新插件时同步原生 Customer 自定义字段标签，不改变客户订阅值。供应商设置使用 Shopware `config.xml` 原生多语言 label/title。

退订页面可使用 `?lang=en-GB`、`?lang=zh-CN`、`?lang=zh-TW` 明确选择语言，否则按照浏览器 `Accept-Language` 选择；不支持的语言回退英文。繁体中文接受 `zh-HK`、`zh-Hant` 等别名。确认、成功和无效链接页面都只显示选中的一种语言。

界面语言不会自动翻译保存的模板正文。正文语言仍由模板的翻译语言选择器管理；Twig 变量名、模板代码、JSON 键和编码标识保持稳定，以便 API 调用。

本次不接通公网退订代理，保留现有 Nuxt 代理示例；公网地址需自行部署代理后才能访问本插件页面。

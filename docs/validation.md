# 验证记录与复现

以下“已执行”主要为 0.1.0 开发时记录。2026-11-01 检查确认插件当前已安装启用；本次 0.1.1 兼容性验证未执行 plugin:update、真实 provider 连接探测或真实短信发送。

验证环境：Shopware 6.7.15.1、PHP 8.4.26 FrankenPHP 容器；独立临时 MySQL 8.0 与 MariaDB 11.8 容器，无宿主数据卷。宿主 PHP 8.5 也通过独立逻辑与 XML/OpenAPI 检查。

## 已执行

- PHP 8.4 全部 PHP 文件语法检查。
- `tests/run.php`：MySQL 8.0 与 MariaDB 11.8 各 **114 项**检查。
- `tests/kernel.php`：独立数据库启动真实 Shopware Kernel，验证 DI 编译、控制器实例化、全部 7 个 DAL 实体、4 个代表性 API/Webhook 路由、Symfony `lint:container`；真实 HTTP 请求无 OAuth token 返回 401。
- `tests/validate.php`：Shopware config.xml 的 XSD 与 PHP 服务配置加载验证，swagger-php OpenAPI 验证，插件 Composer/class metadata 检查。

业务检查包括两家发送的模拟 HTTP 请求、错误分类、provider opt-out、允许/禁止故障切换、幂等冲突、四进程并发入队及领取、队列故障保留 outbox、Worker 中断隔离、429 退避、真实 Doctrine Messenger 序列化/handler/ACK、中文/emoji/GSM 转义边界分段、安全 Twig、四语言默认模板、原生 Shopware ACL 校验、跨 Sales Channel 状态访问拒绝、营销证据、STOP/START/HELP、provider 抑制复查、签名/正文篡改、Webhook 去重/乱序、费用/配额。

签名样例的 Plivo V3 canonicalization 与官方 SDK 规则核对，包含 POST 字段前 `?` 和 nonce 前 `.`；不能只用文档中的简写公式。Webhook 的 Plivo V2 一律拒绝。

## 复现

仅逻辑测试，不连接数据库：

```sh
php custom/plugins/InoceanMessaging/tests/run.php
php custom/plugins/InoceanMessaging/tests/validate.php
```

项目 Docker network 当前为 `maginutcom_default`，PHP 容器为 `maginut-app`。为测试创建独立数据库：

```sh
docker run -d --name ino-messaging-test-db --network maginutcom_default --tmpfs /var/lib/mysql:rw -e MYSQL_ROOT_PASSWORD=ino_messaging_test_only -e MYSQL_DATABASE=ino_messaging_test mysql:8.0
docker run -d --name ino-messaging-test-mariadb --network maginutcom_default --tmpfs /var/lib/mysql:rw -e MARIADB_ROOT_PASSWORD=ino_messaging_test_only -e MARIADB_DATABASE=ino_messaging_test mariadb:11.8
```

数据库就绪后执行：

```sh
docker exec -e INO_SMS_TEST_DATABASE_URL=mysql://root:ino_messaging_test_only@ino-messaging-test-db/ino_messaging_test maginut-app php custom/plugins/InoceanMessaging/tests/run.php
docker exec -e INO_SMS_TEST_DATABASE_URL=mysql://root:ino_messaging_test_only@ino-messaging-test-mariadb/ino_messaging_test maginut-app php custom/plugins/InoceanMessaging/tests/run.php
docker exec -e INO_SMS_TEST_DATABASE_URL=mysql://root:ino_messaging_test_only@ino-messaging-test-mariadb/ino_messaging_test maginut-app php custom/plugins/InoceanMessaging/tests/kernel.php
```

测试只接受名为 `ino_messaging_test` 的数据库，并会重建其中插件测试表；不要把测试 URL 指向业务环境。所有 provider HTTP 都是 MockHttpClient；无真实短信。

完成后移除本次创建的临时容器：

```sh
docker rm -f ino-messaging-test-db ino-messaging-test-mariadb
```

## 明确未执行/不在本次范围

- 真实短信及生产 provider 凭据联调；Plivo 账户的 V3 Messaging 回调可用性需要部署时确认。
- 正式业务库 plugin:install、迁移及升级演练；验证的是隔离库迁移与真实内核加载。
- 完整 OAuth token 签发后的 HTTP 集成链：当前验证了真实 401 与原生 ACL 允许/拒绝，以及发送服务链路，未伪造宣称完整 OAuth E2E。
- 项目没有已配置 PHPStan/Psalm/PHPUnit；未引入新依赖，使用可直接运行的检查脚本。
- Administration 构建、POS、真实门店/租户隔离、公开客户 OTP/订阅界面和 Flow Builder 配置不在已确认后端范围；Store API 默认拒绝。

## 0.1.1 兼容性复测（2026-11-01）

两插件配置改动前后用 Symfony loader 核对，服务定义、参数、工厂、标签、别名和公开性一致。宿主 PHP 8.5.12 通过 51 项独立检查及配置/OpenAPI 校验；PHP 8.4.26 / Shopware 6.7.15.1 / 独立 MySQL 8.4.13 通过 114 项完整检查和内核检查。启用 `FEATURE_ALL=v6.8.0.0` 后，独立缓存下的内核检查也通过。全部 PHP 文件在 PHP 8.4 / 8.5 上语法检查通过。

这属于 6.8 草案预适配，尚未在正式 Shopware 6.8 / Symfony 8 / Twig 4 上执行；正式发布后仍需在该栈完成安装、更新和 API/Worker 复测。6.8 需要 PHP 8.5+，以及 MySQL 8.4+ 或 MariaDB 11.4+。已启用插件的业务库版本元数据本次未刷新。

## 0.2.0 会员与后台模板增量验证（2026-11-01）

- 两个独立临时数据库（MariaDB 11.8、MySQL 8.0）分别通过 `tests/members.php`：**146 项**回归/会员检查，另含真实 Kernel 容器、路由和 ACL 检查。
- 模板 CRUD 使用真实 Shopware DAL（隔离与模板无关的 app-script post-write 订阅者），包含翻译创建、读取、更新和级联删除。
- 已验证原生 customer custom_fields JSON 更新命令的 1/0 编码、授权 opt-in、变量防覆盖、跨 Sales Channel 拦截、链接篡改/过期、GET 不退订、POST 同步 Customer 偏好、重复确认与排队后退订。
- Customer 偏好投影仓库在逻辑测试中为测试适配器；业务库只验证安装的原生字段为 bool、关联 customer、allowCustomerWrite=0，没有为真实客户改变偏好。
- 后台插件资源通过当前 Shopware Vite 生产构建，PHP DI/config/OpenAPI 检查通过。
- 已在当前环境更新到 0.2.0、应用非破坏性迁移并安装 Customer 自定义字段；未发送真实短信。
- 公网域名退订路径当前仍是 Nuxt 404，需接入反向代理；本地路由返回预期的签名错误页面。应用加密/HMAC 密钥已按用户授权初始化到受保护的 .env.local，未输出密钥值。实际 HTTP GET 验证有效凭证显示确认页、无效凭证显示插件错误页。

后台实操：在隔离 Cookie 的 `http://127.0.0.1:81/admin` 完成模板创建、重新读取、多语言编辑与会员预览；保留停用的 member_welcome 草稿（英文/简体/繁体）。Customer → Custom fields → Messaging 的订阅字段已在只读和编辑模式核对，未改变实际客户偏好。两个 localhost 端口实例的原生 bearerAuth Cookie 会冲突，故使用 127.0.0.1 隔离。公开退订/Webhook 使用独立 route scope，不依赖 Storefront 域名或 Sales Channel Context，签名校验保持不变。

## 0.2.1 三语界面验证（2026-11-02）

- `tests/localization.php`：三套后台语言包各 66 个键，三套服务端语言包各 10 个键；校验键名、插值占位符、Symfony 实际加载结果、静态 UI 引用与英文包无中文文字。
- MariaDB 11.8 临时独立数据库执行 `tests/members.php`，**170 项**回归与会员检查通过，另含真实 Kernel/路由/ACL 检查。新增公开退订页三语、Accept-Language、显式参数优先级、繁体别名、英文回退、无效链接及成功页面检查。
- PHP 8.4 语法、Shopware DI 容器、配置 XML XSD、OpenAPI 校验及本插件 Vite 生产构建通过；未引入新的构建依赖。
- 已部署 0.2.1，原生 Customer 字段的三语标签核对正确，未更改真实客户偏好。后台英文模板列表与编辑页实操核对，原有模板正文保留。
- 未发送真实短信，未修改 POS 前端或公网代理。此前记录的其他数据库/版本测试仍是对应历史版本的结果。

实际本地 HTTP GET 复核：三种语言的有效短时合成凭证均显示各自确认按钮（200），无效凭证均显示各自错误页（400），Content-Language 匹配请求。仅 GET、未改变真实客户订阅。旧版缓存容器已清理，后台会话保留且页面可正常访问。

## 0.3.0 Flow Builder（2026-11-11）

在 Shopware 6.7.16.0、PHP 8.4.26 和独立 MySQL 8.0 数据库上运行 `tests/security.php`，214 项累计检查通过。新增用例使用原生 `StorableFlow` 检查密码变更动作，验证按客户语言渲染并入队，以及拒绝缺少授权、跨销售渠道或停用模板的请求。Symfony 容器检查与 `tests/validate.php` 也通过。

用 Shopware 的 Vite 6.4.3 构建脚本生成插件资源。通过编译产物检查英文、简体中文和繁体中文的动作名称、弹窗模板、编辑回填和保存数据。未在已登录的后台执行浏览器验收，未升级业务实例或发送真实短信。

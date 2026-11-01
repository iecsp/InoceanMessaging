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

# 驭舟独立后端

后端使用锁定的 ThinkPHP 5.1.41 LTS，业务代码兼容 PHP 7.2，API、后台和两个 App 分离。正式数据库为 MySQL 5.7/InnoDB；SQLite 仅用于 development/test 的持久化联调和测试。数据库、配置、上传、缓存和账号独立于现有创意岛。

运行依赖：PHP 7.2 的 PDO/PDO MySQL、BCMath、mbstring、fileinfo、JSON、OpenSSL、zip 扩展；本地测试另外需要 PDO SQLite。框架已经固定保存在 `thinkphp/`，不需要在线安装包；不要对现有 PHP 或框架执行升级。

`backend/.env.example` 是空秘密配置模板。`APP_URL`、`CORS_ORIGINS` 和 `VR_BASE_URL` 由部署配置提供。VR 外链使用既有 3D 平台；每条船的 `vr_url` 必须另行映射真实模型。运行库和私有上传应放在站点文档根目录之外。Web 文档根目录只能是 `backend/public`。

数据库由专用迁移账号执行 `database/mysql.sql`、`database/communication-mysql.sql` 和按编号排序的 `database/migrations/*-mysql.sql`。运行账号仅有 SELECT/INSERT/UPDATE/DELETE，不能授予 DDL。已初始化数据库使用 `php backend/bin/init.php --seed-only`，无需运行账号执行迁移。测试种子只在 development/test 生效，要求外部注入 `SEED_PASSWORD`，只建立标明为测试的合成资料，不输出密码。已有用户时不会重建种子。

本地空 SQLite 可执行 `php backend/bin/init.php --seed`，自动建主表、通信表及编号迁移；环境设置 `APP_ENV=test`、`DB_DRIVER=sqlite`、`DB_SQLITE_PATH` 和 `SEED_PASSWORD`。本地服务执行 `php -S 127.0.0.1:8088 -t backend/public backend/public/router.php`。

真实支付、退款、打款、短信、推送、保险及外部签名未配置时明确拒绝。测试支付还要求 `ALLOW_TEST_PAYMENTS=1`，响应与公开 bootstrap 标识测试环境。更换成 production 后连旧测试付款幂等键也拒绝。

规则、权限与 API 见 [API.md](API.md)。主交易领域使用小表和真实外键；商家的18位社会信用代码是业务主键，机构ID仅承载权限。日租实例以 `(boat_id,rental_date)` 唯一约束防重订。账本使用事务、订单与受益人行锁和幂等键；部分退款后冻结，剩余利润由平台重新确认，沿用原始比例和受益人快照记累计差额。

`php backend/bin/jobs.php` 释放超时预约、结算到期未冻结账本和生成到期站内提醒，不执行外部付款或伪造推送。

验证入口：

```sh
php tests/backend/run.php
php tests/backend/communication.php
php tests/backend/operations.php
php tests/backend/crm.php
php tests/backend/events.php
php tests/backend/reports.php
python3 tests/backend/http-smoke.py
```

核心检查覆盖金额、手工利润、退款幂等、角色/组织隔离、日期库存、合同版本、商城原子下单、课程奖励、邀请码及 SQLite 双进程提现。HTTP 检查会自行建立临时测试库和服务器，检查真实 multipart 上传及私有文件下载权限。它们不证明已接入真实支付，也不替代浏览器、签名 App 或真机验收；MySQL 运行证据由部署记录另行保存。

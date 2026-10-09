# 驭舟

两个独立 UniApp Vue 3 App：用户端、经纪人销售端。一个 Vue 3 网页工程提供商家、经纪公司管理、平台三个独立入口。后端使用与创意岛阿里云一致的定制 ThinkPHP 5.1.41 LTS，保持 PHP 7.2 语法和 MySQL 5.7 兼容。

## 开发

需要 Node.js、npm 和 PHP（pdo_sqlite、pdo_mysql、bcmath、mbstring、fileinfo、zip）。

```sh
npm run setup
node scripts/init-dev.mjs
npm run dev
```

网页管理端 `http://127.0.0.1:5173/admin/platform`、`/admin/merchant`、`/admin/agency`；用户预览 `http://127.0.0.1:5174/consumer/`；经纪人预览 `http://127.0.0.1:5175/broker/`。API 位于 `http://127.0.0.1:8088/api/v1`。

本地初始化账号的用户名及随机口令保存在 `backend/storage/dev-accounts.json`；已部署站点当前账号保存在私有部署记录中，以实际数据库为准。账号文件、环境配置、数据库、上传文件和签名文件不会进入 Git 或发布目录。公开界面使用产品名称，合成资料不能用于证明真实商家资质。

开发或测试库可执行 `php scripts/import-test-catalog.php` 导入19个船型、对应演示船艇和两条资讯；重复导入保留已有记录和编辑，不重置账号。生产环境拒绝执行。

```sh
npm run check
npm run build
npm --prefix apps/consumer run build:app
npm --prefix apps/broker run build:app
```

`build:app` 生成 UniApp 原生资源；APK、iOS 模拟器包、签名 IPA 的打包及设备验证需单独记录，资源构建成功不等于安装包验收。

## 业务规则与文档

- 商家以 18 位统一社会信用代码为实际主键；[数据库说明](docs/database.md)含数据字典、外键和关系图。
- 经纪公司管理经纪人；经纪人及公司负责其订单履约。供应商商家无法查询这些订单、合同及客户会话。
- 平台逐单确认可分配利润，按后台配置比例和周期计提。部分成功退款冻结分红，复核剩余利润后只追加差额；全额退款冲回余额。历史账目保留。
- 实际接口见 [API](backend/API.md)、[机器接口契约](docs/api-contract.json)、[咨询扩展契约](docs/communication-api.json)及[统计导出契约](docs/reports-api.json)。
- 方案、参考项目和发布边界见[架构说明](docs/architecture.md)。

## 部署

目标 `https://yuzhou.ourideas.top`，独立库和独立站点。先提交源码，再生成同提交的Android测试包，最后执行 `npm run release`。发布命令会在干净的同一提交中重新构建网页、H5和地图，每个发布目录带 Git 提交、文件 SHA-256 和客户端身份；已有APK回执与当前提交不符会拒绝发布。`deploy/` 内提供独立数据库初始化、证书续期、验证发布和 Nginx 配置；不得覆盖创意岛的站点、环境文件或数据库。

当前安装包打包命令为 `scripts/build-android.py`，需本地官方DCloud SDK、Android SDK、Gradle、Java和独立临时签名/运行密钥，不能提交私有配置。构建命令强制先编译当前App原生资源，核对注册签名及权限，未执行设备安装测试。数据库增量迁移须在切换站点前完成；安装器核对每个迁移的实际执行回执与发布文件哈希。独立数据库和私有文件的备份、隔离恢复流程见[备份说明](deploy/BACKUP.md)。

短信登录、用户注册与手机号绑定已提供阿里云短信适配和两个客户端入口，默认禁用；驭舟独立短信配置及真实发送、接收仍未验收。真实支付、退款、提现、第三方登录、对象存储和推送的正式服务适配及独立配置尚未完成；定金/尾款规则也仍待确认，当前只提供整单测试支付。未配置服务明确返回不可用；测试支付仅在 development/test 且显式开关打开时可用。

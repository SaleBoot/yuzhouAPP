# 2026-10-11 App 交付与验收记录

本记录区分源码、线上 API、原生安装包和 WGT。Git 推送、构建成功与真机业务验收分别记录；不将旧云端准备记录当作本次安装结果。

| 交付项 | 实际来源与状态 |
| --- | --- |
| 本次 1.0.8 页面/业务修复源码 | 源码为包含本记录的 1.0.8 交付提交；精确提交及资源 SHA256 以独立发布回执为准，资源仅由干净源码生成 |
| 线上 API | `cc65830ed19d9172d65cdbb56d7faf9ef8249354`；已安装到独立 `api-107-cc65830` 目录 |
| 线上网页 | 保留 `7fd824ef2410e4953b8ac184c42e7ce140f3d6e5`，本次未重发网页 |
| 两端 Android / iOS 原生 1.0.7 / 107 | 基础提交 `cc65830ed19d9172d65cdbb56d7faf9ef8249354`；构建时包含 GUI 添加的 `app-plus.distribute.ios.dSYMs=false`，不能称为干净提交构建；具体快照见各包回执 |
| 两端 WGT 1.0.8 / 108 | 发布准备已通过；实际构建、HTTPS 发布和真机结果以独立发布回执为准。上一线上版本为 1.0.6 / 106，来源 `febf264b42f62959a39487cf3cbb0a65910a41c5` |

API 在实际 PHP 7.2.33 主机通过运行适配回归 8 项、HTTPS 健康检查（MySQL）和框架 JSON 404 检查。部署仅切换本站 API 入口，网页、地图、旧 APK/WGT、配置、任务和数据库结构保留。平台 API 已将首页“应用更新”横幅 id 59 从 version 2 / published 改为 version 3 / draft；首页公开列表已移除该项，没有恢复一个未经确认的原广告。部署与横幅回执保留在 `artifacts/deployment-private/107-cc65830/`，不随源码提交。

## 两个独立原生 App

| App | AppID | Android 包名 / iOS Bundle ID |
| --- | --- | --- |
| 驭舟（consumer） | `__UNI__E148E73` | `top.ourideas.yuzhou.consumer` |
| 驭舟经纪人（broker） | `__UNI__59A80C3` | `top.ourideas.yuzhou.broker` |

四个安装包均为 1.0.7 / 107、UniApp 5.26。Android 两包已放桌面，名称为 `驭舟-用户端-1.0.7.apk`、`驭舟-经纪人端-1.0.7.apk`，另有 `驭舟-Android-1.0.7-SHA256SUMS.txt`。原生名称无“演示版”标签；品牌图标像素、AppID/包名、APK v2 签名和 `debuggable=false` 已核验。两 APK 使用本项目原有同一临时测试证书，其 SHA256 为 `22016fbf4f80e0d163f4f5ce945f55a59b24b350f111bf9e46d2eb13d13c76c7`；这不构成商店发布验收。

APK 实际原生配置为 minSdk 21、targetSdk 28、仅 `arm64-v8a`、禁止备份、`usesCleartextTraffic=true`。权限包含相机、电话状态、安装包请求、存储/媒体读取及网络等，完整清单见两份 `artifacts/native-android/safepack-*-107/build-receipt.json`。API 地址为 HTTPS；上述原生权限与配置不能通过 WGT 改变。Android 真机安装/启动尚未验收。

iOS 两包已安装到用户 iPhone，用户已确认分别打开并显示正确 Logo；该验收限于安装、启动与品牌显示。两个开发 profile 的有效期均为 7 天：用户端于 **2026-10-18 18:35:57**、经纪人端于 **2026-10-18 19:16:14** 到期（北京时间）。WGT 不会延长原生开发签名有效期。签名/安装回执在 `artifacts/native-ios/safepack-*-107/native-installation-receipt.json`。

| 安装包 | SHA256 |
| --- | --- |
| consumer APK | `8960cbc78fbde43e466b2c763b5bdaa4d52b472f1d689286a92cf47d0394a7f8` |
| broker APK | `5ec83c3127670e899c85d098a210f869d900c4092957dc02c41c6d18d8aceaa9` |
| consumer IPA | `a0a82d5de37ad8163c419ab6166dc7e88e727ed7980799e8be2bf7bc8a6700eb` |
| broker IPA | `b502fc765bd688d081aeaa74ac016a193361f020e533c0dc867dee98105d1d8f` |

## 1.0.8 WGT 发布与后续修复

两个 App 各自从“我的 → 应用更新”进入本站更新页。现有 107 安装包与更新页采用上表相同的两个独立 AppID、包名和 5.26 运行库，108 资源版本高于 107。更新页核验来源、App 身份、版本、长度、源提交和 SHA256，安装 WGT 时使用 `force:false`，安装结果身份/版本正确后重启 App。16 项含英文的更新页自动测试及两端 107→108 模拟升级通过；模拟使用原生 API 替身，不是真机安装证据。

发布回执分别记录 108 源提交、两个 WGT SHA256、公开 HTTPS 校验结果与两端真机更新结果，不能把自动模拟写成真机通过。108 发布后，后续页面/业务修复默认分别发布 WGT，无需重装 APK/IPA；原生运行库、插件、权限、桌面图标或签名变化需独立安装包。支付/短信等服务集成与完整业务流程仍按实际结果验收，启动 Logo 和构建通过不能替代这些验收。

两端“我的”提供中文/English 切换并保存选择；页面、系统导航与底部 Tab 同步切换。已有业务名称、用户输入、船艇资料及外部 3D 内容保留原文。界面统一缩小标题、按钮、价格字号并减轻字重，金额仍为人民币。27 个用户页、13 个经纪人页和 18 个共享组件的静态文案审计通过；320/393 宽度、正常/160% 字号的中英布局 56 组通过。未登录的私人页面显示手动登录入口，返回首页不再触发登录循环；退出清空缓存，旧会话在途 API 响应不能覆盖新会话数据。自动验证使用合成数据，实际语言切换仍需真机验收。

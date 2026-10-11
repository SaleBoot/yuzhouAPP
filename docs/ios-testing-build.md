# iOS 测试包构建

仓库包含两个独立 App；资源交接包不能直接安装到 iPhone。最终归档、签名和设备安装须在连接 iPhone 的 Mac 上执行。

| App | DCloud AppID | Apple Bundle ID |
| --- | --- | --- |
| 用户端 consumer | `__UNI__E148E73` | `top.ourideas.yuzhou.consumer` |
| 经纪人端 broker | `__UNI__59A80C3` | `top.ourideas.yuzhou.broker` |

## 云端准备与资源交接

需要 Python 3.9+、Node.js、npm 和 Git。在仓库根目录执行：

```bash
npm --prefix apps/consumer ci
npm --prefix apps/broker ci
python3 scripts/build-ios-resources.py
```

脚本运行两端源码检查与 `build:app`，校验独立身份、版本、UniApp 编译器版本和编译后的 HTTPS API 地址，输出到新的 `artifacts/native-ios/resources-日期-快照哈希/` 目录：

- `yuzhou-consumer-ios-www.zip` 与 `yuzhou-broker-ios-www.zip`：对应客户端原生资源，ZIP 内以 `www/` 为根。
- `ios-resource-handoff.json`：AppID、Bundle ID、版本、编译器版本、API 地址及每个源文件和资源的 SHA256。
- `SHA256SUMS`：交接清单和两个 ZIP 的 SHA256。
- `scripts/build-ios-ipa.py`、`docs/app-identities.json` 与本指南：Mac 上路径 B 所需的独立签名工具和说明，均记录 SHA256。
- `ios-test-build-handoff.zip`：包含上述文件的单个交接 ZIP。它包含自己的内容校验清单，不递归包含自己；输出目录外层的清单另外记录交接 ZIP 的 SHA256。

默认 API 为 `https://yuzhou.ourideas.top/api/v1`。有经过确认的独立 HTTPS 测试后端时，可传 `--api-base https://测试域名/api/v1`；不能把 HTTP 本地接口误用于 iOS 交付。`--app consumer` 或 `--app broker` 可单独构建；`--output` 指定一个尚不存在的产物目录。

交接清单的 `artifact_type` 为 `uniapp_native_resources`，`signed_ipa` 和 `installable_on_ios` 为 `false`。源码未提交时，`source_worktree_clean` 为 `false`，实际构建版本以完整的 `source_snapshot_sha256` 和逐文件哈希为准，`source_commit` 仅表示其基础提交。构建中源码变化会停止生成交接包。正式发布应先提交源码，再重新生成资源。

在 Mac 收到目录后检查 SHA256：

```bash
cd /实际路径/resources-日期-快照哈希
shasum -a 256 -c SHA256SUMS
```

也可以只传 `ios-test-build-handoff.zip`，在 Mac 解压到一个新目录，然后在该目录执行上述校验命令。路径 B 的脚本可直接从解压后的 `scripts/` 运行，无需下载完整源码；路径 A 使用 HBuilderX 重新编译仍需要完整源码。

## Mac 上完成签名所需资料

已有 Apple 开发者账号和 Xcode 之后，还需准备以下资料；签名密钥与账号密码应保留在 Mac 钥匙串或指定的秘密存储中，不要放进源码、交接 ZIP 或聊天。

1. 在自己的 Apple 开发者团队注册两个 Bundle ID，并为需要的能力配置对应 App ID。
2. 安装适合测试方式的签名证书及其私钥，并分别安装两个 Bundle ID 的 provisioning profile。Development/Ad Hoc 测试必须包含测试 iPhone 的 UDID；TestFlight 使用 App Store Connect 分发配置，不需要收集 UDID。
3. 自己的 DCloud 账号有权使用上表两个 AppID，并完成对应 iOS 平台的 Bundle ID、证书和运行配置。Android 的 AppKey 或临时签名不能视为 iOS 配置已经完成。
4. 使用与资源清单 `compiler_version` 匹配的官方 DCloud iOS 原生工程/离线 SDK。本仓库编译器为 **5.26**；仅有 iOS Simulator runtime 或 Xcode 不提供 DCloud 原生运行库。
5. 在该原生工程配置实际的 AppID、Bundle ID、版本、模块和隐私说明；两个 App 分开构建。除基础运行模块，还需核对 `Camera`、`VideoPlayer`、`Invocation`、File、网络请求、Uploader/Downloader，以及源码 manifest 中的相册和相机用途说明。原生库和资源注册应以该版 SDK 的 `Feature-iOS.xls` 等官方清单为准；仅有网络请求库不能证明上传/下载模块也已正确注册。

尚无 iOS SDK/原生工程时，先在 Mac 打开 [DCloud 官方 iOS 离线 SDK 下载文档入口](https://nativesupport.dcloud.net.cn/AppDocs/download/ios.html)，按官方版本记录选择与本次资源 **5.26** 编译器匹配的 SDK。工程接入步骤以 [官方 iOS SDK 使用文档](https://nativesupport.dcloud.net.cn/AppDocs/usesdk/ios.html) 为准；这是官方文档入口，不是已下载或已验证的 SDK 安装包。本次云端网络允许列表没有该域名，需要在 Mac 下载，不应通过代理或镜像绕过当前网络规则。

解压后先阅读 SDK 自带的说明，打开其中的官方 Xcode 示例工程或 workspace，再通过 Xcode target 的资源引用与 Copy Bundle Resources 配置确定实际 `www` 路径。不同版本的 SDK 目录可能不同，不能把一个猜测的固定目录直接传给脚本。为两个 App 各保留一份独立配置的工程，修改 DCloud 运行 AppID/控制配置、对应 iOS AppKey/license、Bundle ID、模块和 Apple 签名；所需文件与配置名称按实际 SDK 文档确认。拿到 SDK 并完成这些配置后，路径 B 的脚本才具备真实签名条件。

### 路径 A：HBuilderX 云打包

在 Mac 安装匹配版本的 HBuilderX，并使用自己的 DCloud 账号。把同一份已验证源码放到 Mac，保留 `apps/shared` 和 `apps/scripts` 等兄弟目录，安装两端 npm 依赖，将 `apps/consumer` 与 `apps/broker` 分别作为 UniApp CLI 项目导入。配置相同的 HTTPS API 后，在“发行 → 原生 App 云打包”中选择 iOS，分别设置该 App 的 Bundle ID、签名证书和描述文件，生成两个 IPA。

HBuilderX 云打包会重新编译源码，因此资源 ZIP 的成功记录不等于云打包已经成功。下载 IPA 后记录源版本、包的 SHA256、使用的分发方式，并进行 iPhone 安装验证。使用自己的签名资料，不能借用现有创意岛 App 的 AppID、证书、AppKey 或秘密配置。

### 路径 B：官方原生 Xcode 工程归档导出

本仓库的 `scripts/build-ios-ipa.py` 支持已正确配置的官方 DCloud Xcode 工程，不假设离线 SDK 的目录结构。先在 Xcode 确认原生 target 使用正确的 AppID、签名和模块，并找到该 target 实际复制进 App 的现有 `www` 目录。脚本会临时替换其资源，构建完成或失败后恢复原目录。

归档时脚本按资源清单显式传入 `PRODUCT_BUNDLE_IDENTIFIER`、`MARKETING_VERSION` 与 `CURRENT_PROJECT_VERSION`。原生工程的 Info.plist 应使用相应 build setting；如果 SDK 示例仍硬编码旧 Bundle ID 或版本，需按实际 SDK 说明修正，最终 IPA 校验会拒绝版本不一致的包。DCloud 的控制 AppID、AppKey/license 和模块配置仍需自行完成，脚本不会生成虚假的授权。

可以用以下只读命令确认 Xcode 和 scheme：

```bash
xcodebuild -version
xcodebuild -list -project /实际路径/官方工程.xcodeproj
security find-identity -v -p codesigning
```

若官方工程使用 workspace，改用 `xcodebuild -list -workspace /实际路径/官方工程.xcworkspace`。

准备 `ExportOptions.plist`。现代 Xcode 的 Development 测试可使用下面示例，把团队 ID 和该 App 的 profile 名称替换为自己的实际值。Ad Hoc 可改为 `release-testing`；TestFlight 可改为 `app-store-connect`。不同 Xcode 的可用 method 以 `xcodebuild -help` 为准；脚本同时接受旧版的 `development`、`ad-hoc`、`app-store`。

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>method</key><string>debugging</string>
  <key>destination</key><string>export</string>
  <key>teamID</key><string>你的十位TEAMID</string>
  <key>signingStyle</key><string>manual</string>
  <key>provisioningProfiles</key>
  <dict>
    <key>top.ourideas.yuzhou.consumer</key><string>用户端实际Profile名称</string>
  </dict>
</dict>
</plist>
```

在 Mac 的交接包解压目录或同版本源码目录运行；所有示例路径均需替换为自己的实际路径：

```bash
python3 scripts/build-ios-ipa.py consumer \
  --handoff /实际路径/resources-日期-快照哈希/ios-resource-handoff.json \
  --project /实际路径/consumer原生工程.xcodeproj \
  --scheme 实际用户端Scheme \
  --team-id 实际十位TEAMID \
  --export-options /实际路径/consumer-ExportOptions.plist \
  --www-dir /实际路径/原生工程实际资源目录/www \
  --output /实际路径/尚不存在的consumer-ipa目录
```

经纪人端另运行一次，将 `consumer` 改为 `broker`，使用经纪人端原生工程、scheme、profile、资源目录和新输出目录。workspace 工程将 `--project` 改为 `--workspace`。

脚本不自动上传、安装或创建 Apple 账号。它依次校验交接 ZIP、运行 `xcodebuild archive` 和 `xcodebuild -exportArchive`、核对 IPA 内 Bundle ID/版本/全部资源哈希，校验代码签名及内嵌 profile 的团队和有效期。只有这些检查通过才输出根目录的 `yuzhou-端名-test.ipa`、JSON 回执和 `SHA256SUMS`。Xcode 项目需已经能够使用本机钥匙串和 profile 签名；脚本不会获取私钥或自动处理网页登录。

输出目录还包含 `.xcarchive`、私有构建日志和临时资源备份。交付时只传需要的 IPA、回执和校验清单，不要把整个构建目录提交到 Git。若脚本失败，应检查该目录的 `xcodebuild.log`；`export/` 内的中间 IPA 未经脚本验证，不能冒充已完成交付。

## iPhone 验证

### 原生宿主配置与兼容工具链

两端源 manifest 的 `distribute.icons` 使用 `apps/shared/assets` 同一品牌图标。离线工程也必须将这些尺寸填入 `AppIcon.appiconset`，并保持 target 的 `ASSETCATALOG_COMPILER_APPICON_NAME=AppIcon`；仅更新 WGT 无法改变桌面图标。SDK 模板的 `zh-Hans.lproj/InfoPlist.strings` 和 `en.lproj/InfoPlist.strings` 中 `CFBundleDisplayName` 会覆盖 Info.plist，须分别改为“驭舟”或“驭舟经纪人”。

本仓库 `scripts/ios` 保留官方 5.26 示例宿主的 Scene 接入补丁。对每个独立副本的 `HBuilder-Hello/AppDelegate.m` 执行 `patch --fuzz=0 AppDelegate.m < 仓库/scripts/ios/AppDelegate-scene.patch`，复制同目录的 `SceneDelegate.m`，并将 `scene-manifest.plist` 的字典放入 Info.plist 的 `UIApplicationSceneManifest`。补丁通过导入 SceneDelegate.m 编译，勿再重复加入 Compile Sources。原生生命周期、URL、用户活动及快捷操作仍转发给 DCloud。

2026-10-11 真机确认：Xcode 27 构建的 DCloud 5.26 经典 UniApp 运行库在 iOS 27 因默认 NSFileManager 设置 delegate 断言终止；Scene 补丁不能修复运行库内部调用。[DCloud 官方答复](https://ask.dcloud.net.cn/question/221682)建议临时使用 Xcode 26 或云打包。[官方 5.26 下载说明](https://nativesupport.dcloud.net.cn/AppDocs/download/ios.html)列出的云构建环境为 Xcode 26.6 / iOS SDK 26.5。采用并行安装的官方工具链时，显式设置 `DEVELOPER_DIR=/Applications/Xcode-26.6.app/Contents/Developer`，保留原有 Xcode；是否解决闪退须以重新安装后的持续运行与设备日志判断。

Development/Ad Hoc IPA 只允许对应 profile 中登记的设备，通过 Xcode Devices and Simulators 等正规方式安装。App Store Connect 导出的 IPA 需要后续上传及 TestFlight 流程才能供测试者安装；生成 IPA 本身不会建立 TestFlight 邀请。

分别验证两端登录、后端连通、私有合同 PDF 导入/上传/预览、相册/相机授权、视频和 3D 外链，并检查取消选文件、网络失败、退出重进等路径。iOS PDF 桥接源码存在，但目前仍需在实际 iPhone 上确认 `UIDocumentPickerViewController` 回调、文件访问和窗口呈现。原生资源检查、IPA 签名和 iPhone 业务验证应分别记录；未配置的支付和短信服务仍按后端实际错误展示。

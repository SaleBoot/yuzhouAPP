# 框架来源与许可

用户明确要求沿用创意岛现有后端框架。只读本地锁定依赖确认：

- 包：`topthink/framework`，composer 版本 `dev-saizhuge`。
- 源：<https://github.com/xuefeiniao/framework.git>，锁定提交 `8f8e7c0bf37baf86f0762b17c06291d6dab29607`。
- 框架自身 `think\App::VERSION`：`5.1.41 LTS`。
- 许可证：Apache-2.0，原文保存在 `thinkphp/LICENSE.txt`；原作者信息及框架文件保留。
- 本次只复制框架目录，不复制创意岛的配置、业务模块、客户资料、支付秘密或加密业务文件。

本地框架基线 SHA256：

```text
thinkphp/library/think/App.php
cf393061312c7fe50fd5418565d7cf372f0a8eee958bd84b964cedac55bf5553
thinkphp/composer.json
9a6f51e25fbd7fee5c7fcfe376c27d2fc2ac7122f4a009fb90a737a2dd21a492
thinkphp/LICENSE.txt
23f2931ac729a29d82df2f73eb84847fb758dedddc3e0dec9327440e058227cb
```

PHP 8.2 本地入口仅屏蔽旧框架的弃用诊断，不改动框架锁定源码。服务器的 PHP 7.2.33 基线由独立部署核验；PHP 7.2 的生命周期风险由根交付记录说明。

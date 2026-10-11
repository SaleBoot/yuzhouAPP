# 视觉素材来源与授权

- `consumer/src/static/yacht.jpg` 与 `broker/src/static/yacht.jpg` 提取自用户提供的 `交付材料/9ea9f744dde8f826db16780fd53500ac.mp4` 约174秒处的船艇画面，裁去页面业务文字；用于本项目的设计复现和开发预览。原始版权和授权归素材权利人，正式商用前由用户确认。
- 页面深蓝、白、黄色、船艇卡片、列表切换和对比流程依照用户确认的视频与PNG。小图标由项目代码绘制，无外部图标包。
- 船艇、课程、产品、动态的正式图片/视频由后端资源 `payload` 提供。缺失图片时显示上述参考船艇图，不构造业务价格或假订单。
- 群聊截图、私人账号资料和历史凭据不进入 App 包；未复用历史 DCloud AppID 或秘密。当前两个独立临时 AppID 由用户授权的 HBuilderX 创建。
- 两端原生图标使用交付PNG `微信图片_20260519104341_195_333.png` 中白色游艇与 `YUZHOU` 的圆形渐变徽标。已有400×400裁图去除透明通道并按平台尺寸缩放，保存为 `shared/assets/app-icon.png`（1024×1024 RGB）与 `shared/assets/app-icons/`；缩放不增加原图细节。两端 manifest 的 Android 与 iOS 图标配置共用这些文件，保留不同应用名称与独立身份。配置字段及尺寸依照 [DCloud 官方 manifest 说明](https://uniapp.dcloud.net.cn/collocation/manifest-app.html)。
- Android离线脚本使用上述徽标的72/96/144/192像素版本作为各密度启动器图标，启动图片亦使用共用徽标，移除SDK示例启动图片。船艇页缺省照片仍使用 `yacht.jpg`。原生图标和启动图片须重新打安装包才会生效，WGT不能修改已安装App的这些原生资源。

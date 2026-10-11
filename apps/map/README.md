# 驭舟地图找船

`npm ci && npm run check && npm run build` 生成已忽略的 `dist/`，独立发布到站点 `/boat-map/`，与网页、后端 API 和两端 WGT 分别记录源码提交及哈希。页头只保留刷新。两个 App 共用 `apps/shared/BoatMap.vue`，H5 iframe 与原生 HTTPS web-view 都传入当前 `lang=en/zh-Hans`；这项调用修改须随两端 WGT 一起发布。地图自身支持相同语言参数，H5 无参数时使用本站保存的语言，默认中文。

只读取公开 `/api/v1/bootstrap` 与 `/api/v1/resources/boats`，按服务端分页遍历已发布实船。坐标必须为成对的有效数字，不调用浏览器或手机定位，不生成坐标，也不能作为实时 GPS。没有实船位置仍显示基础地图，旁边提示发布方补全位置；不因此隐藏整个地图。

高德采用官方在线 Loader 与 JSAPI 2.0。需在高德开放平台本项目应用下申请 **Web端（JS API）** Key，配套安全密钥，并将域名限制为 `yuzhou.ourideas.top`。本功能运行在 HTTPS 网页中，不申请 iOS/Android 原生 SDK Key，不要求定位权限。账号完善、认证及服务授权按本人实际控制台状态办理，代码就绪不代表账号权限已通过。[官方准备说明](https://lbs.amap.com/api/javascript-api-v2/prerequisites)

构建读取 `AMAP_JS_API_KEY`，或 `MAP_CONFIG_FILE` 指定的本项目私有 JSON 文件中的 `amaps_js_key` 字段。构建只提取公开 Key，生成 `dist/map-config.json` 的两个字段：`amaps_js_key` 与固定的 `serviceHost: "/_AMapService"`；日志只记是否配置，不输出 Key。安全密钥不进入浏览器配置、源码或 Git。即使输入私有文件另有服务端字段，构建也不复制这些字段。浏览器 Key 会随 SDK 请求发送，须同时配置控制台域名限制。

站点 Nginx 的同源 `/_AMapService/` 代理固定转发到 `https://restapi.amap.com/`，仅在服务端附加本 Key 的私有 `jscode`。SDK 加载前设置 `window._AMapSecurityConfig.serviceHost` 为本站绝对 URL 加固定前缀；不用前端 `securityJsCode` 明文方式，也不用业务 ThinkPHP API 中转。本功能没有自定义样式，故不增加官方示例中的自定义样式代理。具体代理由独立发布脚本按现有 vhost 基线审查、校验、回滚后部署，此目录不直接修改在线 Nginx。[官方安全代理说明](https://lbs.amap.com/api/javascript-api-v2/guide/abc/jscode)、[官方 Loader 加载说明](https://lbs.amap.com/api/javascript-api-v2/guide/abc/load)

高德使用 GCJ-02。只有实船 `payload.coordinate_system` 已明确为 `GCJ02` 或 `GCJ-02` 的有效位置才放置高德标记；未知来源、WGS84、BD09 先保留原始坐标、价格与详情列表，提示坐标类型待确认，不擅自转换或定位。后续如确认坐标来源，再按官方方法做明确转换。本次不改变后台坐标、状态或业务数据。[官方坐标转换说明](https://lbs.amap.com/api/javascript-api-v2/guide/transform/convertfrom)

Key 缺失或配置获取失败时保留 Leaflet：使用公开 bootstrap 的可选 `map_tiles={url,attribution,is_test_provider}`，开发/测试环境才默认 OSM 瓦片。无位置仍显示基础底图。Key 已配置但高德加载、认证或网络失败时明确提示，船艇列表、原始坐标和详情仍可查看；不假报地图成功。

Leaflet 按可视范围加载，保留浏览器缓存及 Referer，不做批量下载或离线预取；OSM 标准服务仅作低流量开发测试默认，实际服务须使用适合流量与使用条款的 endpoint。Leaflet 1.9.4 锁定并随发布保留 BSD2 LICENSE。DCloud 官方 uni.webview.1.5.8.js 同源打包并保留 Apache2 LICENSE，等待 `UniAppJSBridgeReady` 后跳转原生详情。H5 父页仅接受本 iframe、同源、合法整数 ID 的消息。

用户端进入对应船型详情并携带实际实船 ID，销售端进入实际船艇配置。单元及本地浏览器 fixture 可验证配置边界、无位置、错误状态、两种语言与桥接 URL；真实高德授权、本站代理、底图请求与两端真机须在有效 Key/安全密钥到位后另行验收。

参考：[Leaflet 下载与版本](https://leafletjs.com/download)、[UniApp web-view](https://uniapp.dcloud.net.cn/component/web-view)、[OSM 瓦片政策](https://operations.osmfoundation.org/policies/tiles/)。

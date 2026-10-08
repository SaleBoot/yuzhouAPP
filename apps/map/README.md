# 驭舟地图找船

`npm ci && npm run check && npm run build` 生成 `dist/`。根发布脚本将该目录复制到站点 `/boat-map/`。两个 App 的开发服务器已提供相同本地路径，需先构建本目录。

只读取公开 `/api/v1/resources/boats`，按服务端 `total/page/page_size` 遍历分页，只展示 `published` 实船且存在数字类型 `payload.latitude/longitude`。不调用浏览器或手机定位、不生成坐标。位置由发布方维护，不能作为实时 GPS。

底图读取公开 `/api/v1/bootstrap` 的 `map_tiles={url,attribution,is_test_provider}`，URL 为 HTTPS `{z}/{x}/{y}` 模板。生产未配置时只显示船艇标记与坐标；开发/测试才默认使用 OSM 标准瓦片。按当前可视范围加载，保留浏览器缓存与 Referer，未实现批量下载或离线预取。OSM 标准服务只作为低流量开发测试默认，正式服务需选择满足实际流量与使用条款的 endpoint。

Leaflet 1.9.4 锁定在 package-lock.json，BSD2 LICENSE 随发布保留。DCloud 官方 uni.webview.1.5.8.js 同源打包，Apache2 LICENSE 保留；公开来源 https://github.com/dcloudio/uni-app/tree/uni-app-vue2-dev/dist 。App 等待 `UniAppJSBridgeReady` 后 `uni.webView.navigateTo`；不依赖可能延迟触发的 `@message`。H5 iframe 的父页仅接受本 iframe、同源、合法整数 ID 的 `window.postMessage`。

用户端进入对应船型详情并携带实际实船 ID，购买预选该实船；销售端进入实际船艇的代客配置。原生桥接仍须各设备验证。

参考：[Leaflet 下载与版本](https://leafletjs.com/download)、[UniApp web-view](https://uniapp.dcloud.net.cn/component/web-view)、[OSM 瓦片政策](https://operations.osmfoundation.org/policies/tiles/)。

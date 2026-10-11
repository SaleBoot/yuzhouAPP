import { t, getLocale } from './i18n.js';
// #ifdef APP-PLUS
import { choosePdf } from "./native-file.js";
// #endif
let BASE = "/api/v1";
// #ifdef APP-PLUS
BASE = "https://yuzhou.ourideas.top/api/v1";
// #endif
BASE = (import.meta.env.VITE_API_BASE || BASE).replace(/\/$/, "");
const CLIENT = import.meta.env.VITE_CLIENT || "consumer";
export let fallbackBoat = "/static/yacht.jpg";
// #ifdef H5
fallbackBoat = `/${CLIENT}/static/yacht.jpg`;
// #endif
const SESSION_KEY = `yuzhou_${CLIENT}_session`;
export function session() {
  return uni.getStorageSync(SESSION_KEY) || null;
}
export function saveSession(value) {
  value
    ? uni.setStorageSync(SESSION_KEY, value)
    : uni.removeStorageSync(SESSION_KEY);
}
export function token() {
  return session()?.token || session()?.access_token || "";
}
export function media(path) {
  if (path?.startsWith("/") && BASE.startsWith("http")) {
    // App service runs without the browser URL constructor.
    const origin = BASE.split("/").slice(0, 3).join("/");
    return path.startsWith("//") ? `${BASE.split(":")[0]}:${path}` : `${origin}${path}`;
  }
  // #ifdef H5
  // Uni H5 otherwise adds its /consumer/ or /broker/ router base to media URLs.
  if (/^\/api\/v1\/files\/[1-9]\d*$/.test(path || ""))
    return new URL(path, window.location.origin).href;
  // #endif
  return path;
}
export const query = (data = {}) =>
  Object.entries(data)
    .filter(([, v]) => v !== undefined && v !== null && v !== "")
    .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`)
    .join("&");
export function resource(item) {
  return item
    ? {
        ...item,
        ...(item.payload || {}),
        price_cents: item.price_cents,
        id: item.id,
        name: item.title,
        image_url: media(
          item.payload?.image_urls?.[0] ||
            item.payload?.image_url ||
            item.image_url,
        ),
        image_urls: (item.payload?.image_urls || []).map(media),
      }
    : null;
}
function serviceMessage(message, code) {
  if (message === "Phone identity already exists. Use SMS login.") return t("手机号已注册，请使用验证码登录");
  const smsErrors = {
    50311: t("短信验证服务暂未开通"),
    50312: t("短信平台未受理，请稍后重试"),
    50313: t("短信发送结果暂无法确认，请等待冷却结束后重试"),
    40103: t("验证码错误、已过期或已使用，请检查后重试"),
    40104: t("手机号未绑定有效账号，请使用密码登录后绑定"),
    40903: t("此手机号无法绑定当前账号，请联系平台"),
    42903: t("短信请求过于频繁，请稍后重试"),
  };
  if (smsErrors[code]) return smsErrors[code];
  const translations = [
    [/Invitation code is invalid/i, t("邀请码无效")],
    [/Activity enrollment requires approved identity materials/i, t("活动报名需平台资料审核通过，请提交身份资料")],
    [/Activity enrollment requires an active membership/i, t("活动报名需有效会员，请查看会员状态")],
    [/The enrollment changed|current ticket/i, t("报名记录已更新，请刷新后重试")],
    [/A checked-in enrollment cannot be cancelled/i, t("已经签到的活动不能在此取消")],
    [/This activity has a payment order/i, t("本活动已有订单，请在订单中取消或申请退款")],
    [/Real SMS service is not configured/i, t("短信登录暂未开通")],
    [
      /Real payment.*not configured|Payment.*not configured/i,
      t("此支付方式尚未开通，请联系平台"),
    ],
    [/Points or reward stock are insufficient/i, t("积分不足或礼品库存不足")],
    [/Progress cannot decrease/i, t("实际进度不能低于已保存进度")],
    [
      /Service appointment must be in the future/i,
      t("服务预约时间应晚于当前时间"),
    ],
    [/Not enough boats are available/i, t("所选日期的可用船艇不足")],
    [
      /This boat already has an active purchase order/i,
      t("这艘船已有购船订单，请选择其他船艇"),
    ],
    [/Product stock is insufficient/i, t("商品库存不足")],
    [/Authentication required|Token.*expired/i, t("请重新登录后操作")],
    [/Reservation expired/i, t("预约已过期，请重新创建订单")],
    [
      /Quote is not available or has expired/i,
      t("报价不可用或已过期，请联系销售更新"),
    ],
  ];
  return (
    translations.find(([pattern]) => pattern.test(message || ""))?.[1] ||
    message
  );
}
export function request(path, { method = "GET", data, authToken } = {}) {
  const bearer = authToken === undefined ? token() : authToken;
  return new Promise((resolve, reject) =>
    uni.request({
      url: `${BASE}${path}`,
      method,
      data,
      header: {
        ...(data !== undefined ? { "Content-Type": "application/json" } : {}),
        ...(bearer ? { Authorization: `Bearer ${bearer}` } : {}),
      },
      timeout: 20000,
      success(res) {
        if (authToken === undefined && bearer && bearer !== token()) {
          reject(new Error(t("登录状态已改变，请重新打开页面")));
          return;
        }
        // OTP failures use 401 without expiring the current binding session.
        if (res.statusCode === 401 && bearer === token() && ![40103, 40104].includes(res.data?.code)) saveSession(null);
        if (
          res.statusCode >= 200 &&
          res.statusCode < 300 &&
          res.data?.code === 0
        )
          resolve(res.data.data);
        else {
          const error = new Error(serviceMessage(res.data?.message, res.data?.code) || t("请求失败（{status}）", { status: res.statusCode }));
          error.code = res.data?.code;
          error.status = res.statusCode;
          reject(error);
        }
      },
      fail: () => reject(new Error(t("暂时无法连接服务，请检查网络或服务地址"))),
    }),
  );
}
export async function list(kind, filters = {}) {
  const items = new Map();
  const singlePage = filters.page !== undefined;
  for (let page = Number(filters.page || 1); ; page++) {
    const data = await request(`/resources/${kind}?${query({ page_size: 100, ...filters, page })}`);
    if (!Array.isArray(data?.items) || !Number.isSafeInteger(data.total) || data.total < 0 || data.page !== page || !Number.isSafeInteger(data.page_size) || data.page_size < 1)
      throw new Error(t("资源列表返回信息不完整，请重试"));
    const before = items.size;
    for (const item of data.items) items.set(item.id, resource(item));
    if (singlePage || page * data.page_size >= data.total) return [...items.values()];
    if (!data.items.length || items.size === before) throw new Error(t("资源列表分页返回异常，请重试"));
  }
}
export const detail = (kind, id) =>
  request(`/resources/${kind}/${encodeURIComponent(id)}`).then(resource);
export const post = (path, data = {}) =>
  request(path, { method: "POST", data });
export const patch = (path, data) => request(path, { method: "PATCH", data });
export const notify = (error) => {
  const message = error?.message || error?.errMsg || String(error);
  if (/cancel/i.test(message)) return;
  uni.showToast({
    title: t(message),
    icon: "none",
    duration: 3000,
  });
};
let openingLogin = false;
export function go(page, params = {}) {
  if (page === "viewer" && params.url === "https://yuzhou.ourideas.top/app-updates/")
    params = { ...params, url: params.url + "?lang=" + getLocale() };
  if (page === "login") {
    const pages = typeof getCurrentPages === "function" ? getCurrentPages() : [];
    if (openingLogin || pages[pages.length - 1]?.route === "pages/login") return;
    openingLogin = true;
  }
  uni.navigateTo({ url: `/pages/${page}?${query(params)}`,
    complete: () => { if (page === "login") openingLogin = false; },
  });
}
export function tab(page) {
  uni.switchTab({ url: `/pages/${page}` });
}
export function external(url) {
  if (!url || !/^https?:\/\//i.test(url))
    return notify(new Error(t("暂未配置有效链接")));
  go("viewer", { url });
}
export function requireLogin(redirect = true) {
  if (token()) return true;
  if (redirect) go("login");
  return false;
}
export function upload(filePath, { purpose = "contract", private: privateFile } = {}) {
  return new Promise((resolve, reject) =>
    uni.uploadFile({
      url: `${BASE}/uploads`,
      filePath,
      name: "file",
      formData: { private: (privateFile ?? (purpose === "contract" || purpose === "application")) ? "1" : "0" },
      header: token() ? { Authorization: `Bearer ${token()}` } : {},
      success(res) {
        try {
          const r = JSON.parse(res.data);
          if (res.statusCode >= 200 && res.statusCode < 300 && r.code === 0)
            resolve(r.data);
          else reject(new Error(serviceMessage(r.message) || t("文件上传失败")));
        } catch {
          reject(new Error(t("文件上传返回格式不正确")));
        }
      },
      fail: () => reject(new Error(t("文件上传失败，请重试"))),
    }),
  );
}
export async function chooseUpload(options = {}) {
  let path;
  const pdf = options.purpose === "contract" || options.file_type === "pdf";
  // #ifdef H5
  path = await new Promise((resolve, reject) =>
    uni.chooseFile({
      count: 1,
      extension:
        pdf
          ? [".pdf"]
          : options.file_type === "image" ? [".png", ".jpg", ".jpeg"]
          : [".png", ".jpg", ".jpeg", ".mp4"],
      success: (r) => resolve(r.tempFiles[0].path),
      fail: reject,
    }),
  );
  // #endif
  // #ifdef APP-PLUS
  if (pdf) {
    path = await choosePdf();
  } else {
    const kind = options.file_type === "image" ? 0 : await new Promise((resolve, reject) =>
      uni.showActionSheet({
        itemList: [t("选择图片"), t("选择视频")],
        success: (r) => resolve(r.tapIndex),
        fail: reject,
      }),
    );
    path = await new Promise((resolve, reject) =>
      kind === 1
        ? uni.chooseVideo({
            count: 1,
            sourceType: ["album"],
            success: (r) => resolve(r.tempFilePath),
            fail: reject,
          })
        : uni.chooseImage({
            count: 1,
            sourceType: ["album"],
            success: (r) => resolve(r.tempFilePaths[0]),
            fail: reject,
          }),
    );
  }
  // #endif
  if (!path) throw new Error(t("未选择文件"));
  return { ...(await upload(path, options)), local_path: path };
}
export async function previewFile(id) {
  const url = `${BASE}/files/${encodeURIComponent(id)}`;
  // #ifdef H5
  const popup = window.open("", "_blank");
  try {
    const r = await fetch(url, {
      headers: token() ? { Authorization: `Bearer ${token()}` } : {},
    });
    if (!r.ok) {
      const data = await r.json().catch(() => ({}));
      throw new Error(data.message || t("文件下载失败"));
    }
    const blob = await r.blob();
    const objectUrl = URL.createObjectURL(blob);
    if (popup) popup.location.href = objectUrl;
    else {
      const a = document.createElement("a");
      a.href = objectUrl;
      a.download = `contract-${id}.pdf`;
      a.click();
    }
    setTimeout(() => URL.revokeObjectURL(objectUrl), 300000);
  } catch (e) {
    popup?.close();
    throw e;
  }
  // #endif
  // #ifdef APP-PLUS
  const result = await new Promise((resolve, reject) =>
    uni.downloadFile({
      url,
      header: token() ? { Authorization: `Bearer ${token()}` } : {},
      success: resolve,
      fail: reject,
    }),
  );
  if (result.statusCode !== 200) throw new Error(t("合同下载失败"));
  await new Promise((resolve, reject) =>
    uni.openDocument({
      filePath: result.tempFilePath,
      fileType: "pdf",
      showMenu: true,
      success: resolve,
      fail: reject,
    }),
  );
  // #endif
}
export async function exportFile(path, filename, fileType = "xlsx") {
  const url = `${BASE}${path}`;
  // #ifdef H5
  const response = await fetch(url, {
    headers: token() ? { Authorization: `Bearer ${token()}` } : {},
  });
  if (!response.ok) {
    const data = await response.json().catch(() => ({}));
    throw new Error(data.message || t("报表下载失败"));
  }
  const blob = await response.blob();
  const objectUrl = URL.createObjectURL(blob);
  const anchor = document.createElement("a");
  anchor.href = objectUrl;
  anchor.download = filename;
  anchor.click();
  setTimeout(() => URL.revokeObjectURL(objectUrl), 300000);
  // #endif
  // #ifdef APP-PLUS
  const result = await new Promise((resolve, reject) =>
    uni.downloadFile({
      url,
      header: token() ? { Authorization: `Bearer ${token()}` } : {},
      success: resolve,
      fail: reject,
    }),
  );
  if (result.statusCode !== 200) throw new Error(t("报表下载失败"));
  await new Promise((resolve, reject) =>
    uni.openDocument({
      filePath: result.tempFilePath,
      fileType,
      showMenu: true,
      success: resolve,
      fail: () =>
        reject(
          new Error(
            t("报表已下载，当前设备无法打开 Excel 文件。可使用销售 H5 下载并查看。"),
          ),
        ),
    }),
  );
  // #endif
}
export const uuid = () =>
  `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`;

import { t } from './i18n.js';
export function publicPostAttachment(file) {
  if (file?.private !== false)
    throw new Error(t("动态仅可使用公开上传的图片或视频"));
  const id = Number(file.id), mime = file.mime || file.mime_type || "";
  if (!Number.isSafeInteger(id) || id < 1 || file.url !== `/api/v1/files/${id}`)
    throw new Error(t("上传文件地址不可用，请重新选择"));
  if (!mime.startsWith("image/") && mime !== "video/mp4")
    throw new Error(t("请选择图片或MP4视频"));
  return { url: file.url, video: mime === "video/mp4" };
}

export async function findBoatFavorite(request, resourceId) {
  for (let page = 1; ; page++) {
    const data = await request(`/favorites?page=${page}&page_size=100`);
    if (!Array.isArray(data?.items) || !Number.isSafeInteger(Number(data.total)) || Number(data.total) < 0)
      throw new Error(t("收藏状态返回信息不完整，请重试"));
    const item = data.items.find(row => Number(row.resource_id) === Number(resourceId) && row.status === "active");
    if (item) {
      if (!Number.isSafeInteger(Number(item.id)) || Number(item.id) < 1)
        throw new Error(t("收藏记录不可用，请刷新后重试"));
      return item;
    }
    if (!data.items.length || page * 100 >= Number(data.total)) return null;
  }
}

export async function toggleBoatFavorite(request, resourceId, favoriteId) {
  if (favoriteId) {
    const result = await request(`/favorites/${encodeURIComponent(favoriteId)}`, { method: "DELETE" });
    if (result?.deleted !== true) throw new Error(t("服务未确认取消收藏，请刷新后重试"));
    return null;
  }
  const result = await request("/favorites", { method: "POST", data: { resource_id: resourceId } });
  if (!Number.isSafeInteger(Number(result?.id)) || Number(result.id) < 1 || Number(result.resource_id) !== Number(resourceId) || result.status !== "active")
    throw new Error(t("服务未确认收藏，请刷新后重试"));
  return result;
}

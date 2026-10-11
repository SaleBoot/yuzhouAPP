export function bannerText(item, locale) {
  const english = locale === "en";
  const copy = item.payload || item;
  return {
    title: english ? copy.title_en || item.title || item.name : item.title || item.name,
    description: english ? copy.description_en || "" : copy.description || "",
  };
}

export function bannerTarget(url, config) {
  if (typeof url !== "string" || /\s/.test(url)) return null;
  if (/^https?:\/\/[^/\s?#]+(?:[/?#][^\s]*)?$/i.test(url)) return { external: url };
  const match = url.match(/^\/pages\/([a-z-]+)(\?[^#\s]*)?$/);
  if (!match || !config.pages.some(page => page.path === `pages/${match[1]}`)) return null;
  const tab = config.tabBar?.list.some(page => page.pagePath === `pages/${match[1]}`);
  // switchTab cannot carry a query. Reject it instead of silently losing the requested destination.
  if (tab && match[2]) return null;
  return { page: match[1], url, tab: Boolean(tab) };
}

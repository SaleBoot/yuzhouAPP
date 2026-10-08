import { readFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import { resolve, extname } from "node:path";
const root = fileURLToPath(new URL("../map/dist/", import.meta.url));
const types = { ".html": "text/html; charset=utf-8", ".js": "text/javascript; charset=utf-8", ".css": "text/css; charset=utf-8", ".png": "image/png", ".map": "application/json" };
export default function mapPreview() {
  return { name: "yuzhou-map-preview", configureServer(server) {
    server.middlewares.use(async (req, res, next) => {
      const request = req.url?.split("?")[0] || "";
      if (!request.startsWith("/boat-map/")) return next();
      try {
        const suffix = decodeURIComponent(request.slice("/boat-map/".length)) || "index.html";
        const file = resolve(root, suffix);
        if (!file.startsWith(root)) { res.statusCode = 403; res.end(); return; }
        const data = await readFile(file);
        res.setHeader("Content-Type", types[extname(file)] || "text/plain; charset=utf-8"); res.end(data);
      } catch { res.statusCode = 404; res.end("Map preview unavailable; run npm --prefix apps/map run build."); }
    });
  } };
}

import { defineConfig } from "vite";
import uni from "@dcloudio/vite-plugin-uni";
import { fileURLToPath, URL } from "node:url";
import mapPreview from "../scripts/map-preview.mjs";
export default defineConfig({
  plugins: [mapPreview(), (typeof uni === "function" ? uni : uni.default)()],
  define: { "import.meta.env.VITE_CLIENT": JSON.stringify("broker") },
  resolve: {
    alias: {
      "qrcode-generator": fileURLToPath(
        new URL("./node_modules/qrcode-generator", import.meta.url),
      ),
      "@shared": fileURLToPath(new URL("../shared", import.meta.url)),
      "@": fileURLToPath(new URL("./src", import.meta.url)),
    },
  },
  server: {
    fs: { allow: [".."] },
    proxy: {
      "/api": {
        target: process.env.VITE_API_PROXY || "http://127.0.0.1:8088",
        changeOrigin: true,
      },
      "/uploads": {
        target: process.env.VITE_API_PROXY || "http://127.0.0.1:8088",
        changeOrigin: true,
      },
    },
  },
});

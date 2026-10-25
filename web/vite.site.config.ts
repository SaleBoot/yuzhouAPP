import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath } from 'node:url'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  return {
    plugins: [vue(), { name: 'desktop-site-entry', configureServer(server) {
      server.middlewares.use((req, _res, next) => {
        const pathname = new URL(req.url || '/', 'http://localhost').pathname
        if (req.method === 'GET' && req.headers.accept?.includes('text/html') && !pathname.includes('.') && !['/api/', '/boat-map/', '/_AMapService/', '/@'].some(prefix => pathname.startsWith(prefix))) req.url = '/site.html'
        next()
      })
    } }],
    base: '/',
    server: {
      host: '127.0.0.1',
      port: 5180,
      proxy: { '/api': { target: env.VITE_API_PROXY || 'http://127.0.0.1:8088', changeOrigin: true }, '/boat-map': { target: env.VITE_API_PROXY || 'http://127.0.0.1:8088', changeOrigin: true }, '/_AMapService': { target: env.VITE_API_PROXY || 'http://127.0.0.1:8088', changeOrigin: true } }
    },
    build: { outDir: 'dist-site', assetsDir: 'site-assets', sourcemap: false, target: 'es2022', rollupOptions: { input: fileURLToPath(new URL('./site.html', import.meta.url)) } }
  }
})

import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  return {
    plugins: [vue()],
    base: '/admin/',
    server: { proxy: { '/api': { target: env.VITE_API_PROXY || 'http://127.0.0.1:8088', changeOrigin: true } } },
    build: { sourcemap: false, target: 'es2022' }
  }
})

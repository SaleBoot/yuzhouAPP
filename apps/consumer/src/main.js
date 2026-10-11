import { createSSRApp } from 'vue'
import { createI18n } from 'vue-i18n'
import App from './App.vue'
import pages from './pages.json'
import { installLanguage } from '@shared/i18n.js'
export function createApp() {
  const app = createSSRApp(App)
  installLanguage(app, createI18n, pages)
  return { app }
}

import { createApp } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import SiteApp from './SiteApp.vue'
import './site.css'

const blank = { template: '<span />' }
const router = createRouter({
  history: createWebHistory('/'),
  scrollBehavior: () => ({ top: 0 }),
  routes: [
    { path: '/', name: 'home', component: blank },
    { path: '/boats', name: 'boats', component: blank },
    { path: '/boats/:id', name: 'boat', component: blank },
    { path: '/market', name: 'market', component: blank },
    { path: '/services', name: 'services', component: blank },
    { path: '/learn', name: 'learn', component: blank },
    { path: '/journal', name: 'journal', component: blank },
    { path: '/resources/:kind/:id', name: 'resource', component: blank },
    { path: '/map', name: 'map', component: blank },
    { path: '/login', name: 'login', component: blank },
    { path: '/account/:tab?', name: 'account', component: blank },
    { path: '/orders/:id?', name: 'orders', component: blank },
    { path: '/checkout', name: 'checkout', component: blank },
    { path: '/sales/:section?/:id?', name: 'sales', component: blank },
    { path: '/:pathMatch(.*)*', name: 'not-found', component: blank }
  ]
})

createApp(SiteApp).use(router).mount('#app')

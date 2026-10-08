import { createRouter, createWebHistory } from 'vue-router'

export const router = createRouter({
  history: createWebHistory('/admin/'),
  routes: [
    { path: '/', component: { template: '<div />' } },
    { path: '/:role(platform|merchant|agency)/:section?', component: { template: '<div />' } },
    { path: '/:pathMatch(.*)*', redirect: '/' }
  ]
})

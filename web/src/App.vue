<script setup lang="ts">
import { computed, ref, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { login, restoreSession, loadIdentity, clearSession, request, session, environment, loadEnvironment, can, type Role } from './api'
import { roleNames, visibleKinds } from './domain.js'
import { resources } from './schemas'
import ResourceView from './components/ResourceView.vue'
import OrdersView from './components/OrdersView.vue'
import CustomersView from './components/CustomersView.vue'
import SettingsView from './components/SettingsView.vue'
import FinanceView from './components/FinanceView.vue'
import DashboardView from './components/DashboardView.vue'
import ApplicationsView from './components/ApplicationsView.vue'
import RefundsView from './components/RefundsView.vue'
import CourseRewardsView from './components/CourseRewardsView.vue'
import ServiceView from './components/ServiceView.vue'
import RedemptionsView from './components/RedemptionsView.vue'
import RolesView from './components/RolesView.vue'
import CommentsView from './components/CommentsView.vue'

const route=useRoute(),router=useRouter()
const role=computed(()=>Object.hasOwn(roleNames,String(route.params.role))?String(route.params.role) as Role:null)
const section=computed(()=>String(route.params.section||'dashboard'))
const username=ref(''),password=ref(''),error=ref(''),busy=ref(false),restoring=ref(false),sidebarOpen=ref(false)
const names=roleNames as Record<string,string>
const menus=computed(()=>{
  const basic=[{key:'dashboard',label:'工作台',symbol:'▦',group:'业务总览'},{key:'orders',label:'订单与合同',symbol:'▤',group:'业务总览'}]
  if(role.value==='agency'||role.value==='platform')basic.push({key:'customers',label:'客户跟进',symbol:'♙',group:'业务总览'})
  basic.push({key:'appointments',label:'带看预约',symbol:'◷',group:'客户服务'},{key:'conversations',label:'咨询会话',symbol:'☏',group:'客户服务'},{key:'notifications',label:'消息通知',symbol:'◇',group:'客户服务'});if(role.value==='platform')basic.push({key:'feedback',label:'意见反馈',symbol:'✎',group:'客户服务'})
  const kinds=visibleKinds(role.value||'')
  for(const key of kinds){if(resources[key])basic.push({key,label:resources[key].title,symbol:key==='boats'?'⚓':key==='users'?'♙':'▱',group:['users','organizations'].includes(key)?'组织与权限':'内容与资源'})}
  basic.push({key:'ledger',label:'佣金分红账本',symbol:'¥',group:'财务与结算'})
  if(role.value==='platform'){basic.push({key:'applications',label:'入驻与实名审核',symbol:'✓',group:'组织与权限'},{key:'comments',label:'评论审核',symbol:'✓',group:'内容与资源'},{key:'roles',label:'后台角色权限',symbol:'⌘',group:'组织与权限'},{key:'redemptions',label:'积分兑换履约',symbol:'◇',group:'内容与资源'},{key:'course-rewards',label:'课程奖励发放',symbol:'◇',group:'内容与资源'},{key:'refunds',label:'退款申请处理',symbol:'↶',group:'财务与结算'},{key:'withdrawals',label:'提现审核',symbol:'⇄',group:'财务与结算'},{key:'settings',label:'分红结算配置',symbol:'⚙',group:'财务与结算'},{key:'audit',label:'操作审计',symbol:'◷',group:'组织与权限'})}
  const permissions:Record<string,string>={orders:'orders',customers:'customers',ledger:'finance',refunds:'finance',withdrawals:'finance',settings:'settings',users:'users',organizations:'organizations',applications:'applications',audit:'users',roles:'rbac',comments:'community',redemptions:'operations','course-rewards':'learning',growth_products:'operations',banners:'operations',posts:'community',appointments:'communication',conversations:'communication',notifications:'communication',feedback:'communication'}
  return basic.filter(menu=>menu.key==='dashboard'||can(permissions[menu.key]||'catalog'))
})
const groups=computed(()=>[...new Set(menus.value.map(menu=>menu.group))])
const currentMenu=computed(()=>menus.value.find(menu=>menu.key===section.value))
function navigate(key:string){if(role.value){router.push(`/${role.value}/${key}`);sidebarOpen.value=false}}
async function signIn(){if(!role.value||busy.value)return;busy.value=true;error.value='';try{await login(role.value,username.value.trim(),password.value);password.value=''}catch(err){error.value=(err as Error).message}finally{busy.value=false}}
async function signOut(){try{await request('/auth/logout',{method:'POST'})}catch{/* Local logout still removes the session. */}clearSession();password.value=''}
onMounted(()=>loadEnvironment().catch(()=>{}))
watch(role,async value=>{error.value='';password.value='';if(!value){clearSession();return}restoreSession(value);if(!session.token)return;restoring.value=true;try{await loadIdentity()}catch(err){clearSession();error.value=(err as Error).message}finally{restoring.value=false}}, {immediate:true})
</script>

<template>
  <div v-if="!role" class="entrance-page"><div class="entrance-brand"><span class="brand-mark">驭</span><span>驭舟<span class="brand-sub">YUZHOU</span></span></div><h1>选择业务管理入口</h1><p>使用对应组织和权限的管理账号登录。</p><div class="entrance-grid"><RouterLink v-for="(name,key) in names" :key="key" :to="`/${key}`" class="entrance-card"><span class="entrance-icon">{{ key==='platform'?'▦':key==='merchant'?'⚓':'♙' }}</span><h2>{{ name }}</h2><p>{{ key==='platform'?'平台业务审核、交易与结算':key==='merchant'?'船艇发布、服务与自身销售订单':'公司团队、客户及销售履约' }}</p><strong>进入管理 →</strong></RouterLink></div><small>消费者与实际销售经纪人请使用各自独立 App。</small></div>
  <div v-else-if="!session.user" class="login-page"><div class="login-art"><div class="entrance-brand"><span class="brand-mark">驭</span><span>驭舟<span class="brand-sub">YUZHOU</span></span></div><div class="login-message"><span class="eyebrow">{{ names[role] }}</span><h1>让每一程<br />航行有序。</h1><p>船艇、客户、合同与履约，<br />在同一业务空间清晰管理。</p><div class="wave-lines"><i /><i /><i /></div></div><span class="login-domain">驭舟业务管理 · YUZHOU</span></div><main class="login-content"><RouterLink to="/" class="back-link">← 返回入口选择</RouterLink><form class="login-form" @submit.prevent="signIn"><span class="eyebrow">欢迎登录</span><h2>{{ names[role] }}</h2><p>请使用当前入口对应的管理账号。</p><p v-if="error" class="notice danger" role="alert">{{ error }}</p><label><span>登录账号</span><input v-model="username" autocomplete="username" placeholder="输入账号" required :disabled="busy||restoring" /></label><label><span>密码</span><input v-model="password" type="password" autocomplete="current-password" placeholder="输入密码" required minlength="8" :disabled="busy||restoring" /></label><button class="button primary login-submit" :disabled="busy||restoring">{{ restoring?'正在恢复会话…':busy?'正在登录…':'登录管理后台' }}</button><small>账号角色与组织由服务端核验，入口不能改变账号权限。</small></form></main></div>
  <div v-else class="app-shell"><div v-if="sidebarOpen" class="sidebar-shade" @click="sidebarOpen=false" /><aside class="sidebar" :class="{open:sidebarOpen}"><RouterLink :to="`/${role}`" class="brand"><span class="brand-mark">驭</span><span>驭舟<span class="brand-sub">YUZHOU</span></span><small>管理系统</small></RouterLink><div class="role-chip"><span class="online-dot" />{{ names[role] }}</div><nav aria-label="业务导航"><div v-for="group in groups" :key="group" class="nav-group"><h3>{{ group }}</h3><button v-for="menu in menus.filter(menu=>menu.group===group)" :key="menu.key"  :aria-label="menu.label" :class="{active:section===menu.key}" @click="navigate(menu.key)"><span>{{ menu.symbol }}</span>{{ menu.label }}<i v-if="section===menu.key" /></button></div></nav><footer class="sidebar-footer"><span class="online-dot" />数据按组织授权<small>驭舟 · 业务管理</small></footer></aside><div class="main-shell"><header class="topbar"><div class="breadcrumbs"><button class="icon-button menu-toggle" aria-label="打开菜单" @click="sidebarOpen=true">☰</button><span>{{ names[role] }}</span><span>/</span><strong>{{ currentMenu?.label||'页面不可用' }}</strong></div><div class="topbar-account"><span class="environment-tag" :class="{'test-environment':environment.isTest}">{{ environment.name?(environment.isTest?'开发测试环境':'正式环境'):'环境待确认' }}</span><span class="avatar">{{ (session.user.nickname||session.user.name||session.user.username||'管').slice(0,1) }}</span><span>{{ session.user.nickname||session.user.name||session.user.username }}</span><button class="text-button muted" @click="signOut">退出</button></div></header><main class="main-content"><p class="scope-banner"><span class="online-dot" />{{ role==='platform'?'平台管理权限':`组织 #${session.user.org_id||'—'} · 仅授权范围数据` }}<span>权限由服务端实时核验</span></p><DashboardView v-if="section==='dashboard'" @navigate="navigate" /><OrdersView v-else-if="section==='orders'" /><CustomersView v-else-if="section==='customers'&&currentMenu" /><ResourceView v-else-if="resources[section]&&currentMenu" :key="section" :resource="resources[section]" /><CommentsView v-else-if="section==='comments'&&role==='platform'&&currentMenu" /><RolesView v-else-if="section==='roles'&&role==='platform'&&currentMenu" /><RedemptionsView v-else-if="section==='redemptions'&&role==='platform'&&currentMenu" /><ServiceView v-else-if="['appointments','conversations','feedback','notifications'].includes(section)&&currentMenu" :mode="section as 'appointments'|'conversations'|'feedback'|'notifications'" /><CourseRewardsView v-else-if="section==='course-rewards'&&role==='platform'&&currentMenu" /><RefundsView v-else-if="section==='refunds'&&role==='platform'&&currentMenu" /><ApplicationsView v-else-if="section==='applications'&&role==='platform'&&currentMenu" /><SettingsView v-else-if="section==='settings'&&role==='platform'&&currentMenu" /><FinanceView v-else-if="['ledger','withdrawals','audit'].includes(section)&&currentMenu" :mode="section as 'ledger'|'withdrawals'|'audit'" /><section v-else class="panel empty"><h2>当前入口无此页面</h2><p>请从左侧菜单进入授权业务。</p><button class="button" @click="navigate('dashboard')">返回工作台</button></section></main><footer class="main-footer"><span>驭舟 · 业务管理</span><span>船艇交易与服务平台</span></footer></div></div>
</template>

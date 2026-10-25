<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { activateRole, listResources, login, logout, registerConsumer, request, siteSession, type SiteRole } from './api'
import { safeWebsiteReturn } from './session-domain.js'
import { imageUrl, minimumPurchasePrice } from './catalog.js'
import { validDetailMessage } from '../../../apps/map/model.js'
import SiteIcon from './SiteIcon.vue'
import ResourceCard from './ResourceCard.vue'
import SiteCatalog from './SiteCatalog.vue'
import SiteDetail from './SiteDetail.vue'
import ConsumerWorkspace from './ConsumerWorkspace.vue'
import BrokerWorkspace from './BrokerWorkspace.vue'

const route = useRoute(), router = useRouter()
const nav = [{to:'/boats',label:'船艇租售'},{to:'/services',label:'维保升级'},{to:'/market',label:'周边商城'},{to:'/learn',label:'航海课堂'},{to:'/journal',label:'航海生活'}]
const salesNav = [{section:'overview',path:'/sales',label:'工作概览',icon:'grid'},{section:'customers',path:'/sales/customers',label:'客户管理',icon:'user'},{section:'quotes',path:'/sales/quotes',label:'配置与报价',icon:'file'},{section:'orders',path:'/sales/orders',label:'销售订单',icon:'ship'},{section:'appointments',path:'/sales/appointments',label:'带看预约',icon:'clock'},{section:'ledger',path:'/sales/ledger',label:'结算账本',icon:'bag'},{section:'messages',path:'/sales/messages',label:'客户会话',icon:'message'}]
const mobileMenu = ref(false), authError = ref(''), authBusy = ref(false), registering = ref(false), signOutError = ref('')
const form = reactive({username:'',password:'',nickname:'',invitation_code:''})
const homeSearch = reactive({search:'',mode:'rent'})
const home = reactive<{boats:any[];actual:any[];products:any[];posts:any[];banners:any[];error:string;loading:boolean}>({boats:[],actual:[],products:[],posts:[],banners:[],error:'',loading:false})
const config = ref<any>({})
const mapFrame = ref<HTMLIFrameElement | null>(null)
const isSales = computed(() => route.name === 'sales')
const wantedRole = computed<SiteRole>(() => isSales.value || (route.name === 'login' && route.query.as === 'broker') ? 'broker' : 'consumer')
const protectedPage = computed(() => ['sales','account','orders','checkout'].includes(String(route.name)))
const loginPage = computed(() => route.name === 'login' || (protectedPage.value && !siteSession.user))
const catalogKind = computed(() => ({boats:'boat_types',market:'products',learn:'courses',services:route.query.kind==='upgrades'?'upgrades':'maintenance',journal:route.query.kind==='events'?'events':'posts'} as Record<string,string>)[String(route.name)])
const detailKind = computed(() => route.name === 'boat' ? 'boat_types' : String(route.params.kind || ''))
const detailAllowed = computed(() => ['boat_types','boats','products','courses','events','posts','upgrades','maintenance'].includes(detailKind.value))
const accountName = computed(() => siteSession.user?.nickname || siteSession.user?.username || '个人中心')
const hero = computed(() => imageUrl(home.banners.find(x=>x.payload?.placement==='home')?.payload?.image_urls?.[0] || home.banners[0]?.payload?.image_urls?.[0]))
const vr = computed(() => imageUrl(config.value.vr_base_url))
const salesSection = computed(() => String(route.params.section || 'overview'))
const consumerPage = computed(() => route.name==='orders' ? (route.params.id?'order':'orders') : String(route.name))
let homeStarted = false

async function loadHome() {
  home.loading=true;home.error=''
  try {
    const [boats,actual,products,posts,banners]=await Promise.all(['boat_types','boats','products','posts','banners'].map(listResources))
    Object.assign(home,{boats,actual,products,posts,banners})
  } catch(e) { home.error=(e as Error).message }
  finally { home.loading=false }
}
function searchBoats() { router.push({path:'/boats',query:{search:homeSearch.search||undefined,mode:homeSearch.mode}}) }
async function signIn() {
  if(authBusy.value)return
  authBusy.value=true;authError.value=''
  try {
    if(registering.value&&wantedRole.value==='consumer') await registerConsumer({...form})
    else await login(wantedRole.value,form.username.trim(),form.password)
    form.password=''
    const fallback=wantedRole.value==='broker'?'/sales':'/account'
    if(route.name==='login') await router.replace(safeWebsiteReturn(route.query.return,fallback))
  } catch(e) { authError.value=(e as Error).message }
  finally { authBusy.value=false }
}
async function signOut() {
  signOutError.value=''
  try { await logout() } catch(e) { signOutError.value=(e as Error).message }
  await router.push(wantedRole.value==='broker'?'/sales':'/')
}
watch(wantedRole,async role=>{authError.value='';registering.value=false;form.password='';try{await activateRole(role)}catch(e){if(wantedRole.value===role)authError.value=(e as Error).message}if(wantedRole.value===role){if(!homeStarted){homeStarted=true;loadHome()}request('/bootstrap').then(data=>{config.value=data}).catch(()=>{})}},{immediate:true})
watch(()=>route.fullPath,()=>{mobileMenu.value=false;authError.value='';form.password='';document.title=isSales.value?'驭舟 · 销售工作台':'驭舟 · 船艇与航海生活'})
function mapMessage(event: MessageEvent) {
  if (event.origin !== location.origin || event.source !== mapFrame.value?.contentWindow || !validDetailMessage(event.data)) return
  router.push({ path: `/boats/${event.data.boat_type_id}`, query: { mode: 'buy', instance_id: event.data.boat_id } })
}
onMounted(()=>window.addEventListener('message',mapMessage))
onUnmounted(()=>window.removeEventListener('message',mapMessage))
</script>

<template>
  <div class="site"><a class="skip-link" href="#main-content">跳转到主要内容</a><div class="site-topline"><div class="site-container"><span>船艇租售 · 航海生活平台</span><div><RouterLink to="/sales">销售工作台</RouterLink><a href="/admin/">业务管理</a></div></div></div>
    <header class="site-header"><div class="site-container site-header-inner"><RouterLink to="/" class="site-brand" aria-label="驭舟首页"><span class="site-brand-mark"><SiteIcon name="ship" :size="30" /></span><span><strong>驭舟</strong><small>YUZHOU</small></span></RouterLink><nav :class="['site-navigation',{open:mobileMenu}]" aria-label="网站主导航"><RouterLink v-for="item in nav" :key="item.to" :to="item.to" :class="{selected:route.path.startsWith(item.to)}">{{ item.label }}</RouterLink></nav><div class="site-header-actions"><RouterLink to="/map" class="header-map"><SiteIcon name="map" :size="19" /> 地图找船</RouterLink><template v-if="siteSession.user"><RouterLink :to="wantedRole==='broker'?'/sales':'/account'" class="header-account"><SiteIcon name="user" :size="20" /><span>{{ accountName }}</span></RouterLink><button class="text-button" @click="signOut">退出</button></template><RouterLink v-else :to="{path:'/login',query:{as:wantedRole==='broker'?'broker':undefined,return:route.fullPath!=='/login'?route.fullPath:undefined}}" class="button primary small">登录 / 注册</RouterLink><button class="menu-button" :aria-expanded="mobileMenu" aria-label="展开导航" @click="mobileMenu=!mobileMenu"><SiteIcon name="menu" /></button></div></div></header>
    <p v-if="signOutError" class="site-container notice danger" role="alert">{{ signOutError }}</p>
    <main id="main-content">
      <template v-if="route.name==='home'">
        <section class="website-hero" :style="hero?{backgroundImage:`linear-gradient(90deg,rgba(7,32,52,.95) 0%,rgba(7,32,52,.75) 38%,rgba(7,32,52,.08) 80%),url('${hero}')`}:{}"><div class="site-container hero-content"><div class="hero-kicker"><span /> EXPLORE A LIFE AT SEA</div><h1>下一段航程，<br />从这里启航。</h1><p>发现合适的船艇，安排安心的航程。<br />从租售到维保，让每一次出发更从容。</p><div class="actions"><RouterLink to="/boats" class="button primary">探索船艇 <SiteIcon name="arrow" /></RouterLink><a v-if="vr" :href="vr" target="_blank" rel="noopener" class="hero-secondary"><SiteIcon name="compass" /> 体验 3D 看船</a></div><div class="hero-footnote"><span>船型与实船资料</span><i /> <span>按日租赁</span><i /><span>订单全程跟进</span></div></div></section>
        <div class="site-container home-search-wrap"><form class="home-search" @submit.prevent="searchBoats"><div class="search-intent"><button type="button" :class="{selected:homeSearch.mode==='rent'}" @click="homeSearch.mode='rent'">我要租船</button><button type="button" :class="{selected:homeSearch.mode==='buy'}" @click="homeSearch.mode='buy'">我要买船</button></div><label><SiteIcon name="search" /><input v-model="homeSearch.search" aria-label="搜索船型名称或型号" placeholder="搜索船型名称或型号，开启你的航程" /></label><button class="button primary">搜索船艇 <SiteIcon name="arrow" :size="18" /></button></form></div>
        <section class="site-container home-services" aria-label="航海服务"><RouterLink to="/boats"><span class="service-icon"><SiteIcon name="ship" :size="29" /></span><div><h2>船艇租售</h2><p>从船型了解，到实际船艇选择</p></div><SiteIcon name="arrow" :size="19" /></RouterLink><RouterLink to="/services"><span class="service-icon"><SiteIcon name="tool" :size="29" /></span><div><h2>维保升级</h2><p>为船艇安排养护与升级服务</p></div><SiteIcon name="arrow" :size="19" /></RouterLink><RouterLink to="/learn"><span class="service-icon"><SiteIcon name="book" :size="29" /></span><div><h2>航海课堂</h2><p>学习知识，从容探索水上生活</p></div><SiteIcon name="arrow" :size="19" /></RouterLink></section>
        <section class="site-container home-section"><div class="section-heading"><div><span class="eyebrow">FIND YOUR NEXT BOAT</span><h2>精选船艇</h2><p>一览船型参数、图片与租售信息，找到适合你的选择。</p></div><RouterLink to="/boats" class="text-link">浏览全部船艇 <SiteIcon name="arrow" :size="19" /></RouterLink></div><p v-if="home.error" class="notice danger" role="alert">{{ home.error }} <button class="text-button" @click="loadHome">重试</button></p><div v-if="home.loading" class="panel empty" role="status">正在载入船艇资料…</div><div v-else class="resource-grid"><ResourceCard v-for="item in home.boats.slice(0,6)" :key="item.id" :item="item" :purchase-price="minimumPurchasePrice(item.id,home.actual)" /></div><p v-if="!home.loading&&!home.error&&!home.boats.length" class="panel empty">暂无已发布的船艇资料。</p></section>
        <section class="home-feature-band"><div class="site-container feature-band-inner"><div><span class="eyebrow">MORE THAN A JOURNEY</span><h2>从认识一艘船，<br />到开启一种生活。</h2><p>通过 3D 了解船艇细节，在地图中浏览已发布位置，<br />也可以预约看船，与销售进一步沟通。</p><div class="actions"><RouterLink to="/map" class="button primary"><SiteIcon name="map" /> 地图找船</RouterLink><a v-if="vr" :href="vr" target="_blank" rel="noopener" class="button">进入 3D 看船平台 <SiteIcon name="arrow" :size="18" /></a></div></div><div class="feature-steps"><div><span>01</span><h3>了解船艇</h3><p>浏览船型参数、图片与实际船艇。</p></div><div><span>02</span><h3>安排出行</h3><p>查看租赁日期、选择船艇或预约服务。</p></div><div><span>03</span><h3>跟进订单</h3><p>在个人中心查看报价、合同与履约。</p></div></div></div></section>
        <section class="site-container home-section" v-if="home.products.length"><div class="section-heading"><div><span class="eyebrow">ESSENTIALS FOR THE WATER</span><h2>航海周边</h2><p>探索平台直营的航海装备与日常周边。</p></div><RouterLink to="/market" class="text-link">进入周边商城 <SiteIcon name="arrow" :size="19" /></RouterLink></div><div class="resource-grid"><ResourceCard v-for="item in home.products.slice(0,3)" :key="item.id" :item="item" /></div></section>
        <section class="site-container home-section journal-section" v-if="home.posts.length"><div class="section-heading"><div><span class="eyebrow">STORIES & INSPIRATION</span><h2>航海生活</h2><p>关于船艇、出行与水上生活的更多内容。</p></div><RouterLink to="/journal" class="text-link">阅读更多 <SiteIcon name="arrow" :size="19" /></RouterLink></div><div class="resource-grid"><ResourceCard v-for="item in home.posts.slice(0,3)" :key="item.id" :item="item" /></div></section>
      </template>
      <template v-else-if="loginPage"><div class="site-container desktop-login"><div class="desktop-login-intro"><span class="eyebrow">{{ wantedRole==='broker'?'SALES WORKSPACE':'WELCOME ABOARD' }}</span><h1>{{ wantedRole==='broker'?'每一步销售，\n都清晰有序。':'把下一段航程，\n交给更好的安排。' }}</h1><p>{{ wantedRole==='broker'?'在销售工作台跟进客户、配置报价、管理合同与交付。':'登录后管理你的订单、预约、学习与航海生活。' }}</p><div><SiteIcon :name="wantedRole==='broker'?'file':'compass'" :size="120" /></div><RouterLink :to="wantedRole==='broker'?'/login':'/sales'" class="text-link">{{ wantedRole==='broker'?'我是普通用户 →':'我是销售经纪人 →' }}</RouterLink></div><form class="desktop-login-form panel" @submit.prevent="signIn"><span class="eyebrow">{{ wantedRole==='broker'?'销售经纪人入口':'驭舟账户' }}</span><h2>{{ wantedRole==='broker'?'登录销售工作台':registering?'注册账户':'欢迎回来' }}</h2><p class="muted">{{ registering?'注册后开始探索船艇与航海服务。':'使用已有驭舟账号，继续你的航海生活。' }}</p><p v-if="authError" class="notice danger" role="alert">{{ authError }}</p><label>登录账号<input v-model="form.username" name="username" autocomplete="username" required minlength="3" maxlength="120" placeholder="请输入账号" :disabled="authBusy||siteSession.restoring" /></label><label v-if="registering">昵称<input v-model="form.nickname" maxlength="120" placeholder="希望如何称呼你" :disabled="authBusy" /></label><label>密码<input v-model="form.password" name="password" type="password" :autocomplete="registering?'new-password':'current-password'" required minlength="8" maxlength="72" placeholder="请输入密码" :disabled="authBusy||siteSession.restoring" /></label><label v-if="registering">邀请码（选填）<input v-model="form.invitation_code" maxlength="16" placeholder="如有邀请码请填写" :disabled="authBusy" /></label><button class="button primary login-action" :disabled="authBusy||siteSession.restoring">{{ siteSession.restoring?'正在恢复登录…':authBusy?'正在处理…':registering?'注册并登录':'登录' }} <SiteIcon name="arrow" :size="19" /></button><div v-if="wantedRole==='consumer'" class="login-switch"><span>{{ registering?'已有账户？':'还没有账户？' }}</span><button type="button" class="text-button" :disabled="authBusy" @click="registering=!registering;authError=''">{{ registering?'返回登录':'注册账户' }}</button></div><p v-else class="login-role-hint">销售账号由经纪公司管理，请使用本人销售账号。</p></form></div></template>
      <SiteCatalog v-else-if="catalogKind" :key="String(route.name)" :kind="catalogKind" />
      <SiteDetail v-else-if="['boat','resource'].includes(String(route.name))&&detailAllowed" :key="detailKind+route.params.id" :kind="detailKind" :id="String(route.params.id)" />
      <div v-else-if="isSales" class="sales-shell site-container"><aside class="sales-sidebar"><div><span class="eyebrow">SALES CENTER</span><h2>销售工作台</h2><p>{{ accountName }}</p></div><nav aria-label="销售工作台导航"><RouterLink v-for="item in salesNav" :key="item.path" :to="item.path" :class="{selected:salesSection===item.section}"><SiteIcon :name="item.icon" :size="20" />{{ item.label }}</RouterLink></nav><span class="sales-sidebar-note">客户、订单与履约<br />在一个工作空间持续跟进</span></aside><div class="sales-main"><BrokerWorkspace :section="salesSection" :id="String(route.params.id||'')" /></div></div>
      <div v-else-if="['account','orders','checkout'].includes(String(route.name))" class="site-container account-workspace"><ConsumerWorkspace :page="consumerPage" :id="String(route.params.id||route.query.id||'')" :kind="String(route.query.kind||'')" /></div>
      <div v-else-if="route.name==='map'" class="site-container website-map"><div class="page-heading"><div><span class="eyebrow">EXPLORE BY LOCATION</span><h1>地图找船</h1><p>查看发布方维护的船艇位置，浏览相应船型和实船资料。</p></div><RouterLink to="/boats" class="button">返回船艇列表</RouterLink></div><iframe ref="mapFrame" src="/boat-map/?client=consumer&lang=zh-Hans" title="船艇位置地图" referrerpolicy="same-origin" /></div>
      <div v-else class="site-container panel empty"><h1>页面未找到</h1><p>你访问的页面可能已更换地址。</p><RouterLink to="/" class="button primary">返回首页</RouterLink></div>
    </main>
    <footer class="site-footer"><div class="site-container footer-main"><div><RouterLink to="/" class="site-brand"><span class="site-brand-mark"><SiteIcon name="ship" :size="28" /></span><span><strong>驭舟</strong><small>YUZHOU</small></span></RouterLink><p>连接船艇与航海生活。<br />让每一次出发，都有更好的安排。</p></div><div><h3>探索驭舟</h3><RouterLink to="/boats">船艇租售</RouterLink><RouterLink to="/services">维保升级</RouterLink><RouterLink to="/learn">航海课堂</RouterLink></div><div><h3>我的航程</h3><RouterLink to="/orders">我的订单</RouterLink><RouterLink to="/account?tab=appointments">看船预约</RouterLink><RouterLink to="/account">个人中心</RouterLink></div><div><h3>业务入口</h3><RouterLink to="/sales">销售工作台</RouterLink><a href="/admin/merchant/">商家后台</a><a href="/admin/agency/">经纪公司后台</a><a href="/admin/platform/">平台后台</a></div></div><div class="site-container footer-bottom"><span>© {{ new Date().getFullYear() }} 驭舟</span><span>船艇交易与航海生活服务平台</span></div></footer>
  </div>
</template>

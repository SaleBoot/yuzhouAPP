<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { listResources } from './api'
import { catalogFilter, catalogNames, categoryNames, minimumPurchasePrice } from './catalog.js'
import ResourceCard from './ResourceCard.vue'
import SiteIcon from './SiteIcon.vue'
const props = defineProps<{ kind: string }>()
const route = useRoute(), router = useRouter()
const items = ref<any[]>([]), boats = ref<any[]>([]), error = ref(''), busy = ref(false), page = ref(1)
const filters = reactive({ search: '', city: '', category: '', mode: 'rent', sort: 'default' })
const title = computed(() => (catalogNames as Record<string, string>)[props.kind] || '航海生活')
const categories = computed(() => [...new Set(items.value.map(x => String(x.payload?.category || '')).filter(Boolean))])
const prices = computed(() => Object.fromEntries(items.value.map(x => [x.id, minimumPurchasePrice(x.id, boats.value)])))
const filtered = computed(() => catalogFilter(items.value, filters, prices.value))
const visible = computed(() => filtered.value.slice((page.value - 1) * 12, page.value * 12))
let loadId = 0
async function load() {
  const id = ++loadId
  busy.value = true; error.value = ''
  try {
    const [rows, actual] = await Promise.all([listResources(props.kind), props.kind === 'boat_types' ? listResources('boats') : Promise.resolve([])])
    if (id === loadId) { items.value = rows; boats.value = actual }
  } catch (e) { if (id === loadId) error.value = (e as Error).message }
  finally { if (id === loadId) busy.value = false }
}
function search() { page.value = 1; router.replace({ query: { ...route.query, search: filters.search || undefined, city: filters.city || undefined } }) }
watch(() => props.kind, load, { immediate: true })
watch(() => route.query, value => { filters.search = String(value.search || ''); filters.city = String(value.city || ''); filters.mode = value.mode === 'buy' ? 'buy' : 'rent'; page.value = 1 }, { immediate: true })
watch(() => [filters.category, filters.sort, filters.mode], () => { page.value = 1 })
</script>
<template>
  <div class="site-container catalog-page"><nav class="breadcrumbs"><RouterLink to="/">首页</RouterLink><span>/</span><span>{{ title }}</span></nav><div class="page-heading"><div><span class="eyebrow">EXPLORE YUZHOU</span><h1>{{ title }}</h1><p>{{ kind==='boat_types'?'从船型参数到实际船艇，为下一段航程找到合适的选择。':kind==='products'?'发现航海装备与日常周边。':kind==='courses'?'在每一次学习中，让出航更加从容。':kind==='posts'?'了解航海知识与船艇生活。':'查看已发布的服务与活动安排。' }}</p></div><RouterLink v-if="kind==='boat_types'" to="/map" class="button"><SiteIcon name="map" /> 地图找船</RouterLink></div>
    <div class="catalog-switch" v-if="kind==='boat_types'"><button :class="{active:filters.mode==='rent'}" @click="filters.mode='rent';router.replace({query:{...route.query,mode:'rent'}})">按日租船</button><button :class="{active:filters.mode==='buy'}" @click="filters.mode='buy';router.replace({query:{...route.query,mode:'buy'}})">选择购船</button></div>
    <div class="catalog-switch" v-if="['maintenance','upgrades'].includes(kind)"><RouterLink to="/services" :class="{active:kind==='maintenance'}">维修养护</RouterLink><RouterLink to="/services?kind=upgrades" :class="{active:kind==='upgrades'}">船艇升级</RouterLink></div>
    <div class="catalog-switch" v-if="['posts','events'].includes(kind)"><RouterLink to="/journal" :class="{active:kind==='posts'}">航海资讯</RouterLink><RouterLink to="/journal?kind=events" :class="{active:kind==='events'}">航海活动</RouterLink></div>
    <form class="catalog-search panel" @submit.prevent="search"><label class="search-field"><SiteIcon name="search" /><input v-model="filters.search" aria-label="搜索名称或型号" placeholder="搜索名称或型号" /></label><label v-if="kind==='boat_types'"><span class="sr-only">城市或泊位</span><input v-model="filters.city" aria-label="城市或泊位" placeholder="城市或泊位" /></label><button class="button primary">搜索</button></form>
    <div v-if="categories.length" class="category-filter"><span>船艇分类</span><button :class="{active:!filters.category}" @click="filters.category=''">全部</button><button v-for="category in categories" :key="category" :class="{active:filters.category===category}" @click="filters.category=category">{{ (categoryNames as Record<string,string>)[category]||category }}</button></div>
    <div class="catalog-toolbar"><span>共 <strong>{{ filtered.length }}</strong> 项{{ filters.mode==='buy'&&kind==='boat_types'?'船型，可在详情中选择实际船艇':'' }}</span><div class="actions"><button class="text-button" :disabled="busy" @click="load">刷新</button><select v-model="filters.sort" aria-label="排序方式"><option value="default">默认排序</option><option v-if="!['courses','posts'].includes(kind)" value="price_asc">价格从低到高</option><option v-if="!['courses','posts'].includes(kind)" value="price_desc">价格从高到低</option></select></div></div>
    <p v-if="error" class="notice danger" role="alert">{{ error }} <button class="text-button" @click="load">重新加载</button></p><div v-if="busy" class="panel empty" role="status">正在载入资料…</div><div v-else-if="!visible.length&&!error" class="panel empty"><SiteIcon name="compass" :size="42" /><h2>暂无符合条件的内容</h2><p>调整搜索条件，或稍后再来看看。</p></div><div v-else class="resource-grid"><ResourceCard v-for="item in visible" :key="item.id" :item="item" :mode="filters.mode" :purchase-price="prices[item.id]" /></div>
    <div v-if="filtered.length>12" class="pagination"><span>第 {{ page }} / {{ Math.ceil(filtered.length/12) }} 页</span><button class="button" :disabled="page===1" @click="page--">上一页</button><button class="button" :disabled="page*12>=filtered.length" @click="page++">下一页</button></div>
  </div>
</template>

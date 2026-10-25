<script setup lang="ts">
import { computed, ref } from 'vue'
import { money, displayTitle } from '../domain.js'
import { imageUrl, resourcePath, categoryNames } from './catalog.js'
import SiteIcon from './SiteIcon.vue'
const props = withDefaults(defineProps<{ item: any; mode?: string; purchasePrice?: number | null }>(), { mode: 'rent', purchasePrice: null })
const failed = ref(false)
const picture = computed(() => imageUrl(props.item.payload?.image_urls?.[0] || props.item.payload?.image_url))
const path = computed(() => resourcePath(props.item) + (props.item.kind === 'boat_types' ? `?mode=${props.mode}` : ''))
const price = computed(() => props.item.kind === 'boat_types' ? (props.mode === 'buy' ? props.purchasePrice : props.item.payload?.daily_price_cents ?? props.item.price_cents) : props.item.price_cents)
const category = computed(() => (categoryNames as Record<string, string>)[props.item.payload?.category] || props.item.payload?.category)
</script>
<template>
  <RouterLink :to="path" class="resource-card">
    <div class="resource-picture"><img v-if="picture&&!failed" :src="picture" :alt="item.title" loading="lazy" @error="failed=true" /><div v-else class="picture-placeholder"><SiteIcon :name="item.kind==='courses'?'book':item.kind==='products'?'bag':'ship'" :size="48" /></div><span v-if="category" class="picture-label">{{ category }}</span></div>
    <div class="resource-card-content"><h3>{{ displayTitle(item.title) }}</h3><p class="resource-caption" v-if="item.kind==='boat_types'"><span v-if="item.payload?.length_m">{{ item.payload.length_m }} 米</span><span v-if="item.payload?.capacity">核定 {{ item.payload.capacity }} 人</span><span v-if="!item.payload?.length_m&&!item.payload?.capacity">{{ item.payload?.model_code||'查看船型资料' }}</span></p><p class="resource-caption" v-else>{{ item.payload?.description || item.payload?.location || (item.kind==='courses'?'了解课程内容与学习安排':'查看详细内容') }}</p><div class="resource-card-bottom"><span v-if="item.kind==='courses'" class="card-price subtle">查看课程</span><span v-else-if="item.kind==='posts'" class="card-price subtle">阅读资讯</span><span v-else-if="price===null||price===undefined" class="card-price subtle">价格待确认</span><span v-else class="card-price">{{ money(price) }}<small v-if="item.kind==='boat_types'&&mode==='rent'"> / 天</small><small v-if="item.kind==='boat_types'&&mode==='buy'"> 起</small></span><SiteIcon name="arrow" :size="19" /></div></div>
  </RouterLink>
</template>

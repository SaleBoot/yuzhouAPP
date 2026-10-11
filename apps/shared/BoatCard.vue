<template>
  <view class="boat-card" :class="{ horizontal }" @click="$emit('open', boat)">
    <view class="boat-cover"
      ><image
        :src="boat.image_url || boat.cover_url || fallbackBoat"
        mode="aspectFill"
      /><text class="rating">☆ {{ boat.rating || t("精选") }}</text></view
    >
    <view class="boat-content"
      ><text class="boat-name">{{ boat.name }}</text
      ><text class="muted"
        >♧ {{ boat.capacity || "—" }}{{ t("人　◉") }}{{ boat.speed_knots || "—" }}{{ t("节") }}</text
      ><text class="muted"
        >⌖ {{ boat.city || boat.location || t("码头待确认") }}</text
      ><view class="row spread"
        ><text v-if="mode === 'buy'" class="price">{{ t("实船报价") }}</text
        ><text v-else class="price"
          >¥ {{ money(boat.daily_price_cents || boat.price_cents)
          }}<text class="unit">{{ t("/天") }}</text></text
        ><button class="round-action" @click.stop="$emit('open', boat)">
          {{ mode === "buy" ? t("购买") : t("预订") }}
        </button></view
      ><button
        v-if="compare"
        class="compare-link"
        @click.stop="$emit('compare', boat)"
      >
        {{ selected ? t("移出比对") : t("＋ 加入比对") }}
      </button></view
    >
  </view>
</template>
<script setup>
import { t } from './i18n.js';

import { fallbackBoat } from "./api.js";
import { money } from "./domain.js";
defineProps({
  boat: { type: Object, required: true },
  horizontal: Boolean,
  compare: Boolean,
  selected: Boolean,
  mode: { type: String, default: "rent" },
});
defineEmits(["open", "compare"]);
</script>

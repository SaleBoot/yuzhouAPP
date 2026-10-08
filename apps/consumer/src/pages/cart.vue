<template>
  <view class="page"
    ><view class="title">购物车</view
    ><view class="subtitle">航海生活需要的小物，一次收藏</view
    ><view class="notice small section"
      >按商品分单结算，每件生成独立订单并分别支付。订单创建成功后移出购物车，失败商品保留。</view
    ><view v-for="item in items" :key="item.id" class="card section"
      ><view class="row"
        ><image
          :src="media(item.product?.payload?.image_urls?.[0]) || fallbackBoat"
          mode="aspectFill"
          class="thumb"
        /><view class="flex"
          ><view class="card-head">{{ item.product?.title }}</view
          ><view class="price">¥{{ money(item.product?.price_cents) }}</view
          ><view class="small muted">数量 {{ item.quantity }}</view></view
        ></view
      ><view class="row spread section"
        ><view class="row"
          ><button
            class="secondary small"
            :disabled="busy"
            @click="update(item, item.quantity - 1)"
          >
            −</button
          ><text>{{ item.quantity }}</text
          ><button
            class="secondary small"
            :disabled="busy"
            @click="update(item, item.quantity + 1)"
          >
            ＋
          </button></view
        ><text class="link small" @click="remove(item)">移除</text></view
      ><button class="primary section" :disabled="busy" @click="checkout(item)">
        为该商品创建订单
      </button></view
    ><view v-if="!items.length && !error" class="empty"
      >购物车里还没有商品。<text
        class="link"
        @click="go('shop', { kind: 'products' })"
        >逛逛周边商城</text
      ></view
    ><view v-if="error" class="error"
      >{{ error }} <text @click="load">重试</text></view
    ></view
  >
</template>
<script setup>
import { ref } from "vue";
import { onShow } from "@dcloudio/uni-app";
import {
  request,
  post,
  media,
  notify,
  go,
  requireLogin,
  fallbackBoat,
} from "@shared/api.js";
import { money } from "@shared/domain.js";
const items = ref([]),
  busy = ref(false),
  error = ref("");
async function load() {
  if (!requireLogin()) return;
  try {
    const r = await request("/cart");
    items.value = r.items || [];
    error.value = "";
  } catch (e) {
    error.value = e.message;
  }
}
async function update(item, quantity) {
  if (quantity < 1) return;
  busy.value = true;
  try {
    await request(`/cart/${item.id}`, {
      method: "PATCH",
      data: { product_id: item.product_id, quantity },
    });
    await load();
  } catch (e) {
    notify(e);
  } finally {
    busy.value = false;
  }
}
async function remove(item) {
  busy.value = true;
  try {
    await request(`/cart/${item.id}`, { method: "DELETE" });
    await load();
  } catch (e) {
    notify(e);
  } finally {
    busy.value = false;
  }
}
async function checkout(item) {
  if (busy.value) return;
  busy.value = true;
  try {
    const order = await post("/orders", {
      type: "mall",
      resource_id: item.product_id,
      quantity: item.quantity,
    });
    try {
      await request(`/cart/${item.id}`, { method: "DELETE" });
    } catch (e) {
      notify(new Error("订单已创建，购物车移除失败，请勿重复下单"));
    }
    go("order", { id: order.id });
  } catch (e) {
    notify(e);
  } finally {
    busy.value = false;
  }
}
onShow(load);
</script>
<style scoped>
.thumb {
  width: 170rpx;
  height: 145rpx;
  border-radius: 16rpx;
  margin-right: 20rpx;
}
.flex {
  flex: 1;
}
.row button {
  padding: 14rpx 22rpx;
}
</style>

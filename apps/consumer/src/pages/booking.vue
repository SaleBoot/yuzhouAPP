<template>
  <view class="page"
    ><view class="title">{{ ORDER_TYPES[form.type] }}预约</view
    ><view class="subtitle">确认配置，开始下一段航程</view
    ><view v-if="error" class="error">{{ error }}</view
    ><template v-if="item"
      ><view class="card"
        ><view class="row"
          ><image
            :src="item.image_url || fallbackBoat"
            class="thumb"
            mode="aspectFill"
          /><view
            ><view class="card-head">{{ item.name }}</view
            ><view class="muted small">{{
              form.type === "boat_rental"
                ? "按天租赁，归还日不计租"
                : "提交后由销售确认配置与报价"
            }}</view></view
          ></view
        ></view
      ><view class="card section"
        ><view v-if="form.type === 'boat_purchase'" class="formline"
          ><text class="form-label">购买的实际船艇</text
          ><picker
            :range="
              instances.map((b) => `${b.name} · ${b.serial_number || b.id}`)
            "
            :value="instanceIndex"
            @change="instanceIndex = Number($event.detail.value)"
            ><view class="picker-value"
              >{{
                instances[instanceIndex]?.name || "暂无可购买的船艇，请咨询销售"
              }}
              ▾</view
            ></picker
          ><view v-if="instances[instanceIndex]" class="small muted section"
            >所选实船售价 ¥{{ money(instances[instanceIndex].price_cents) }} ·
            最终以销售报价为准</view
          ></view
        ><view v-if="form.type === 'boat_rental'"
          ><view class="formline"
            ><text class="form-label">开始日期</text
            ><picker
              mode="date"
              :value="form.start_date"
              :start="today()"
              @change="
                form.start_date = $event.detail.value;
                availability = null;
              "
              ><view class="picker-value"
                >{{ form.start_date || "选择日期" }} ▾</view
              ></picker
            ></view
          ><view class="formline"
            ><text class="form-label">归还日期</text
            ><picker
              mode="date"
              :value="form.end_date"
              :start="form.start_date"
              @change="
                form.end_date = $event.detail.value;
                availability = null;
              "
              ><view class="picker-value"
                >{{ form.end_date || "选择日期" }} ▾</view
              ></picker
            ></view
          ></view
        ><view v-if="form.type !== 'boat_purchase'" class="formline"
          ><text class="form-label">{{
            form.type === "boat_rental" ? "船艇数量" : "数量"
          }}</text
          ><input
            v-model="form.quantity"
            type="number"
            placeholder="1"
            @input="availability = null" /></view
        ><button
          v-if="form.type === 'boat_rental'"
          class="secondary"
          :disabled="busy"
          @click="checkInventory"
        >
          查询可用船艇</button
        ><view v-if="availability" class="notice section"
          >{{
            availability.available
              ? "当前日期可预约，提交后由服务端锁定船艇"
              : "当前日期库存不足，请调整日期或数量"
          }}<view v-for="boat in availableBoats" :key="boat.id" class="small">{{
            boat.title || boat.serial_number || `船艇 ${boat.id}`
          }}</view></view
        ><ServiceScheduleFields
          v-if="['boat_upgrade', 'maintenance'].includes(form.type)"
          v-model:date="serviceDate"
          v-model:time="serviceTime"
          v-model:location="serviceLocation" />
        <view v-if="form.type === 'boat_purchase' && purchaseOptions.length"
          ><view class="card-head section">选择配置</view
          ><view
            v-for="opt in purchaseOptions"
            :key="opt.id || opt.name"
            class="list-item"
            @click="toggleOption(opt)"
            ><text
              >{{ form.option_ids.includes(opt.id || opt.name) ? "☑" : "☐" }}
              {{ opt.name || opt.title }}</text
            ><text>¥{{ money(opt.price_cents) }}</text></view
          ></view
        ><view class="formline"
          ><text class="form-label">优惠券</text
          ><picker
            :range="[
              '不使用优惠券',
              ...coupons.map((c) => c.title || c.name || '优惠券'),
            ]"
            :value="couponIndex"
            @change="couponIndex = Number($event.detail.value)"
            ><view class="picker-value"
              >{{
                couponIndex
                  ? coupons[couponIndex - 1]?.title || "已选择优惠券"
                  : "不使用优惠券"
              }}
              ▾</view
            ></picker
          ></view
        ><view class="formline"
          ><text class="form-label">备注</text
          ><textarea
            v-model="form.notes"
            placeholder="出行需求、交付地点和其他说明"
          /></view></view
      ><view class="notice small section"
        >实际总价、定金、优惠和可用船艇以服务端订单为准。未确认订单前不会发生扣款。</view
      ><button
        class="primary section"
        :loading="busy"
        :disabled="busy"
        @click="submit"
      >
        提交预约
      </button></template
    ></view
  >
</template>
<script setup>
import { reactive, ref, computed, watch } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import ServiceScheduleFields from "@shared/ServiceScheduleFields.vue";
import {
  detail,
  list,
  request,
  post,
  query,
  go,
  notify,
  requireLogin,
  fallbackBoat,
} from "@shared/api.js";
import {
  ORDER_TYPES,
  today,
  dayCount,
  validateOrder,
  money,
} from "@shared/domain.js";
const item = ref(null),
  error = ref(""),
  busy = ref(false),
  coupons = ref([]),
  couponIndex = ref(0),
  availability = ref(null),
  instances = ref([]),
  instanceIndex = ref(0),
  serviceDate = ref(""),
  serviceTime = ref("10:00"),
  serviceLocation = ref("");
const purchaseOptions = computed(
  () => instances.value[instanceIndex.value]?.options || [],
);
watch(instanceIndex, () => (form.option_ids = []));
const form = reactive({
  type: "boat_rental",
  resource_id: null,
  quantity: 1,
  start_date: today(),
  end_date: "",
  notes: "",
  option_ids: [],
});
const availableBoats = computed(
  () =>
    availability.value?.boats ||
    availability.value?.items ||
    (availability.value?.boat_ids || []).map((id) => ({
      id,
      title: `可用船艇 ${id}`,
    })),
);
onLoad(async (q) => {
  form.type = q.type || "boat_rental";
  form.resource_id = Number(q.id);
  try {
    item.value = await detail(q.kind || "boat_types", q.id);
    if (form.type === "boat_purchase") {
      instances.value = (await list("boats")).filter(
        (b) => Number(b.boat_type_id) === Number(q.id) && !b.unavailable,
      );
      if (q.instance_id) {
        instanceIndex.value = instances.value.findIndex((boat) => Number(boat.id) === Number(q.instance_id));
        if (instanceIndex.value < 0) throw new Error("所选实船当前不可购买，请返回地图刷新");
      }
    }
    const c = await request("/coupons/my");
    coupons.value = (c.items || c || [])
      .filter((c) => c.status === "available")
      .map((c) => ({ ...c, ...c.coupon, ...c.coupon?.payload, id: c.id }));
  } catch (e) {
    error.value = e.message;
  }
});
async function checkInventory() {
  try {
    dayCount(form.start_date, form.end_date);
    busy.value = true;
    const r = await request(
      `/inventory?${query({ boat_type_id: form.resource_id, start_date: form.start_date, end_date: form.end_date, quantity: form.quantity })}`,
    );
    availability.value = {
      ...r,
      available:
        r.available ??
        (r.available_count ?? r.items?.length ?? r.boats?.length ?? 0) >=
          Number(form.quantity),
    };
  } catch (e) {
    notify(e);
  } finally {
    busy.value = false;
  }
}
function toggleOption(opt) {
  const id = opt.id || opt.name;
  form.option_ids = form.option_ids.includes(id)
    ? form.option_ids.filter((v) => v !== id)
    : [...form.option_ids, id];
}
async function submit() {
  if (!requireLogin() || busy.value) return;
  try {
    if (
      ["boat_upgrade", "maintenance"].includes(form.type) &&
      (!serviceDate.value || !serviceLocation.value.trim())
    )
      throw new Error("请确定服务日期、时间和地点");
    const payload = validateOrder({
      ...form,
      resource_id:
        form.type === "boat_purchase"
          ? instances.value[instanceIndex.value]?.id
          : form.resource_id,
      coupon_id: couponIndex.value
        ? coupons.value[couponIndex.value - 1].id
        : null,
      ...(["boat_upgrade", "maintenance"].includes(form.type)
        ? {
            scheduled_at: `${serviceDate.value} ${serviceTime.value}:00`,
            service_location: serviceLocation.value,
          }
        : {}),
    });
    if (form.type === "boat_rental") {
      await checkInventory();
      if (!availability.value?.available)
        throw new Error("请先确认预约库存充足");
    }
    busy.value = true;
    const o = await post("/orders", payload);
    go("order", { id: o.id });
  } catch (e) {
    notify(e);
  } finally {
    busy.value = false;
  }
}
</script>
<style scoped>
.thumb {
  width: 150rpx;
  height: 120rpx;
  border-radius: 14rpx;
  flex-shrink: 0;
  margin-right: 18rpx;
}
</style>

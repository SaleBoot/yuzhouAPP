<template>
  <view class="page"
    ><view class="title">为客户配置一段航程</view
    ><view class="subtitle">提交订单，再确认报价与合同</view
    ><view class="card section"
      ><view class="formline"
        ><text class="form-label">客户</text
        ><picker
          :range="customers.map((c) => `${c.name} · ${c.user_id}`)"
          :value="customerIndex"
          @change="customerIndex = Number($event.detail.value)"
          ><view class="picker-value"
            >{{ customers[customerIndex]?.name || "请选择客户" }} ▾</view
          ></picker
        ></view
      ><view class="formline"
        ><text class="form-label">订单类型</text
        ><picker
          :range="types.map((t) => t.label)"
          :value="typeIndex"
          @change="
            typeIndex = Number($event.detail.value);
            loadResources();
          "
          ><view class="picker-value"
            >{{ types[typeIndex].label }} ▾</view
          ></picker
        ></view
      ><view class="formline"
        ><text class="form-label">船艇或服务</text
        ><picker
          :range="resources.map((r) => r.name)"
          :value="resourceIndex"
          @change="resourceIndex = Number($event.detail.value)"
          ><view class="picker-value"
            >{{ chosen?.name || "请选择船艇或服务" }} ▾</view
          ></picker
        ></view
      ><image
        v-if="chosen"
        :src="chosen.image_url || fallbackBoat"
        class="cover section"
        mode="aspectFill" /><view
        v-if="types[typeIndex].value === 'boat_purchase' && chosen"
        class="price section"
        >实船售价 ¥{{ money(chosen.price_cents) }}</view
      ><view v-if="types[typeIndex].value !== 'boat_purchase'" class="formline"
        ><text class="form-label">数量</text
        ><input v-model="form.quantity" type="number" /></view
      ><template v-if="types[typeIndex].value === 'boat_rental'"
        ><view class="formline"
          ><text class="form-label">开始日期</text
          ><picker
            mode="date"
            :value="form.start_date"
            :start="today()"
            @change="form.start_date = $event.detail.value"
            ><view class="picker-value">{{ form.start_date }} ▾</view></picker
          ></view
        ><view class="formline"
          ><text class="form-label">归还日期</text
          ><picker
            mode="date"
            :value="form.end_date"
            :start="form.start_date"
            @change="form.end_date = $event.detail.value"
            ><view class="picker-value"
              >{{ form.end_date || "选择归还日期" }} ▾</view
            ></picker
          ></view
        ></template
      ><ServiceScheduleFields
        v-if="['boat_upgrade', 'maintenance'].includes(types[typeIndex].value)"
        v-model:date="serviceDate"
        v-model:time="serviceTime"
        v-model:location="serviceLocation" />
      <view v-if="chosen?.options?.length"
        ><view class="card-head section">选配项目</view
        ><view
          v-for="opt in chosen.options"
          :key="opt.id || opt.name"
          class="list-item"
          @click="toggle(opt)"
          ><text
            >{{ form.option_ids.includes(opt.id || opt.name) ? "☑" : "☐" }}
            {{ opt.name || opt.title }}</text
          ><text>¥{{ money(opt.price_cents) }}</text></view
        ></view
      ><view class="formline"
        ><text class="form-label">配置与交付说明</text
        ><textarea
          v-model="form.notes"
          placeholder="记录客户确认的配置、交付地点、时间和其他约定"
        /></view></view
    ><view class="notice small section"
      >订单将绑定本销售及所属销售公司，订单金额和库存由服务端确认。创建后可报价、上传PDF合同，并发送客户链接。</view
    ><button
      class="primary section"
      :loading="busy"
      :disabled="busy"
      @click="create"
    >
      创建代客订单</button
    ><view v-if="error" class="error">{{ error }}</view></view
  >
</template>
<script setup>
import { ref, reactive, computed } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import ServiceScheduleFields from "@shared/ServiceScheduleFields.vue";
import {
  request,
  list,
  post,
  go,
  notify,
  requireLogin,
  fallbackBoat,
} from "@shared/api.js";
import { ORDER_TYPES, today, validateOrder, money } from "@shared/domain.js";
const customers = ref([]),
  customerIndex = ref(0),
  typeIndex = ref(0),
  resources = ref([]),
  resourceIndex = ref(0),
  busy = ref(false),
  error = ref("");
const types = [
  "boat_purchase",
  "boat_rental",
  "boat_upgrade",
  "maintenance",
].map((value) => ({ value, label: ORDER_TYPES[value] }));
const chosen = computed(() => resources.value[resourceIndex.value]);
const form = reactive({
  quantity: 1,
  start_date: today(),
  end_date: "",
  option_ids: [],
  notes: "",
});
const serviceDate = ref(""),
  serviceTime = ref("10:00"),
  serviceLocation = ref("");
onLoad(async (q) => {
  if (!requireLogin()) return;
  try {
    const c = await request("/customers?page_size=100");
    customers.value = c.items || [];
    customerIndex.value = Math.max(
      0,
      customers.value.findIndex(
        (c) => Number(c.user_id) === Number(q.customer_id),
      ),
    );
    await loadResources();
    if (q.boat_id) {
      resourceIndex.value = resources.value.findIndex((boat) => Number(boat.id) === Number(q.boat_id));
      if (resourceIndex.value < 0) throw new Error("地图中的实船当前不可选择，请刷新地图");
    }
  } catch (e) {
    error.value = e.message;
  }
});
async function loadResources() {
  try {
    resources.value = await list(
      types[typeIndex.value].value === "boat_upgrade"
        ? "upgrades"
        : types[typeIndex.value].value === "maintenance"
          ? "maintenance"
          : types[typeIndex.value].value === "boat_purchase"
            ? "boats"
            : "boat_types",
    );
    resourceIndex.value = 0;
    form.option_ids = [];
  } catch (e) {
    error.value = e.message;
  }
}
function toggle(o) {
  const id = o.id || o.name;
  form.option_ids = form.option_ids.includes(id)
    ? form.option_ids.filter((x) => x !== id)
    : [...form.option_ids, id];
}
async function create() {
  if (busy.value) return;
  try {
    if (!customers.value[customerIndex.value]) throw new Error("请先添加客户");
    const isService = ["boat_upgrade", "maintenance"].includes(
      types[typeIndex.value].value,
    );
    if (isService && (!serviceDate.value || !serviceLocation.value.trim()))
      throw new Error("请确定服务日期、时间和地点");
    const data = validateOrder({
      ...form,
      type: types[typeIndex.value].value,
      resource_id: chosen.value?.id,
      customer_id: customers.value[customerIndex.value].user_id,
      quantity:
        types[typeIndex.value].value === "boat_purchase" ? 1 : form.quantity,
      ...(isService
        ? {
            scheduled_at: `${serviceDate.value} ${serviceTime.value}:00`,
            service_location: serviceLocation.value,
          }
        : {}),
    });
    busy.value = true;
    const order = await post("/orders", data);
    go("order", { id: order.id });
  } catch (e) {
    notify(e);
  } finally {
    busy.value = false;
  }
}
</script>

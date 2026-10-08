<template>
  <AppointmentPanel
    :resource-id="resourceId"
    :customer-id="customerId"
    broker
  />
</template>
<script setup>
import { ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import AppointmentPanel from "@shared/AppointmentPanel.vue";
const resourceId = ref(""),
  customerId = ref("");
onLoad((q) => {
  resourceId.value = q.resource_id || "";
  customerId.value = q.customer_id || "";
});
</script>

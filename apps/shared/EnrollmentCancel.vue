<template>
  <button v-if="enrollment && !enrollment.order_id && ['pending', 'active'].includes(enrollment.status)" class="secondary section" :disabled="busy" @click.stop="confirmCancel">取消免费报名</button>
</template>
<script setup>
import { ref } from "vue";
import { post, notify } from "./api.js";
const props = defineProps({ enrollment: Object });
const emit = defineEmits(["cancelled"]);
const busy = ref(false);
function confirmCancel() {
  if (busy.value) return;
  uni.showModal({
    title: "取消免费报名",
    content: "取消后将释放本次活动名额，可以重新报名。已付费活动请在订单中处理取消或退款申请。",
    success: async (r) => {
      if (!r.confirm || busy.value) return;
      busy.value = true;
      try {
        const updated = await post(`/enrollments/${props.enrollment.id}/cancel`, { reason: "用户取消免费报名", ticket_code: props.enrollment.ticket_code });
        emit("cancelled", updated);
        uni.showToast({ title: "报名已取消", icon: "success" });
      } catch (e) { notify(e); }
      finally { busy.value = false; }
    },
  });
}
</script>

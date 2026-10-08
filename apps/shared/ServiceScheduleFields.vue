<template>
  <view>
    <view class="row"
      ><view class="formline flex"
        ><text class="form-label">服务日期</text
        ><picker
          mode="date"
          :value="date"
          :start="today()"
          @change="$emit('update:date', $event.detail.value)"
          ><view class="picker-value">{{ date || "选择日期" }} ▾</view></picker
        ></view
      ><view class="formline flex"
        ><text class="form-label">时间</text
        ><picker
          mode="time"
          :value="time"
          @change="$emit('update:time', $event.detail.value)"
          ><view class="picker-value">{{ time || "选择时间" }} ▾</view></picker
        ></view
      ></view
    >
    <view class="formline"
      ><text class="form-label">服务地点</text
      ><input
        :value="location"
        placeholder="安装或保养地点"
        @input="$emit('update:location', $event.detail.value)"
    /></view>
  </view>
</template>
<script setup>
import { today } from "./domain.js";
defineProps({ date: String, time: String, location: String });
defineEmits(["update:date", "update:time", "update:location"]);
</script>
<style scoped>
.flex {
  flex: 1;
  min-width: 0;
}
</style>

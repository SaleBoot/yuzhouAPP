<script>
// #ifdef APP-PLUS
import { checkNativeUpdate } from '@shared/native-update.js';
// #endif
export default {
  onLaunch() {
    console.info("驭舟 consumer 已启动");
    // #ifdef APP-PLUS
    checkNativeUpdate();
    // #endif
  },
  onShow() {
    // #ifdef APP-PLUS
    checkNativeUpdate();
    // #endif
  },
};
</script>
<style>
@import "@shared/theme.css";
</style>

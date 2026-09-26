<script setup lang="ts">
import { onShow, onHide } from '@dcloudio/uni-app';
import { refreshUnread } from './lib/session';
let timer: ReturnType<typeof setInterval> | undefined;
onShow(() => { clearInterval(timer); void refreshUnread(); timer = setInterval(() => { void refreshUnread(); }, 30000); });
onHide(() => { clearInterval(timer); timer = undefined; });
</script>
<style>
page { background: #f4f7f6; color: #202b26; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; font-size: 16px; }
view, text, button, input, textarea { box-sizing: border-box; }
button { font-size: 15px; border-radius: 12px; margin: 0; }
button::after { border: none; }
.icon::after, .tab::after, .filter::after, .primary::after, .secondary::after, .quick-action::after { border: none; }
.shell { max-width: 750px; min-height: 100vh; margin: auto; background: #fff; padding: calc(14px + env(safe-area-inset-top)) 24px calc(96px + env(safe-area-inset-bottom)); }
.header { display: flex; align-items: center; justify-content: space-between; gap: 12px; min-height: 56px; margin-bottom: 20px; }
.header-title { flex: 1; text-align: center; font-size: 20px; font-weight: 600; }
.icon { width: 44px; height: 44px; line-height: 44px; text-align: center; background: transparent; position: relative; padding: 0; flex-shrink: 0; }
.badge { position: absolute; right: 0; top: 0; min-width: 18px; height: 18px; padding: 0 4px; background: #dd4141; color: #fff; font-size: 10px; line-height: 18px; border-radius: 12px; }
.tabs { position: fixed; bottom: 0; left: 50%; transform: translateX(-50%); width: 100%; max-width: 750px; display: flex; background: #fff; border-top: 1px solid #eef1ef; padding: 10px 18px calc(10px + env(safe-area-inset-bottom)); z-index: 5; }
.tab { flex: 1; text-align: center; padding: 8px; color: #79827d; font-size: 13px; background: transparent; line-height: 24px; }
.tab.active, .green { color: #39ad8d; }
.tab-icon { display: block; position: relative; width: 40px; margin: auto; font-size: 24px; }
.muted { color: #7a867f; font-size: 14px; line-height: 1.7; }
.row { padding: 22px 0; border-bottom: 1px solid #e7ece9; }
.flex { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
.stack { display: flex; flex-direction: column; gap: 18px; }
.title { display: block; font-size: 22px; font-weight: 600; margin: 12px 0; }
.body { display: block; white-space: pre-wrap; overflow-wrap: anywhere; line-height: 1.9; }
.primary { background: #39ad8d; color: #fff; padding: 4px 20px; }
.secondary { background: #f1f7f4; color: #27866d; padding: 4px 20px; }
.empty { padding: 60px 0; text-align: center; color: #7a867f; }
.error { color: #b63838; padding: 14px 0; font-size: 14px; }
.field { width: 100%; min-height: 48px; border: 1px solid #dde6e0; border-radius: 10px; padding: 12px; font-size: 16px; background: #fff; }
.label { display: block; font-size: 14px; margin-bottom: 8px; }
.toolbar { display: flex; gap: 4px; margin-bottom: 12px; }
.filter { flex: 1; font-size: 12px; line-height: 1.4; padding: 12px 4px; border-radius: 20px; background: transparent; }
.filter.selected { background: #e7f6f0; color: #258a6e; }
.pagination { display: flex; justify-content: space-between; margin-top: 24px; }
.message-title { display: block; overflow-wrap: anywhere; font-weight: 600; }
.dot { width: 8px; height: 8px; border-radius: 50%; background: #39ad8d; flex-shrink: 0; }
.brand-logo { width: 130px; height: 60px; }
.quick-actions { display: flex; flex-wrap: wrap; gap: 20px; padding: 32px 0; }
.quick-action { background: transparent; padding: 0; width: calc(50% - 10px); }
.quick-icon { display: block; position: relative; width: 44px; margin: 0 auto 6px; font-size: 28px; }
@media(min-width:768px) { .shell { padding-left: 40px; padding-right: 40px; } }
</style>

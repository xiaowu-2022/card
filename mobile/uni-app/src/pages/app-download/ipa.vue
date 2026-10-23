<script setup lang="ts">
import {ref, onMounted, onBeforeUnmount} from 'vue';
import {iosManifestUrl, loadIOSPackages, type IOSPackage} from '../../lib/ios-distribution';
const packages = ref<IOSPackage[]>([]), loading = ref(true), failed = ref(false), notice = ref('');
let controller: AbortController | null = null, disposed = false;
async function load() {
    controller?.abort();
    const current = new AbortController(); controller = current;
    loading.value = true; failed.value = false;
    const timer = setTimeout(() => current.abort(), 8000);
    try {
        const data = await loadIOSPackages(current.signal);
        if (!disposed && controller === current) packages.value = data;
    } catch { if (!disposed && controller === current) failed.value = true; }
    finally { clearTimeout(timer); if (!disposed && controller === current) loading.value = false; }
}
function install(file: string) {
    // #ifdef H5
    if (location.protocol !== 'https:') { notice.value = '请使用 HTTPS 地址打开本页后安装。'; return; }
    if (/MicroMessenger|QQ\//i.test(navigator.userAgent)) { notice.value = '请通过浏览器菜单在 Safari 中打开本页。'; return; }
    if (!/iPhone|iPad|iPod/i.test(navigator.userAgent) && !(navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)) {
        notice.value = '请在 iPhone 或 iPad 上使用 Safari 安装。'; return;
    }
    location.assign('itms-services://?action=download-manifest&url=' + encodeURIComponent(iosManifestUrl(location.origin, file)));
    notice.value = '已请求系统安装，请确认提示后返回主屏幕查看进度。';
    // #endif
}
onMounted(() => { void load(); });
onBeforeUnmount(() => { disposed = true; controller?.abort(); });
</script>
<template>
    <view class="ios-distribution">
        <view class="distribution-wrap">
            <view class="brand">SPEC PAY <text>iOS 下载</text></view>
            <text class="eyebrow">GLOBAL PAYMENT</text>
            <h1>U 卡，随身同行。</h1>
            <p class="intro">在 iPhone 或 iPad 上使用 Safari 安装。</p>
            <view v-if="loading" class="status" role="status">正在读取安装包…</view>
            <view v-else-if="failed" class="status" role="alert">暂时无法读取安装包，请重试。<button @click="load">重试</button></view>
            <view v-else-if="!packages.length" class="status">安装包准备中，请稍后再试。</view>
            <view v-for="p in packages" :key="p.file" class="package-card">
                <view class="app-icon">U</view><h2>{{ p.title }}</h2>
                <p>版本 {{ p.version }} · Build {{ p.build }} · {{ (p.size / 1048576).toFixed(1) }} MB</p>
                <button class="install" @click="install(p.file)">安装到 iPhone / iPad</button>
            </view>
            <p v-if="notice" class="status" role="status">{{ notice }}</p>
            <view class="guide"><h2>安装说明</h2><p>确认系统安装提示后，返回主屏幕查看进度。网页无法确认安装是否完成。</p><p>安装需有效签名及设备授权；如系统提示，请按系统说明完成操作。</p></view>
            <view class="footer">Spec Pay 万事达 U卡</view>
        </view>
    </view>
</template>
<style scoped>
.ios-distribution{min-height:100vh;background:radial-gradient(ellipse at top right,#3b3018,#11100c 65%);color:#f5edda;padding:32px 22px;box-sizing:border-box}.distribution-wrap{max-width:540px;margin:auto}.brand{font-size:20px;font-weight:700;letter-spacing:2px;display:flex;justify-content:space-between;align-items:center}.brand text{font-size:12px;letter-spacing:0;border:1px solid #776646;border-radius:20px;padding:6px 12px}.eyebrow{display:block;margin-top:58px;color:#bdab7a;font-size:11px;letter-spacing:4px}h1{font-size:34px;margin:15px 0}h2{font-size:20px;margin:12px 0}.intro,.guide p,.package-card p{color:#bfb5a0;font-size:14px;line-height:1.8}.package-card{margin-top:28px;padding:24px;background:#211e17;border:1px solid #655638;border-radius:22px}.app-icon{width:56px;height:56px;border-radius:14px;background:linear-gradient(120deg,#eddaa3,#b5944e);color:#252014;font-size:34px;text-align:center;line-height:56px;font-weight:bold}.install{margin-top:24px;background:linear-gradient(110deg,#f1dda5,#c2a35a);color:#211b0e;font-size:16px;border-radius:28px}.status{margin-top:28px;padding:20px;background:#282318;border-radius:16px;line-height:1.8}.status button{margin-top:12px}.guide{margin-top:38px}.footer{color:#8f856f;text-align:center;margin:42px 0 20px;font-size:12px}
</style>

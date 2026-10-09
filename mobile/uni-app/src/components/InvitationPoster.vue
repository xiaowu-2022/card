<script setup lang="ts">
import { getCurrentInstance, nextTick, ref } from 'vue';
import qrcode from 'qrcode-generator';
import Modal from './Modal.vue';
import { privateImage, native, photoUrl } from '../lib/api';
import { t } from '../lib/i18n';
import { boundedPosterSave, inWebview, isIOSDevice, isIOSWebview, saveBrowserPoster } from '../lib/poster-save';
const props = defineProps<{ link: string; code: string; background: string | null }>();
const open = ref(false),
    preview = ref(''),
    failed = ref(false),
    width = ref(900),
    height = ref(1000),
    saving = ref(false),
    saveMessage = ref(''),
    saveFailed = ref(false),
    instance = getCurrentInstance();
const canvasId = 'invitation-poster';
const iosBrowser = !native && isIOSDevice();
let generation = 0;
async function generate() {
    const run = ++generation;
    open.value = true;
    preview.value = '';
    failed.value = false;
    saveMessage.value = '';
    saveFailed.value = false;
    try {
        let picture: { path: string; width: number; height: number } | null = null;
        if (props.background) {
            const path = /^https:\/\//i.test(props.background) ? photoUrl(props.background) : await privateImage('/client/promotion/poster-background');
            picture = await new Promise((resolve, reject) =>
                uni.getImageInfo({
                    src: path,
                    success: (r) => resolve({ path: r.path, width: r.width, height: r.height }),
                    fail: reject,
                }),
            );
        }
        if (run !== generation || !open.value) return;
        // Bound native PNG transfer cost before the photo-library call. Keep QR cells integral.
        const maxEdge = native || inWebview() ? 1600 : 2400;
        const scale = Math.min(1, maxEdge / Math.max(picture?.width ?? 900, picture?.height ?? 1000));
        width.value = Math.round((picture?.width ?? 900) * scale);
        height.value = Math.round((picture?.height ?? 1000) * scale);
        const qr = qrcode(0, 'M');
        qr.addData(props.link, 'Byte');
        qr.make();
        const count = qr.getModuleCount(), quiet = 4, total = count + quiet * 2;
        const cell = Math.max(1, Math.floor(Math.min(width.value * 0.28, height.value * 0.3) / total));
        const size = cell * total;
        const x = Math.floor((width.value - size) / 2);
        const y = height.value - size - Math.round(height.value * 0.04);
        function drawQr(fill: (color: string) => void, rect: (x: number, y: number, w: number, h: number) => void) {
            fill('#ffffff');
            rect(x, y, size, size);
            fill('#000000');
            for (let row = 0; row < count; row++)
                for (let col = 0; col < count; col++)
                    if (qr.isDark(row, col)) rect(x + (col + quiet) * cell, y + (row + quiet) * cell, cell, cell);
        }
        // #ifdef H5
        // Draw on an explicitly sized bitmap, independent of offscreen uni-canvas layout.
        if (!native) {
            const canvas = document.createElement('canvas');
            canvas.width = width.value;
            canvas.height = height.value;
            const context = canvas.getContext('2d');
            if (!context) throw new Error('Canvas unavailable');
            context.fillStyle = '#163e34';
            context.fillRect(0, 0, canvas.width, canvas.height);
            if (picture) {
                const image = new Image();
                await new Promise<void>((resolve, reject) => {
                    image.crossOrigin = 'anonymous';
                    image.onload = () => resolve();
                    image.onerror = () => reject(new Error('Poster background unavailable'));
                    image.src = picture!.path;
                });
                context.drawImage(image, 0, 0, canvas.width, canvas.height);
            } else {
                context.fillStyle = '#e2d7a9';
                context.textAlign = 'center';
                context.font = '60px sans-serif';
                context.fillText(t('Invitation to join'), 450, 420, 800);
                context.font = '32px sans-serif';
                context.fillText(t('Share your invitation link with a friend.'), 450, 500, 780);
            }
            drawQr(color => { context.fillStyle = color; }, (x, y, w, h) => context.fillRect(x, y, w, h));
            if (run === generation && open.value) preview.value = canvas.toDataURL('image/png');
            return;
        }
        // #endif
        await nextTick();
        const ctx = uni.createCanvasContext(canvasId, instance?.proxy);
        ctx.setFillStyle('#163e34');
        ctx.fillRect(0, 0, width.value, height.value);
        if (picture) {
            ctx.drawImage(picture.path, 0, 0, width.value, height.value);
        } else {
            ctx.setFillStyle('#e2d7a9');
            ctx.setFontSize(60);
            ctx.setTextAlign('center');
            ctx.fillText(t('Invitation to join'), 450, 420, 800);
            ctx.setFontSize(32);
            ctx.fillText(t('Share your invitation link with a friend.'), 450, 500, 780);
        }
        drawQr(color => ctx.setFillStyle(color), (x, y, w, h) => ctx.fillRect(x, y, w, h));
        await new Promise<void>((resolve) => ctx.draw(false, () => resolve()));
        const output = await new Promise<string>((resolve, reject) =>
            uni.canvasToTempFilePath(
                {
                    canvasId,
                    width: width.value,
                    height: height.value,
                    destWidth: width.value,
                    destHeight: height.value,
                    fileType: 'png',
                    success: (r) => resolve(r.tempFilePath),
                    fail: reject,
                },
                instance?.proxy,
            ),
        );
        if (run === generation && open.value) preview.value = output;
    } catch {
        if (run === generation) failed.value = true;
    }
}
function close() {
    if (saving.value) return;
    open.value = false;
    generation++;
    preview.value = '';
}
async function save() {
    if (!preview.value || saving.value) return;
    saving.value = true;
    saveMessage.value = '';
    saveFailed.value = false;
    try {
        // #ifdef H5
        const result = await saveBrowserPoster(preview.value, props.code);
        saveMessage.value = result === 'saved' ? 'Poster saved to your photo library.'
            : result === 'download' ? 'If the download does not appear, press and hold the poster to save it.'
            : result === 'shared' ? 'If you selected Save Image, check Photos. Otherwise, press and hold the poster to save it.'
            : result === 'manual' ? 'Press and hold the poster and choose Save Image. If no menu appears, open this page in Safari and try again.'
            : '';
        // #endif
        // #ifdef APP-PLUS
        await boundedPosterSave(() => new Promise<void>((resolve, reject) =>
            uni.saveImageToPhotosAlbum({
                filePath: preview.value,
                success: () => resolve(),
                fail: reject,
            }),
        ), undefined, 60000);
        saveMessage.value = 'Poster saved to your photo library.';
        // #endif
    } catch (error) {
        saveFailed.value = true;
        const code = error instanceof Error ? error.message : '';
        saveMessage.value = code === 'update-required'
            ? 'This app version cannot save posters. Please update the app or open this page in your browser.'
            : code === 'invalid' ? 'The poster could not be saved. Please generate a smaller poster and try again.'
            : code === 'timeout' ? 'Saving timed out. Check your photo library before trying again.'
            : 'Unable to save the poster. Check photo permissions in your phone settings and try again.';
    } finally {
        saving.value = false;
    }
}
function longSave() {
    // Preserve the iOS WebView image context menu; do not start a second save on long press.
    if (native || (inWebview() && !isIOSWebview())) void save();
}
defineExpose({ generate });
</script>
<template>
    <canvas
        :canvas-id="canvasId"
        :id="canvasId"
        class="poster-canvas"
        :style="{ width: width + 'px', height: height + 'px' }"
    /><Modal
        :open="open"
        :busy="saving"
        :title="t('Invitation poster')"
        :description="t(iosBrowser ? 'Tap Save image, then choose Save Image in the system menu, or press and hold the poster.' : 'Save the poster or press and hold the image to save it on your phone.')"
        @close="close"
        ><template v-if="preview"
            >
            <!-- #ifdef H5 -->
            <img v-if="iosBrowser" :src="preview" :alt="t('Invitation poster')" class="preview ios-preview" />
            <!-- #endif -->
            <image
                v-if="!iosBrowser"
                :src="preview"
                mode="widthFix"
                class="preview"
                :show-menu-by-longpress="true"
                @longpress="longSave"
            /><button class="primary" :disabled="saving" @click="save">
                {{ t(saving ? 'Saving poster…' : 'Save image') }}
            </button>
            <text v-if="saveMessage" class="save-feedback" :class="{ failed: saveFailed }" role="status">{{ t(saveMessage) }}</text>
            </template
        ><text v-else>{{
            t(failed ? 'Could not generate poster. Please try again.' : 'Generating poster…')
        }}</text
        ><button v-if="failed" class="secondary" @click="generate">
            {{ t('Try again') }}
        </button></Modal
    >
</template>
<style scoped>
.save-feedback { display: block; margin-top: 12px; font-size: 14px; line-height: 1.6; color: #59645f; }
.save-feedback.failed { color: #9f2828; }
.ios-preview { height: auto; -webkit-touch-callout: default; -webkit-user-select: auto; user-select: auto; }
.poster-canvas {
    position: fixed;
    left: -9999px;
    top: -9999px;
    pointer-events: none;
}
.preview {
    width: 100%;
    border-radius: 8px;
    display: block;
    margin-bottom: 16px;
}
</style>

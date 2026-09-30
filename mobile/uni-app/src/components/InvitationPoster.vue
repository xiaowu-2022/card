<script setup lang="ts">
import { getCurrentInstance, nextTick, ref } from 'vue';
import qrcode from 'qrcode-generator';
import Modal from './Modal.vue';
import { privateImage, native, photoUrl } from '../lib/api';
import { t } from '../lib/i18n';
const props = defineProps<{ link: string; code: string; background: string | null }>();
const open = ref(false),
    preview = ref(''),
    failed = ref(false),
    width = ref(900),
    height = ref(1000),
    saving = ref(false),
    instance = getCurrentInstance();
const canvasId = 'invitation-poster';
let generation = 0;
async function generate() {
    const run = ++generation;
    open.value = true;
    preview.value = '';
    failed.value = false;
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
        width.value = picture?.width ?? 900;
        height.value = picture?.height ?? 1000;
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
    open.value = false;
    generation++;
    preview.value = '';
}
async function save() {
    if (!preview.value || saving.value) return;
    saving.value = true;
    try {
        // #ifdef H5
        const a = document.createElement('a');
        a.href = preview.value;
        a.download = 'invitation-' + props.code + '.png';
        a.click();
        // #endif
        // #ifdef APP-PLUS
        await new Promise<void>((resolve, reject) =>
            uni.saveImageToPhotosAlbum({
                filePath: preview.value,
                success: () => resolve(),
                fail: reject,
            }),
        );
        uni.showToast({ title: t('Saved'), icon: 'none' });
        // #endif
    } catch {
        uni.showToast({ title: t('Unable to save image. Please try again.'), icon: 'none' });
    } finally {
        saving.value = false;
    }
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
        :title="t('Invitation poster')"
        :description="t('Save the poster or press and hold the image to save it on your phone.')"
        @close="close"
        ><template v-if="preview"
            ><image
                :src="preview"
                mode="widthFix"
                class="preview"
                :show-menu-by-longpress="true"
            /><button class="primary" :disabled="saving" @click="save">
                {{ t('Save image') }}
            </button></template
        ><text v-else>{{
            t(failed ? 'Could not generate poster. Please try again.' : 'Generating poster…')
        }}</text
        ><button v-if="failed" class="secondary" @click="generate">
            {{ t('Try again') }}
        </button></Modal
    >
</template>
<style scoped>
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

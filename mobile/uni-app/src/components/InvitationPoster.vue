<script setup lang="ts">
import { getCurrentInstance, nextTick, ref } from 'vue';
import qrcode from 'qrcode-generator';
import Modal from './Modal.vue';
import { privateImage, native } from '../lib/api';
import { t } from '../lib/i18n';
const props = defineProps<{ link: string; code: string; background: string | null }>();
const open = ref(false),
    preview = ref(''),
    failed = ref(false),
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
            const path = await privateImage('/client/promotion/poster-background');
            picture = await new Promise((resolve, reject) =>
                uni.getImageInfo({
                    src: path,
                    success: (r) => resolve({ path: r.path, width: r.width, height: r.height }),
                    fail: reject,
                }),
            );
        }
        if (run !== generation || !open.value) return;
        height.value = picture
            ? Math.ceil(Math.min(1600, Math.max(600, (900 * picture.height) / picture.width)))
            : 1000;
        await nextTick();
        const ctx = uni.createCanvasContext(canvasId, instance?.proxy);
        ctx.setFillStyle('#163e34');
        ctx.fillRect(0, 0, 900, height.value);
        if (picture) {
            const scale = Math.min(900 / picture.width, height.value / picture.height);
            ctx.drawImage(
                picture.path,
                (900 - picture.width * scale) / 2,
                (height.value - picture.height * scale) / 2,
                picture.width * scale,
                picture.height * scale,
            );
        } else {
            ctx.setFillStyle('#e2d7a9');
            ctx.setFontSize(60);
            ctx.setTextAlign('center');
            ctx.fillText(t('Invitation to join'), 450, 420, 800);
            ctx.setFontSize(32);
            ctx.fillText(t('Share your invitation link with a friend.'), 450, 500, 780);
        }
        const qr = qrcode(0, 'M');
        qr.addData(props.link, 'Byte');
        qr.make();
        const count = qr.getModuleCount(),
            size = 220,
            quiet = 4,
            total = count + quiet * 2,
            x = 340,
            y = height.value - 330;
        ctx.setFillStyle('#ffffff');
        ctx.fillRect(x, y, size, size);
        ctx.setFillStyle('#000000');
        for (let row = 0; row < count; row++)
            for (let col = 0; col < count; col++)
                if (qr.isDark(row, col)) {
                    const left = Math.round(((col + quiet) * size) / total),
                        top = Math.round(((row + quiet) * size) / total);
                    ctx.fillRect(
                        x + left,
                        y + top,
                        Math.round(((col + quiet + 1) * size) / total) - left,
                        Math.round(((row + quiet + 1) * size) / total) - top,
                    );
                }
        ctx.setFillStyle('#e2d7a9');
        ctx.setFontSize(26);
        ctx.setTextAlign('center');
        ctx.setTextBaseline('middle');
        ctx.setShadow(0, 0, 6, 'rgba(0,0,0,0.6)');
        ctx.fillText(t('Scan with your browser'), 450, height.value - 78, 600);
        await new Promise<void>((resolve) => ctx.draw(false, () => resolve()));
        const output = await new Promise<string>((resolve, reject) =>
            uni.canvasToTempFilePath(
                {
                    canvasId,
                    width: 900,
                    height: height.value,
                    destWidth: 900,
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
        :style="{ width: '900px', height: height + 'px' }"
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

import { staticAsset } from './origin';

// One app-wide reminder, shared by all pages. Only fresh authenticated counters
// may trigger it; stale offline data never schedules speech by itself.
let count = 0,
    lastSpoken = 0,
    active = true,
    authorized = false;
let nativeAudio: ReturnType<typeof uni.createInnerAudioContext> | undefined;
// #ifdef H5
let audio: HTMLAudioElement | undefined;
let unlocked = false;
function webAudio() {
    if (!audio) {
        audio = new Audio(staticAsset('audio/support-unread.m4a'));
        audio.preload = 'auto';
    }
    return audio;
}
function unlock() {
    if (!authorized || unlocked) return;
    unlocked = true;
    const player = webAudio();
    if (active && count > 0) {
        lastSpoken = 0;
        remindSupportUnread(count, authorized);
    } else {
        player.muted = true;
        void player
            .play()
            .then(() => {
                player.pause();
                player.currentTime = 0;
                player.muted = false;
            })
            .catch(() => {
                unlocked = false;
                player.muted = false;
            });
    }
}
document.addEventListener('pointerdown', unlock);
document.addEventListener('keydown', unlock);
// #endif
export function remindSupportUnread(value: number, isAgent: boolean) {
    authorized = isAgent === true;
    count = authorized ? value : 0;
    if (!count) {
        resetSupportReminder();
        return;
    }
    if (!active || Date.now() - lastSpoken < 60000) return;
    // #ifdef H5
    if (document.hidden || !unlocked) return;
    const player = webAudio();
    if (!player.paused) return;
    player.muted = false;
    player.currentTime = 0;
    lastSpoken = Date.now();
    void player.play().catch(() => {
        unlocked = false;
    });
    // #endif
    // #ifdef APP-PLUS
    if (!nativeAudio) {
        nativeAudio = uni.createInnerAudioContext();
        nativeAudio.src = '/static/audio/support-unread.m4a';
        nativeAudio.onError(() => {
            /* A device may prohibit audio playback. */
        });
    }
    lastSpoken = Date.now();
    nativeAudio.play();
    // #endif
}
export function pauseSupportReminder() {
    active = false;
    // #ifdef H5
    audio?.pause();
    // #endif
    nativeAudio?.stop();
}
export function resumeSupportReminder() {
    active = true;
}
export function resetSupportReminder() {
    authorized = false;
    count = 0;
    lastSpoken = 0;
    // #ifdef H5
    audio?.pause();
    // #endif
    nativeAudio?.stop();
}

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import ts from 'typescript';
let source = readFileSync('mobile/uni-app/src/lib/support-reminder.ts', 'utf8')
    .replace(/import .*?;\n/, '')
    .replace(/\/\/ #ifdef APP-PLUS[\s\S]*?\/\/ #endif/g, '');
let now = 100000, plays = 0, player;
const events = {};
class Audio {
    paused = true;
    constructor() { player = this; }
    play() { plays++; this.paused = false; return Promise.resolve(); }
    pause() { this.paused = true; }
}
const context = { exports: {}, Audio, staticAsset: v => v, Date: { now: () => now }, document: { hidden: false, addEventListener: (e, f) => { events[e] = f; } } };
vm.runInNewContext(ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText, context);
const reminder = context.exports;
reminder.remindSupportUnread(9, false);
events.pointerdown();
assert.equal(plays, 0, 'ordinary users never unlock or play audio');
reminder.remindSupportUnread(2, true);
assert.equal(plays, 0, 'wait for user gesture');
events.pointerdown();
assert.equal(plays, 1);
reminder.pauseSupportReminder(); reminder.resumeSupportReminder();
now += 30000; reminder.remindSupportUnread(2, true);
assert.equal(plays, 1, 'no repeat before a minute');
now += 30000; reminder.remindSupportUnread(2, true);
assert.equal(plays, 2, 'repeat after a minute');
reminder.resetSupportReminder(); now += 60000; reminder.remindSupportUnread(0, true);
assert.equal(plays, 2, 'no reminder once read');
reminder.pauseSupportReminder(); reminder.remindSupportUnread(3, true);
assert.equal(plays, 2, 'no background reminder');
reminder.resumeSupportReminder(); reminder.remindSupportUnread(3, true);
assert.equal(plays, 3);
reminder.remindSupportUnread(9, false);
events.pointerdown();
now += 60000;
assert.equal(plays, 3, 'revoked agents stop playback');
console.log('Support voice reminder timing checks passed');

reminder.remindSupportUnread(2, true);
const beforeNewMessage = plays;
player.paused = true;
now += 5000;
reminder.remindSupportUnread(3, true);
assert.equal(plays, beforeNewMessage + 1, 'new messages bypass minute delay');
player.paused = true;
now += 5000;
reminder.remindSupportUnread(3, true);
assert.equal(plays, beforeNewMessage + 1, 'unchanged unread waits a minute');
now += 55000;
reminder.remindSupportUnread(3, true);
assert.equal(plays, beforeNewMessage + 2, 'unread repeats a minute after new message alert');

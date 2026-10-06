import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import ts from 'typescript';
let source = readFileSync('mobile/uni-app/src/lib/support-reminder.ts', 'utf8')
    .replace(/import .*?;\n/, '')
    .replace(/\/\/ #ifdef APP-PLUS[\s\S]*?\/\/ #endif/g, '');
let now = 100000, plays = 0;
const events = {};
class Audio {
    paused = true;
    play() { plays++; this.paused = false; return Promise.resolve(); }
    pause() { this.paused = true; }
}
const context = { exports: {}, Audio, staticAsset: v => v, Date: { now: () => now }, document: { hidden: false, addEventListener: (e, f) => { events[e] = f; } } };
vm.runInNewContext(ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText, context);
const reminder = context.exports;
reminder.remindSupportUnread(2);
assert.equal(plays, 0, 'wait for user gesture');
events.pointerdown();
assert.equal(plays, 1);
reminder.pauseSupportReminder(); reminder.resumeSupportReminder();
now += 30000; reminder.remindSupportUnread(2);
assert.equal(plays, 1, 'no repeat before a minute');
now += 30000; reminder.remindSupportUnread(2);
assert.equal(plays, 2, 'repeat after a minute');
reminder.resetSupportReminder(); now += 60000; reminder.remindSupportUnread(0);
assert.equal(plays, 2, 'no reminder once read');
reminder.pauseSupportReminder(); reminder.remindSupportUnread(3);
assert.equal(plays, 2, 'no background reminder');
reminder.resumeSupportReminder(); reminder.remindSupportUnread(3);
assert.equal(plays, 3);
console.log('Support voice reminder timing checks passed');

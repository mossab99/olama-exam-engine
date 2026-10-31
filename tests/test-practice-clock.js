/** Run with: node tests/test-practice-clock.js */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/js/exam-engine.js'), 'utf8');
const start = source.indexOf('    function startTimer()');
const end = source.indexOf('    // ── Autosave', start);
assert.ok(start > 0 && end > start);
let now = 7200 * 1000, tick, submissions = 0;
const classes = new Set();
const timer = {
    classList: { add: name => classes.add(name), remove: (...names) => names.forEach(name => classes.delete(name)) },
    querySelector: () => timer.label || null,
    prepend: label => { timer.label = label; },
    setAttribute: () => {}
};
const display = {};
const state = {timerMode:'elapsed', elapsedSeconds:7200, elapsedOrigin:0, remainingSeconds:0};
const context = vm.createContext({state, Date:{now:()=>now},
    document:{getElementById:id=>id === 'oe-timer' ? timer : display, createElement:()=>({})},
    setInterval: callback=>{tick=callback;return 1;}, clearInterval:()=>{}, submitExam:()=>{submissions++;}});
vm.runInContext(source.slice(start, end) + '\nstartTimer();', context);
assert.equal(display.textContent, '120:00');
now += 5000;
tick();
assert.equal(display.textContent, '120:05'); // Wall time includes time spent in a background tab.
assert.equal(submissions, 0);
assert.equal(classes.size, 0);
assert.equal(timer.label.textContent, 'الوقت المنقضي');
state.timerMode = 'countdown';
state.remainingSeconds = 2;
vm.runInContext('startTimer();', context);
tick();
assert.equal(display.textContent, '00:01');
tick();
assert.equal(submissions, 1); // Timed exams retain their normal auto-submit behavior.
console.log('Practice elapsed clock and ordinary countdown behavior passed.');

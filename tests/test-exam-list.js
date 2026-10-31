/** Run with: node tests/test-exam-list.js */
const assert = require('node:assert/strict');
const list = require('../assets/js/exam-list.js');
assert.equal(list.hasFilters({exam_type:'exam', search:'   ', grade_id:'0'}), false);
for (const filters of [{grade_id:'2'}, {status:'active'}, {section_id:4}, {search:'  تقويم  '}]) {
    assert.equal(list.hasFilters(filters), true);
}
const exam = {
    subject_name:'اللغة الانجليزية',
    title:'2026-2027 - First Semester - امتحان التقويم الثاني - أول ثانوي - أ - اللغة الانجليزية'
};
assert.deepEqual(list.titleParts(exam), {
    subject:'اللغة الانجليزية', detail:'امتحان التقويم الثاني - أول ثانوي - أ', academic:'2026-2027 - First Semester'
});
assert.deepEqual(list.titleParts({subject_name:'الرياضيات',title:'مراجعة خاصة - النموذج أ'}), {
    subject:'الرياضيات', detail:'مراجعة خاصة - النموذج أ', academic:''
});
assert.deepEqual(list.titleParts({subject_name:'الرياضيات',title:'2026-2027 - First Semester - أول ثانوي - أ - الرياضيات - اختبار قصير 2'}), {
    subject:'الرياضيات', detail:'أول ثانوي - أ - اختبار قصير 2', academic:'2026-2027 - First Semester'
});
assert.equal(list.titleParts({title:'عنوان مخصص'}).subject, 'عنوان مخصص');
assert.equal(list.dateTime('2026-11-01 00:00:00'), '01/11/2026 00:00');
assert.equal(list.dateTime('2026-11-01T23:59:00'), '01/11/2026 23:59');
assert.equal(list.dateTime(null), '—');
assert.equal(list.dateTime('0000-00-00 00:00:00'), '—');
assert.equal(exam.title, '2026-2027 - First Semester - امتحان التقويم الثاني - أول ثانوي - أ - اللغة الانجليزية');
console.log('Exam list filtering, title presentation, and school-local dates passed.');

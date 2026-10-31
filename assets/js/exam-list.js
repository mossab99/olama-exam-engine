/** Presentation helpers for school exam lists. Stored titles remain unchanged. */
(function (root) {
    'use strict';
    const list = {
        hasFilters(filters) {
            return Boolean(filters.status || Number(filters.grade_id) > 0 || Number(filters.section_id) > 0 || String(filters.search || '').trim());
        },
        titleParts(exam) {
            const title = String(exam.title || '').trim();
            const subject = String(exam.subject_name || '').trim();
            const parts = title.split(/\s+-\s+/);
            let academic = '';
            // Auto-generated titles start with the academic year (after an optional placement label).
            const yearIndex = parts.findIndex(part => /^\d{4}\s*[-/]\s*\d{4}$/.test(part));
            if (yearIndex >= 0 && yearIndex <= 1 && parts.length > yearIndex + 2) {
                academic = parts.splice(yearIndex, 2).join(' - ');
            }
            const subjectIndex = subject ? parts.indexOf(subject) : -1;
            if (subjectIndex !== -1) parts.splice(subjectIndex, 1);
            return { subject: subject || title, detail: subject ? parts.join(' - ') : '', academic: academic };
        },
        dateTime(value) {
            // DATETIME values are already in the school's timezone; keep their wall-clock time.
            const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
            return match && match[1] !== '0000' ? match[3] + '/' + match[2] + '/' + match[1] + ' ' + match[4] + ':' + match[5] : '—';
        }
    };
    if (typeof module === 'object' && module.exports) module.exports = list;
    else root.OlamaExamList = list;
})(typeof window !== 'undefined' ? window : globalThis);

/**
 * Olama Exam Engine — Student Exam JS
 * Timer, autosave, question rendering, answer tracking, submit
 */
(function () {
    'use strict';

    const container = document.getElementById('oe-exam-container');
    if (!container) return;

    const config = {
        examId: parseInt(container.dataset.examId),
        ajaxUrl: container.dataset.ajaxUrl,
        nonce: container.dataset.nonce,
        studentUid: container.dataset.studentUid || '',
        isPreview: container.dataset.isPreview === '1',
    };

    const state = {
        attemptId: null,
        answers: {},
        questions: [],
        totalQuestions: 0,
        durationMinutes: 0,
        startedAt: null,
        remainingSeconds: 0,
        timerInterval: null,
        autosaveInterval: null,
        submitted: false,
    };

    // ── Initialize ────────────────────────────────────────────
    init();

    function init() {
        ajax('olama_exam_start', { 
            exam_id: config.examId,
            student_uid: config.studentUid
        }, function (data) {
            if (data.resumed) {
                state.remainingSeconds = data.remaining_seconds;
            }

            state.attemptId = data.attempt_id;
            state.questions = data.questions;
            state.totalQuestions = data.total_questions;
            state.durationMinutes = data.duration_minutes;
            state.startedAt = data.started_at;
            
            // Force answers to be an object map, ignoring PHP sparse arrays
            state.answers = (typeof data.answers === 'object' && !Array.isArray(data.answers)) ? data.answers : Object.assign({}, data.answers);
            console.log("Olama Exam JS: Init state answers", state.answers);

            if (!data.resumed) {
                state.remainingSeconds = data.duration_minutes * 60;
            }

            document.getElementById('oe-exam-title').textContent = data.exam_title;
            document.getElementById('oe-total-count').textContent = state.totalQuestions;

            renderQuestions();
            updateProgress();
            startTimer();
            startAutosave();

            document.getElementById('oe-loading').style.display = 'none';
            document.getElementById('oe-header').style.display = '';
            document.getElementById('oe-questions').style.display = '';
            document.getElementById('oe-footer').style.display = '';

            // Observe question cards for fade-in
            setTimeout(observeCards, 100);
        }, function (msg) {
            document.getElementById('oe-loading').innerHTML =
                '<p style="color:#dc2626;">❌ ' + escHtml(msg) + '</p>' +
                '<a href="?exam_view=dashboard" class="oe-btn oe-btn-outline" style="margin-top:16px;">← Back</a>';
        });
    }

    // ── Render Questions ──────────────────────────────────────
    function renderQuestions() {
        const wrap = document.getElementById('oe-questions');
        let html = '';

        state.questions.forEach(function (q, idx) {
            const qId = q.question_id;
            const isAnswered = state.answers[qId] !== undefined && state.answers[qId] !== null && state.answers[qId] !== '';
            const answeredClass = isAnswered ? 'oe-answered' : '';

            html += '<div class="oe-question-card ' + answeredClass + '" data-qid="' + qId + '" id="q-' + qId + '">';
            html += '  <div class="oe-q-header">';
            html += '    <span class="oe-q-number">' + (idx + 1) + '</span>';
            html += '    <span class="oe-q-status" id="status-' + qId + '">' + (isAnswered ? '✅' : '⬜') + '</span>';
            html += '  </div>';

            // Question image
            if (q.image_filename) {
                html += '  <img class="oe-q-image" src="' + config.ajaxUrl + '?action=olama_exam_stream_image&file=' + encodeURIComponent(q.image_filename) + '" alt="">';
            }

            // Question text
            html += '  <div class="oe-q-text">' + q.question_text + '</div>';

            // Answer area by type
            html += renderAnswerArea(q, qId);

            html += '</div>';
        });

        wrap.innerHTML = html;
        bindAnswerEvents();
    }

    function renderAnswerArea(q, qId) {
        const saved = state.answers[qId];

        switch (q.type) {
            case 'mcq':
                return renderMCQ(q, qId, saved);
            case 'tf':
                return renderTF(q, qId, saved);
            case 'short':
                return renderShort(qId, saved);
            case 'matching':
                return renderMatching(q, qId, saved);
            case 'ordering':
                return renderOrdering(q, qId, saved);
            case 'fill_blank':
                return renderFillBlank(q, qId, saved);
            case 'essay':
                return renderEssay(qId, saved);
            default:
                return '<p>Unsupported question type</p>';
        }
    }

    function renderMCQ(q, qId, saved) {
        let html = '<div class="oe-choices">';
        const choices = q.answers.choices || [];
        choices.forEach(function (choice, i) {
            const sel = (saved !== undefined && parseInt(saved) === i) ? ' oe-selected' : '';
            html += '<div class="oe-choice' + sel + '" data-qid="' + qId + '" data-value="' + i + '">';
            html += '  <div class="oe-choice-radio"></div>';
            html += '  <span>' + escHtml(choice) + '</span>';
            html += '</div>';
        });
        html += '</div>';
        return html;
    }

    function renderTF(q, qId, saved) {
        const trueClass = (saved === true || saved === 'true') ? ' oe-selected-true' : '';
        const falseClass = (saved === false || saved === 'false') ? ' oe-selected-false' : '';
        return '<div class="oe-tf-container">' +
            '<button type="button" class="oe-tf-btn' + trueClass + '" data-qid="' + qId + '" data-value="true">✅ ' + (document.documentElement.lang === 'ar' ? 'صح' : 'True') + '</button>' +
            '<button type="button" class="oe-tf-btn' + falseClass + '" data-qid="' + qId + '" data-value="false">❌ ' + (document.documentElement.lang === 'ar' ? 'خطأ' : 'False') + '</button>' +
            '</div>';
    }

    function renderShort(qId, saved) {
        return '<input type="text" class="oe-short-input" data-qid="' + qId + '" ' +
            'placeholder="' + (document.documentElement.lang === 'ar' ? 'اكتب إجابتك...' : 'Type your answer...') + '" ' +
            'value="' + escAttr(saved || '') + '">';
    }

    function renderMatching(q, qId, saved) {
        const lefts = q.answers.lefts || [];
        const rights = q.answers.rights || [];
        const savedArr = Array.isArray(saved) ? saved : [];

        let html = '<div class="oe-matching-wrap">';

        // Left column: items with dropdowns
        html += '<div class="oe-matching-left">';
        lefts.forEach(function (left, i) {
            html += '<div class="oe-matching-item">';
            html += '  <span class="oe-match-label">' + escHtml(left) + '</span>';
            html += '  <select class="oe-match-select" data-qid="' + qId + '" data-index="' + i + '">';
            html += '    <option value="">—</option>';
            rights.forEach(function (right) {
                const sel = (savedArr[i] === right) ? ' selected' : '';
                html += '    <option value="' + escAttr(right) + '"' + sel + '>' + escHtml(right) + '</option>';
            });
            html += '  </select>';
            html += '</div>';
        });
        html += '</div>';

        html += '</div>';
        return html;
    }

    function renderOrdering(q, qId, saved) {
        const items = Array.isArray(saved) && saved.length > 0 ? saved : (q.answers.items || []);

        let html = '<div class="oe-ordering-list" data-qid="' + qId + '">';
        items.forEach(function (item, i) {
            html += '<div class="oe-ordering-item" data-value="' + escAttr(item) + '">';
            html += '  <span class="oe-ordering-grip">≡</span>';
            html += '  <span class="oe-ordering-num">' + (i + 1) + '</span>';
            html += '  <span>' + escHtml(item) + '</span>';
            html += '  <div class="oe-ordering-arrows">';
            html += '    <button type="button" class="oe-order-up" data-qid="' + qId + '">▲</button>';
            html += '    <button type="button" class="oe-order-down" data-qid="' + qId + '">▼</button>';
            html += '  </div>';
            html += '</div>';
        });
        html += '</div>';
        return html;
    }

    function renderFillBlank(q, qId, saved) {
        let text = q.question_text;
        const savedArr = Array.isArray(saved) ? saved : [];
        let blankIdx = 0;

        // Replace ____ with inputs
        text = text.replace(/_{3,}/g, function () {
            const val = savedArr[blankIdx] || '';
            const inp = '<input type="text" class="oe-fill-input" data-qid="' + qId + '" data-blank="' + blankIdx + '" value="' + escAttr(val) + '">';
            blankIdx++;
            return inp;
        });

        return '<div class="oe-fill-text">' + text + '</div>';
    }

    function renderEssay(qId, saved) {
        const val = saved || '';
        const wordCount = val.trim() ? val.trim().split(/\s+/).length : 0;
        return '<textarea class="oe-essay-textarea" data-qid="' + qId + '" placeholder="' +
            (document.documentElement.lang === 'ar' ? 'اكتب إجابتك...' : 'Type your answer...') + '">' +
            escHtml(val) + '</textarea>' +
            '<div class="oe-word-count" id="wc-' + qId + '">' + wordCount + ' ' +
            (document.documentElement.lang === 'ar' ? 'كلمة' : 'words') + '</div>';
    }

    // ── Bind Answer Events ────────────────────────────────────
    function bindAnswerEvents() {
        // MCQ
        document.querySelectorAll('.oe-choice').forEach(function (el) {
            el.addEventListener('click', function () {
                const qId = this.dataset.qid;
                const val = parseInt(this.dataset.value);
                // Deselect others
                document.querySelectorAll('.oe-choice[data-qid="' + qId + '"]').forEach(function (c) {
                    c.classList.remove('oe-selected');
                });
                this.classList.add('oe-selected');
                setAnswer(qId, val);
            });
        });

        // T/F
        document.querySelectorAll('.oe-tf-btn').forEach(function (el) {
            el.addEventListener('click', function () {
                const qId = this.dataset.qid;
                const val = this.dataset.value;
                document.querySelectorAll('.oe-tf-btn[data-qid="' + qId + '"]').forEach(function (b) {
                    b.classList.remove('oe-selected-true', 'oe-selected-false');
                });
                this.classList.add(val === 'true' ? 'oe-selected-true' : 'oe-selected-false');
                setAnswer(qId, val);
            });
        });

        // Short Answer
        document.querySelectorAll('.oe-short-input').forEach(function (el) {
            el.addEventListener('input', function () {
                setAnswer(this.dataset.qid, this.value);
            });
        });

        // Matching selects
        document.querySelectorAll('.oe-match-select').forEach(function (el) {
            el.addEventListener('change', function () {
                const qId = this.dataset.qid;
                const selects = document.querySelectorAll('.oe-match-select[data-qid="' + qId + '"]');
                const vals = [];
                selects.forEach(function (s) { vals.push(s.value); });
                setAnswer(qId, vals);
            });
        });

        // Ordering arrows
        document.querySelectorAll('.oe-order-up').forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.stopPropagation();
                const item = this.closest('.oe-ordering-item');
                const prev = item.previousElementSibling;
                if (prev) {
                    item.parentNode.insertBefore(item, prev);
                    updateOrderingAnswer(this.dataset.qid);
                }
            });
        });
        document.querySelectorAll('.oe-order-down').forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.stopPropagation();
                const item = this.closest('.oe-ordering-item');
                const next = item.nextElementSibling;
                if (next) {
                    item.parentNode.insertBefore(next, item);
                    updateOrderingAnswer(this.dataset.qid);
                }
            });
        });

        // Fill in blank
        document.querySelectorAll('.oe-fill-input').forEach(function (el) {
            el.addEventListener('input', function () {
                const qId = this.dataset.qid;
                const inputs = document.querySelectorAll('.oe-fill-input[data-qid="' + qId + '"]');
                const vals = [];
                inputs.forEach(function (inp) { vals.push(inp.value); });
                setAnswer(qId, vals);
            });
        });

        // Essay
        document.querySelectorAll('.oe-essay-textarea').forEach(function (el) {
            el.addEventListener('input', function () {
                setAnswer(this.dataset.qid, this.value);
                const wc = this.value.trim() ? this.value.trim().split(/\s+/).length : 0;
                const wcEl = document.getElementById('wc-' + this.dataset.qid);
                if (wcEl) wcEl.textContent = wc + ' ' + (document.documentElement.lang === 'ar' ? 'كلمة' : 'words');
            });
        });

        // Submit button
        document.getElementById('oe-submit-btn').addEventListener('click', confirmSubmit);

        // Modal buttons
        document.getElementById('oe-confirm-cancel').addEventListener('click', function () {
            document.getElementById('oe-confirm-modal').style.display = 'none';
        });
        document.getElementById('oe-confirm-ok').addEventListener('click', function () {
            document.getElementById('oe-confirm-modal').style.display = 'none';
            submitExam();
        });
    }

    function updateOrderingAnswer(qId) {
        const list = document.querySelector('.oe-ordering-list[data-qid="' + qId + '"]');
        const items = list.querySelectorAll('.oe-ordering-item');
        const vals = [];
        items.forEach(function (item, i) {
            vals.push(item.dataset.value);
            item.querySelector('.oe-ordering-num').textContent = i + 1;
        });
        setAnswer(qId, vals);
    }

    // ── Answer Tracking ───────────────────────────────────────
    function setAnswer(qId, value) {
        state.answers[qId] = value;
        updateProgress();

        // Update card visual
        const card = document.getElementById('q-' + qId);
        const statusEl = document.getElementById('status-' + qId);
        if (card) {
            const hasAnswer = value !== null && value !== '' && value !== undefined &&
                !(Array.isArray(value) && value.every(function (v) { return v === ''; }));
            if (hasAnswer) {
                card.classList.add('oe-answered');
                if (statusEl) statusEl.textContent = '✅';
            } else {
                card.classList.remove('oe-answered');
                if (statusEl) statusEl.textContent = '⬜';
            }
        }
    }

    function updateProgress() {
        let answered = 0;
        state.questions.forEach(function (q) {
            const a = state.answers[q.question_id];
            if (a !== undefined && a !== null && a !== '' &&
                !(Array.isArray(a) && a.every(function (v) { return v === ''; }))) {
                answered++;
            }
        });
        document.getElementById('oe-answered-count').textContent = answered;
        const pct = state.totalQuestions > 0 ? (answered / state.totalQuestions * 100) : 0;
        document.getElementById('oe-progress-bar').style.width = pct + '%';
    }

    // ── Timer ─────────────────────────────────────────────────
    function startTimer() {
        updateTimerDisplay();
        state.timerInterval = setInterval(function () {
            state.remainingSeconds--;
            updateTimerDisplay();

            if (state.remainingSeconds <= 0) {
                clearInterval(state.timerInterval);
                submitExam(true);
            }
        }, 1000);
    }

    function updateTimerDisplay() {
        const secs = Math.max(0, state.remainingSeconds);
        const m = Math.floor(secs / 60);
        const s = secs % 60;
        const display = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;

        const timerEl = document.getElementById('oe-timer');
        document.getElementById('oe-timer-display').textContent = display;

        // Timer states
        timerEl.classList.remove('oe-warning', 'oe-critical');
        if (secs <= 60) {
            timerEl.classList.add('oe-critical');
        } else if (secs <= 300) {
            timerEl.classList.add('oe-warning');
        }
    }

    // ── Autosave ──────────────────────────────────────────────
    function startAutosave() {
        state.autosaveInterval = setInterval(doAutosave, 60000); // every 60s
    }

    function doAutosave() {
        if (state.submitted) return;

        const statusEl = document.getElementById('oe-autosave-status');
        statusEl.className = 'oe-autosave-status oe-saving';
        statusEl.textContent = '💾 ' + (document.documentElement.lang === 'ar' ? 'جاري الحفظ...' : 'Saving...');

        ajax('olama_exam_autosave', {
            attempt_id: state.attemptId,
            student_uid: config.studentUid,
            answers_json: JSON.stringify(state.answers),
        }, function () {
            statusEl.className = 'oe-autosave-status oe-saved';
            statusEl.textContent = '💾 ' + (document.documentElement.lang === 'ar' ? 'تم الحفظ' : 'Saved just now');
            setTimeout(function () {
                statusEl.textContent = '';
                statusEl.className = 'oe-autosave-status';
            }, 5000);
        }, function () {
            statusEl.className = 'oe-autosave-status oe-failed';
            statusEl.textContent = '⚠️ ' + (document.documentElement.lang === 'ar' ? 'فشل الحفظ، جاري إعادة المحاولة...' : 'Save failed, retrying...');
            setTimeout(doAutosave, 10000);
        });
    }

    // ── Submit ─────────────────────────────────────────────────
    function confirmSubmit() {
        // Count unanswered
        let unanswered = 0;
        state.questions.forEach(function (q) {
            const a = state.answers[q.question_id];
            if (a === undefined || a === null || a === '' ||
                (Array.isArray(a) && a.every(function (v) { return v === ''; }))) {
                unanswered++;
            }
        });

        const text = document.getElementById('oe-confirm-text');
        if (unanswered > 0) {
            text.textContent = (document.documentElement.lang === 'ar'
                ? 'لديك ' + unanswered + ' سؤال/أسئلة بدون إجابة. هل أنت متأكد من التسليم؟'
                : 'You have ' + unanswered + ' unanswered question(s). Are you sure you want to submit?');
        } else {
            text.textContent = (document.documentElement.lang === 'ar'
                ? 'هل أنت متأكد من تسليم الاختبار؟'
                : 'Are you sure you want to submit this exam?');
        }

        document.getElementById('oe-confirm-modal').style.display = '';
    }

    function submitExam(isTimeout) {
        if (state.submitted) return;
        state.submitted = true;

        clearInterval(state.timerInterval);
        clearInterval(state.autosaveInterval);

        console.log("Olama Exam JS: Calling autosave before submit with answers:", state.answers);

        // Save answers first, then submit
        ajax('olama_exam_autosave', {
            attempt_id: state.attemptId,
            student_uid: config.studentUid,
            answers_json: JSON.stringify(state.answers),
        }, function () {
            console.log("Olama Exam JS: Autosave successful before submit");
            doSubmit();
        }, function (msg) {
            console.log("Olama Exam JS: Autosave FAILED before submit:", msg);
            doSubmit(); // Submit even if save fails
        });

        function doSubmit() {
            const submitBtn = document.getElementById('oe-submit-btn');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = document.documentElement.lang === 'ar' ? '⏳ جاري التسليم...' : '⏳ Submitting...';
            }

            ajax('olama_exam_submit', {
                attempt_id: state.attemptId,
                student_uid: config.studentUid,
            }, function (data) {
                showResults(data);
            }, function (msg) {
                alert('Error: ' + msg);
                state.submitted = false;
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = '✅ Submit Exam';
                }
            });
        }
    }

    // ── Results Display ───────────────────────────────────────
    function showResults(data) {
        document.getElementById('oe-header').style.display = 'none';
        document.getElementById('oe-questions').style.display = 'none';
        document.getElementById('oe-footer').style.display = 'none';

        const resultsEl = document.getElementById('oe-results');
        resultsEl.style.display = '';

        const isArabic = document.documentElement.lang === 'ar';
        const resultLabels = {
            'pass': isArabic ? 'ناجح ✅' : 'PASS ✅',
            'fail': isArabic ? 'راسب ❌' : 'FAIL ❌',
            'pending': isArabic ? 'قيد المراجعة ⏳' : 'PENDING ⏳'
        };

        const pct = parseFloat(data.percentage);
        const radius = 90;
        const circumference = 2 * Math.PI * radius;
        const offset = circumference - (pct / 100) * circumference;

        let html = '<div class="oe-score-summary">';
        
        // Circular Progress
        html += '<div class="oe-result-circle-wrap">';
        html += '  <svg class="oe-result-svg" viewBox="0 0 200 200">';
        html += '    <circle class="oe-result-bg-circle" cx="100" cy="100" r="' + radius + '"></circle>';
        html += '    <circle class="oe-result-progress-circle" id="oe-result-circle" cx="100" cy="100" r="' + radius + '"></circle>';
        html += '  </svg>';
        html += '  <div class="oe-score-content">';
        html += '    <span class="oe-score-number">' + data.percentage + '%</span>';
        html += '    <span class="oe-score-text">' + data.score + ' / ' + data.max_score + '</span>';
        html += '  </div>';
        html += '</div>';

        html += '<div class="oe-result-label oe-result-' + data.result + '">' + (resultLabels[data.result] || data.result) + '</div>';

        // Stats Grid
        html += '<div class="oe-stats-grid">';
        
        // Total Questions
        html += '  <div class="oe-stat-card">';
        html += '    <span class="oe-stat-val">' + state.totalQuestions + '</span>';
        html += '    <span class="oe-stat-label">' + (isArabic ? 'إجمالي الأسئلة' : 'Total') + '</span>';
        html += '  </div>';

        // Correct
        let correctCount = 0;
        if (data.details) {
            correctCount = data.details.filter(function(d) { return d.status === 'correct'; }).length;
        }
        html += '  <div class="oe-stat-card">';
        html += '    <span class="oe-stat-val" style="color:var(--oe-success)">' + correctCount + '</span>';
        html += '    <span class="oe-stat-label">' + (isArabic ? 'صحيحة' : 'Correct') + '</span>';
        html += '  </div>';

        // Result Color indicator card
        html += '  <div class="oe-stat-card">';
        html += '    <span class="oe-stat-val">' + data.percentage + '%</span>';
        html += '    <span class="oe-stat-label">' + (isArabic ? 'النسبة' : 'Grade') + '</span>';
        html += '  </div>';

        html += '</div>'; // close stats-grid
        html += '</div>'; // close score-summary

        if (data.show_results && data.details) {
            html += '<div class="oe-answer-review">';
            data.details.forEach(function (d, i) {
                const cardClass = d.status === 'correct' ? 'oe-correct' : (d.status === 'pending' ? 'oe-pending' : 'oe-incorrect');
                const statusIcon = d.status === 'correct' ? '✅' : (d.status === 'pending' ? '⏳' : '❌');

                html += '<div class="oe-review-card ' + cardClass + '">';
                html += '  <div class="oe-review-header">';
                html += '    <span class="oe-review-num">' + (isArabic ? 'سؤال ' : 'Question ') + (i + 1) + '</span>';
                html += '    <span class="oe-review-status">' + statusIcon + '</span>';
                html += '  </div>';
                html += '  <div class="oe-review-question">' + escHtml(d.text) + '</div>';
                if (d.explanation) {
                    html += '  <div class="oe-review-explanation">📖 ' + escHtml(d.explanation) + '</div>';
                }
                html += '</div>';
            });
            html += '</div>';
        }

        html += '<div style="text-align:center; margin-top:40px;">';
        html += '<a href="?page=olama-exam-create" class="oe-btn oe-btn-primary oe-btn-lg">← ' + (isArabic ? 'العودة للوحة التحكم' : 'Back to Dashboard') + '</a>';
        html += '</div>';

        resultsEl.innerHTML = html;

        // Animate the circle
        setTimeout(function() {
            const circle = document.getElementById('oe-result-circle');
            if (circle) {
                circle.style.strokeDashoffset = offset;
            }
        }, 100);

        // Scroll to top to see result
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // ── Intersection Observer for fade-in ─────────────────────
    function observeCards() {
        const cards = document.querySelectorAll('.oe-question-card');
        if (!('IntersectionObserver' in window)) {
            cards.forEach(function (c) { c.classList.add('oe-visible'); });
            return;
        }

        const observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('oe-visible');
                }
            });
        }, { threshold: 0.1 });

        cards.forEach(function (card) { observer.observe(card); });
    }

    // ── AJAX Helper ───────────────────────────────────────────
    function ajax(action, data, onSuccess, onError) {
        data.action = action;
        data.nonce = config.nonce;
        if (config.isPreview) {
            data.is_preview = 1;
        }

        const formData = new FormData();
        Object.keys(data).forEach(function (key) {
            formData.append(key, typeof data[key] === 'object' ? JSON.stringify(data[key]) : data[key]);
        });

        fetch(config.ajaxUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
        })
            .then(function (response) { return response.json(); })
            .then(function (result) {
                if (result.success) {
                    onSuccess(result.data);
                } else {
                    (onError || function () { })(result.data ? result.data.message : 'Unknown error');
                }
            })
            .catch(function (err) {
                (onError || function () { })(err.message || 'Network error');
            });
    }

    // ── Utilities ─────────────────────────────────────────────
    function escHtml(str) {
        if (!str) return '';
        var d = document.createElement('div');
        d.textContent = String(str);
        return d.innerHTML;
    }

    function escAttr(str) {
        return String(str || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    // ── Page Unload Warning ───────────────────────────────────
    window.addEventListener('beforeunload', function (e) {
        if (!state.submitted && state.attemptId) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

})();

/**
 * Olama Exam Engine — Drag & Drop
 * Handles Matching and Ordering question types
 * Stub — full implementation in Phase 4
 */

(function ($) {
    'use strict';

    window.ExamDragDrop = {
        /**
         * Initialize matching question drag & drop
         */
        initMatching(containerId) {
            const container = document.getElementById(containerId);
            if (!container) return;

            const items = container.querySelectorAll('.exam-matching-item');
            const targets = container.querySelectorAll('.exam-matching-target');

            items.forEach(item => {
                item.setAttribute('draggable', true);

                item.addEventListener('dragstart', (e) => {
                    e.dataTransfer.setData('text/plain', item.dataset.id);
                    item.classList.add('dragging');
                });

                item.addEventListener('dragend', () => {
                    item.classList.remove('dragging');
                });

                // Touch support
                this.addTouchDrag(item);
            });

            targets.forEach(target => {
                target.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    target.classList.add('drag-over');
                });

                target.addEventListener('dragleave', () => {
                    target.classList.remove('drag-over');
                });

                target.addEventListener('drop', (e) => {
                    e.preventDefault();
                    target.classList.remove('drag-over');
                    const itemId = e.dataTransfer.getData('text/plain');
                    const draggedItem = container.querySelector(`[data-id="${itemId}"]`);

                    if (draggedItem && target) {
                        // If target already has an item, swap it back
                        const existing = target.querySelector('.exam-matching-item');
                        if (existing) {
                            container.querySelector('.exam-matching-column:first-child').appendChild(existing);
                        }
                        target.appendChild(draggedItem);
                        target.classList.add('matched');
                        this.updateMatchingAnswer(containerId);
                    }
                });
            });
        },

        /**
         * Get current matching answer
         */
        getMatchingAnswer(containerId) {
            const container = document.getElementById(containerId);
            if (!container) return {};

            const result = {};
            container.querySelectorAll('.exam-matching-target').forEach(target => {
                const item = target.querySelector('.exam-matching-item');
                if (item) {
                    result[target.dataset.id] = item.dataset.id;
                }
            });
            return result;
        },

        /**
         * Update matching answer in exam engine
         */
        updateMatchingAnswer(containerId) {
            const $container = $('#' + containerId);
            const qid = $container.closest('.exam-question-card').data('question-id');
            const answer = this.getMatchingAnswer(containerId);
            if (window.ExamEngine) {
                ExamEngine.onAnswerChange(qid, answer);
            }
        },

        /**
         * Initialize ordering question sortable list
         */
        initOrdering(containerId) {
            const container = document.getElementById(containerId);
            if (!container) return;

            const items = container.querySelectorAll('.exam-ordering-item');

            items.forEach(item => {
                item.setAttribute('draggable', true);

                item.addEventListener('dragstart', (e) => {
                    e.dataTransfer.setData('text/plain', item.dataset.index);
                    item.classList.add('dragging');
                });

                item.addEventListener('dragend', () => {
                    item.classList.remove('dragging');
                    this.renumberOrdering(containerId);
                    this.updateOrderingAnswer(containerId);
                });

                item.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    const dragging = container.querySelector('.dragging');
                    if (dragging && item !== dragging) {
                        const rect = item.getBoundingClientRect();
                        const midY = rect.top + rect.height / 2;
                        if (e.clientY < midY) {
                            container.insertBefore(dragging, item);
                        } else {
                            container.insertBefore(dragging, item.nextSibling);
                        }
                    }
                });

                // Touch support
                this.addTouchDrag(item, container, true);
            });
        },

        /**
         * Renumber ordering items after drag
         */
        renumberOrdering(containerId) {
            const container = document.getElementById(containerId);
            container.querySelectorAll('.exam-ordering-item').forEach((item, idx) => {
                item.querySelector('.exam-ordering-number').textContent = idx + 1;
            });
        },

        /**
         * Get current ordering answer
         */
        getOrderingAnswer(containerId) {
            const container = document.getElementById(containerId);
            if (!container) return [];

            return Array.from(container.querySelectorAll('.exam-ordering-item'))
                .map(item => parseInt(item.dataset.index));
        },

        /**
         * Update ordering answer in exam engine
         */
        updateOrderingAnswer(containerId) {
            const $container = $('#' + containerId);
            const qid = $container.closest('.exam-question-card').data('question-id');
            const answer = this.getOrderingAnswer(containerId);
            if (window.ExamEngine) {
                ExamEngine.onAnswerChange(qid, answer);
            }
        },

        /**
         * Add touch support for drag & drop
         */
        addTouchDrag(element, sortContainer, isSortable) {
            let startY = 0;
            let currentY = 0;
            let clone = null;

            element.addEventListener('touchstart', (e) => {
                startY = e.touches[0].clientY;
                element.classList.add('dragging');
            }, { passive: true });

            element.addEventListener('touchmove', (e) => {
                currentY = e.touches[0].clientY;
                // Phase 4: implement full touch drag
            }, { passive: false });

            element.addEventListener('touchend', () => {
                element.classList.remove('dragging');
                // Phase 4: finalize drop
            });
        },
    };

})(jQuery);

/**
 * Olama Exam Engine — Admin JavaScript
 * Handles modal controls, toasts, and admin interactions.
 * Category CRUD has been removed — questions are now organized by curriculum units.
 */

(function ($) {
    'use strict';

    const ExamAdmin = {
        /**
         * Initialize admin interactions
         */
        init() {
            this.bindEvents();
        },

        /**
         * Bind event handlers
         */
        bindEvents() {
            // Modal controls
            $(document).on('click', '.olama-exam-modal-close', this.closeModal);
            $(document).on('click', '.olama-exam-modal-overlay', function (e) {
                if (e.target === this) ExamAdmin.closeModal();
            });

            // Select-all checkbox
            $(document).on('click', '#olama-exam-select-all', this.toggleSelectAll);
        },

        /**
         * Show toast notification
         */
        toast(message, type = 'success') {
            const $toast = $('<div class="olama-exam-toast olama-exam-toast-' + type + '">' + message + '</div>');
            $('body').append($toast);
            setTimeout(() => $toast.fadeOut(300, () => $toast.remove()), 3000);
        },

        /**
         * Open modal
         */
        openModal(modalId) {
            $('#' + modalId).addClass('active');
        },

        /**
         * Close modal
         */
        closeModal() {
            $('.olama-exam-modal-overlay').removeClass('active');
        },

        /**
         * AJAX helper
         */
        ajax(action, data = {}) {
            return $.ajax({
                url: olamaExam.ajaxUrl,
                type: 'POST',
                data: {
                    action: action,
                    nonce: olamaExam.nonce,
                    ...data
                }
            });
        },

        /**
         * Debounce helper
         */
        debounce(func, wait) {
            let timeout;
            return function (...args) {
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(this, args), wait);
            };
        },

        /**
         * Switch question type - show/hide relevant fields
         */
        switchQuestionType() {
            const type = $(this).val();
            $('.question-type-fields').hide();
            $('#fields-' + type).show();
        },

        /**
         * Toggle select-all checkbox
         */
        toggleSelectAll() {
            const checked = $(this).prop('checked');
            $('.olama-exam-row-check').prop('checked', checked);
        },
    };

    // Expose to global scope for inline scripts in view files
    window.ExamAdmin = ExamAdmin;

    // Initialize on DOM ready
    $(document).ready(function () {
        ExamAdmin.init();
    });

})(jQuery);

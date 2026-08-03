/**
 * Scoped MathJax integration for Olama Exam Engine.
 *
 * Exposes window.OlamaExamMath.typeset(root) and clear(root). A mutation
 * observer catches AJAX-rendered exam fragments that do not call it directly.
 */
(function (window, document) {
    'use strict';

    const mathSelector = [
        '.oee-math',
        '[data-oee-math]',
        '.oe-q-text',
        '.oe-choices',
        '.oe-matching-wrap',
        '.oe-ordering-list',
        '.oe-fill-text',
        '.oe-review-question',
        '.oe-review-answer',
        '.oe-review-correct',
        '.oe-review-explanation',
        '.oee-question-text'
    ].join(',');

    let queue = Promise.resolve();
    let pendingRoots = new Set();
    let scheduled = false;

    function hasMathSource(element) {
        if (!element || !element.textContent) return false;
        return /\\\(|\\\[|\$\$|\$(?!\s)/.test(element.textContent);
    }

    function collect(root) {
        const elements = [];
        if (!root) return elements;

        if (root.nodeType === 1 && root.matches(mathSelector) && hasMathSource(root)) {
            elements.push(root);
        }

        if (root.querySelectorAll) {
            root.querySelectorAll(mathSelector).forEach(function (element) {
                if (hasMathSource(element)) elements.push(element);
            });
        }

        return elements.filter(function (element, index, all) {
            return all.indexOf(element) === index && !all.some(function (parent) {
                return parent !== element && parent.contains(element);
            });
        });
    }

    function typeset(root) {
        const elements = collect(root || document);
        if (!elements.length) return queue;

        queue = queue.then(function () {
            if (!window.MathJax || !window.MathJax.typesetPromise) return null;
            return window.MathJax.typesetPromise(elements);
        }).catch(function (error) {
            if (window.console && console.warn) {
                console.warn('Olama Exam: MathJax typesetting failed.', error);
            }
        });

        return queue;
    }

    function clear(root) {
        if (!root || !window.MathJax || !window.MathJax.typesetClear) return;
        window.MathJax.typesetClear([root]);
    }

    function schedule(root) {
        if (root) pendingRoots.add(root);
        if (scheduled) return;
        scheduled = true;

        window.requestAnimationFrame(function () {
            scheduled = false;
            const roots = Array.from(pendingRoots);
            pendingRoots = new Set();
            roots.forEach(typeset);
        });
    }

    function observe() {
        if (!window.MutationObserver || !document.body) return;

        const observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (node.nodeType !== 1 || node.tagName === 'MJX-CONTAINER') return;
                    if ((node.matches && node.matches(mathSelector)) ||
                        (node.querySelector && node.querySelector(mathSelector))) {
                        schedule(node);
                    }
                });
            });
        });

        observer.observe(document.body, { childList: true, subtree: true });
    }

    window.OlamaExamMath = {
        typeset: typeset,
        clear: clear,
        schedule: schedule
    };

    function ready() {
        typeset(document);
        observe();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ready);
    } else {
        ready();
    }
})(window, document);

# MathJax runtime

This directory contains the browser distribution files from `mathjax@4.1.3`.
They are distributed under the Apache License 2.0 in `LICENSE`.

The combined `tex-chtml.js` component enables MathJax 4 speech and Braille
generation by default. Its worker and the rule maps needed by the configured
English speech and Nemeth Braille defaults are therefore bundled under `sre/`
so speech generation remains same-origin and does not fall back to a CDN.

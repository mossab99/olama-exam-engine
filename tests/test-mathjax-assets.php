<?php
/** Run with: php tests/test-mathjax-assets.php */

$root = dirname(__DIR__) . '/assets/vendor/mathjax';
$assets = array(
    'tex-chtml.js' => '6ecd14970a73fca8bb115f8d6967aef818135dee0091339061c41ada230072b6',
    'ui/safe.js' => 'ddd5b5eb3076bdf71c9b0508c58fae095b441a22b7bf2ff2e1ad5ace389f49b2',
    'sre/speech-worker.js' => '80bd663f2d48505291dcc256728a4fe3be1be4b73d3675b905bd51b1c431745b',
    'sre/mathmaps/base.json' => '72558700556d997a95e9281044e330eba9a472dda43642a9b5cc0483294d875f',
    'sre/mathmaps/en.json' => '5e60d1843351966a159cc409eb73e9abc7c7e375a3311c40d35843d70fee79fb',
    'sre/mathmaps/nemeth.json' => '16f30ad5bc7db02bbcce4623b108626f1a73d65660799891f23efd76dcaa333e',
);

foreach ($assets as $relative_path => $expected_hash) {
    $path = $root . '/' . $relative_path;
    if (!is_file($path)) {
        fwrite(STDERR, "Missing MathJax asset: {$relative_path}\n");
        exit(1);
    }

    if (hash_file('sha256', $path) !== $expected_hash) {
        fwrite(STDERR, "Unexpected MathJax 4.1.3 asset contents: {$relative_path}\n");
        exit(1);
    }
}

foreach (array('base.json', 'en.json', 'nemeth.json') as $map) {
    $contents = file_get_contents($root . '/sre/mathmaps/' . $map);
    json_decode($contents, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        fwrite(STDERR, "Invalid MathJax speech rule map: {$map}\n");
        exit(1);
    }
}

echo "MathJax 4.1.3 runtime assets are complete and verified.\n";

<?php
/** Run with: php tests/test-acceptance-routing.php */
define('ABSPATH', __DIR__);

$actions = array();
$rules = array();
$saved_rules = array();
$flushes = 0;
function add_action($hook, $callback, $priority = 10) { global $actions; $actions[$hook][] = $callback; }
function add_filter($hook, $callback) {}
function add_rewrite_rule($route, $query, $position) { global $rules; $rules[$route] = $query; }
function get_option($key, $default = false) { global $saved_rules; return $key === 'rewrite_rules' ? $saved_rules : true; }
function flush_rewrite_rules($hard) { global $flushes, $saved_rules, $rules; $flushes++; $saved_rules = $rules; }
function get_query_var($key) { global $request; return $request->query_vars[$key] ?? ''; }
function status_header($code) { global $status; $status = $code; }
function nocache_headers() { global $no_cache; $no_cache = true; }
class Acceptance_Route_Reached extends Exception {}
class OEE_Acceptance_Tests {
    public static function get_by_token($token) { throw new Acceptance_Route_Reached($token); }
}
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }

require_once __DIR__ . '/../includes/acceptance/class-oee-acceptance-public.php';
$controller = new OEE_Acceptance_Public();
check($flushes === 0, 'Registration must not flush before all routes are registered.');
check(isset($actions['parse_request'], $actions['wp_loaded']), 'Recovery hooks must be registered.');
$controller->ensure_rewrite_rule();
check($flushes === 1, 'Missing rules must be repaired despite existing legacy flags.');
$controller->ensure_rewrite_rule();
check($flushes === 1, 'Healthy rules must not be flushed repeatedly.');
$saved_rules['^acceptance/([a-zA-Z0-9]+)/?$'] = 'index.php?wrong=1';
$controller->ensure_rewrite_rule();
check($flushes === 2, 'An incorrect saved target must be repaired.');

foreach (array('acceptance/KBWYY7RfzD0bKgFKb7kUGdvBj2qyrJX', 'acceptance/AbC123/') as $path) {
    $request = (object) array('request' => $path, 'query_vars' => array('error' => '404', 'pagename' => $path));
    $controller->resolve_acceptance_route($request);
    check(count($request->query_vars) === 1, 'Stale page and 404 query variables must be removed.');
    $wp_query = (object) array('is_404' => true);
    $status = 404;
    $no_cache = false;
    try { $controller->handle_acceptance_request(); } catch (Acceptance_Route_Reached $e) {
        check($e->getMessage() === $request->query_vars['oee_acceptance_token'], 'Preserve token case.');
        check(!$wp_query->is_404 && $status === 200 && $no_cache, 'Exam pages must send an uncached successful response.');
        continue;
    }
    throw new RuntimeException('The exam controller was not reached.');
}
foreach (array('another-page', 'acceptance/abc/extra', 'acceptance/abc-def', 'prefix/acceptance/abc') as $path) {
    $request = (object) array('request' => $path, 'query_vars' => array('pagename' => $path));
    $controller->resolve_acceptance_route($request);
    check($request->query_vars === array('pagename' => $path), 'Unrelated routes must be preserved.');
}
echo "Acceptance routing checks passed.\n";

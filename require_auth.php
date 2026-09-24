<?php
// 401 unless the request is from a logged-in user or a machine client.
//
//   Browser: a valid portal session (the sessionObject cookie login_sc.php
//   signs), checked by signCollect-v2's menu_beta/php_api/session.php, as in
//   signlab_camera-control's require_login.php.
//
//   Machine (signlab_drs-pipeline's crop_fix.py): header X-Api-Token equal to
//   VIDEOFIX_TOKEN from the signcollect-lib env file (sc_env(), normally
//   <webroot>/.env), or from the process environment on a host without the
//   library. A token that is sent but wrong is refused outright.
//
// Fails closed: no session library and no token configured means 401.
//
//   require_once __DIR__ . '/require_auth.php';   // at top level, not in a function

require_once __DIR__ . '/sc_paths.php';

function videofix_token(): string
{
    $want = '';
    if (function_exists('sc_env')) {
        try { $want = (string)(sc_env()['VIDEOFIX_TOKEN'] ?? ''); } catch (RuntimeException $e) {}
    }
    if ($want === '') $want = (string)getenv('VIDEOFIX_TOKEN');
    return $want;
}

function videofix_deny(): void
{
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'not logged in', 'login' => '/login.html']);
    exit;
}

if (isset($_SERVER['HTTP_X_API_TOKEN'])) {
    $videofix_want = videofix_token();
    if ($videofix_want === '') {
        error_log('videoFix: VIDEOFIX_TOKEN is not configured - refusing token request');
    }
    if ($videofix_want === '' || !hash_equals($videofix_want, (string)$_SERVER['HTTP_X_API_TOKEN'])) {
        videofix_deny();
    }
    unset($videofix_want);
} else {
    // Required here, at top level, so mysql_config's globals stay global.
    $videofix_session_lib = sc_path('menu_beta/php_api/session.php');
    if (is_readable($videofix_session_lib)) {
        require_once dirname($videofix_session_lib) . '/db.php';
        require_once $videofix_session_lib;
    } else {
        error_log('videoFix: ' . $videofix_session_lib . ' missing - refusing request');
    }
    unset($videofix_session_lib);

    if (!function_exists('current_session') || current_session() === null) {
        videofix_deny();
    }
}

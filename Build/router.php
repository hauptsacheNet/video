<?php
/**
 * Router script for the PHP built-in web server used by E2E tests.
 *
 * It sets the cross-origin isolation headers required for ffmpeg.wasm
 * (SharedArrayBuffer) on every response. Static files are served by this
 * router itself (rather than letting the built-in server handle them) so
 * the headers are applied to JavaScript / Wasm assets too.
 *
 * It also emulates the part of TYPO3's stock .htaccess that routes
 * /typo3/... requests to public/typo3/index.php on TYPO3 13.
 */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$docRoot = dirname(__DIR__) . '/public';
$path = $docRoot . $uri;

$mimeMap = [
    'js'    => 'application/javascript',
    'mjs'   => 'application/javascript',
    'css'   => 'text/css',
    'json'  => 'application/json',
    'wasm'  => 'application/wasm',
    'svg'   => 'image/svg+xml',
    'png'   => 'image/png',
    'jpg'   => 'image/jpeg',
    'jpeg'  => 'image/jpeg',
    'gif'   => 'image/gif',
    'ico'   => 'image/x-icon',
    'woff'  => 'font/woff',
    'woff2' => 'font/woff2',
    'ttf'   => 'font/ttf',
    'map'   => 'application/json',
    'txt'   => 'text/plain',
    'html'  => 'text/html',
];

header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Embedder-Policy: require-corp');
header('Cross-Origin-Resource-Policy: same-origin');

// Reject `..` segments early so a crafted URI cannot escape the docroot.
// realpath() is not used here because TYPO3 publishes _assets via symlinks
// pointing into vendor/ or typo3conf/ — those would be wrongly rejected.
if (strpos($uri, '..') !== false) {
    http_response_code(403);
    return true;
}

if ($uri !== '/' && is_file($path)) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (isset($mimeMap[$ext])) {
        header('Content-Type: ' . $mimeMap[$ext]);
    }
    readfile($path);
    return true;
}

// TYPO3 13: backend lives in public/typo3/index.php and ALL /typo3/<route>
// requests must hit that entry point. TYPO3 14 routes /typo3/... through
// public/index.php, but always entering the backend index.php still works.
if (preg_match('#^/typo3(/|$)#', $uri) && is_file($docRoot . '/typo3/index.php')) {
    // typo3/index.php uses relative require '../index.php' so the CWD must be
    // the typo3 directory.
    chdir($docRoot . '/typo3');
    require $docRoot . '/typo3/index.php';
    return true;
}

chdir($docRoot);
require $docRoot . '/index.php';

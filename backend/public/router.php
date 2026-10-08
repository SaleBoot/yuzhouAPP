<?php
// Development server only; private storage lives outside this directory.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && strpos($path, '..') === false && is_file(__DIR__ . $path)) {
    return false;
}
require __DIR__ . '/index.php';

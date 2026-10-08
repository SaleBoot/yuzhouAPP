<?php
// Only this project's environment is loaded; no existing site configuration.
$environmentFile = dirname(dirname(__DIR__)) . '/.env';
if (is_file($environmentFile)) {
    foreach (file($environmentFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/D', $key)) continue;
        if (getenv($key) === false) putenv($key . '=' . trim($value, " \t\r\n\"'"));
    }
}
spl_autoload_register(function ($class) {
    if (strpos($class, 'yuzhou\\') !== 0) return;
    $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, 7)) . '.php';
    if (is_file($path)) require $path;
});
date_default_timezone_set('Asia/Shanghai');

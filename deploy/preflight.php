<?php
// 仅读取部署条件，不回显环境变量、连接口令或客户数据。
$required = array('pdo_mysql', 'mbstring', 'openssl', 'fileinfo', 'json', 'bcmath', 'zip');
$extensions = array();
foreach ($required as $extension) $extensions[$extension] = extension_loaded($extension);
$report = array('php' => PHP_VERSION, 'compatible_php' => version_compare(PHP_VERSION, '7.2.0', '>='), 'extensions' => $extensions, 'memory_limit' => ini_get('memory_limit'), 'upload_max_filesize' => ini_get('upload_max_filesize'), 'disk_free_bytes' => disk_free_space(__DIR__), 'framework_sha256' => is_file(__DIR__ . '/../backend/thinkphp/library/think/App.php') ? hash_file('sha256', __DIR__ . '/../backend/thinkphp/library/think/App.php') : null);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($report['compatible_php'] && !in_array(false, $extensions, true) ? 0 : 1);

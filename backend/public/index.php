<?php
define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');
date_default_timezone_set('Asia/Shanghai');
require ROOT_PATH . 'extend/yuzhou/bootstrap.php';
require ROOT_PATH . 'thinkphp/base.php';
think\Loader::addNamespace('yuzhou', ROOT_PATH . 'extend/yuzhou');
\yuzhou\RuntimeErrorHandler::register();
think\Error::setExceptionHandler('\\yuzhou\\HttpExceptionHandler');
think\Container::get('app', [APP_PATH])->run()->send();

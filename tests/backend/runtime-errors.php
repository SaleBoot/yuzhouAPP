<?php
require_once __DIR__ . '/../../backend/extend/yuzhou/bootstrap.php';
require_once __DIR__ . '/../../backend/thinkphp/base.php';

use yuzhou\RuntimeErrorHandler;
use yuzhou\HttpExceptionHandler;

$checks = 0;
function runtimeCheck($condition, $label) { global $checks; if (!$condition) throw new RuntimeException($label); $checks++; }
RuntimeErrorHandler::register();
think\Error::setExceptionHandler('\\yuzhou\\HttpExceptionHandler');
runtimeCheck(RuntimeErrorHandler::handle(E_DEPRECATED, 'Synthetic deprecated diagnostic') === true, 'Runtime deprecations are ignored');
runtimeCheck(trigger_error('Synthetic user deprecation', E_USER_DEPRECATED), 'User deprecations do not fail the request');
try {
    trigger_error('Synthetic warning', E_USER_WARNING);
    throw new RuntimeException('A warning was swallowed');
} catch (think\exception\ErrorException $error) {
    runtimeCheck($error->getMessage() === 'Synthetic warning', 'Warnings remain framework exceptions');
}
try {
    RuntimeErrorHandler::handle(E_WARNING, 'Synthetic runtime warning', __FILE__, __LINE__);
    throw new RuntimeException('A runtime warning was swallowed');
} catch (think\exception\ErrorException $error) {
    runtimeCheck($error->getMessage() === 'Synthetic runtime warning', 'Runtime warnings remain framework exceptions');
}

$handler = new HttpExceptionHandler();
$privateMessage = 'Synthetic-private-message-that-must-not-appear';
$internal = $handler->render(new RuntimeException($privateMessage));
$internalData = json_decode($internal->getContent(), true);
runtimeCheck($internal->getCode() === 500 && $internalData['code'] === 500 && $internalData['data'] === null, 'Internal framework failures return JSON 500');
runtimeCheck(strpos($internal->getContent(), $privateMessage) === false && $internalData['message'] === 'Request could not be completed.', 'Internal response excludes exception details');
$missing = $handler->render(new think\exception\HttpException(404, $privateMessage));
runtimeCheck($missing->getCode() === 404 && json_decode($missing->getContent(), true)['message'] === 'Endpoint not found.', 'Missing routes retain JSON 404');

$log = tempnam(sys_get_temp_dir(), 'yuzhou-runtime-errors-');
$previous = ini_get('error_log');
try {
    ini_set('error_log', $log);
    $handler->report(new RuntimeException($privateMessage));
    $logged = file_get_contents($log);
    runtimeCheck(strpos($logged, 'RuntimeException') !== false && strpos($logged, 'runtime-errors.php:') !== false
        && strpos($logged, $privateMessage) === false, 'Logs retain exception location without private messages');
} finally {
    ini_set('error_log', $previous);
    unlink($log);
}
echo "Runtime error adapter checks: $checks passed.\n";

<?php
return [
    'app_debug' => false,
    'default_timezone' => 'Asia/Shanghai',
    'default_module' => 'api',
    'default_controller' => 'Index',
    'default_action' => 'handle',
    'default_return_type' => 'json',
    'url_route_on' => true,
    'url_route_must' => true,
    'show_error_msg' => false,
    'exception_handle' => '\\yuzhou\\HttpExceptionHandler',
];

<?php
use think\facade\Route;
Route::any('api/v1/:path', 'api/Index/handle')->pattern(['path' => '.*']);
Route::get('api/v1', 'api/Index/handle');

<?php
namespace yuzhou;

class HttpExceptionHandler extends \think\exception\Handle
{
    public function render(\Exception $exception)
    {
        return \think\Response::create(['code' => 404, 'message' => 'Endpoint not found.', 'data' => null], 'json', 404);
    }
}

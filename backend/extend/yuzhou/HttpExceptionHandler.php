<?php
namespace yuzhou;

class HttpExceptionHandler extends \think\exception\Handle
{
    public function report(\Exception $exception)
    {
        if ($this->isIgnoreReport($exception)) return;
        // The vendored reporter belongs to the original site. Log location only:
        // exception messages and request data can contain passwords or private files.
        error_log('Yuzhou framework failure: ' . get_class($exception) . ' at '
            . basename($exception->getFile()) . ':' . $exception->getLine());
    }

    public function render(\Exception $exception)
    {
        $status = $exception instanceof \think\exception\HttpException ? $exception->getStatusCode() : 500;
        $message = $status === 404 ? 'Endpoint not found.' : 'Request could not be completed.';
        return \think\Response::create(['code' => $status, 'message' => $message, 'data' => null], 'json', $status)
            ->header(['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store']);
    }
}

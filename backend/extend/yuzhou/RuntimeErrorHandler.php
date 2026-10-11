<?php
namespace yuzhou;

class RuntimeErrorHandler
{
    public static function register()
    {
        // ThinkPHP resets E_ALL during bootstrap. Keep the locked framework usable
        // on newer local PHP runtimes without forwarding deprecations to its site reporter.
        error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
        set_error_handler([self::class, 'handle']);
    }

    public static function handle($severity, $message, $file = '', $line = 0)
    {
        if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) return true;
        return \think\Error::appError($severity, $message, $file, $line);
    }
}

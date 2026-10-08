<?php
namespace yuzhou;

class ApiError extends \RuntimeException
{
    public $status;
    public $details;
    public function __construct($message, $status = 422, $code = 422, array $details = [])
    {
        parent::__construct($message, $code);
        $this->status = $status;
        $this->details = $details;
    }
}

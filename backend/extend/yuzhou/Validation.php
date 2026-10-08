<?php
namespace yuzhou;

class Validation
{
    public static function text($value, $name, $max = 200, $required = true)
    {
        if (!is_string($value)) throw new ApiError($name . ' must be text.');
        $value = trim($value);
        if (($required && $value === '') || mb_strlen($value, 'UTF-8') > $max) throw new ApiError($name . ' is invalid.');
        return $value;
    }

    public static function integer($value, $name, $min = 0, $max = 100000000000)
    {
        if (is_string($value) && preg_match('/^[0-9]+$/D', $value)) $value = (int)$value;
        if (!is_int($value) || $value < $min || $value > $max) throw new ApiError($name . ' is invalid.');
        return $value;
    }

    public static function money($value, $name = 'amount_cents') { return self::integer($value, $name, 0, 100000000000); }
    public static function id($value, $name = 'id') { return self::integer($value, $name, 1, 2147483647); }
    public static function choice($value, array $values, $name)
    {
        if (!in_array($value, $values, true)) throw new ApiError($name . ' is invalid.');
        return $value;
    }

    public static function dates($start, $end)
    {
        $a = \DateTimeImmutable::createFromFormat('!Y-m-d', (string)$start);
        $b = \DateTimeImmutable::createFromFormat('!Y-m-d', (string)$end);
        if (!$a || !$b || $a->format('Y-m-d') !== $start || $b->format('Y-m-d') !== $end || $b <= $a) throw new ApiError('Rental dates are invalid.');
        $days = (int)$a->diff($b)->days;
        if ($days > 90) throw new ApiError('Rental period cannot exceed 90 days.');
        $dates = [];
        for ($date = $a; $date < $b; $date = $date->modify('+1 day')) $dates[] = $date->format('Y-m-d');
        return $dates;
    }

    public static function key($value)
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9_-]{8,100}$/D', $value)) throw new ApiError('A valid idempotency_key is required.');
        return $value;
    }

    public static function creditCode($value)
    {
        $value = strtoupper(self::text($value, 'credit_code', 18));
        if (!preg_match('/^[0-9A-HJ-NP-RTUWXY]{18}$/D', $value)) throw new ApiError('A valid 18-character business credit code is required.');
        return $value;
    }
}

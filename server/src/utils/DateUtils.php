<?php
use MongoDB\BSON\UTCDateTime;

class DateUtils
{
    public static function safeFormat($dateValue): ?string
    {
        if ($dateValue instanceof UTCDateTime) {
            return $dateValue->toDateTime()->format('Y-m-d\TH:i:s.vP');
        }
        if (is_string($dateValue)) {
            return $dateValue;
        }
        return null;
    }
}
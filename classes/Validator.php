<?php
class Validator {
    public static function date(string $value): bool {
        $d = DateTime::createFromFormat('Y-m-d', $value);
        return $d && $d->format('Y-m-d') === $value;
    }

    public static function status(string $value): bool {
        return in_array($value, ['present','absent','leave'], true);
    }

    public static function note(string $value): bool {
        return mb_strlen($value) <= 255;
    }

    public static function intId($value): bool {
        return is_numeric($value) && (int)$value > 0;
    }
}
?>

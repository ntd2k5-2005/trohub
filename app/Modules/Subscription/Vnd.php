<?php
namespace App\Modules\Subscription;
/** Backward-compatible entry point for the shared exact VND formatter. */
final class Vnd {
    public static function add(string $a,string $b):string{return \App\Support\Vnd::add($a,$b);}
    public static function format(string|int $value):string{return \App\Support\Vnd::format($value);}
}

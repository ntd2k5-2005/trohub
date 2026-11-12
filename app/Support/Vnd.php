<?php

namespace App\Support;

/** Integer decimal strings avoid float rounding in aggregates and exports. */
final class Vnd
{
    public static function add(string $a, string $b): string
    {
        if(!ctype_digit($a)||!ctype_digit($b))throw new \InvalidArgumentException('Expected nonnegative VND integers');
        $carry=0;$result='';$i=strlen($a)-1;$j=strlen($b)-1;
        while($i>=0||$j>=0||$carry){$n=($i>=0?(int)$a[$i--]:0)+($j>=0?(int)$b[$j--]:0)+$carry;$result=($n%10).$result;$carry=intdiv($n,10);}
        return ltrim($result,'0')?:'0';
    }
    public static function format(string|int $value): string
    {
        $value=(string)$value;if(!ctype_digit($value))throw new \InvalidArgumentException('Expected integer VND');
        return preg_replace('/\B(?=(\d{3})+(?!\d))/','.',$value).' ₫';
    }
}

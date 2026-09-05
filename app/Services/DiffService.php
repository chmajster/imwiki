<?php
declare(strict_types=1);

namespace ImWiki\Services;

final class DiffService
{
    public function lineDiff(string $old, string $new): array
    {
        $a = preg_split('/\R/u', $old) ?: [];
        $b = preg_split('/\R/u', $new) ?: [];
        $n = count($a); $m = count($b); $max = $n + $m;
        if ($max === 0) return [];

        $v = [1 => 0];
        $trace = [];
        $endD = 0;
        for ($d = 0; $d <= $max; $d++) {
            $trace[$d] = $v;
            for ($k = -$d; $k <= $d; $k += 2) {
                $left = $v[$k - 1] ?? PHP_INT_MIN;
                $right = $v[$k + 1] ?? PHP_INT_MIN;
                if ($k === -$d || ($k !== $d && $left < $right)) {
                    $x = $right === PHP_INT_MIN ? 0 : $right;
                } else {
                    $x = ($left === PHP_INT_MIN ? 0 : $left) + 1;
                }
                $y = $x - $k;
                while ($x < $n && $y < $m && $y >= 0 && $a[$x] === $b[$y]) {
                    $x++; $y++;
                }
                $v[$k] = $x;
                if ($x >= $n && $y >= $m) {
                    $endD = $d;
                    break 2;
                }
            }
        }

        $x = $n; $y = $m; $ops = [];
        for ($d = $endD; $d > 0; $d--) {
            $prevV = $trace[$d];
            $k = $x - $y;
            $left = $prevV[$k - 1] ?? PHP_INT_MIN;
            $right = $prevV[$k + 1] ?? PHP_INT_MIN;
            if ($k === -$d || ($k !== $d && $left < $right)) {
                $prevK = $k + 1;
            } else {
                $prevK = $k - 1;
            }
            $prevX = $prevV[$prevK] ?? 0;
            $prevY = $prevX - $prevK;

            while ($x > $prevX && $y > $prevY) {
                $ops[] = ['type'=>'equal','text'=>$a[$x-1]];
                $x--; $y--;
            }
            if ($x === $prevX) {
                if ($y > 0) {
                    $ops[] = ['type'=>'add','text'=>$b[$y-1]];
                    $y--;
                }
            } else {
                if ($x > 0) {
                    $ops[] = ['type'=>'remove','text'=>$a[$x-1]];
                    $x--;
                }
            }
        }
        while ($x > 0 && $y > 0) {
            if ($a[$x-1] === $b[$y-1]) {
                $ops[]=['type'=>'equal','text'=>$a[$x-1]]; $x--; $y--;
            } else break;
        }
        while ($x > 0) { $ops[]=['type'=>'remove','text'=>$a[--$x]]; }
        while ($y > 0) { $ops[]=['type'=>'add','text'=>$b[--$y]]; }
        return array_reverse($ops);
    }
}

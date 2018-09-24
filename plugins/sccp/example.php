<?php
class C {
	public $i;
}

function fn(int $x, $unused) : int {
	if($x) {
		$a = [1, 2, 3];
	} else {
		$a = [3, 2, 1];
	}
	return $a[1];
}

function test(int $x) {
    if ($x) {
        $y = 2;
    } else {
        $y = 2;
    }
    return $y > 0;
}

var_export(fn(1));

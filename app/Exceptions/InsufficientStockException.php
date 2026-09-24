<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public function __construct(public readonly array $shortages)
    {
        parent::__construct('المخزون غير كافٍ: '.implode('، ', array_map(
            fn (array $shortage) => $shortage['name'].' (الكمية الناقصة: '.$shortage['shortfall'].')',
            $shortages,
        )));
    }
}

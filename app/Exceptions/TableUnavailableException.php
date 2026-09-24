<?php

namespace App\Exceptions;

use RuntimeException;

class TableUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('هذه الطاولة أصبحت مشغولة، الرجاء اختيار طاولة أخرى');
    }
}

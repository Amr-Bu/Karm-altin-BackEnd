<?php

namespace App\Exceptions;

use RuntimeException;

class OrderAlreadyPaidException extends RuntimeException
{
    public function __construct(string $message = 'تم دفع هذا الطلب بالفعل ولا يمكن تعديله أو إلغاؤه')
    {
        parent::__construct($message);
    }
}

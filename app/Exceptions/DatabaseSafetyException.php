<?php

namespace App\Exceptions;

use RuntimeException;

class DatabaseSafetyException extends RuntimeException
{
    // Fail-closed database safety exception
}

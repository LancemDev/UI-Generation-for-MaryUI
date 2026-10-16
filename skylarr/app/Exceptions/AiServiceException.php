<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The AI service was unreachable or rejected the request. The message is safe to show users.
 */
class AiServiceException extends RuntimeException
{
}

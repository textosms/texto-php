<?php

declare(strict_types=1);

namespace Texto\Exception;

/** The API key is missing, invalid or revoked (401). */
class AuthenticationException extends ApiException
{
}

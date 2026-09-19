<?php

declare(strict_types=1);

namespace Texto\Exception;

/** The account has too few credits, or a card problem occurred (402). */
class InsufficientCreditsException extends ApiException
{
    public function creditsRequired(): ?int
    {
        $value = $this->body['credits_required'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    public function creditsAvailable(): ?int
    {
        $value = $this->body['credits_available'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}

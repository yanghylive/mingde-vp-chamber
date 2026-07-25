<?php

declare(strict_types=1);

namespace app\chamber\verification;

use InvalidArgumentException;

final class GraduateVerificationValidationException extends InvalidArgumentException
{
    /** @var string */
    private $field;

    /** @var string */
    private $fieldCode;

    public function __construct(string $field, string $fieldCode, string $message)
    {
        parent::__construct($message);
        $this->field = $field;
        $this->fieldCode = $fieldCode;
    }

    public function field(): string
    {
        return $this->field;
    }

    public function fieldCode(): string
    {
        return $this->fieldCode;
    }
}

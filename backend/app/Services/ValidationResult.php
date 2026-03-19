<?php

namespace App\Services;

class ValidationResult
{
    private bool $passed = true;
    private ?string $errorMessage = null;

    private function __construct(bool $passed, ?string $errorMessage)
    {
        $this->passed = $passed;
        $this->errorMessage = $errorMessage;
    }

    public static function success(): self
    {
        return new self(true, null);
    }

    public static function failure(string $errorMessage): self
    {
        return new self(false, $errorMessage);
    }

    public function passes(): bool
    {
        return $this->passed;
    }

    public function fails(): bool
    {
        return !$this->passed;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }
}

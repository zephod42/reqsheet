<?php

declare(strict_types=1);

namespace Reqsheet\Settings;

final class SettingsValidationException extends \RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(private readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }
}

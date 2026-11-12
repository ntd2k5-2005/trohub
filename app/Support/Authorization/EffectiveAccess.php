<?php

namespace App\Support\Authorization;

final readonly class EffectiveAccess
{
    public function __construct(
        public bool $accountActive,
        public array $permissions = [],
        public array $scopes = [],
        public array $entitlements = [],
        public array $capabilities = [],
        public array $allowedContexts = ['public'],
        public string $profileKey = 'guest',
        public ?string $displayName = null,
        public ?string $profileLabel = null,
    ) {}

    public function can(string $capability): bool
    {
        if (! $this->accountActive) {
            return false;
        }

        foreach ($this->capabilities as $granted) {
            if ($granted === '*' || $granted === $capability) {
                return true;
            }

            if (str_ends_with($granted, '.*')) {
                $prefix = substr($granted, 0, -1);
                if (str_starts_with($capability, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function canUseContext(string $context): bool
    {
        return in_array($context, $this->allowedContexts, true);
    }

    public function isGuest(): bool
    {
        return $this->profileKey === 'guest' || $this->displayName === null;
    }
}

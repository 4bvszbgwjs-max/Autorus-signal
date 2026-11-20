<?php

namespace App\Notifications;

use Illuminate\Support\Collection;

final class ScenarioRouting
{
    private const DESTINATION = [
        'notices' => 'phone',
        'builtinEmail' => 'email',
        'smsIdgtl' => 'phone',
        'emailRetailRocket' => 'email',
        'pushFirebase' => 'applicationFirebaseToken',
        'telegram' => 'phone',
    ];

    public function selectChannels(array $first, array $every, ?object $recipient, Collection $available): array
    {
        $chosen = [];
        foreach ($first as $rule) {
            if ($rule['isActive'] && $available->has($rule['channel']) && $this->matches($rule, $recipient)) {
                $chosen[$rule['channel']] = $rule;
                break;
            }
        }

        foreach ($every as $rule) {
            if ($rule['isActive'] && $available->has($rule['channel']) && $this->matches($rule, $recipient)) {
                $chosen[$rule['channel']] = $rule;
            }
        }

        return $chosen;
    }

    private function matches(array $rule, ?object $recipient): bool
    {
        foreach ($rule['fieldsConditions'] ?? [] as $condition => $fields) {
            foreach ($fields as $field) {
                $value = $recipient?->{$field};
                $matches = match ($condition) {
                    'notNone' => (bool) $value,
                    'equalTrue' => $value === true,
                    'equalFalse' => $value === false,
                    default => false,
                };
                if (! $matches) {
                    return false;
                }
            }
        }

        return true;
    }

    public function destination(array $metadata, ?object $recipient, array $rule, string $sender): ?string
    {
        $path = $rule['destinationFieldPath'] ?? '';
        $field = $rule['destinationField'] ?? self::DESTINATION[$sender] ?? '';
        $value = $recipient?->{$field};
        if ($path !== '') {
            $value = $metadata;
            foreach (explode('.', $path) as $segment) {
                $value = is_array($value) ? ($value[$segment] ?? null) : null;
            }
        }

        if ((! is_string($value) && ! is_int($value)) || trim((string) $value) === '' || mb_strlen((string) $value) > 200) {
            return null;
        }

        return (string) $value;
    }
}

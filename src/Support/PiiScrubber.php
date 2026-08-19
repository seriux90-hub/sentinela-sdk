<?php

namespace Sentinela\LaravelClient\Support;

class PiiScrubber
{
    private const REDACTED = '[redacted]';

    /**
     * @param  array<string, mixed>  $keys de config('sentinela.scrub_keys') como lista simple ['password', ...]
     * @param  array<string, string>  $valuePatterns nombre => regex, de config('sentinela.scrub_value_patterns')
     */
    public function __construct(
        private array $keys,
        private array $valuePatterns = [],
    ) {
    }

    /**
     * Redacta recursivamente cualquier clave del array que coincida (sin
     * distinguir mayúsculas) con la lista configurada, y aplica los
     * patrones de valor si se han definido. No muta el array de entrada.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function scrub(array $context): array
    {
        $lowerKeys = array_map('strtolower', $this->keys);

        return $this->scrubRecursive($context, $lowerKeys);
    }

    /** @param  array<string, mixed>  $data */
    private function scrubRecursive(array $data, array $lowerKeys): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $lowerKeys, true)) {
                $result[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $result[$key] = $this->scrubRecursive($value, $lowerKeys);

                continue;
            }

            $result[$key] = is_string($value) ? $this->scrubValue($value) : $value;
        }

        return $result;
    }

    private function scrubValue(string $value): string
    {
        foreach ($this->valuePatterns as $pattern) {
            $value = preg_replace($pattern, self::REDACTED, $value) ?? $value;
        }

        return $value;
    }
}

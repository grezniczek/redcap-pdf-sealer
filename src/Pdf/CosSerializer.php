<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pdf;

/** Serializes the parser's COS tokens for dictionary and array revisions. */
final class CosSerializer
{
    public function serialize(array $token): string
    {
        $type = $token[0] ?? null;
        $value = $token[1] ?? null;
        if ($type === '<<' && is_array($value)) {
            if (count($value) % 2 !== 0) {
                throw new \InvalidArgumentException('Odd number of dictionary tokens');
            }
            $entries = [];
            for ($index = 0; $index < count($value); $index += 2) {
                if (($value[$index][0] ?? null) !== '/') {
                    throw new \InvalidArgumentException('Dictionary key must be a name');
                }
                $entries[] = $this->serialize($value[$index]) . ' ' . $this->serialize($value[$index + 1]);
            }
            return '<< ' . implode(' ', $entries) . ' >>';
        }
        if ($type === '[' && is_array($value)) {
            return '[ ' . implode(' ', array_map($this->serialize(...), $value)) . ' ]';
        }
        if ($type === '/' && is_string($value)) {
            return '/' . preg_replace_callback('/[^A-Za-z0-9!$&\'*+,:;=?@^_`|~.-]/',
                static fn(array $match): string => sprintf('#%02X', ord($match[0])), $value);
        }
        if ($type === 'objref' && is_string($value) && preg_match('/^([1-9][0-9]*)_([0-9]+)$/D', $value, $parts) === 1) {
            return $parts[1] . ' ' . $parts[2] . ' R';
        }
        if ($type === 'numeric' && is_string($value) && preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)$/D', $value) === 1) {
            return $value;
        }
        if ($type === 'boolean' && ($value === 'true' || $value === 'false')) {
            return $value;
        }
        if ($type === 'null') {
            return 'null';
        }
        if ($type === '(' && is_string($value)) {
            return '(' . $value . ')';
        }
        if ($type === '<' && is_string($value) && strlen($value) % 2 === 0
            && preg_match('/^[0-9A-Fa-f]*$/D', $value) === 1) {
            return '<' . $value . '>';
        }
        throw new \InvalidArgumentException('Unsupported COS token');
    }

    public static function name(string $value): array
    {
        return ['/', $value, 0];
    }

    public static function reference(string $value): array
    {
        return ['objref', $value, 0];
    }

    public static function literal(string $value): array
    {
        return ['(', strtr($value, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '\\r', "\n" => '\\n']), 0];
    }

    public static function dictionary(array $entries): array
    {
        $tokens = [];
        foreach ($entries as $key => $value) {
            $tokens[] = self::name($key);
            $tokens[] = $value;
        }
        return ['<<', $tokens, 0];
    }
}

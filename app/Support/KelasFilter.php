<?php

namespace App\Support;

class KelasFilter
{
    /**
     * Parse filter kelas (single/array) menjadi pasangan CODE02 + DESC02 + DESC03.
     * Format: unit~~jenjang~~kelas | unit~jenjang~~kelas | unit,jenjang,kelas
     */
    public static function parse(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];
        $pairs = [];

        foreach ($values as $raw) {
            $raw = trim((string) $raw);
            if ($raw === '' || strtolower($raw) === 'all') {
                continue;
            }

            if (str_contains($raw, '~~')) {
                $parts = explode('~~', $raw);
            } elseif (str_contains($raw, ',')) {
                $parts = explode(',', $raw);
            } else {
                $parts = explode('~', $raw);
            }

            if (count($parts) < 3) {
                continue;
            }

            $pairs[] = [
                'CODE02' => trim((string) $parts[0]),
                'DESC02' => trim((string) $parts[1]),
                'DESC03' => trim((string) $parts[2]),
            ];
        }

        return $pairs;
    }

    private static function normalizePairs(mixed $value): array
    {
        if (is_array($value) && isset($value[0]) && is_array($value[0])) {
            $pairs = [];
            foreach ($value as $row) {
                $code02 = trim((string) ($row['CODE02'] ?? $row['CODE01'] ?? ''));
                $desc02 = trim((string) ($row['DESC02'] ?? ''));
                $desc03 = trim((string) ($row['DESC03'] ?? $row['CODE03'] ?? ''));
                if ($code02 === '' && $desc02 === '' && $desc03 === '') {
                    continue;
                }
                $pairs[] = [
                    'CODE02' => $code02,
                    'DESC02' => $desc02,
                    'DESC03' => $desc03,
                ];
            }

            return $pairs;
        }

        return self::parse($value);
    }

    public static function apply($query, mixed $value, string $alias = 'scctcust'): void
    {
        $pairs = self::normalizePairs($value);
        if ($pairs === []) {
            return;
        }

        $query->where(function ($outer) use ($pairs, $alias) {
            foreach ($pairs as $pair) {
                $outer->orWhere(function ($inner) use ($pair, $alias) {
                    $inner->where($alias . '.CODE02', $pair['CODE02'])
                        ->where($alias . '.DESC02', $pair['DESC02'])
                        ->where($alias . '.DESC03', $pair['DESC03']);
                });
            }
        });
    }
}

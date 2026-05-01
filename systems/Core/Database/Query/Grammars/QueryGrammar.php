<?php

namespace Core\Database\Query\Grammars;

abstract class QueryGrammar
{
    abstract public function compileTemporalExpression(string $type, string $column): string;

    protected function normalizeType(string $type): string
    {
        $normalized = strtolower(trim($type));
        $supported = ['date', 'day', 'month', 'year', 'time'];

        if (!in_array($normalized, $supported, true)) {
            throw new \InvalidArgumentException('Unsupported temporal expression type: ' . $type);
        }

        return $normalized;
    }
}
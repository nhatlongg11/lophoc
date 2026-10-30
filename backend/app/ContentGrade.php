<?php

namespace App;

use Illuminate\Database\Query\Builder;

class ContentGrade
{
    public const GRADES = ['3', '4', '5'];

    public static function filter(Builder $query, string $column, ?string $grade): Builder
    {
        if ($grade === 'unclassified') {
            return $query->where(fn (Builder $q) => $q->whereNull($column)->orWhereNotIn($column, self::GRADES));
        }

        return $grade !== null && $grade !== '' ? $query->where($column, $grade) : $query;
    }
}

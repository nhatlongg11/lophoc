<?php

namespace App;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class TenantQuery extends Builder
{
    public static function forOrganization(string $table, int $organizationId): self
    {
        $query = new self(DB::connection());
        $query->tenantId = $organizationId;
        $query->from($table)->where($table.'.organization_id', $organizationId);

        return $query;
    }

    private int $tenantId;

    public function insert(array $values): bool
    {
        if ($values === []) {
            return true;
        }
        $rows = is_array(reset($values)) ? $values : [$values];

        return parent::insert(array_map(fn (array $row): array => [...$row, 'organization_id' => $this->tenantId], $rows));
    }

    public function insertGetId(array $values, $sequence = null): int
    {
        return (int) parent::insertGetId([...$values, 'organization_id' => $this->tenantId], $sequence);
    }

    public function insertOrIgnore(array $values): int
    {
        if ($values === []) {
            return 0;
        }
        $rows = is_array(reset($values)) ? $values : [$values];

        return parent::insertOrIgnore(array_map(fn (array $row): array => [...$row, 'organization_id' => $this->tenantId], $rows));
    }
}

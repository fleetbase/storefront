<?php

use Illuminate\Support\Facades\Cache;

if (!function_exists('apiQueryCacheVersion')) {
    /**
     * The list cache version core's ApiModelCache keeps for a table and company (null is
     * the table wide version); every invalidation of that table advances it.
     */
    function apiQueryCacheVersion(string $table, ?string $companyUuid = null): int
    {
        return (int) Cache::get("api_query_version:{$table}:{$companyUuid}", 0);
    }
}

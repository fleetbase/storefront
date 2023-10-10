<?php

namespace Fleetbase\Storefront\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ProductsImport implements ToCollection, WithHeadingRow
{
    /**
     * @param array $row
     *
     * @return \Illuminate\Support\Collection
     */
    public function collection(Collection $rows)
    {
        return $rows;
    }
}

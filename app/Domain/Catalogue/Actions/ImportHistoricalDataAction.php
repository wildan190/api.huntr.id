<?php

namespace App\Domain\Catalogue\Actions;

use App\Domain\Catalogue\Models\Catalogue;
use App\Domain\Catalogue\Repositories\CatalogueRepositoryInterface;
use App\Domain\Company\Models\Company;
use App\Domain\Order\Models\HistoricalPoItem;
use App\Domain\Order\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Rap2hpoutre\FastExcel\FastExcel;

class ImportHistoricalDataAction
{
    public function __construct(
        private readonly CatalogueRepositoryInterface $catalogueRepository
    ) {}

    /**
     * Import historical CSV/TSV/XLSX catalogue data for a vendor company.
     */
    public function execute(Company $company, string $filePath): int
    {
        if (!file_exists($filePath)) {
            return 0;
        }

        return $this->importVendorCatalogue($company, $filePath);
    }

    // ── VENDOR: Import Product Catalogue ─────────────────────────────────────

    private function importVendorCatalogue(Company $company, string $filePath): int
    {
        $items = [];

        try {
            (new FastExcel)->import($filePath, function ($row) use (&$items, $company) {
                $normalized = [];
                foreach ($row as $key => $val) {
                    $normalized[trim((string) $key)] = $val;
                }

                $itemCode = $normalized['Inventory Code'] ?? $normalized['inventory code'] ?? null;
                $name     = $normalized['Inventory name'] ?? $normalized['inventory name'] ?? $normalized['Inventory Name'] ?? null;

                if (!$itemCode || !$name) {
                    return;
                }

                $category = $normalized['Category'] ?? $normalized['category'] ?? $normalized['Purchase Category'] ?? $normalized['purchase category'] ?? null;

                $items[] = [
                    'company_id'     => $company->id,
                    'item_code'      => $itemCode,
                    'name'           => $name,
                    'category'       => $category,
                    'specifications' => $normalized['Specifications'] ?? $normalized['specifications'] ?? null,
                    'uom'            => $normalized['Primary UOM'] ?? $normalized['primary uom'] ?? 'Pc',
                ];
            });
        } catch (\Exception $e) {
            Log::error('ImportHistoricalDataAction (vendor) - FastExcel failed: ' . $e->getMessage());
            return 0;
        }

        if (empty($items)) {
            return 0;
        }

        return $this->catalogueRepository->bulkCreate($items);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
}


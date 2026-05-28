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

                $itemCode = $normalized['Inventory Code'] ?? null;
                $name     = $normalized['Inventory name'] ?? $normalized['Inventory Name'] ?? null;

                if (!$itemCode || !$name) {
                    return;
                }

                $priceStr = (string) ($normalized['Unit price in original currency'] ?? $normalized['Orgi Curr Unit Price'] ?? '0');
                $price    = $this->cleanDecimal($priceStr);
                $category = $normalized['Category'] ?? $normalized['Purchase Category'] ?? null;

                $items[] = [
                    'company_id'     => $company->id,
                    'item_code'      => $itemCode,
                    'name'           => $name,
                    'category'       => $category,
                    'specifications' => $normalized['Specifications'] ?? null,
                    'uom'            => $normalized['Primary UOM'] ?? 'Pc',
                    'price'          => $price,
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

    private function cleanDecimal(mixed $value): float
    {
        $str = (string) $value;

        // Handle Indonesian format: 18.000.000 or 18,000,000
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $str)) {
            $str = str_replace('.', '', $str);
            $str = str_replace(',', '.', $str);
        } elseif (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $str)) {
            $str = str_replace(',', '', $str);
        } else {
            $str = str_replace(',', '', $str);
        }

        return (float) $str;
    }
}


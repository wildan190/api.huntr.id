<?php

namespace App\Domain\Order\Actions;

use App\Domain\Company\Models\Company;
use App\Domain\Order\Models\HistoricalPoItem;
use App\Domain\Order\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Rap2hpoutre\FastExcel\FastExcel;

class ImportHistoricalPoAction
{
    public function execute(Company $company, string $filePath): int
    {
        if (!file_exists($filePath)) {
            return 0;
        }

        // Collect all rows first
        $rows = collect();

        try {
            (new FastExcel)->import($filePath, function ($row) use (&$rows) {
                $normalized = [];
                foreach ($row as $key => $val) {
                    $normalized[trim((string) $key)] = $val;
                }
                $rows->push($normalized);
            });
        } catch (\Exception $e) {
            Log::error('ImportHistoricalPoAction - FastExcel failed: ' . $e->getMessage());
            return 0;
        }

        if ($rows->isEmpty()) {
            return 0;
        }

        // Group rows by Order No (PO Number)
        $grouped = $rows->groupBy(fn($row) => trim((string) ($row['Order No'] ?? $row['PO Number'] ?? '')));

        $totalItems = 0;

        DB::transaction(function () use ($company, $grouped, &$totalItems) {
            foreach ($grouped as $poNumber => $poRows) {
                if (empty($poNumber)) {
                    continue;
                }

                $firstRow = $poRows->first();

                $vendorName       = trim((string) ($firstRow['Vendor'] ?? ''));
                $department       = trim((string) ($firstRow['Department'] ?? ''));
                $currency         = trim((string) ($firstRow['Currency'] ?? 'IDR'));
                $purchaseCategory = trim((string) ($firstRow['Purchase Category'] ?? ''));
                $purchaseType     = trim((string) ($firstRow['Purchase Type'] ?? ''));
                $orderDateRaw     = $firstRow['Date'] ?? null;
                $orderDate        = $this->parseDate($orderDateRaw);

                $createdBy        = trim((string) ($firstRow['Created By'] ?? $firstRow['Created by'] ?? ''));
                $approvedBy       = trim((string) ($firstRow['Approved by'] ?? $firstRow['Approved By'] ?? ''));

                // Auto-create or find vendor company by name
                $vendorCompany = null;
                if (!empty($vendorName)) {
                    $vendorCompany = Company::firstOrCreate(
                        ['name' => $vendorName, 'type' => 'vendor'],
                        ['status' => 'pending']
                    );
                }

                // Create PurchaseOrder record
                $po = PurchaseOrder::create([
                    'buyer_company_id'        => $company->id,
                    'rfq_id'                  => null,
                    'vendor_id'               => $vendorCompany?->id,
                    'po_number'               => $poNumber,
                    'vendor_name'             => $vendorName,
                    'department'              => $department,
                    'currency'                => $currency,
                    'purchase_category'       => $purchaseCategory,
                    'purchase_type'           => $purchaseType,
                    'order_date'              => $orderDate,
                    'status'                  => 'completed',
                    'is_historical'           => true,
                    'created_by'              => $createdBy,
                    'approved_by'             => $approvedBy,
                ]);

                // Create line items
                foreach ($poRows as $row) {
                    $inventoryCode = trim((string) ($row['Inventory Code'] ?? ''));
                    $inventoryName = trim((string) ($row['Inventory name'] ?? $row['Inventory Name'] ?? ''));

                    if (empty($inventoryName)) {
                        continue;
                    }

                    $qty          = (float) ($row['Qty'] ?? 1);
                    $unitPrice    = $this->cleanDecimal($row['Unit price in original currency'] ?? $row['Orgi Curr Unit Price'] ?? 0);
                    $amount       = $this->cleanDecimal($row['Amount in original currency'] ?? 0);
                    $taxAmount    = $this->cleanDecimal($row['Tax amount in original currency'] ?? 0);
                    $totalAmount  = $this->cleanDecimal($row['Original Currency Total Amount'] ?? ($amount + $taxAmount));
                    $expectedDate = $this->parseDate($row['Expected receiving date'] ?? null);
                    $exchangeRate = $this->cleanDecimal($row['Exchange rate'] ?? 1);

                    HistoricalPoItem::create([
                        'purchase_order_id'       => $po->id,
                        'pr_reference_number'     => trim((string) ($row['PR Refference Number'] ?? $row['PR Reference Number'] ?? '')),
                        'inventory_code'          => $inventoryCode,
                        'inventory_name'          => $inventoryName,
                        'category'                => trim((string) ($row['Category'] ?? '')),
                        'specifications'          => trim((string) ($row['Specifications'] ?? '')),
                        'uom'                     => trim((string) ($row['Primary UOM'] ?? 'Pc')),
                        'qty'                     => $qty,
                        'unit_price'              => $unitPrice,
                        'amount'                  => $amount,
                        'tax_amount'              => $taxAmount,
                        'total_amount'            => $totalAmount > 0 ? $totalAmount : $amount,
                        'currency'                => $currency,
                        'exchange_rate'           => $exchangeRate ?: 1,
                        'clerk'                   => trim((string) ($row['Clerk'] ?? '')),
                        'created_by'              => trim((string) ($row['Created By'] ?? '')),
                        'approved_by'             => trim((string) ($row['Approved by'] ?? '')),
                        'order_date'              => $orderDate,
                        'expected_receiving_date' => $expectedDate,
                    ]);

                    $totalItems++;
                }
            }
        });

        return $totalItems;
    }

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

    private function parseDate(mixed $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        // FastExcel returns Excel dates as float/int
        if (is_numeric($value)) {
            try {
                // Excel serial date to PHP date
                $unixDate = ($value - 25569) * 86400;
                return date('Y-m-d', (int) $unixDate);
            } catch (\Exception $e) {
                return null;
            }
        }

        $str = trim((string) $value);

        // Try common formats
        $formats = ['d/m/y', 'd/m/Y', 'Y-m-d', 'm/d/Y', 'd-m-Y'];
        foreach ($formats as $fmt) {
            $date = \DateTime::createFromFormat($fmt, $str);
            if ($date !== false) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }
}

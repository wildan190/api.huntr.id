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
        $grouped = $rows->groupBy(function($row) {
            $val = $row['Order No'] ?? $row['Order no'] ?? $row['PO Number'] ?? $row['PO number'] ?? '';
            return trim((string) $val);
        });

        $totalItems = 0;

        DB::transaction(function () use ($company, $grouped, &$totalItems) {
            foreach ($grouped as $poNumber => $poRows) {
                if (empty($poNumber)) {
                    continue;
                }

                $firstRow = $poRows->first();

                $vendorName       = trim((string) ($firstRow['Vendor'] ?? $firstRow['vendor'] ?? ''));
                $department       = trim((string) ($firstRow['Department'] ?? $firstRow['department'] ?? ''));
                $currency         = trim((string) ($firstRow['Currency'] ?? $firstRow['currency'] ?? 'IDR'));
                $purchaseCategory = trim((string) ($firstRow['Purchase Category'] ?? $firstRow['purchase category'] ?? ''));
                $purchaseType     = trim((string) ($firstRow['Purchase Type'] ?? $firstRow['purchase type'] ?? ''));
                $orderDateRaw     = $firstRow['Date'] ?? $firstRow['date'] ?? null;
                $orderDate        = $this->parseDate($orderDateRaw);

                $createdBy        = trim((string) ($firstRow['Created By'] ?? $firstRow['Created by'] ?? $firstRow['created by'] ?? ''));
                $approvedBy       = trim((string) ($firstRow['Approved by'] ?? $firstRow['Approved By'] ?? $firstRow['approved by'] ?? ''));

                // Auto-create or find vendor company by name
                $vendorCompany = null;
                if (!empty($vendorName)) {
                    $vendorCompany = Company::firstOrCreate(
                        ['name' => $vendorName, 'type' => 'vendor'],
                        ['status' => 'pending']
                    );
                }

                // Create or find the PurchaseOrder record scoped by company
                // This ensures PO number is unique WITHIN a company, but can repeat ACROSS different companies
                $po = PurchaseOrder::updateOrCreate(
                    [
                        'buyer_company_id' => $company->id,
                        'po_number'        => $poNumber,
                    ],
                    [
                        'rfq_id'            => null,
                        'vendor_id'         => $vendorCompany?->id,
                        'vendor_name'       => $vendorName,
                        'department'        => $department,
                        'currency'          => $currency,
                        'purchase_category' => $purchaseCategory,
                        'purchase_type'     => $purchaseType,
                        'order_date'        => $orderDate,
                        'status'            => 'completed',
                        'is_historical'     => true,
                        'created_by'        => $createdBy,
                        'approved_by'       => $approvedBy,
                    ]
                );

                // If updating an existing PO, clear old items first to avoid duplication
                $po->historicalItems()->delete();

                // Create line items
                foreach ($poRows as $row) {
                    $inventoryCode = trim((string) ($row['Inventory Code'] ?? $row['inventory code'] ?? ''));
                    $inventoryName = trim((string) ($row['Inventory name'] ?? $row['inventory name'] ?? $row['Inventory Name'] ?? ''));

                    if (empty($inventoryName)) {
                        continue;
                    }

                    $qty          = (float) ($row['Qty'] ?? $row['qty'] ?? 1);
                    
                    // Priority for nominals based on user's excel structure
                    // Unit Price (Base)
                    $unitPrice    = $this->cleanDecimal($row['Unit price in original currency'] ?? $row['unit price in original currency'] ?? 0);
                    if ($unitPrice <= 0) {
                        $unitPrice = $this->cleanDecimal($row['Orgi Curr Unit Price'] ?? $row['orgi curr unit price'] ?? 0);
                    }

                    $amount       = $this->cleanDecimal($row['Amount in original currency'] ?? $row['amount in original currency'] ?? 0);
                    $taxAmount    = $this->cleanDecimal($row['Tax amount in original currency'] ?? $row['tax amount in original currency'] ?? 0);
                    $totalAmount  = $this->cleanDecimal($row['Original Currency Total Amount'] ?? $row['original currency total amount'] ?? 0);
                    
                    // Fallback calculations if total is missing
                    if ($totalAmount <= 0) {
                        $totalAmount = $amount + $taxAmount;
                    }
                    if ($amount <= 0 && $unitPrice > 0) {
                        $amount = $unitPrice * $qty;
                    }

                    $expectedDate = $this->parseDate($row['Expected receiving date'] ?? $row['expected receiving date'] ?? null);
                    $exchangeRate = $this->cleanDecimal($row['Exchange rate'] ?? $row['exchange rate'] ?? 1);

                    HistoricalPoItem::create([
                        'purchase_order_id'       => $po->id,
                        'pr_reference_number'     => trim((string) ($row['PR Refference Number'] ?? $row['pr refference number'] ?? $row['PR Reference Number'] ?? '')),
                        'inventory_code'          => $inventoryCode,
                        'inventory_name'          => $inventoryName,
                        'category'                => trim((string) ($row['Category'] ?? $row['category'] ?? '')),
                        'specifications'          => trim((string) ($row['Specifications'] ?? $row['specifications'] ?? '')),
                        'uom'                     => trim((string) ($row['Primary UOM'] ?? $row['primary uom'] ?? 'Pc')),
                        'qty'                     => $qty,
                        'unit_price'              => $unitPrice,
                        'amount'                  => $amount,
                        'tax_amount'              => $taxAmount,
                        'total_amount'            => $totalAmount,
                        'currency'                => $currency,
                        'exchange_rate'           => $exchangeRate ?: 1,
                        'clerk'                   => trim((string) ($row['Clerk'] ?? $row['clerk'] ?? '')),
                        'created_by'              => trim((string) ($row['Created By'] ?? $row['created by'] ?? '')),
                        'approved_by'             => trim((string) ($row['Approved by'] ?? $row['approved by'] ?? '')),
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
        if (is_numeric($value)) {
            return (float) $value;
        }

        $str = trim((string) $value);
        if (empty($str)) {
            return 0;
        }

        // Remove currency symbols or other non-numeric prefixes (except minus sign)
        $str = preg_replace('/[^\d,.-]/', '', $str);

        // Detect if it's Indonesian format: 1.000,00
        // If there's a comma and it's near the end, and there are dots before it
        if (str_contains($str, ',') && str_contains($str, '.')) {
            $lastComma = strrpos($str, ',');
            $lastDot = strrpos($str, '.');
            if ($lastComma > $lastDot) {
                // Indo format: 1.234,56
                $str = str_replace('.', '', $str);
                $str = str_replace(',', '.', $str);
            } else {
                // US format: 1,234.56
                $str = str_replace(',', '', $str);
            }
        } elseif (str_contains($str, ',')) {
            // Only comma: could be decimal (10,5) or thousand (10,000)
            // If comma is 3 digits from end, assume thousand unless there's only one comma and it's 1-2 digits from end
            $parts = explode(',', $str);
            if (count($parts) > 2 || (isset($parts[1]) && strlen($parts[1]) === 3)) {
                $str = str_replace(',', '', $str);
            } else {
                $str = str_replace(',', '.', $str);
            }
        } elseif (str_contains($str, '.')) {
            // Only dot: could be decimal (10.5) or thousand (10.000)
            $parts = explode('.', $str);
            if (count($parts) > 2 || (isset($parts[1]) && strlen($parts[1]) === 3)) {
                $str = str_replace('.', '', $str);
            }
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
        if (is_numeric($value) && $value > 10000) {
            try {
                // Excel serial date to PHP date
                $unixDate = ($value - 25569) * 86400;
                return date('Y-m-d', (int) $unixDate);
            } catch (\Exception $e) {
                // ignore
            }
        }

        $str = trim((string) $value);
        if (empty($str)) return null;

        // Try common formats
        $formats = ['d/m/y', 'd/m/Y', 'Y-m-d', 'm/d/Y', 'd-m-Y', 'j/n/y', 'j/n/Y'];
        foreach ($formats as $fmt) {
            try {
                $date = \DateTime::createFromFormat($fmt, $str);
                if ($date !== false) {
                    // Check for reasonable year (e.g., 2024 instead of 0024)
                    if ((int)$date->format('Y') < 100) {
                        $year = (int)$date->format('Y');
                        $date->setDate($year + 2000, (int)$date->format('m'), (int)$date->format('d'));
                    }
                    return $date->format('Y-m-d');
                }
            } catch (\Exception $e) {}
        }

        // Last resort: strtotime
        $ts = strtotime($str);
        return $ts ? date('Y-m-d', $ts) : null;
    }
}

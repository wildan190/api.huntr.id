<?php

namespace App\Domain\AI\Services;

use App\Domain\Company\Models\Company;
use App\Domain\Order\Models\Invoice;
use App\Domain\Order\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Builder;

/** Read-only, tenant-scoped functions callable by Huntr Assist. */
class HuntrAssistToolGateway
{
    public function supports(string $query): bool
    {
        return preg_match('/\b(po|purchase\s+order|invoice|faktur|tagihan)\b/iu', $query) === 1;
    }

    public function definitions(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'list_purchase_orders',
                    'description' => 'List Purchase Orders that belong to the active Huntr workspace. Use for questions about PO, their status, value, vendor, or recent PO.',
                    'strict' => true,
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'period' => ['type' => 'string', 'enum' => ['this_month', 'last_month', 'all']],
                            'status' => ['type' => ['string', 'null'], 'description' => 'Exact PO status, or null when no status filter was requested.'],
                            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
                        ],
                        'required' => ['period', 'status', 'limit'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'list_invoices',
                    'description' => 'List invoices issued from Purchase Orders in the active Huntr workspace. Use for invoices, proforma, final invoices, invoice status, and invoice amount.',
                    'strict' => true,
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'period' => ['type' => 'string', 'enum' => ['this_month', 'last_month', 'all']],
                            'status' => ['type' => ['string', 'null'], 'description' => 'Exact invoice status, or null when no status filter was requested.'],
                            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
                        ],
                        'required' => ['period', 'status', 'limit'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];
    }

    public function execute(string $name, array $arguments, Company $company): array
    {
        return match ($name) {
            'list_purchase_orders' => $this->purchaseOrders($arguments, $company),
            'list_invoices' => $this->invoices($arguments, $company),
            default => ['error' => 'Tool tidak tersedia.'],
        };
    }

    private function purchaseOrders(array $arguments, Company $company): array
    {
        $query = PurchaseOrder::query()
            ->when($company->type === 'vendor', fn (Builder $q) => $q->where('vendor_id', $company->id), fn (Builder $q) => $q->where('buyer_company_id', $company->id));
        $this->applyPeriod($query, $arguments['period'] ?? 'all');
        if (! empty($arguments['status'])) {
            $query->where('status', (string) $arguments['status']);
        }

        $rows = $query->latest('created_at')->limit($this->limit($arguments))->get([
            'id', 'po_number', 'vendor_name', 'currency', 'total_amount', 'status', 'created_at',
        ]);

        return [
            'count' => $rows->count(),
            'purchase_orders' => $rows->map(fn (PurchaseOrder $po) => [
                'number' => $po->po_number,
                'vendor' => $po->vendor_name,
                'currency' => $po->currency,
                'total_amount' => $po->total_amount,
                'status' => $po->status,
                'created_at' => $po->created_at?->toIso8601String(),
            ])->all(),
        ];
    }

    private function invoices(array $arguments, Company $company): array
    {
        $query = Invoice::query()->with('purchaseOrder:id,buyer_company_id,vendor_id,po_number,currency')
            ->whereNotNull('purchase_order_id')
            ->whereHas('purchaseOrder', function (Builder $q) use ($company): void {
                $q->where($company->type === 'vendor' ? 'vendor_id' : 'buyer_company_id', $company->id);
            });
        $this->applyPeriod($query, $arguments['period'] ?? 'all');
        if (! empty($arguments['status'])) {
            $query->where('status', (string) $arguments['status']);
        }

        $rows = $query->latest('created_at')->limit($this->limit($arguments))->get();

        return [
            'count' => $rows->count(),
            'invoices' => $rows->map(fn (Invoice $invoice) => [
                'purchase_order_number' => $invoice->purchaseOrder?->po_number,
                'type' => $invoice->type,
                'status' => $invoice->status,
                'currency' => $invoice->purchaseOrder?->currency ?? 'IDR',
                'amount' => $invoice->total_amount ?? $invoice->amount,
                'issued_at' => $invoice->created_at?->toIso8601String(),
            ])->all(),
        ];
    }

    private function applyPeriod(Builder $query, string $period): void
    {
        match ($period) {
            'this_month' => $query->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()]),
            'last_month' => $query->whereBetween('created_at', [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()]),
            default => null,
        };
    }

    private function limit(array $arguments): int
    {
        return min(20, max(1, (int) ($arguments['limit'] ?? 10)));
    }
}

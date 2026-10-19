<?php

namespace App\Domain\Subscription\Services;

use Illuminate\Support\Facades\DB;

class AdminSubscriptionMonitorService
{
    public function transactions(array $filters): array
    {
        $query = DB::table('subscription_checkouts as c')->join('companies as company', 'company.id', '=', 'c.company_id');
        if (! empty($filters['search'])) {
            $search = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['search']).'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(c.order_id) LIKE LOWER(?)', [$search])->orWhereRaw('LOWER(company.name) LIKE LOWER(?)', [$search]));
        }
        foreach (['status', 'purchase_type'] as $field) {
            if (! empty($filters[$field])) {
                $query->where('c.'.$field, $filters[$field]);
            }
        }
        if (! empty($filters['from'])) $query->where('c.created_at', '>=', \Carbon\Carbon::parse($filters['from'], 'Asia/Jakarta')->startOfDay()->utc());
        if (! empty($filters['to'])) $query->where('c.created_at', '<', \Carbon\Carbon::parse($filters['to'], 'Asia/Jakarta')->addDay()->startOfDay()->utc());

        $summary = [
            'total' => (clone $query)->count(),
            'paid_count' => (clone $query)->where('c.status', 'paid')->count(),
            'paid_amount' => (int) (clone $query)->where('c.status', 'paid')->sum('c.amount'),
            'pending_count' => (clone $query)->whereIn('c.status', ['pending', 'initiating'])->count(),
            'needs_attention' => (clone $query)->where('c.status', 'unknown')->count(),
            'active_subscriptions' => DB::table('company_subscriptions')->whereNotNull('enterprise_plan')->where('status', 'active')->where('starts_at', '<=', now())->where('ends_at', '>', now())->count(),
        ];
        $rows = $query->select('c.id', 'c.order_id', 'c.company_id', 'company.name as company_name', 'c.purchase_type', 'c.enterprise_plan', 'c.addon_key', 'c.quantity', 'c.addons_snapshot', 'c.billing_cycle', 'c.amount', 'c.payment_method', 'c.status', 'c.billing_email_sent_at', 'c.created_at')
            ->orderByDesc('c.created_at')->paginate((int) ($filters['per_page'] ?? 15));
        $rows->getCollection()->transform(function ($row) {
            $row->created_at = \Carbon\Carbon::parse($row->created_at, 'UTC')->toIso8601String();
            $row->addons_snapshot = json_decode($row->addons_snapshot ?? '[]', true) ?: [];
            return $row;
        });
        return ['summary' => $summary, 'transactions' => $rows];
    }

    public function audit(): array
    {
        return DB::table('enterprise_pricing_audits as a')->leftJoin('admins', 'admins.id', '=', 'a.admin_id')
            ->orderByDesc('a.created_at')->limit(10)->get(['a.id', 'a.created_at', 'a.before', 'a.after', 'admins.name as admin_name'])
            ->map(function ($row) {
                $row->created_at = \Carbon\Carbon::parse($row->created_at, 'UTC')->toIso8601String();
                return $row;
            })->all();
    }
}

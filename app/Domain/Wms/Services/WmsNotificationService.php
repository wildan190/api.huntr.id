<?php

namespace App\Domain\Wms\Services;

use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\Company;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;
use App\Domain\Communication\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WmsNotificationService
{
    public function __construct(
        private readonly BroadcastWebsocketNotificationAction $broadcastAction
    ) {}

    /**
     * Check if a stock item is at or below its reorder level and notify tenant users.
     */
    public function checkAndNotifyLowStock(Company $company, int|string|object $stock): void
    {
        try {
            $stockId = is_object($stock) ? ($stock->id ?? null) : $stock;
            if (! $stockId) {
                return;
            }

            $item = DB::table('warehouse_stock as s')
                ->join('warehouses as w', 'w.id', '=', 's.warehouse_id')
                ->where('s.id', $stockId)
                ->where('s.company_id', $company->id)
                ->select(
                    's.id',
                    's.sku',
                    's.item_name',
                    's.uom',
                    's.on_hand',
                    's.allocated',
                    's.reorder_level',
                    's.lead_time_days',
                    'w.name as warehouse_name'
                )
                ->first();

            if (! $item || (float) $item->reorder_level <= 0) {
                return;
            }

            $available = (float) $item->on_hand - (float) $item->allocated;
            if ($available > (float) $item->reorder_level) {
                return;
            }

            // Deduplicate: avoid sending if same stock was notified in the last 6 hours
            $alreadyNotified = DB::table('notifications')
                ->where('created_at', '>=', now()->subHours(6))
                ->where('data', 'like', '%"stock_id":' . $item->id . '%')
                ->exists();

            if ($alreadyNotified) {
                return;
            }

            $leadTimeDays = (int) ($item->lead_time_days ?? 0);
            $leadTimeText = $leadTimeDays > 0
                ? "{$leadTimeDays} hari (Est. tiba: " . now()->addDays($leadTimeDays)->format('d M Y') . ")"
                : "Belum diset";

            $title = "⚠️ Stok Menipis: {$item->item_name} ({$item->sku})";
            $body = "Stok tersedia tinggal {$available} {$item->uom} (batas reorder: {$item->reorder_level} {$item->uom}) di gudang {$item->warehouse_name}. Lead time: {$leadTimeText}. Segera buat Purchase Order!";

            $slug = Str::slug($company->name ?? '');
            $url = ($slug ? "/{$slug}" : '') . "/wms?tab=Reports";

            $notifData = [
                'type' => 'wms_low_stock',
                'stock_id' => $item->id,
                'sku' => $item->sku,
                'available' => $available,
                'reorder_level' => (float) $item->reorder_level,
                'lead_time_days' => $leadTimeDays,
            ];

            // 1. Notify Company model (in-app database notification)
            $company->notify(new DatabaseNotification($title, $body, $url, 'alert-triangle', $notifData));

            // 2. Notify company members / owner / approvers via WebSocket push + individual user database notification
            $userIds = collect($company->users->pluck('id'))
                ->push($company->owner_id)
                ->push(auth()->id())
                ->unique()
                ->filter();

            foreach ($userIds as $userId) {
                $user = User::find($userId);
                if ($user) {
                    $user->notify(new DatabaseNotification($title, $body, $url, 'alert-triangle', $notifData));
                }

                // In-app real-time WebSocket push (NO EMAIL)
                $this->broadcastAction->execute(
                    $title,
                    $body,
                    'test-channel',
                    false, // immediate push
                    (string) $userId,
                    $url,
                    $notifData
                );
            }
        } catch (\Throwable $e) {
            Log::warning("WmsNotificationService::checkAndNotifyLowStock failed: " . $e->getMessage());
        }
    }
}

<?php

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Support\CrmContext;
use App\Domain\Company\Services\CompanyAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Tenant-scoped CRM operations that sit outside the core sales workflow.
 * Secrets are encrypted before persistence; responses never expose them.
 */
class CrmEnterpriseService
{
    public function __construct(
        private CrmContext $context,
        private CompanyAccessService $access,
    ) {}

    public function contacts(Request $request)
    {
        $company = $this->context->tenant($request);

        return response()->json([
            'contacts' => DB::table('crm_contacts')
                ->where('company_id', $company->id)->where('user_id', request()->user()->id)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function storeContact(Request $request)
    {
        $company = $this->context->tenant($request);
        $data = $this->validated($request, [
            'customer_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:160'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:40'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'is_primary' => ['nullable', 'boolean'],
            'attributes' => ['nullable', 'array'],
        ]);
        $this->assertOwned($company->id, 'crm_customers', $data['customer_id']);

        $id = (string) Str::uuid();
        DB::table('crm_contacts')->insert($this->record($data, [
            'id' => $id,
            'company_id' => $company->id, 'user_id' => $request->user()->id,
            'is_primary' => $data['is_primary'] ?? false,
        ]));
        $this->audit($company->id, $request->user()?->id, 'contact', $id, 'created', $data);

        return response()->json(['contact' => DB::table('crm_contacts')->find($id)], 201);
    }

    public function campaigns(Request $request)
    {
        $company = $this->context->tenant($request);

        return response()->json([
            'campaigns' => DB::table('crm_campaigns')
                ->where('company_id', $company->id)->where('user_id', request()->user()->id)
                ->latest()
                ->get(),
        ]);
    }

    public function storeCampaign(Request $request)
    {
        $company = $this->context->tenant($request);
        $data = $this->validated($request, [
            'name' => ['required', 'string', 'max:160'],
            'channel' => ['nullable', 'in:email'],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string'],
            'audience' => ['nullable', 'array'],
            'scheduled_at' => ['nullable', 'date'],
        ]);
        $id = (string) Str::uuid();
        $status = empty($data['scheduled_at']) ? 'draft' : 'scheduled';

        DB::table('crm_campaigns')->insert($this->record($data, [
            'id' => $id,
            'company_id' => $company->id, 'user_id' => $request->user()->id,
            'created_by' => $request->user()?->id,
            'channel' => $data['channel'] ?? 'email',
            'status' => $status,
        ]));
        $this->audit($company->id, $request->user()?->id, 'campaign', $id, 'created', $data);

        return response()->json(['campaign' => DB::table('crm_campaigns')->find($id)], 201);
    }

    public function knowledge(Request $request)
    {
        $company = $this->context->tenant($request);

        return response()->json([
            'articles' => DB::table('crm_knowledge_articles')
                ->where('company_id', $company->id)->where('user_id', request()->user()->id)
                ->latest()
                ->get(),
        ]);
    }

    public function storeKnowledge(Request $request)
    {
        $company = $this->context->tenant($request);
        $data = $this->validated($request, [
            'title' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string'],
            'status' => ['nullable', 'in:draft,published'],
            'locale' => ['nullable', 'string', 'max:10'],
        ]);
        $id = (string) Str::uuid();

        DB::table('crm_knowledge_articles')->insert($this->record($data, [
            'id' => $id,
            'company_id' => $company->id, 'user_id' => $request->user()->id,
            'author_id' => $request->user()?->id,
            'slug' => Str::slug($data['title']) . '-' . Str::lower(Str::random(5)),
            'status' => $data['status'] ?? 'draft',
            'locale' => $data['locale'] ?? 'id',
        ]));

        return response()->json(['article' => DB::table('crm_knowledge_articles')->find($id)], 201);
    }

    public function integrations(Request $request)
    {
        $company = $this->context->tenant($request);

        return response()->json([
            'integrations' => DB::table('crm_integration_connections')
                ->where('company_id', $company->id)->where('user_id', request()->user()->id)
                ->get(['id', 'provider', 'channel', 'status', 'settings', 'last_synced_at', 'created_at']),
        ]);
    }

    public function connectIntegration(Request $request)
    {
        $company = $this->context->tenant($request);
        $data = $this->validated($request, [
            'provider' => ['required', 'in:gmail,microsoft,whatsapp,live_chat,social'],
            'channel' => ['required', 'in:email,whatsapp,live_chat,social'],
            'credentials' => ['nullable', 'array'],
            'settings' => ['nullable', 'array'],
        ]);
        $identity = [
            'company_id' => $company->id, 'user_id' => $request->user()->id,
            'provider' => $data['provider'],
            'channel' => $data['channel'],
        ];
        $existing = DB::table('crm_integration_connections')->where($identity)->first();
        $credentials = isset($data['credentials'])
            ? Crypt::encryptString(json_encode($data['credentials'], JSON_THROW_ON_ERROR))
            : $existing?->encrypted_credentials;
        $values = $this->record([
            'encrypted_credentials' => $credentials,
            'settings' => $data['settings'] ?? null,
        ], [
            ...$identity,
            'id' => $existing?->id ?? (string) Str::uuid(),
            'status' => $credentials ? 'connected' : 'pending_authorization',
        ]);
        if ($existing) {
            unset($values['created_at']);
        }
        DB::table('crm_integration_connections')->updateOrInsert($identity, $values);

        return response()->json([
            'message' => 'Koneksi disimpan. Aktifkan OAuth atau webhook penyedia dengan kredensial tenant untuk mulai sinkronisasi.',
        ]);
    }

    public function rates(Request $request)
    {
        $company = $this->context->tenant($request);

        return response()->json([
            'rates' => DB::table('crm_currency_rates')
                ->where('company_id', $company->id)->where('user_id', request()->user()->id)
                ->latest('effective_at')
                ->get(),
        ]);
    }

    public function storeRate(Request $request)
    {
        $company = $this->context->tenant($request);
        $data = $this->validated($request, [
            'base_currency' => ['required', 'string', 'size:3'],
            'quote_currency' => ['required', 'string', 'size:3', 'different:base_currency'],
            'rate' => ['required', 'numeric', 'gt:0'],
            'effective_at' => ['nullable', 'date'],
        ]);
        $id = (string) Str::uuid();

        DB::table('crm_currency_rates')->insert($this->record($data, [
            'id' => $id,
            'company_id' => $company->id, 'user_id' => $request->user()->id,
            'base_currency' => strtoupper($data['base_currency']),
            'quote_currency' => strtoupper($data['quote_currency']),
            'source' => 'manual',
            'effective_at' => $data['effective_at'] ?? now(),
        ]));

        return response()->json(['rate' => DB::table('crm_currency_rates')->find($id)], 201);
    }

    public function targets(Request $request)
    {
        $company = $this->context->tenant($request);

        return response()->json([
            'targets' => DB::table('crm_sales_targets')
                ->where('company_id', $company->id)->where('user_id', request()->user()->id)
                ->orderByDesc('period')
                ->get(),
        ]);
    }

    public function storeTarget(Request $request)
    {
        $company = $this->context->tenant($request);
        $data = $this->validated($request, [
            'period' => ['required', 'date_format:Y-m'],
            'target_amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'owner_id' => ['nullable', 'uuid'],
            'territory_id' => ['nullable', 'uuid'],
        ]);
        if (! empty($data['territory_id'])) {
            $this->assertOwned($company->id, 'crm_territories', $data['territory_id']);
        }
        $id = (string) Str::uuid();

        DB::table('crm_sales_targets')->insert($this->record($data, [
            'id' => $id,
            'company_id' => $company->id, 'user_id' => $request->user()->id,
            'currency' => strtoupper($data['currency'] ?? 'IDR'),
        ]));

        return response()->json(['target' => DB::table('crm_sales_targets')->find($id)], 201);
    }

    public function requestQuoteApproval(Request $request, string $quoteId)
    {
        $company = $this->context->tenant($request);
        $this->context->assertPermission($request, $company, 'crm.manage');
        $this->assertOwned($company->id, 'crm_quotes', $quoteId);
        $data = $this->validated($request, [
            'department' => ['nullable', 'string', 'max:120'],
            'approver_id' => ['required', 'uuid'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        abort_if((string) $data['approver_id'] === (string) $request->user()->id, 422, 'Peminta quote tidak dapat menjadi approver.');
        $approver = \App\Domain\Auth\Models\User::query()->findOrFail($data['approver_id']);
        abort_unless($this->access->isMember($approver, $company), 422, 'Approver harus merupakan anggota company ini.');
        $this->access->assertCan($approver, $company, 'crm.quote.approve', 'Approver belum memiliki izin menyetujui quote CRM.');
        $approvalId = (string) Str::uuid();

        DB::transaction(function () use ($company, $quoteId, $data, $approvalId, $request) {
            DB::table('crm_quote_approvals')->insert($this->record($data, [
                'id' => $approvalId,
                'company_id' => $company->id, 'user_id' => $request->user()->id,
                'quote_id' => $quoteId,
                'approver_id' => $data['approver_id'],
                'status' => 'pending',
            ]));
            DB::table('crm_quotes')->where('id', $quoteId)->update([
                'status' => 'pending_approval',
                'updated_at' => now(),
            ]);
            $this->audit($company->id, $request->user()?->id, 'quote', $quoteId, 'approval_requested', $data);
        });

        return response()->json(['approval' => DB::table('crm_quote_approvals')->find($approvalId)], 201);
    }

    public function decideQuoteApproval(Request $request, string $quoteId, string $approvalId)
    {
        $company = $this->context->tenant($request);
        $this->context->assertPermission($request, $company, 'crm.quote.approve');
        abort_unless(DB::table('crm_quotes')->where('company_id', $company->id)->where('id', $quoteId)->exists(), 404);
        $approval = DB::table('crm_quote_approvals')
            ->where('company_id', $company->id)->where('approver_id', request()->user()->id)
            ->where('quote_id', $quoteId)
            ->where('id', $approvalId)
            ->where('status', 'pending')
            ->first();
        if (! $approval) {
            throw ValidationException::withMessages(['approval' => 'Approval tidak ditemukan.']);
        }
        $data = $this->validated($request, [
            'decision' => ['required', 'in:approved,rejected'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($company, $quoteId, $approvalId, $data, $request) {
            DB::table('crm_quote_approvals')->where('id', $approvalId)->update([
                'status' => $data['decision'],
                'note' => $data['note'] ?? null,
                'decided_at' => now(),
                'updated_at' => now(),
            ]);
            $status = $data['decision'] === 'approved' ? 'approved' : 'draft';
            DB::table('crm_quotes')->where('id', $quoteId)->update(['status' => $status, 'updated_at' => now()]);
            $this->audit($company->id, $request->user()?->id, 'quote', $quoteId, 'approval_' . $data['decision'], $data);
        });

        return response()->json(['quote' => DB::table('crm_quotes')->find($quoteId)]);
    }

    public function reports(Request $request)
    {
        $company = $this->context->tenant($request);
        $period = $request->query('period', now()->format('Y-m'));
        $month = Carbon::createFromFormat('Y-m-d', $period . '-01')->startOfMonth();
        $currency = strtoupper((string) $request->query('currency', 'IDR'));
        $rates = $this->ratesByCurrency($company->id, $currency);
        $target = $this->convertRows(
            DB::table('crm_sales_targets')->where('company_id', $company->id)->where('user_id', request()->user()->id)->where('period', $period)->get(['target_amount as amount', 'currency']),
            $rates,
            $currency,
        );
        $won = $this->convertRows(
            DB::table('crm_deals')->where('company_id', $company->id)->where('user_id', request()->user()->id)->where('stage', 'closed_won')
                ->whereBetween('updated_at', [$month, $month->copy()->endOfMonth()])->get(['amount', 'currency']),
            $rates,
            $currency,
        );
        $allDeals = DB::table('crm_deals')->where('company_id', $company->id)->where('user_id', request()->user()->id)->count();
        $wonCount = DB::table('crm_deals')->where('company_id', $company->id)->where('user_id', request()->user()->id)->where('stage', 'closed_won')->count();

        return response()->json([
            'period' => $period,
            'currency' => $currency,
            'sales_target' => $target,
            'won_revenue' => $won,
            'target_attainment' => $target > 0 ? round(($won / $target) * 100, 2) : null,
            'win_rate' => $allDeals > 0 ? round(($wonCount / $allDeals) * 100, 2) : null,
            'tickets' => DB::table('crm_tickets')->where('company_id', $company->id)->where('user_id', request()->user()->id)->select('status', DB::raw('count(*) as count'))->groupBy('status')->pluck('count', 'status'),
            'campaigns' => DB::table('crm_campaigns')->where('company_id', $company->id)->where('user_id', request()->user()->id)->select('status', DB::raw('count(*) as count'))->groupBy('status')->pluck('count', 'status'),
            'rate_coverage' => count($rates),
        ]);
    }

    private function ratesByCurrency(string $companyId, string $baseCurrency): array
    {
        $rates = [$baseCurrency => 1.0];
        DB::table('crm_currency_rates')->where('company_id', $companyId)->where('user_id', request()->user()->id)
            ->where('base_currency', $baseCurrency)
            ->orderByDesc('effective_at')->get()
            ->each(function ($rate) use (&$rates) {
                $rates[$rate->quote_currency] ??= (float) $rate->rate;
            });

        return $rates;
    }

    private function convertRows(iterable $rows, array $rates, string $currency): float
    {
        return round(collect($rows)->sum(function ($row) use ($rates, $currency) {
            $from = strtoupper($row->currency ?? $currency);
            return (float) $row->amount / ($rates[$from] ?? ($from === $currency ? 1 : 1));
        }), 2);
    }

    private function validated(Request $request, array $rules): array
    {
        return validator($request->all(), $rules)->validate();
    }

    private function assertOwned(string $companyId, string $table, string $id): void
    {
        if (! DB::table($table)->where('company_id', $companyId)->where('user_id', request()->user()->id)->where('id', $id)->exists()) {
            throw ValidationException::withMessages(['id' => 'Data tidak ditemukan pada workspace ini.']);
        }
    }

    private function record(array $data, array $extra = []): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = json_encode($value, JSON_THROW_ON_ERROR);
            }
        }

        return array_merge($data, $extra, ['created_at' => now(), 'updated_at' => now()]);
    }

    private function audit(string $companyId, ?string $actorId, string $type, string $id, string $action, array $payload): void
    {
        DB::table('crm_audit_logs')->insert($this->record(['payload' => $payload], [
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'user_id' => $actorId,
            'actor_id' => $actorId,
            'entity_type' => $type,
            'entity_id' => $id,
            'action' => $action,
        ]));
    }
}

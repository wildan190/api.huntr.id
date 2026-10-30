<?php

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Support\CrmContext;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CrmService
{
    private const DEAL_STAGES = ['qualification', 'discovery', 'proposal', 'negotiation', 'closed_won', 'closed_lost'];
    private const LEAD_STATUSES = ['new', 'contacted', 'qualified', 'nurturing', 'converted', 'disqualified'];
    private const TICKET_STATUSES = ['open', 'pending', 'resolved', 'closed'];

    public function __construct(private CrmContext $context, private CrmWorkflowRunner $workflowRunner) {}

    public function dashboard(Request $request)
    {
        $company = $this->context->tenant($request);
        $companyId = $company->id;
        $openDeals = DB::table('crm_deals')->where('company_id', $companyId)->where('user_id', request()->user()->id)->whereNotIn('stage', ['closed_won', 'closed_lost']);
        $deals = $openDeals->get(['stage', 'amount', 'probability', 'currency']);
        $pipeline = $deals->groupBy('stage')->map(fn ($rows) => [
            'count' => $rows->count(),
            'amount' => round($rows->sum('amount'), 2),
            'weighted_amount' => round($rows->sum(fn ($deal) => (float) $deal->amount * ((int) $deal->probability / 100)), 2),
        ]);
        $now = now();

        return response()->json([
            'metrics' => [
                'open_leads' => DB::table('crm_leads')->where('company_id', $companyId)->where('user_id', request()->user()->id)->whereNotIn('status', ['converted', 'disqualified'])->count(),
                'pipeline_value' => round($deals->sum('amount'), 2),
                'weighted_forecast' => round($deals->sum(fn ($deal) => (float) $deal->amount * ((int) $deal->probability / 100)), 2),
                'open_tickets' => DB::table('crm_tickets')->where('company_id', $companyId)->where('user_id', request()->user()->id)->whereIn('status', ['open', 'pending'])->count(),
                'sla_breaches' => DB::table('crm_tickets')->where('company_id', $companyId)->where('user_id', request()->user()->id)->whereIn('status', ['open', 'pending'])->where(function ($query) use ($now) {
                    $query->where('first_response_due_at', '<', $now)->orWhere('resolve_due_at', '<', $now);
                })->count(),
                'overdue_tasks' => DB::table('crm_activities')->where('company_id', $companyId)->where('user_id', request()->user()->id)->where('status', 'open')->whereNotNull('due_at')->where('due_at', '<', $now)->count(),
            ],
            'pipeline' => $pipeline,
            'lead_funnel' => DB::table('crm_leads')->where('company_id', $companyId)->where('user_id', request()->user()->id)->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status'),
            'recent_activity' => DB::table('crm_activities')->where('company_id', $companyId)->where('user_id', request()->user()->id)->orderByDesc('created_at')->limit(8)->get(),
            'upcoming_tasks' => DB::table('crm_activities')->where('company_id', $companyId)->where('user_id', request()->user()->id)->where('status', 'open')->whereNotNull('due_at')->where('due_at', '>=', $now)->orderBy('due_at')->limit(8)->get(),
            'channels' => DB::table('crm_channel_conversations')->where('company_id', $companyId)->where('user_id', request()->user()->id)->select('channel', DB::raw('count(*) as total'))->groupBy('channel')->pluck('total', 'channel'),
        ]);
    }

    public function customers(Request $request)
    {
        $company = $this->context->tenant($request);
        $query = DB::table('crm_customers')->where('company_id', $company->id)->where('user_id', request()->user()->id);
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('email', 'ilike', "%{$search}%")->orWhere('company_name', 'ilike', "%{$search}%"));
        }
        if ($stage = $request->query('stage')) $query->where('lifecycle_stage', $stage);

        return response()->json($this->paginate($query->orderByDesc('updated_at'), $request));
    }

    public function customer(Request $request, string $customerId)
    {
        $company = $this->context->tenant($request);
        $customer = DB::table('crm_customers')->where('company_id', $company->id)->where('user_id', request()->user()->id)->where('id', $customerId)->first();
        abort_unless($customer, 404, 'Customer tidak ditemukan.');

        return response()->json([
            'customer' => $customer,
            'leads' => DB::table('crm_leads')->where('company_id', $company->id)->where('user_id', request()->user()->id)->where('customer_id', $customerId)->latest()->get(),
            'deals' => DB::table('crm_deals')->where('company_id', $company->id)->where('user_id', request()->user()->id)->where('customer_id', $customerId)->latest()->get(),
            'tickets' => DB::table('crm_tickets')->where('company_id', $company->id)->where('user_id', request()->user()->id)->where('customer_id', $customerId)->latest()->get(),
            'activities' => DB::table('crm_activities')->where('company_id', $company->id)->where('user_id', request()->user()->id)->where('customer_id', $customerId)->latest()->get(),
            'conversations' => DB::table('crm_channel_conversations')->where('company_id', $company->id)->where('user_id', request()->user()->id)->where('customer_id', $customerId)->latest('last_message_at')->get(),
            'purchase_history' => $customer->external_company_id
                ? DB::table('purchase_orders')->where('buyer_company_id', $company->id)->where('vendor_id', $customer->external_company_id)->latest('order_date')->limit(25)->get(['id', 'po_number', 'total_amount', 'currency', 'status', 'order_date'])
                : [],
        ]);
    }

    public function storeCustomer(Request $request)
    {
        $company = $this->context->tenant($request);
        $data = validator($request->all(), [
            'name' => ['required', 'string', 'max:160'], 'email' => ['nullable', 'email', 'max:180'], 'phone' => ['nullable', 'string', 'max:40'],
            'company_name' => ['nullable', 'string', 'max:180'], 'job_title' => ['nullable', 'string', 'max:150'], 'external_company_id' => ['nullable', 'uuid'], 'lifecycle_stage' => ['nullable', 'string', 'max:40'],
            'source' => ['nullable', 'string', 'max:80'], 'territory' => ['nullable', 'string', 'max:120'], 'language' => ['nullable', 'string', 'max:10'], 'currency' => ['nullable', 'string', 'size:3'],
            'attributes' => ['nullable', 'array'], 'notes' => ['nullable', 'string'],
        ])->validate();
        $id = (string) Str::uuid();
        DB::table('crm_customers')->insert(array_merge($this->timestamps(), $this->databasePayload($data), ['id' => $id, 'company_id' => $company->id, 'user_id' => $request->user()->id, 'language' => $data['language'] ?? 'id', 'currency' => strtoupper($data['currency'] ?? 'IDR')]));

        return response()->json(['customer' => DB::table('crm_customers')->where('id', $id)->first()], 201);
    }

    public function updateCustomer(Request $request, string $customerId)
    {
        $company = $this->context->tenant($request);
        $this->assertOwned($company->id, 'crm_customers', $customerId, 'Customer');
        $data = validator($request->all(), ['name' => ['sometimes', 'string', 'max:160'], 'email' => ['nullable', 'email', 'max:180'], 'phone' => ['nullable', 'string', 'max:40'], 'company_name' => ['nullable', 'string', 'max:180'], 'job_title' => ['nullable', 'string', 'max:150'], 'external_company_id' => ['nullable', 'uuid'], 'lifecycle_stage' => ['nullable', 'string', 'max:40'], 'source' => ['nullable', 'string', 'max:80'], 'territory' => ['nullable', 'string', 'max:120'], 'language' => ['nullable', 'string', 'max:10'], 'currency' => ['nullable', 'string', 'size:3'], 'attributes' => ['nullable', 'array'], 'notes' => ['nullable', 'string']])->validate();
        DB::table('crm_customers')->where('id', $customerId)->update(array_merge($this->databasePayload($data), ['updated_at' => now()]));

        return response()->json(['customer' => DB::table('crm_customers')->where('id', $customerId)->first()]);
    }

    public function leads(Request $request)
    {
        $company = $this->context->tenant($request);
        $query = DB::table('crm_leads')->where('company_id', $company->id)->where('user_id', request()->user()->id);
        if ($status = $request->query('status')) $query->where('status', $status);
        if ($search = trim((string) $request->query('search', ''))) $query->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('email', 'ilike', "%{$search}%")->orWhere('company_name', 'ilike', "%{$search}%"));

        return response()->json($this->paginate($query->orderByDesc('score')->orderByDesc('created_at'), $request));
    }

    public function storeLead(Request $request)
    {
        $company = $this->context->tenant($request);
        $data = validator($request->all(), [
            'customer_id' => ['nullable', 'uuid'], 'territory_id' => ['nullable', 'uuid'], 'name' => ['required', 'string', 'max:160'], 'email' => ['nullable', 'email'], 'phone' => ['nullable', 'string', 'max:40'],
            'company_name' => ['nullable', 'string', 'max:180'], 'source' => ['nullable', 'string', 'max:80'], 'status' => ['nullable', 'in:' . implode(',', self::LEAD_STATUSES)], 'estimated_value' => ['nullable', 'numeric', 'min:0'], 'currency' => ['nullable', 'string', 'size:3'], 'next_follow_up_at' => ['nullable', 'date'], 'attributes' => ['nullable', 'array'],
        ])->validate();
        $this->assertOptionalOwned($company->id, 'crm_customers', $data['customer_id'] ?? null, 'Customer');
        $this->assertOptionalOwned($company->id, 'crm_territories', $data['territory_id'] ?? null, 'Territory');
        $data['territory_id'] ??= $this->matchTerritory($company->id, $data);
        [$score, $reason] = $this->scoreLead($data);
        $id = (string) Str::uuid();
        DB::table('crm_leads')->insert(array_merge($this->timestamps(), $this->databasePayload($data), ['id' => $id, 'company_id' => $company->id, 'user_id' => $request->user()->id, 'owner_id' => $request->user()->id, 'status' => $data['status'] ?? 'new', 'score' => $score, 'score_reason' => $reason, 'estimated_value' => $data['estimated_value'] ?? 0, 'currency' => strtoupper($data['currency'] ?? 'IDR')]));

        
        $this->workflowRunner->run($company, 'lead_created', ['lead_id' => $id, 'customer_id' => $data['customer_id'] ?? null, 'user_id' => $request->user()->id, 'source' => $data['source'] ?? 'manual']);

        return response()->json(['lead' => DB::table('crm_leads')->where('id', $id)->first(), 'scoring_model' => 'explainable_lead_score_v1'], 201);
    }

    public function updateLead(Request $request, string $leadId)
    {
        $company = $this->context->tenant($request);
        $lead = $this->assertOwned($company->id, 'crm_leads', $leadId, 'Lead');
        $data = validator($request->all(), ['status' => ['sometimes', 'in:' . implode(',', self::LEAD_STATUSES)], 'customer_id' => ['nullable', 'uuid'], 'territory_id' => ['nullable', 'uuid'], 'estimated_value' => ['nullable', 'numeric', 'min:0'], 'next_follow_up_at' => ['nullable', 'date'], 'attributes' => ['nullable', 'array']])->validate();
        $this->assertOptionalOwned($company->id, 'crm_customers', $data['customer_id'] ?? null, 'Customer');
        $this->assertOptionalOwned($company->id, 'crm_territories', $data['territory_id'] ?? null, 'Territory');
        $data['territory_id'] ??= $this->matchTerritory($company->id, array_merge((array) $lead, $data));
        $scoreData = array_merge((array) $lead, $data);
        [$score, $reason] = $this->scoreLead($scoreData);
        DB::table('crm_leads')->where('id', $leadId)->update(array_merge($this->databasePayload($data), ['score' => $score, 'score_reason' => $reason, 'updated_at' => now()]));

        return response()->json(['lead' => DB::table('crm_leads')->where('id', $leadId)->first(), 'scoring_model' => 'explainable_lead_score_v1']);
    }

    /** Converts a qualified lead into a CRM customer and its initial pipeline deal. */
    public function convertLead(Request $request, string $leadId)
    {
        $company = $this->context->tenant($request);
        $lead = $this->assertOwned($company->id, 'crm_leads', $leadId, 'Lead');
        $data = validator($request->all(), [
            'create_deal' => ['nullable', 'boolean'],
        ])->validate();

        $result = DB::transaction(function () use ($company, $lead, $data, $request) {
            $customer = $lead->customer_id
                ? DB::table('crm_customers')->where('company_id', $company->id)->where('user_id', request()->user()->id)->where('id', $lead->customer_id)->first()
                : null;
            if (! $customer && $lead->email) {
                $customer = DB::table('crm_customers')->where('company_id', $company->id)->where('user_id', request()->user()->id)->whereRaw('lower(email) = ?', [strtolower($lead->email)])->first();
            }
            if (! $customer) {
                $customerId = (string) Str::uuid();
                DB::table('crm_customers')->insert([
                    ...$this->timestamps(),
                    'id' => $customerId,
                    'company_id' => $company->id, 'user_id' => $request->user()->id,
                    'name' => $lead->name,
                    'email' => $lead->email,
                    'phone' => $lead->phone,
                    'company_name' => $lead->company_name,
                    'lifecycle_stage' => 'customer',
                    'source' => $lead->source,
                    'territory' => $this->territoryName($company->id, $lead->territory_id),
                    'currency' => $lead->currency,
                    'attributes' => $lead->attributes,
                ]);
                $customer = DB::table('crm_customers')->find($customerId);
            }

            $deal = DB::table('crm_deals')->where('company_id', $company->id)->where('user_id', request()->user()->id)->where('lead_id', $lead->id)->first();
            if (! $deal && ($data['create_deal'] ?? true)) {
                $dealId = (string) Str::uuid();
                DB::table('crm_deals')->insert([
                    ...$this->timestamps(),
                    'id' => $dealId,
                    'company_id' => $company->id, 'user_id' => $request->user()->id,
                    'customer_id' => $customer->id,
                    'lead_id' => $lead->id,
                    'owner_id' => $request->user()?->id,
                    'territory_id' => $lead->territory_id,
                    'name' => 'Opportunity · ' . ($lead->company_name ?: $lead->name),
                    'stage' => 'qualification',
                    'probability' => max(10, min(80, (int) round($lead->score / 2))),
                    'amount' => $lead->estimated_value,
                    'currency' => $lead->currency,
                    'attributes' => $lead->attributes,
                ]);
                $deal = DB::table('crm_deals')->find($dealId);
            }

            DB::table('crm_leads')->where('id', $lead->id)->update([
                'customer_id' => $customer->id,
                'status' => 'converted',
                'updated_at' => now(),
            ]);
            DB::table('crm_activities')->insert([
                ...$this->timestamps(),
                'id' => (string) Str::uuid(),
                'company_id' => $company->id, 'user_id' => $request->user()->id,
                'customer_id' => $customer->id,
                'lead_id' => $lead->id,
                'deal_id' => $deal?->id,
                'created_by' => $request->user()?->id,
                'type' => 'follow_up',
                'title' => 'Lead dikonversi menjadi customer',
                'description' => 'Customer dan deal dibuat atau ditautkan secara otomatis dari lead.',
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            return [
                'lead' => DB::table('crm_leads')->find($lead->id),
                'customer' => $customer,
                'deal' => $deal,
            ];
        });

        return response()->json($result);
    }

    public function deals(Request $request)
    {
        $company = $this->context->tenant($request);
        $query = DB::table('crm_deals')->where('company_id', $company->id)->where('user_id', request()->user()->id);
        if ($stage = $request->query('stage')) $query->where('stage', $stage);

        return response()->json($this->paginate($query->orderBy('expected_close_date')->orderByDesc('amount'), $request));
    }

    public function storeDeal(Request $request)
    {
        $company = $this->context->tenant($request);
        $data = validator($request->all(), ['name' => ['required', 'string', 'max:180'], 'customer_id' => ['nullable', 'uuid'], 'lead_id' => ['nullable', 'uuid'], 'territory_id' => ['nullable', 'uuid'], 'stage' => ['nullable', 'in:' . implode(',', self::DEAL_STAGES)], 'probability' => ['nullable', 'integer', 'between:0,100'], 'amount' => ['nullable', 'numeric', 'min:0'], 'currency' => ['nullable', 'string', 'size:3'], 'expected_close_date' => ['nullable', 'date'], 'attributes' => ['nullable', 'array']])->validate();
        $this->assertOptionalOwned($company->id, 'crm_customers', $data['customer_id'] ?? null, 'Customer');
        $this->assertOptionalOwned($company->id, 'crm_leads', $data['lead_id'] ?? null, 'Lead');
        $id = (string) Str::uuid();
        DB::table('crm_deals')->insert(array_merge($this->timestamps(), $this->databasePayload($data), ['id' => $id, 'company_id' => $company->id, 'user_id' => $request->user()->id, 'owner_id' => $request->user()->id, 'stage' => $data['stage'] ?? 'qualification', 'probability' => $data['probability'] ?? 10, 'amount' => $data['amount'] ?? 0, 'currency' => strtoupper($data['currency'] ?? 'IDR')]));

        return response()->json(['deal' => DB::table('crm_deals')->where('id', $id)->first()], 201);
    }

    public function updateDeal(Request $request, string $dealId)
    {
        $company = $this->context->tenant($request);
        $this->assertOwned($company->id, 'crm_deals', $dealId, 'Deal');
        $data = validator($request->all(), ['stage' => ['sometimes', 'in:' . implode(',', self::DEAL_STAGES)], 'probability' => ['nullable', 'integer', 'between:0,100'], 'amount' => ['nullable', 'numeric', 'min:0'], 'expected_close_date' => ['nullable', 'date'], 'loss_reason' => ['nullable', 'string', 'max:500'], 'attributes' => ['nullable', 'array']])->validate();
        DB::table('crm_deals')->where('id', $dealId)->update(array_merge($this->databasePayload($data), ['updated_at' => now()]));
        if (array_key_exists('stage', $data)) {
            $this->workflowRunner->run($company, 'deal_stage_changed', ['deal_id' => $dealId, 'user_id' => $request->user()->id, 'stage' => $data['stage']]);
        }

        return response()->json(['deal' => DB::table('crm_deals')->where('id', $dealId)->first()]);
    }

    public function activities(Request $request)
    {
        $company = $this->context->tenant($request);
        $query = DB::table('crm_activities')->where('company_id', $company->id)->where('user_id', request()->user()->id);
        if ($status = $request->query('status')) $query->where('status', $status);

        return response()->json($this->paginate($query->orderBy('due_at')->orderByDesc('created_at'), $request));
    }

    public function storeActivity(Request $request)
    {
        $company = $this->context->tenant($request);
        $data = validator($request->all(), ['type' => ['nullable', 'in:task,call,email,meeting,follow_up'], 'title' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string'], 'customer_id' => ['nullable', 'uuid'], 'lead_id' => ['nullable', 'uuid'], 'deal_id' => ['nullable', 'uuid'], 'assignee_id' => ['nullable', 'uuid'], 'due_at' => ['nullable', 'date']])->validate();
        foreach (['customer_id' => 'crm_customers', 'lead_id' => 'crm_leads', 'deal_id' => 'crm_deals'] as $key => $table) $this->assertOptionalOwned($company->id, $table, $data[$key] ?? null, ucfirst(str_replace('_id', '', $key)));
        $id = (string) Str::uuid();
        DB::table('crm_activities')->insert(array_merge($this->timestamps(), $this->databasePayload($data), ['id' => $id, 'company_id' => $company->id, 'user_id' => $request->user()->id, 'created_by' => $request->user()->id, 'type' => $data['type'] ?? 'task', 'status' => 'open']));

        return response()->json(['activity' => DB::table('crm_activities')->where('id', $id)->first()], 201);
    }

    public function updateActivity(Request $request, string $activityId)
    {
        $company = $this->context->tenant($request);
        $this->assertOwned($company->id, 'crm_activities', $activityId, 'Activity');
        $data = validator($request->all(), ['status' => ['sometimes', 'in:open,completed,cancelled'], 'due_at' => ['nullable', 'date'], 'title' => ['sometimes', 'string', 'max:180'], 'description' => ['nullable', 'string']])->validate();
        if (($data['status'] ?? null) === 'completed') $data['completed_at'] = now();
        DB::table('crm_activities')->where('id', $activityId)->update(array_merge($this->databasePayload($data), ['updated_at' => now()]));

        return response()->json(['activity' => DB::table('crm_activities')->where('id', $activityId)->first()]);
    }

    public function tickets(Request $request)
    {
        $company = $this->context->tenant($request);
        $query = DB::table('crm_tickets')->where('company_id', $company->id)->where('user_id', request()->user()->id);
        if ($status = $request->query('status')) $query->where('status', $status);

        return response()->json($this->paginate($query->orderByDesc('created_at'), $request));
    }

    public function storeTicket(Request $request)
    {
        $company = $this->context->tenant($request);
        $data = validator($request->all(), ['subject' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string'], 'customer_id' => ['nullable', 'uuid'], 'channel' => ['nullable', 'in:web,email,whatsapp,live_chat,social'], 'priority' => ['nullable', 'in:low,normal,high,urgent'], 'first_response_due_at' => ['nullable', 'date'], 'resolve_due_at' => ['nullable', 'date']])->validate();
        $this->assertOptionalOwned($company->id, 'crm_customers', $data['customer_id'] ?? null, 'Customer');
        $id = (string) Str::uuid();
        $priority = $data['priority'] ?? 'normal';
        $sla = $this->ticketSla($priority);
        DB::table('crm_tickets')->insert(array_merge($this->timestamps(), $this->databasePayload($data), ['id' => $id, 'company_id' => $company->id, 'user_id' => $request->user()->id, 'number' => $this->number('TKT'), 'channel' => $data['channel'] ?? 'web', 'priority' => $priority, 'status' => 'open', 'first_response_due_at' => $data['first_response_due_at'] ?? now()->addMinutes($sla['response_minutes']), 'resolve_due_at' => $data['resolve_due_at'] ?? now()->addMinutes($sla['resolve_minutes'])]));

        
        $this->workflowRunner->run($company, 'ticket_created', ['customer_id' => $data['customer_id'] ?? null, 'user_id' => $request->user()->id, 'ticket_id' => $id, 'priority' => $data['priority'] ?? 'normal']);

        return response()->json(['ticket' => DB::table('crm_tickets')->where('id', $id)->first()], 201);
    }

    public function updateTicket(Request $request, string $ticketId)
    {
        $company = $this->context->tenant($request);
        $this->assertOwned($company->id, 'crm_tickets', $ticketId, 'Ticket');
        $data = validator($request->all(), ['status' => ['sometimes', 'in:' . implode(',', self::TICKET_STATUSES)], 'priority' => ['nullable', 'in:low,normal,high,urgent'], 'assignee_id' => ['nullable', 'uuid']])->validate();
        if (in_array($data['status'] ?? '', ['resolved', 'closed'], true)) $data['resolved_at'] = now();
        DB::table('crm_tickets')->where('id', $ticketId)->update(array_merge($this->databasePayload($data), ['updated_at' => now()]));

        return response()->json(['ticket' => DB::table('crm_tickets')->where('id', $ticketId)->first()]);
    }

    public function ticketMessages(Request $request, string $ticketId)
    {
        $company = $this->context->tenant($request);
        $this->assertOwned($company->id, 'crm_tickets', $ticketId, 'Ticket');

        return response()->json(['messages' => DB::table('crm_ticket_messages')->where('company_id', $company->id)->where('user_id', request()->user()->id)->where('ticket_id', $ticketId)->oldest('created_at')->get()]);
    }

    public function storeTicketMessage(Request $request, string $ticketId)
    {
        $company = $this->context->tenant($request);
        $this->assertOwned($company->id, 'crm_tickets', $ticketId, 'Ticket');
        $data = validator($request->all(), ['body' => ['required', 'string'], 'is_internal' => ['nullable', 'boolean']])->validate();
        $id = (string) Str::uuid();
        DB::table('crm_ticket_messages')->insert(array_merge($this->timestamps(), $this->databasePayload($data), ['id' => $id, 'company_id' => $company->id, 'user_id' => $request->user()->id, 'ticket_id' => $ticketId, 'author_id' => $request->user()->id, 'is_internal' => $data['is_internal'] ?? false]));
        if (! ($data['is_internal'] ?? false)) DB::table('crm_tickets')->where('id', $ticketId)->whereNull('first_responded_at')->update(['first_responded_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => DB::table('crm_ticket_messages')->where('id', $id)->first()], 201);
    }

    public function conversations(Request $request)
    {
        $company = $this->context->tenant($request);
        $query = DB::table('crm_channel_conversations as conversations')
            ->leftJoin('crm_customers as customers', 'customers.id', '=', 'conversations.customer_id')
            ->where('conversations.company_id', $company->id)
            ->where('conversations.user_id', $request->user()->id)
            ->select([
                'conversations.*',
                'customers.name as customer_name',
                'customers.phone as customer_phone',
            ])
            ->selectSub(
                DB::table('crm_conversation_messages as messages')
                    ->select('messages.body')
                    ->whereColumn('messages.conversation_id', 'conversations.id')
                    ->orderByDesc('messages.created_at')
                    ->limit(1),
                'last_message_body',
            );
        if ($channel = $request->query('channel')) $query->where('conversations.channel', $channel);

        return response()->json($this->paginate($query->orderByDesc('conversations.last_message_at')->orderByDesc('conversations.created_at'), $request));
    }

    public function storeConversation(Request $request)
    {
        $company = $this->context->tenant($request);
        $data = validator($request->all(), ['customer_id' => ['nullable', 'uuid'], 'channel' => ['required', 'in:whatsapp,email,live_chat,social'], 'external_id' => ['nullable', 'string', 'max:180'], 'metadata' => ['nullable', 'array']])->validate();
        $this->assertOptionalOwned($company->id, 'crm_customers', $data['customer_id'] ?? null, 'Customer');
        $id = (string) Str::uuid();
        DB::table('crm_channel_conversations')->insert(array_merge($this->timestamps(), $this->databasePayload($data), ['id' => $id, 'company_id' => $company->id, 'user_id' => $request->user()->id, 'status' => 'open', 'last_message_at' => now()]));

        return response()->json(['conversation' => DB::table('crm_channel_conversations')->where('id', $id)->first()], 201);
    }

    public function conversationMessages(Request $request, string $conversationId)
    {
        $company = $this->context->tenant($request);
        $this->assertOwned($company->id, 'crm_channel_conversations', $conversationId, 'Conversation');

        return response()->json([
            'messages' => DB::table('crm_conversation_messages')->where('company_id', $company->id)->where('user_id', request()->user()->id)
                ->where('conversation_id', $conversationId)->oldest('created_at')->get(),
        ]);
    }

    public function storeConversationMessage(Request $request, string $conversationId)
    {
        $company = $this->context->tenant($request);
        $this->assertOwned($company->id, 'crm_channel_conversations', $conversationId, 'Conversation');
        $data = validator($request->all(), [
            'body' => ['required', 'string'],
            'direction' => ['nullable', 'in:inbound,outbound'],
            'metadata' => ['nullable', 'array'],
        ])->validate();
        $id = (string) Str::uuid();
        DB::transaction(function () use ($company, $conversationId, $data, $id, $request) {
            DB::table('crm_conversation_messages')->insert(array_merge(
                $this->timestamps(),
                $this->databasePayload($data),
                [
                    'id' => $id,
                    'company_id' => $company->id, 'user_id' => $request->user()->id,
                    'conversation_id' => $conversationId,
                    'author_id' => $request->user()?->id,
                    'direction' => $data['direction'] ?? 'outbound',
                    'delivered_at' => ($data['direction'] ?? 'outbound') === 'outbound' ? now() : null,
                ],
            ));
            DB::table('crm_channel_conversations')->where('id', $conversationId)->update([
                'last_message_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json(['message' => DB::table('crm_conversation_messages')->find($id)], 201);
    }

    public function quotes(Request $request)
    {
        $company = $this->context->tenant($request);
        $quotes = DB::table('crm_quotes as quotes')
            ->leftJoin('crm_quote_approvals as approvals', function ($join) use ($request): void {
                $join->on('approvals.quote_id', '=', 'quotes.id')
                    ->where('approvals.approver_id', '=', $request->user()->id)
                    ->where('approvals.status', '=', 'pending');
            })
            ->where('quotes.company_id', $company->id)
            ->where(function ($query) use ($request): void {
                $query->where('quotes.user_id', $request->user()->id)
                    ->orWhereNotNull('approvals.id');
            })
            ->select('quotes.*', 'approvals.id as pending_approval_id')
            ->orderByDesc('quotes.created_at');

        return response()->json($this->paginate($quotes, $request));
    }

    public function storeQuote(Request $request)
    {
        $company = $this->context->tenant($request);
        $data = validator($request->all(), ['customer_id' => ['nullable', 'uuid'], 'deal_id' => ['nullable', 'uuid'], 'currency' => ['nullable', 'string', 'size:3'], 'discount_amount' => ['nullable', 'numeric', 'min:0'], 'tax_amount' => ['nullable', 'numeric', 'min:0'], 'valid_until' => ['nullable', 'date'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.name' => ['required', 'string', 'max:180'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'lines.*.unit_price' => ['required', 'numeric', 'min:0'], 'terms' => ['nullable', 'string']])->validate();
        $this->assertOptionalOwned($company->id, 'crm_customers', $data['customer_id'] ?? null, 'Customer');
        $this->assertOptionalOwned($company->id, 'crm_deals', $data['deal_id'] ?? null, 'Deal');
        $subtotal = collect($data['lines'])->sum(fn ($line) => (float) $line['quantity'] * (float) $line['unit_price']);
        $total = max(0, $subtotal - (float) ($data['discount_amount'] ?? 0) + (float) ($data['tax_amount'] ?? 0));
        $id = (string) Str::uuid();
        DB::table('crm_quotes')->insert(array_merge($this->timestamps(), $this->databasePayload($data), ['id' => $id, 'company_id' => $company->id, 'user_id' => $request->user()->id, 'owner_id' => $request->user()->id, 'number' => $this->number('QTE'), 'status' => 'draft', 'currency' => strtoupper($data['currency'] ?? 'IDR'), 'subtotal' => $subtotal, 'discount_amount' => $data['discount_amount'] ?? 0, 'tax_amount' => $data['tax_amount'] ?? 0, 'total_amount' => $total]));

        return response()->json(['quote' => DB::table('crm_quotes')->where('id', $id)->first()], 201);
    }

    public function territories(Request $request)
    {
        $company = $this->context->tenant($request);
        return response()->json(['territories' => DB::table('crm_territories')->where('company_id', $company->id)->where('user_id', request()->user()->id)->orderBy('name')->get()]);
    }

    public function storeTerritory(Request $request)
    {
        $company = $this->context->tenant($request);
        $data = validator($request->all(), ['name' => ['required', 'string', 'max:150'], 'region' => ['nullable', 'string', 'max:150'], 'industry' => ['nullable', 'string', 'max:150'], 'rules' => ['nullable', 'array'], 'owner_id' => ['nullable', 'uuid']])->validate();
        $id = (string) Str::uuid();
        DB::table('crm_territories')->insert(array_merge($this->timestamps(), $this->databasePayload($data), ['id' => $id, 'company_id' => $company->id, 'user_id' => $request->user()->id]));

        return response()->json(['territory' => DB::table('crm_territories')->where('id', $id)->first()], 201);
    }

    public function workflows(Request $request)
    {
        $company = $this->context->tenant($request);
        return response()->json(['workflows' => DB::table('crm_workflows')->where('company_id', $company->id)->where('user_id', request()->user()->id)->orderByDesc('updated_at')->get()]);
    }

    public function storeWorkflow(Request $request)
    {
        $company = $this->context->tenant($request);
        $data = validator($request->all(), ['name' => ['required', 'string', 'max:160'], 'trigger' => ['required', 'in:lead_created,deal_stage_changed,ticket_created,quote_approved'], 'conditions' => ['nullable', 'array'], 'actions' => ['required', 'array', 'min:1'], 'is_active' => ['nullable', 'boolean']])->validate();
        $id = (string) Str::uuid();
        DB::table('crm_workflows')->insert(array_merge($this->timestamps(), $this->databasePayload($data), ['id' => $id, 'company_id' => $company->id, 'user_id' => $request->user()->id, 'is_active' => $data['is_active'] ?? true]));

        return response()->json(['workflow' => DB::table('crm_workflows')->where('id', $id)->first()], 201);
    }

    public function emailConnections(Request $request)
    {
        $company = $this->context->tenant($request);
        return response()->json(['connections' => DB::table('crm_email_connections')->where('company_id', $company->id)->where('user_id', request()->user()->id)->orderByDesc('created_at')->get(['id', 'provider', 'email', 'status', 'last_synced_at', 'created_at'])]);
    }

    public function storeEmailConnection(Request $request)
    {
        $company = $this->context->tenant($request);
        $data = validator($request->all(), ['provider' => ['required', 'in:gmail,microsoft,imap'], 'email' => ['required', 'email'], 'settings' => ['nullable', 'array']])->validate();
        $existing = DB::table('crm_email_connections')->where('company_id', $company->id)->where('user_id', request()->user()->id)->where('email', $data['email'])->first();
        if ($existing) return response()->json(['connection' => $existing, 'message' => 'Email sudah terhubung.']);
        $id = (string) Str::uuid();
        DB::table('crm_email_connections')->insert(array_merge($this->timestamps(), $this->databasePayload($data), ['id' => $id, 'company_id' => $company->id, 'user_id' => $request->user()->id, 'status' => 'pending_authorization']));

        return response()->json(['connection' => DB::table('crm_email_connections')->where('id', $id)->first(), 'message' => 'Koneksi email disimpan. Lanjutkan otorisasi OAuth dari penyedia email.'], 201);
    }

    public function forecast(Request $request)
    {
        $company = $this->context->tenant($request);
        $companyId = $company->id;
        $deals = DB::table('crm_deals')->where('company_id', $companyId)->where('user_id', request()->user()->id)->whereNotIn('stage', ['closed_won', 'closed_lost'])->get(['amount', 'probability']);
        $closed = DB::table('crm_deals')->where('company_id', $companyId)->where('user_id', request()->user()->id)->whereIn('stage', ['closed_won', 'closed_lost'])->get(['stage', 'amount']);
        $won = $closed->where('stage', 'closed_won');
        $winRate = $closed->count() ? round($won->count() / $closed->count(), 4) : null;
        $weighted = round($deals->sum(fn ($deal) => (float) $deal->amount * ((int) $deal->probability / 100)), 2);
        $confidence = $winRate === null ? 'low' : ($closed->count() >= 12 ? 'high' : 'medium');

        return response()->json(['forecast' => ['period' => 'next_quarter', 'currency' => 'IDR', 'pipeline_amount' => round($deals->sum('amount'), 2), 'weighted_amount' => $weighted, 'predicted_amount' => $weighted, 'historical_win_rate' => $winRate, 'confidence' => $confidence, 'model' => 'pipeline_probability_with_historical_conversion_v1', 'explanation' => 'Prediksi memakai nilai deal terbuka, probabilitas per tahap, dan rasio menang deal historis saat data historis tersedia.']]);
    }

    private function paginate($query, Request $request): array
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(10, (int) $request->query('per_page', 25)));
        /** @var LengthAwarePaginator $items */
        $items = $query->paginate($perPage, ['*'], 'page', $page);

        return ['data' => $items->items(), 'meta' => ['page' => $items->currentPage(), 'per_page' => $items->perPage(), 'total' => $items->total(), 'last_page' => $items->lastPage()]];
    }

    /** Query builder does not cast arrays for PostgreSQL json/jsonb columns. */
    private function databasePayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = json_encode($value, JSON_THROW_ON_ERROR);
            }
        }

        return $payload;
    }

    private function timestamps(): array
    {
        return ['created_at' => now(), 'updated_at' => now()];
    }

    private function assertOwned(string $companyId, string $table, string $id, string $label): object
    {
        $row = DB::table($table)->where('company_id', $companyId)->where('user_id', request()->user()->id)->where('id', $id)->first();
        if (! $row) throw ValidationException::withMessages(['id' => "{$label} tidak ditemukan pada workspace ini."]);

        return $row;
    }

    private function assertOptionalOwned(string $companyId, string $table, ?string $id, string $label): void
    {
        if ($id) $this->assertOwned($companyId, $table, $id, $label);
    }

    private function scoreLead(array $lead): array
    {
        $score = 15;
        $reasons = ['Lead baru'];
        if (! empty($lead['email'])) { $score += 20; $reasons[] = 'email tersedia'; }
        if (! empty($lead['phone'])) { $score += 15; $reasons[] = 'telepon tersedia'; }
        if (! empty($lead['company_name'])) { $score += 15; $reasons[] = 'perusahaan teridentifikasi'; }
        $value = (float) ($lead['estimated_value'] ?? 0);
        if ($value >= 100000000) { $score += 25; $reasons[] = 'potensi nilai tinggi'; }
        elseif ($value >= 10000000) { $score += 15; $reasons[] = 'potensi nilai menengah'; }
        if (in_array($lead['source'] ?? '', ['referral', 'inbound', 'website'], true)) { $score += 10; $reasons[] = 'sumber inbound/referral'; }

        return [min(100, $score), implode(', ', $reasons)];
    }

    /** Match by territory rules before assigning the lead, while keeping an explicit assignment untouched. */
    private function matchTerritory(string $companyId, array $lead): ?string
    {
        $attributes = is_array($lead['attributes'] ?? null)
            ? $lead['attributes']
            : (json_decode((string) ($lead['attributes'] ?? '{}'), true) ?: []);
        $region = strtolower((string) ($attributes['region'] ?? $lead['territory'] ?? ''));
        $industry = strtolower((string) ($attributes['industry'] ?? ''));

        foreach (DB::table('crm_territories')->where('company_id', $companyId)->where('user_id', request()->user()->id)->orderBy('created_at')->get() as $territory) {
            $rules = json_decode($territory->rules ?? '[]', true) ?: [];
            $regions = array_map('strtolower', $rules['regions'] ?? array_filter([$territory->region]));
            $industries = array_map('strtolower', $rules['industries'] ?? array_filter([$territory->industry]));
            $regionMatches = empty($regions) || in_array($region, $regions, true);
            $industryMatches = empty($industries) || in_array($industry, $industries, true);
            if ($regionMatches && $industryMatches) return $territory->id;
        }

        return null;
    }

    private function ticketSla(string $priority): array
    {
        return match ($priority) {
            'urgent' => ['response_minutes' => 15, 'resolve_minutes' => 240],
            'high' => ['response_minutes' => 60, 'resolve_minutes' => 480],
            'low' => ['response_minutes' => 480, 'resolve_minutes' => 4320],
            default => ['response_minutes' => 240, 'resolve_minutes' => 1440],
        };
    }

    private function territoryName(string $companyId, ?string $territoryId): ?string
    {
        return $territoryId
            ? DB::table('crm_territories')->where('company_id', $companyId)->where('user_id', request()->user()->id)->where('id', $territoryId)->value('name')
            : null;
    }

    private function number(string $prefix): string
    {
        return $prefix . '-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
    }
}

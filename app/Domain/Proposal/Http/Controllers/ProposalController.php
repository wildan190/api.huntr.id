<?php

namespace App\Domain\Proposal\Http\Controllers;

use App\Domain\Proposal\Actions\SubmitProposalAction;
use App\Domain\Proposal\Http\Requests\SubmitProposalRequest;
use Illuminate\Http\JsonResponse;
use App\Domain\Company\Models\Company;
use App\Domain\Rfq\Models\Rfq;

class ProposalController extends \App\Http\Controllers\Controller
{
    public function store(SubmitProposalRequest $request, SubmitProposalAction $action): JsonResponse
    {
        $company = Company::findOrFail($request->input('company_id'));
        $rfq = Rfq::findOrFail($request->input('rfq_id'));
        $proposal = $action->execute($company, $rfq, $request->validated());
        return response()->json(['proposal' => $proposal], 201);
    }
}

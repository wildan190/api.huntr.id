<?php

namespace App\Domain\Budget\Http\Controllers;

use App\Domain\Budget\Http\Requests\DecideProcurementPlanRequest;
use App\Domain\Budget\Http\Requests\LinkBudgetDocumentRequest;
use App\Domain\Budget\Http\Requests\StoreBudgetCycleRequest;
use App\Domain\Budget\Http\Requests\StoreBudgetLineRequest;
use App\Domain\Budget\Http\Requests\StoreProcurementPlanRequest;
use App\Domain\Budget\Services\BudgetAppService;
use App\Domain\Budget\Services\BudgetService;
use Illuminate\Http\Request;

class BudgetController
{
    public function apps(Request $request, BudgetAppService $service)
    {
        return $service->apps($request);
    }

    public function install(Request $request, BudgetAppService $service)
    {
        return $service->install($request);
    }

    public function uninstall(Request $request, BudgetAppService $service)
    {
        return $service->uninstall($request);
    }

    public function dashboard(Request $request, BudgetService $service)
    {
        return $service->dashboard($request);
    }

    public function cycles(Request $request, BudgetService $service)
    {
        return $service->cycles($request);
    }

    public function storeCycle(StoreBudgetCycleRequest $request, BudgetService $service)
    {
        return $service->storeCycle($request, $request->validated());
    }

    public function updateCycle(StoreBudgetCycleRequest $request, string $cycleId, BudgetService $service)
    {
        return $service->updateCycle($request, $cycleId, $request->validated());
    }

    public function activateCycle(Request $request, string $cycleId, BudgetService $service)
    {
        return $service->activateCycle($request, $cycleId);
    }

    public function lines(Request $request, BudgetService $service)
    {
        return $service->lines($request);
    }

    public function storeLine(StoreBudgetLineRequest $request, BudgetService $service)
    {
        return $service->storeLine($request, $request->validated());
    }

    public function updateLine(StoreBudgetLineRequest $request, string $lineId, BudgetService $service)
    {
        return $service->updateLine($request, $lineId, $request->validated());
    }

    public function plans(Request $request, BudgetService $service)
    {
        return $service->plans($request);
    }

    public function storePlan(StoreProcurementPlanRequest $request, BudgetService $service)
    {
        return $service->storePlan($request, $request->validated());
    }

    public function updatePlan(StoreProcurementPlanRequest $request, string $planId, BudgetService $service)
    {
        return $service->updatePlan($request, $planId, $request->validated());
    }

    public function submitPlan(Request $request, string $planId, BudgetService $service)
    {
        return $service->submitPlan($request, $planId);
    }

    public function approvePlan(DecideProcurementPlanRequest $request, string $planId, BudgetService $service)
    {
        return $service->approvePlan($request, $planId);
    }

    public function rejectPlan(DecideProcurementPlanRequest $request, string $planId, BudgetService $service)
    {
        return $service->rejectPlan($request, $planId);
    }

    public function linkRfq(LinkBudgetDocumentRequest $request, string $planId, BudgetService $service)
    {
        return $service->linkRfq($request, $planId, $request->validated());
    }

    public function linkPurchaseOrder(LinkBudgetDocumentRequest $request, string $planId, BudgetService $service)
    {
        return $service->linkPurchaseOrder($request, $planId, $request->validated());
    }

    public function recordActual(LinkBudgetDocumentRequest $request, string $planId, BudgetService $service)
    {
        return $service->recordActual($request, $planId, $request->validated());
    }
}

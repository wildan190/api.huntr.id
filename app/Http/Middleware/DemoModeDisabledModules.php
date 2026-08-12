<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DemoModeDisabledModules
{
    protected array $disabledPathPatterns = [
        'api/efaktur',
        'api/efaktur/*',
        'api/returns',
        'api/returns/*',
        'api/debit-notes',
        'api/debit-notes/*',
        'api/basts',
        'api/basts/*',
    ];

    protected array $disabledExactPaths = [
        // Finance Approval: approve & publish invoice
    ];

    protected function isFinanceApprovalPath(string $path, string $method): bool
    {
        $segments = explode('/', trim($path, '/'));
        if (count($segments) >= 3 && $segments[0] === 'api' && $segments[1] === 'invoices') {
            $action = $segments[3] ?? '';
            if (in_array($action, ['approve', 'publish'], true)) {
                return true;
            }
        }
        return false;
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!env('DEMO_MODE', false)) {
            return $next($request);
        }

        $currentPath = trim($request->path(), '/');

        foreach ($this->disabledPathPatterns as $pattern) {
            if ($request->is($pattern)) {
                return $this->demoDisabledResponse($pattern);
            }
        }

        if ($this->isFinanceApprovalPath($currentPath, $request->method())) {
            return $this->demoDisabledResponse('finance_approval');
        }

        return $next($request);
    }

    protected function demoDisabledResponse(string $module): Response
    {
        $moduleLabels = [
            'api/efaktur' => 'e-Faktur',
            'api/efaktur/*' => 'e-Faktur',
            'api/returns' => 'Returns',
            'api/returns/*' => 'Returns',
            'api/debit-notes' => 'Debit Notes',
            'api/debit-notes/*' => 'Debit Notes',
            'api/basts' => 'BAST',
            'api/basts/*' => 'BAST',
            'finance_approval' => 'Finance Approval',
        ];

        $label = $moduleLabels[$module] ?? 'This feature';

        return response()->json([
            'message' => "{$label} dinonaktifkan dalam mode demo.",
            'error' => 'demo_mode_disabled',
            'module' => $module,
            'demo_mode' => true,
        ], 403);
    }
}

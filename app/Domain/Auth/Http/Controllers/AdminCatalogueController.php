<?php

namespace App\Domain\Auth\Http\Controllers;

use App\Domain\Catalogue\Models\Catalogue;
use App\Domain\Company\Models\Company;
use App\Domain\Auth\Models\Admin;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Domain\Catalogue\Actions\CreateCatalogueAction;
use Illuminate\Validation\ValidationException;

use Illuminate\Support\Str;

class AdminCatalogueController extends Controller
{
    /**
     * Get all global catalogue items.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->query('per_page', 10);
        $search = $request->query('search', '');

        $query = Catalogue::with('company')->orderBy('created_at', 'desc');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('item_code', 'like', "%{$search}%")
                  ->orWhereHas('company', function ($c) use ($search) {
                      $c->where('name', 'like', "%{$search}%");
                  });
            });
        }

        return response()->json($query->paginate($perPage));
    }

    /**
     * Store a new catalogue item on behalf of a vendor.
     */
    public function store(Request $request, CreateCatalogueAction $action): JsonResponse
    {
        $data = $request->validate([
            'company_id' => 'nullable|uuid|exists:companies,id',
            'item_code' => 'nullable|string|max:255',
            'name' => 'required|string|max:255',
            'category' => 'nullable|string|max:255',
            'specifications' => 'nullable|string',
            'uom' => 'required|string|max:50',
            'price' => 'nullable|numeric|min:0',
            'image' => 'nullable|image|max:2048',
        ]);

        if (empty($data['item_code'])) {
            $data['item_code'] = 'GLB-' . strtoupper(Str::random(8));
        }
        
        if (!isset($data['price'])) {
            $data['price'] = 0;
        }

        // Create a dummy admin user instance just to pass to the action to bypass Vendor check
        $admin = new Admin();
        $admin->id = 1; // dummy

        try {
            $item = $action->execute($admin, $data);
            return response()->json([
                'message' => 'Produk berhasil ditambahkan ke katalog vendor.',
                'data'    => $item
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
    }
}

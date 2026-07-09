<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

/**
 * Debug routes for investigating cart vs PR item count issue
 * Temporary debugging routes - remove in production
 */

Route::prefix('debug')->group(function () {
    
    // Test PHP input limits
    Route::post('/test-input-vars', function (Request $request) {
        $allData = $request->all();
        $itemsData = $request->input('items', []);
        
        return response()->json([
            'php_max_input_vars' => ini_get('max_input_vars'),
            'total_request_keys' => count($allData, COUNT_RECURSIVE),
            'items_received' => count($itemsData),
            'all_keys' => array_keys($allData),
            'items_keys' => array_keys($itemsData),
            'sample_items' => array_slice($itemsData, 0, 3),
        ]);
    });
    
    // Test FormData parsing
    Route::post('/test-formdata', function (Request $request) {
        $items = [];
        $rawInput = $request->all();
        
        // Extract items from FormData format
        foreach ($rawInput as $key => $value) {
            if (strpos($key, 'items[') === 0) {
                preg_match('/items\[(\d+)\]\[(\w+)\]/', $key, $matches);
                if (count($matches) === 3) {
                    $index = $matches[1];
                    $field = $matches[2];
                    $items[$index][$field] = $value;
                }
            }
        }
        
        return response()->json([
            'raw_keys_count' => count($rawInput),
            'extracted_items_count' => count($items),
            'extracted_items' => $items,
            'raw_input_sample' => array_slice($rawInput, 0, 10, true),
        ]);
    });
    
});
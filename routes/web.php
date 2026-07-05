<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Document download routes - require authentication
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/documents/rfq/{rfqId}', [App\Http\Controllers\DocumentController::class, 'downloadRfqDocument']);
    Route::get('/documents/company/{documentId}', [App\Http\Controllers\DocumentController::class, 'downloadCompanyDocument']);
    Route::get('/assets/url', [App\Http\Controllers\DocumentController::class, 'getAssetUrl']);
});

// Dynamic SEO & Sitemap fallbacks
Route::get('/robots.txt', function() {
    $frontendUrl = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'https://app.huntr.id')), '/');
    $content = "User-agent: *\nAllow: /\nDisallow: /api/auth/\nDisallow: /api/admin/\nDisallow: /admin/\n\nSitemap: {$frontendUrl}/sitemap.xml";
    return response($content, 200)->header('Content-Type', 'text/plain');
});

Route::get('/llms.txt', function() {
    if (file_exists(public_path('llms.txt'))) {
        return response(file_get_contents(public_path('llms.txt')), 200)->header('Content-Type', 'text/plain');
    }
    $frontendUrl = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'https://app.huntr.id')), '/');
    $catalogues = \App\Domain\Catalogue\Models\Catalogue::with('company')->get();
    
    $txt = "# Huntr Catalogue\n\n> Huntr is a B2B Procurement and Marketplace Platform. Discover products, vendors, and raise RFQs seamlessly.\n\n## Main Directory\n\n- [Home Page]({$frontendUrl}/)\n- [Catalogue Index]({$frontendUrl}/catalogues)\n\n## Products and Services\n\n";
    foreach ($catalogues as $item) {
        $brandStr = $item->brand ? " Brand: {$item->brand}." : '';
        $categoryStr = $item->category ? " Category: {$item->category}." : '';
        $vendorStr = ($item->company && $item->company->name) ? " Offered by: {$item->company->name}." : '';
        $specs = $item->specifications ? " Specs: {$item->specifications}." : '';
        $txt .= "- [{$item->name}]({$frontendUrl}/catalogues/{$item->id}) -{$brandStr}{$categoryStr}{$vendorStr}{$specs}\n";
    }
    return response($txt, 200)->header('Content-Type', 'text/plain');
});

Route::get('/sitemap.xml', function() {
    if (file_exists(public_path('sitemap.xml'))) {
        return response(file_get_contents(public_path('sitemap.xml')), 200)->header('Content-Type', 'application/xml');
    }
    $frontendUrl = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'https://app.huntr.id')), '/');
    $now = now()->toAtomString();
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<sitemapindex xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n    <sitemap>\n        <loc>{$frontendUrl}/sitemap-static.xml</loc>\n        <lastmod>{$now}</lastmod>\n    </sitemap>\n    <sitemap>\n        <loc>{$frontendUrl}/sitemap-products.xml</loc>\n        <lastmod>{$now}</lastmod>\n    </sitemap>\n</sitemapindex>";
    return response($xml, 200)->header('Content-Type', 'application/xml');
});

Route::get('/sitemap-static.xml', function() {
    if (file_exists(public_path('sitemap-static.xml'))) {
        return response(file_get_contents(public_path('sitemap-static.xml')), 200)->header('Content-Type', 'application/xml');
    }
    $frontendUrl = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'https://app.huntr.id')), '/');
    $now = now()->toAtomString();
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n    <url>\n        <loc>{$frontendUrl}/</loc>\n        <lastmod>{$now}</lastmod>\n        <changefreq>daily</changefreq>\n        <priority>1.0</priority>\n    </url>\n    <url>\n        <loc>{$frontendUrl}/catalogues</loc>\n        <lastmod>{$now}</lastmod>\n        <changefreq>daily</changefreq>\n        <priority>0.8</priority>\n    </url>\n</urlset>";
    return response($xml, 200)->header('Content-Type', 'application/xml');
});

Route::get('/sitemap-products.xml', function() {
    if (file_exists(public_path('sitemap-products.xml'))) {
        return response(file_get_contents(public_path('sitemap-products.xml')), 200)->header('Content-Type', 'application/xml');
    }
    $frontendUrl = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'https://app.huntr.id')), '/');
    $catalogues = \App\Domain\Catalogue\Models\Catalogue::with('company')->get();
    
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    $xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\" xmlns:image=\"http://www.google.com/schemas/sitemap-image/1.1\">\n";

    foreach ($catalogues as $item) {
        $loc = "{$frontendUrl}/catalogues/{$item->id}";
        $lastmod = $item->updated_at ? $item->updated_at->toAtomString() : now()->toAtomString();
        
        $xml .= "    <url>\n";
        $xml .= "        <loc>{$loc}</loc>\n";
        $xml .= "        <lastmod>{$lastmod}</lastmod>\n";
        $xml .= "        <changefreq>weekly</changefreq>\n";
        $xml .= "        <priority>0.7</priority>\n";

        if ($item->image_path) {
            $path = $item->image_path;
            if (filter_var($path, FILTER_VALIDATE_URL)) {
                $imgUrl = $path;
            } else {
                $disk = config('filesystems.default') === 's3' ? 's3' : 'public';
                $imgUrl = \Illuminate\Support\Facades\Storage::disk($disk)->url($path);
            }
            $xml .= "        <image:image>\n";
            $xml .= "            <image:loc>" . htmlspecialchars($imgUrl) . "</image:loc>\n";
            $xml .= "            <image:title>" . htmlspecialchars($item->name) . "</image:title>\n";
            $xml .= "        </image:image>\n";
        }

        $xml .= "    </url>\n";
    }

    $xml .= "</urlset>";
    return response($xml, 200)->header('Content-Type', 'application/xml');
});


<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Domain\Catalogue\Models\Catalogue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;

class GenerateSitemaps extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'seo:generate-sitemaps';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate static XML sitemaps, robots.txt, and llms.txt for Huntr SEO';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Generating SEO artifacts...');

        $frontendUrl = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'https://app.huntr.id')), '/');
        $catalogues = Catalogue::with('company')->get();

        // 1. Generate sitemap-static.xml
        $staticXml = $this->generateStaticSitemap($frontendUrl);
        File::put(public_path('sitemap-static.xml'), $staticXml);
        $this->info('Generated sitemap-static.xml');

        // 2. Generate sitemap-products.xml
        $productsXml = $this->generateProductsSitemap($frontendUrl, $catalogues);
        File::put(public_path('sitemap-products.xml'), $productsXml);
        $this->info('Generated sitemap-products.xml');

        // 3. Generate main sitemap.xml (Sitemap Index)
        $indexXml = $this->generateSitemapIndex($frontendUrl);
        File::put(public_path('sitemap.xml'), $indexXml);
        $this->info('Generated main sitemap.xml (Index)');

        // 4. Generate llms.txt
        $llmsTxt = $this->generateLlmsTxt($frontendUrl, $catalogues);
        File::put(public_path('llms.txt'), $llmsTxt);
        $this->info('Generated llms.txt');

        // 5. Update robots.txt
        $robotsTxt = $this->generateRobotsTxt($frontendUrl);
        File::put(public_path('robots.txt'), $robotsTxt);
        $this->info('Updated robots.txt');

        $this->info('All SEO artifacts generated successfully!');
    }

    private function generateStaticSitemap(string $frontendUrl): string
    {
        $now = now()->toAtomString();
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url>
        <loc>{$frontendUrl}/</loc>
        <lastmod>{$now}</lastmod>
        <changefreq>daily</changefreq>
        <priority>1.0</priority>
    </url>
    <url>
        <loc>{$frontendUrl}/catalogues</loc>
        <lastmod>{$now}</lastmod>
        <changefreq>daily</changefreq>
        <priority>0.8</priority>
    </url>
</urlset>
XML;
    }

    private function generateProductsSitemap(string $frontendUrl, $catalogues): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . PHP_EOL;

        foreach ($catalogues as $item) {
            $loc = "{$frontendUrl}/catalogues/{$item->id}";
            $lastmod = $item->updated_at ? $item->updated_at->toAtomString() : now()->toAtomString();
            
            $xml .= '    <url>' . PHP_EOL;
            $xml .= "        <loc>{$loc}</loc>" . PHP_EOL;
            $xml .= "        <lastmod>{$lastmod}</lastmod>" . PHP_EOL;
            $xml .= '        <changefreq>weekly</changefreq>' . PHP_EOL;
            $xml .= '        <priority>0.7</priority>' . PHP_EOL;

            if ($item->image_path) {
                $imgUrl = $this->getImageUrl($item->image_path);
                $xml .= '        <image:image>' . PHP_EOL;
                $xml .= "            <image:loc>" . htmlspecialchars($imgUrl) . "</image:loc>" . PHP_EOL;
                $xml .= "            <image:title>" . htmlspecialchars($item->name) . "</image:title>" . PHP_EOL;
                $xml .= '        </image:image>' . PHP_EOL;
            }

            $xml .= '    </url>' . PHP_EOL;
        }

        $xml .= '</urlset>';
        return $xml;
    }

    private function generateSitemapIndex(string $frontendUrl): string
    {
        $now = now()->toAtomString();
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <sitemap>
        <loc>{$frontendUrl}/sitemap-static.xml</loc>
        <lastmod>{$now}</lastmod>
    </sitemap>
    <sitemap>
        <loc>{$frontendUrl}/sitemap-products.xml</loc>
        <lastmod>{$now}</lastmod>
    </sitemap>
</sitemapindex>
XML;
    }

    private function generateLlmsTxt(string $frontendUrl, $catalogues): string
    {
        $txt = "# Huntr Catalogue" . PHP_EOL . PHP_EOL;
        $txt .= "> Huntr is a B2B Procurement and Marketplace Platform. Discover products, vendors, and raise RFQs seamlessly." . PHP_EOL . PHP_EOL;
        $txt .= "## Main Directory" . PHP_EOL . PHP_EOL;
        $txt .= "- [Home Page]({$frontendUrl}/)" . PHP_EOL;
        $txt .= "- [Catalogue Index]({$frontendUrl}/catalogues)" . PHP_EOL . PHP_EOL;

        $txt .= "## Products and Services" . PHP_EOL . PHP_EOL;
        foreach ($catalogues as $item) {
            $brandStr = $item->brand ? " Brand: {$item->brand}." : '';
            $categoryStr = $item->category ? " Category: {$item->category}." : '';
            $vendorStr = ($item->company && $item->company->name) ? " Offered by: {$item->company->name}." : '';
            $specs = $item->specifications ? " Specs: {$item->specifications}." : '';
            
            $txt .= "- [{$item->name}]({$frontendUrl}/catalogues/{$item->id}) -{$brandStr}{$categoryStr}{$vendorStr}{$specs}" . PHP_EOL;
        }

        return $txt;
    }

    private function generateRobotsTxt(string $frontendUrl): string
    {
        return <<<TXT
User-agent: *
Allow: /

# Disallow API and Admin areas
Disallow: /api/auth/
Disallow: /api/admin/
Disallow: /admin/

Sitemap: {$frontendUrl}/sitemap.xml
TXT;
    }

    private function getImageUrl(string $path): string
    {
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        return Storage::disk(config('filesystems.default'))->url($path);
    }
}

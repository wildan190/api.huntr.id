<?php

namespace App\Domain\EFaktur\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PajakExpressService (EFaktur domain)
 *
 * Extends the Company domain's PajakExpressService which handles the full
 * 3-step auth flow: POST /auth/login → POST /npwp/log → GET /npwp/log → x-token.
 *
 * All requests use:
 *   Authorization: Bearer {jwt}
 *   x-token: {xToken}
 *
 * VAT Out: IF_TXR_001 (create, list, upload, cancel, delete)
 * VAT In:  IF_TXR_015 (list, prepopulated, upload, verify)
 */
class PajakExpressService extends \App\Domain\Company\Services\PajakExpressService
{
    /* ─────────────────────────────────────────────────────────────── */
    /*  Auth headers helper                                            */
    /* ─────────────────────────────────────────────────────────────── */

    private function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->getJwtToken(),
            'x-token'       => $this->getAuthToken(),
        ];
    }

    /* ─────────────────────────────────────────────────────────────── */
    /*  Generic HTTP helpers with auth + retry on 401                  */
    /* ─────────────────────────────────────────────────────────────── */

    private function request(string $method, string $path, array $data = [], array $query = []): array
    {
        $url = "{$this->baseUrl}/{$path}";

        $attempt = 0;
        while ($attempt < 2) {
            $headers = $this->authHeaders();

            $req = Http::asJson()->acceptJson()->withHeaders($headers)->timeout(30);

            $response = match (strtoupper($method)) {
                'GET'  => $req->get($url, $query),
                'POST' => $req->post($url, $data),
                default => throw new \InvalidArgumentException("Unsupported method: {$method}"),
            };

            Log::info("PajakExpressService {$method} {$path}", [
                'status' => $response->status(),
                'attempt' => $attempt + 1,
            ]);

            // Retry once on auth failure
            if (in_array($response->status(), [401, 403]) && $attempt === 0) {
                Log::warning("PajakExpressService: Auth failure on {$path}, refreshing tokens...");
                $this->invalidateAuthCache();
                $attempt++;
                continue;
            }

            if ($response->successful()) {
                return $response->json() ?? [];
            }

            $body = $response->json() ?? [];
            $msg  = $body['message'] ?? $body['error'] ?? "HTTP {$response->status()}";
            throw new \RuntimeException("PajakExpress {$method} {$path} failed: {$msg}");
        }

        throw new \RuntimeException("PajakExpress {$method} {$path}: auth retry exhausted.");
    }

    /* ─────────────────────────────────────────────────────────────── */
    /*  VAT OUT — Faktur Pajak Keluaran (IF_TXR_001)                  */
    /* ─────────────────────────────────────────────────────────────── */

    /** Buat / edit draft faktur keluaran. POST /IF_TXR_001/create */
    public function createVatOut(array $payload): array
    {
        Log::info('PajakExpressService: createVatOut', ['referensi' => $payload['referensi'] ?? null]);
        return $this->request('POST', 'IF_TXR_001/create', $payload);
    }

    /** List faktur keluaran. GET /IF_TXR_001 */
    public function listVatOut(int $page = 1, int $limit = 20, array $filters = []): array
    {
        $query = array_merge(['limit' => $limit, 'page' => $page], $filters);
        return $this->request('GET', 'IF_TXR_001', [], $query);
    }

    /**
     * Upload draft ke DJP untuk approval.
     * POST /IF_TXR_001/upload
     */
    public function uploadVatOut(int $id, string $tempatPenandatangan, string $npwpNikPenandatangan): array
    {
        Log::info('PajakExpressService: uploadVatOut', ['id' => $id]);
        return $this->request('POST', 'IF_TXR_001/upload', [
            'id'                   => $id,
            'tempatPenandatangan'  => $tempatPenandatangan,
            'npwpNikPenandatangan' => $npwpNikPenandatangan,
        ]);
    }

    /** Cancel faktur APPROVED. POST /IF_TXR_006 */
    public function cancelVatOut(string $kdJenisTransaksi, string $nomorFaktur, string $npwpPenjual): array
    {
        Log::info('PajakExpressService: cancelVatOut', ['nomorFaktur' => $nomorFaktur]);
        return $this->request('POST', 'IF_TXR_006', [
            'kdJenisTransaksi' => $kdJenisTransaksi,
            'nomorFaktur'      => $nomorFaktur,
            'npwpPenjual'      => $npwpPenjual,
            'revokeFlag'       => false,
        ]);
    }

    /** Hapus draft. POST /IF_TXR_001/delete */
    public function deleteVatOut(string|int $id): array
    {
        Log::info('PajakExpressService: deleteVatOut', ['id' => $id]);
        return $this->request('POST', 'IF_TXR_001/delete', ['id' => (string) $id]);
    }

    /* ─────────────────────────────────────────────────────────────── */
    /*  VAT IN — Faktur Pajak Masukan (IF_TXR_015)                    */
    /* ─────────────────────────────────────────────────────────────── */

    /** Inquiry prepopulated faktur masukan dari DJP. POST /IF_TXR_015/prepopulated */
    public function prepopulatedVatIn(
        string $tahunPajak,
        string $masaPajak,
        string $npwpPenjual = '',
        string $nomorFaktur = ''
    ): array {
        return $this->request('POST', 'IF_TXR_015/prepopulated', [
            'fgPermintaan'         => 1,
            'requestFakturMasukan' => [
                'prepopTahunPajak'   => $tahunPajak,
                'prepopMasaPajak'    => $masaPajak,
                'prepopNpwpPenjual'  => $npwpPenjual,
                'prepopNomorFaktur'  => $nomorFaktur,
            ],
            'NpwpPembeli'          => $this->npwp,
            'userId'               => '',
            'kanal'                => '14',
        ]);
    }

    /** List faktur masukan. GET /IF_TXR_015 */
    public function listVatIn(int $page = 1, int $limit = 20, string $periode = ''): array
    {
        $query = ['page' => $page, 'limit' => $limit];
        if ($periode) {
            $query['periode'] = $periode;
        }
        return $this->request('GET', 'IF_TXR_015', [], $query);
    }

    /** Konfirmasi pengkreditan faktur masukan. POST /IF_TXR_015/upload */
    public function uploadVatIn(
        string $nomorFaktur,
        string $masaPajak,
        string $tahunPajak,
        int    $konfirmasiPengkreditan = 1
    ): array {
        return $this->request('POST', 'IF_TXR_015/upload', [
            'fgPermintaan'            => 2,
            'npwpPembeli'             => $this->npwp,
            'konfirmasiFakturMasukan' => [
                'konfirmasiPengkreditan' => $konfirmasiPengkreditan,
                'nomorFaktur'            => $nomorFaktur,
                'masaPajak'              => $masaPajak,
                'tahunPajak'             => $tahunPajak,
            ],
            'userId'                  => '',
            'kanal'                   => '14',
        ]);
    }

    /** Verifikasi faktur masukan. POST /IF_TXR_015/verify */
    public function verifyVatIn(
        string $tahunPajak,
        string $masaPajak,
        string $npwpPenjual = '',
        string $nomorFaktur = ''
    ): array {
        return $this->request('POST', 'IF_TXR_015/verify', [
            'fgPermintaan'         => 1,
            'requestFakturMasukan' => [
                'prepopTahunPajak'   => $tahunPajak,
                'prepopMasaPajak'    => $masaPajak,
                'prepopNpwpPenjual'  => $npwpPenjual,
                'prepopNomorFaktur'  => $nomorFaktur,
            ],
            'NpwpPembeli'          => $this->npwp,
            'userId'               => '',
            'kanal'                => '14',
        ]);
    }

    /* ─────────────────────────────────────────────────────────────── */
    /*  Reference Data                                                  */
    /* ─────────────────────────────────────────────────────────────── */

    /**
     * Ambil kode barang (mst_goods) atau jasa (mst_services) dari PajakExpress.
     * Hasil di-cache selama 24 jam.
     */
    public function getReference(string $type = 'goods'): array
    {
        $endpoint = $type === 'services' ? 'mst_services' : 'mst_goods';
        $cacheKey = "pajakexpress_ref_{$type}";

        if (\Illuminate\Support\Facades\Cache::has($cacheKey)) {
            return \Illuminate\Support\Facades\Cache::get($cacheKey);
        }

        $res  = $this->request('GET', $endpoint, [], ['limit' => 9999]);
        $data = $res['data'] ?? [];

        \Illuminate\Support\Facades\Cache::put($cacheKey, $data, now()->addHours(24));

        return $data;
    }

    /**
     * Ambil kode satuan (mst_satuan) dari PajakExpress. Cache 24 jam.
     */
    public function getSatuanReference(): array
    {
        $cacheKey = 'pajakexpress_ref_satuan';

        if (\Illuminate\Support\Facades\Cache::has($cacheKey)) {
            return \Illuminate\Support\Facades\Cache::get($cacheKey);
        }

        $res  = $this->request('GET', 'mst_satuan', [], ['page' => 1, 'limit' => 1000]);
        $data = $res['data'] ?? [];

        \Illuminate\Support\Facades\Cache::put($cacheKey, $data, now()->addHours(24));

        return $data;
    }

    /* ─────────────────────────────────────────────────────────────── */
    /*  PDF Download                                                    */
    /* ─────────────────────────────────────────────────────────────── */

    /**
     * Download PDF faktur menggunakan approvalSign URL dari DJP.
     * Endpoint: POST https://restdev.pajakexpress.com:9922/report/ctas/cetak
     * Mengembalikan base64 arraybuff PDF.
     *
     * @param  string $approvalSignUrl  URL dari field approvalSign / approvalsign
     * @return array  ['KdStatus'=>'1', 'data'=>['arraybuff'=>'base64...']]
     */
    public function downloadPdf(string $approvalSignUrl): array
    {
        $pdfBaseUrl = config(
            'services.pajak_express.pdf_url',
            'https://restdev.pajakexpress.com:9922'
        );
        $url = rtrim($pdfBaseUrl, '/') . '/report/ctas/cetak';

        \Illuminate\Support\Facades\Log::info('PajakExpressService: downloadPdf', [
            'url'             => $url,
            'approvalSignUrl' => $approvalSignUrl,
        ]);

        try {
            // PDF endpoint mungkin membutuhkan autentikasi yang sama
            $headers = [
                'Authorization' => 'Bearer ' . $this->getJwtToken(),
                'x-token'       => $this->getAuthToken(),
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ];

            $response = \Illuminate\Support\Facades\Http::withHeaders($headers)
                ->timeout(60)
                ->post($url, ['url' => $approvalSignUrl]);

            if ($response->successful()) {
                return $response->json() ?? [];
            }

            // Fallback: coba tanpa auth (mungkin public endpoint)
            $response = \Illuminate\Support\Facades\Http::timeout(60)
                ->post($url, ['url' => $approvalSignUrl]);

            if ($response->successful()) {
                return $response->json() ?? [];
            }

            $body = $response->json() ?? [];
            throw new \RuntimeException('PDF download failed: ' . ($body['message'] ?? "HTTP {$response->status()}"));

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            \Illuminate\Support\Facades\Log::error('PajakExpressService: PDF download connection failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Tidak dapat terhubung ke server PDF PajakExpress: ' . $e->getMessage());
        }
    }

    /* ─────────────────────────────────────────────────────────────── */
    /*  Verify VAT                                                      */
    /* ─────────────────────────────────────────────────────────────── */

    /**
     * Verifikasi faktur pajak.
     * POST /IF_TXR_063
     *
     * @param  string $nomorFaktur   Nomor faktur (boleh dengan prefix "INV#")
     * @param  string $npwpPenjual
     * @param  string $npwpPembeli
     * @param  string $userId        NPWP/NIK user yang melakukan verifikasi
     */
    public function verifyVat(
        string $nomorFaktur,
        string $npwpPenjual,
        string $npwpPembeli,
        string $userId = ''
    ): array {
        // PajakExpress IF_TXR_063 membutuhkan prefix "INV#" pada nomorFaktur
        $nomorWithPrefix = str_starts_with($nomorFaktur, 'INV#')
            ? $nomorFaktur
            : 'INV#' . $nomorFaktur;

        \Illuminate\Support\Facades\Log::info('PajakExpressService: verifyVat', [
            'nomorFaktur' => $nomorWithPrefix,
        ]);

        return $this->request('POST', 'IF_TXR_063', [
            'nomorFaktur' => $nomorWithPrefix,
            'npwpPenjual' => $npwpPenjual,
            'npwpPembeli' => $npwpPembeli,
            'userId'      => $userId ?: $this->npwp,
        ]);
    }

    /**
     * Verifikasi prepopulated data faktur.
     * POST /IF_TXR_063/prepop
     */
    public function verifyPrepopulated(
        string $nomorFaktur,
        string $npwpPenjual,
        string $npwpPembeli,
        string $userId  = '',
        string $idKanal = '14'
    ): array {
        $nomorWithPrefix = str_starts_with($nomorFaktur, 'INV#')
            ? $nomorFaktur
            : 'INV#' . $nomorFaktur;

        return $this->request('POST', 'IF_TXR_063/prepop', [
            'nomorFaktur' => $nomorWithPrefix,
            'npwpPenjual' => $npwpPenjual,
            'npwpPembeli' => $npwpPembeli,
            'userId'      => $userId ?: $this->npwp,
            'IdKanal'     => $idKanal,
        ]);
    }
}

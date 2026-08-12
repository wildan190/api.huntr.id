<?php

namespace App\Domain\Company\Actions;

use App\Domain\Company\Services\PajakExpressService;

class VerifyNpwpAction
{
    protected $pajakExpress;

    public function __construct(PajakExpressService $pajakExpress)
    {
        $this->pajakExpress = $pajakExpress;
    }

    /**
     * Verify NPWP using PajakExpress service.
     * Only verify if country is Indonesia.
     *
     * @param string $npwp
     * @param string|null $country
     * @return array
     */
    public function execute(string $npwp, ?string $country = 'ID'): array
    {
        if (!$this->isIndonesia($country)) {
            return [
                'valid' => false,
                'message' => 'NPWP verification is only available for Indonesia.',
            ];
        }

        return $this->pajakExpress->verifyNpwp($npwp);
    }

    /**
     * Check if the country is Indonesia.
     *
     * @param string|null $country
     * @return bool
     */
    private function isIndonesia(?string $country): bool
    {
        if (!$country) {
            return false;
        }

        $countryCode = strtoupper(trim($country));
        return in_array($countryCode, ['ID', 'INDONESIA']);
    }
}

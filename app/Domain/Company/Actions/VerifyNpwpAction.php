<?php

namespace App\Domain\Company\Actions;

use App\Domain\Company\Services\PajakIoService;

class VerifyNpwpAction
{
    protected $pajakIo;

    public function __construct(PajakIoService $pajakIo)
    {
        $this->pajakIo = $pajakIo;
    }

    /**
     * Verify NPWP using PajakIo service.
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

        return $this->pajakIo->verifyNpwp($npwp);
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

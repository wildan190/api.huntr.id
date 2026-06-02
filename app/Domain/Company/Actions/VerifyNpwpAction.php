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
     *
     * @param string $npwp
     * @return array
     */
    public function execute(string $npwp): array
    {
        return $this->pajakIo->verifyNpwp($npwp);
    }
}

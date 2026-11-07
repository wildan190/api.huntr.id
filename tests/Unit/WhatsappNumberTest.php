<?php

namespace Tests\Unit;

use App\Support\WhatsappNumber;
use PHPUnit\Framework\TestCase;

class WhatsappNumberTest extends TestCase
{
    public function test_normalize_local_number_with_leading_zero(): void
    {
        $this->assertSame('6285156334793', WhatsappNumber::normalize('085156334793'));
    }

    public function test_normalize_international_number(): void
    {
        $this->assertSame('6285156334793', WhatsappNumber::normalize('6285156334793'));
    }

    public function test_normalize_formatted_number(): void
    {
        $this->assertSame('6285156334793', WhatsappNumber::normalize('+62 851-563-347-93'));
    }

    public function test_normalize_number_without_country_or_leading_zero(): void
    {
        $this->assertSame('6285156334793', WhatsappNumber::normalize('85156334793'));
    }

    public function test_normalize_common_typo_missing_leading_zero(): void
    {
        $this->assertSame('6285156334793', WhatsappNumber::normalize('685156334793'));
    }

    public function test_normalize_malaysian_numbers(): void
    {
        // Malaysian international format: +60 12-345 6789
        $this->assertSame('60123456789', WhatsappNumber::normalize('+60 12-345 6789'));
        $this->assertSame('60123456789', WhatsappNumber::normalize('60123456789'));
        // Malaysian 11-digit mobile: +60 11-1234 5678
        $this->assertSame('601112345678', WhatsappNumber::normalize('+60 11-1234 5678'));
        // Malaysian local prefix: 012-345 6789 -> converts to 60123456789
        $this->assertSame('60123456789', WhatsappNumber::normalize('0123456789'));
        $this->assertSame('601112345678', WhatsappNumber::normalize('01112345678'));
    }

    public function test_is_valid_accepts_indonesian_and_malaysian_numbers(): void
    {
        $this->assertFalse(WhatsappNumber::isValid('685156334793'));
        $this->assertFalse(WhatsappNumber::isValid('12345'));
        // Indonesian numbers
        $this->assertTrue(WhatsappNumber::isValid('6285156334793'));
        $this->assertTrue(WhatsappNumber::isValid('6281234567890'));
        // Malaysian numbers
        $this->assertTrue(WhatsappNumber::isValid('60123456789'));
        $this->assertTrue(WhatsappNumber::isValid('601112345678'));
    }

    public function test_fonnte_target_uses_full_international_number(): void
    {
        $this->assertSame('6285156334793', WhatsappNumber::fonnteTarget('6285156334793'));
        $this->assertSame('60123456789', WhatsappNumber::fonnteTarget('60123456789'));
    }
}

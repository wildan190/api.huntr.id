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

    public function test_is_valid_rejects_invalid_numbers(): void
    {
        $this->assertFalse(WhatsappNumber::isValid('685156334793'));
        $this->assertTrue(WhatsappNumber::isValid('6285156334793'));
    }

    public function test_fonnte_target_uses_full_international_number(): void
    {
        $this->assertSame('6285156334793', WhatsappNumber::fonnteTarget('6285156334793'));
    }
}

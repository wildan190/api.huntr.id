<?php

namespace Tests\Unit;

use App\Domain\AI\Services\HuntrAssistToolGateway;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HuntrAssistToolGatewayTest extends TestCase
{
    #[Test]
    public function it_exposes_only_the_read_only_workspace_tools(): void
    {
        $tools = new HuntrAssistToolGateway;

        $definitions = $tools->definitions();

        $this->assertSame(['list_purchase_orders', 'list_invoices'], array_column(array_column($definitions, 'function'), 'name'));
        $this->assertTrue($definitions[0]['function']['strict']);
        $this->assertTrue($definitions[1]['function']['strict']);
        $this->assertSame(['period', 'status', 'limit'], $definitions[0]['function']['parameters']['required']);
        $this->assertSame(['string', 'null'], $definitions[1]['function']['parameters']['properties']['status']['type']);
        $this->assertTrue($tools->supports('Tampilkan PO terbaru saya'));
        $this->assertTrue($tools->supports('Ada faktur bulan ini?'));
        $this->assertFalse($tools->supports('Bagaimana membuat purchase request?'));
    }
}

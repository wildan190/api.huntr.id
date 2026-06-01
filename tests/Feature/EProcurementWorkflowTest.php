<?php

namespace Tests\Feature;

use App\Domain\Auth\Actions\RegisterUserAction;
use App\Domain\Company\Actions\RegisterCompanyAction;
use App\Domain\Company\Actions\AuditCompanyAction;
use App\Domain\Catalogue\Actions\ImportHistoricalDataAction;
use App\Domain\Rfq\Actions\CreateRfqAction;
use App\Domain\Rfq\Actions\ApproveRfqAction;
use App\Domain\Proposal\Actions\SubmitProposalAction;
use App\Domain\Proposal\Actions\CalculateSawRankingAction;
use App\Domain\Order\Actions\AwardVendorAction;
use App\Domain\Order\Actions\ConfirmPurchaseOrderAction;
use App\Domain\Order\Actions\ProcessPoPaymentAction;
use App\Domain\Order\Actions\ReleaseDeliveryOrderAction;
use App\Domain\Receipt\Actions\ConfirmDeliveryOrderAction;
use App\Domain\Receipt\Actions\CreateGoodsReceiptAction;
use App\Domain\Receipt\Actions\ApproveFinalInvoiceAction;

use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\Company;
use App\Domain\Catalogue\Models\Catalogue;
use App\Domain\Rfq\Models\Rfq;
use App\Domain\Proposal\Models\Proposal;
use App\Domain\Order\Models\PurchaseOrder;
use App\Domain\Order\Models\Invoice;
use App\Domain\Order\Models\DeliveryOrder;
use App\Domain\Receipt\Models\GoodsReceipt;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class EProcurementWorkflowTest extends TestCase
{
    use RefreshDatabase;
    /**
     * Test the complete sequential B2B Procurement workflow.
     */
    public function test_complete_b2b_procurement_workflow(): void
    {
        // --- 1. User & Company Registration ---
        $registerUserAction = app(RegisterUserAction::class);
        $registerCompanyAction = app(RegisterCompanyAction::class);
        $auditCompanyAction = app(AuditCompanyAction::class);
        $importAction = app(ImportHistoricalDataAction::class);
        $createRfqAction = app(CreateRfqAction::class);
        $approveRfqAction = app(ApproveRfqAction::class);
        $submitProposalAction = app(SubmitProposalAction::class);
        $sawRankingAction = app(CalculateSawRankingAction::class);
        $awardVendorAction = app(AwardVendorAction::class);
        $confirmPoAction = app(ConfirmPurchaseOrderAction::class);
        $paymentAction = app(ProcessPoPaymentAction::class);
        $releaseDoAction = app(ReleaseDeliveryOrderAction::class);
        $confirmDoAction = app(ConfirmDeliveryOrderAction::class);
        $createReceiptAction = app(CreateGoodsReceiptAction::class);
        $approveFinalInvoiceAction = app(ApproveFinalInvoiceAction::class);

        $admin = $registerUserAction->execute([
            'name' => 'System Admin',
            'email' => 'admin@huntr.id',
            'password' => 'adminpassword',
            'role' => 'admin'
        ]);

        $buyerUser = $registerUserAction->execute([
            'name' => 'Buyer Officer',
            'email' => 'buyer@huntr.id',
            'password' => 'buyerpassword',
            'role' => 'buyer'
        ]);

        $managerUser = $registerUserAction->execute([
            'name' => 'Buyer Manager',
            'email' => 'manager@huntr.id',
            'password' => 'managerpassword',
            'role' => 'manager'
        ]);

        $financeUser = $registerUserAction->execute([
            'name' => 'Finance Officer',
            'email' => 'finance@huntr.id',
            'password' => 'financepassword',
            'role' => 'finance'
        ]);

        $vendorUser1 = $registerUserAction->execute([
            'name' => 'Vendor Officer 1',
            'email' => 'vendor1@cannonex.id',
            'password' => 'vendorpassword',
            'role' => 'vendor'
        ]);

        $vendorUser2 = $registerUserAction->execute([
            'name' => 'Vendor Officer 2',
            'email' => 'vendor2@cannonex.id',
            'password' => 'vendorpassword',
            'role' => 'vendor'
        ]);

        $buyerCompany = $registerCompanyAction->execute($buyerUser, [
            'name' => 'PT. Procurement Jaya',
            'type' => 'buyer'
        ]);
        $managerUser->update(['company_id' => $buyerCompany->id]);
        $financeUser->update(['company_id' => $buyerCompany->id]);

        $vendorCompany1 = $registerCompanyAction->execute($vendorUser1, [
            'name' => 'PT. CANNONEX INDONESIA',
            'type' => 'vendor'
        ]);

        $vendorCompany2 = $registerCompanyAction->execute($vendorUser2, [
            'name' => 'PT. SUPPLIER UTAMA',
            'type' => 'vendor'
        ]);

        // --- 2. Admin Onboarding Audit ---
        $auditCompanyAction->execute($admin, $buyerCompany, 'approve', 'Valid Buyer Entity');
        $auditCompanyAction->execute($admin, $vendorCompany1, 'approve', 'Trusted Vendor Partners');
        $auditCompanyAction->execute($admin, $vendorCompany2, 'approve', 'Verified Supplier');

        $this->assertEquals('approved', $buyerCompany->fresh()->status);
        $this->assertEquals('approved', $vendorCompany1->fresh()->status);
        $this->assertEquals('approved', $vendorCompany2->fresh()->status);

        // --- 3. CSV Historical Inventory Import ---
        $csvContent = "SN,Select,PR Refference Number,Purchase Type,Order No,Purchase Category,Purchase Contract No,Purchase Contract_head,Date,Month,Vendor,Department,Clerk,Currency,Exchange rate,Inventory Code,Category,Inventory name,Specifications,Primary UOM,Qty,Orgi Curr Unit Price,Unit price in original currency,Amount in original currency,Tax amount in original currency,Original Currency Total Amount,Expected receiving date,Created By,Approved by\n" .
                      "1,,PR/SER24010002,Original purchase,PO/EXS24010006,Service-EX SP,,,1/2/24,1,PT. CANNONEX INDONESIA,Sales Sparepart,Dini Nuraeni,IDR,1,SS000002,,Spareparts Freight Cost,,Pc,1,18000000,18000000,18000000,198000,18198000,1/2/24,Dini Nuraeni,Du Xin\n" .
                      "2,,PR/SER24010003,Original purchase,PO/EXS24010006,Service-EX SP,,,1/2/24,1,PT. CANNONEX INDONESIA,Sales Sparepart,Dini Nuraeni,IDR,1,SS000006,,Insurance,,Pc,1,217577,217577,217577,0,217577,1/2/24,Dini Nuraeni,Du Xin";
        $tempCsvPath = tempnam(sys_get_temp_dir(), 'historical_import_') . '.csv';
        file_put_contents($tempCsvPath, $csvContent);

        // Import vendor catalogue
        $importCatalogueAction = app(ImportHistoricalDataAction::class);
        $catalogueCount = $importCatalogueAction->execute($vendorCompany1, $tempCsvPath);
        $this->assertEquals(2, $catalogueCount);
        $this->assertDatabaseHas('catalogues', ['item_code' => 'SS000002']);

        // Import buyer historical POs
        $importPoAction = app(\App\Domain\Order\Actions\ImportHistoricalPoAction::class);
        $poCount = $importPoAction->execute($buyerCompany, $tempCsvPath);
        $this->assertEquals(2, $poCount);
        $this->assertDatabaseHas('purchase_orders', ['po_number' => 'PO/EXS24010006']);
        $this->assertDatabaseHas('historical_po_items', ['inventory_code' => 'SS000002']);

        unlink($tempCsvPath);

        // --- 4. RFQ Checkout & Manager PO Approval ---
        $item1 = Catalogue::where('item_code', 'SS000002')->first();

        $createRfqAction = app(CreateRfqAction::class);
        $rfq = $createRfqAction->execute($buyerCompany, 'RFQ Spareparts Freight Cost', 'Need shipping service urgently', [
            ['catalogue_id' => $item1->id, 'qty' => 5, 'expected_date' => '2026-06-01']
        ]);
        $this->assertEquals('pending_approval', $rfq->status);
        $this->assertCount(1, $rfq->items);

        $approveRfqAction = app(ApproveRfqAction::class);
        $rfq = $approveRfqAction->execute($managerUser, $rfq);
        $this->assertEquals('active', $rfq->status);

        // --- 5. Submit Vendor Proposals ---
        $submitProposalAction = app(SubmitProposalAction::class);
        $proposal1 = $submitProposalAction->execute($vendorCompany1, $rfq, [
            'price_offer' => 15000000,
            'delivery_days' => 5,
            'warranty_months' => 24
        ]);
        $proposal2 = $submitProposalAction->execute($vendorCompany2, $rfq, [
            'price_offer' => 12000000,
            'delivery_days' => 8,
            'warranty_months' => 12
        ]);

        // --- 6. SAW Scoring & Ranking System ---
        $sawRankingAction = app(CalculateSawRankingAction::class);
        $rankings = $sawRankingAction->execute($rfq);
        $this->assertCount(2, $rankings);
        $this->assertEquals($proposal1->id, $rankings[0]['proposal']->id);
        $this->assertGreaterThan($rankings[1]['score'], $rankings[0]['score']);

        // --- 7. Award winning Vendor & Generate PO ---
        $awardVendorAction = app(AwardVendorAction::class);
        $po = $awardVendorAction->execute($managerUser, $rfq, $proposal1);
        $this->assertEquals('awarded', $rfq->fresh()->status);
        $this->assertEquals('accepted', $proposal1->fresh()->status);
        $this->assertEquals('rejected', $proposal2->fresh()->status);
        $this->assertEquals('pending_manager', $po->status);
        $this->assertNotNull($po->po_number);

        // --- 8. Vendor PO Confirmation & Proforma Invoice ---
        $confirmPoAction = app(ConfirmPurchaseOrderAction::class);
        $po = $confirmPoAction->execute($vendorCompany1, $po);
        $this->assertEquals('confirmed', $po->status);
        $this->assertDatabaseHas('invoices', [
            'purchase_order_id' => $po->id,
            'type' => 'proforma',
            'amount' => 15000000,
            'status' => 'unpaid'
        ]);

        // --- 9. Process PO Payment (Midtrans integration) ---
        $paymentAction = app(ProcessPoPaymentAction::class);
        $paymentResult = $paymentAction->execute($po, 'bank_transfer');
        $this->assertEquals('paid', $po->fresh()->status);
        $this->assertEquals('paid', $po->invoices()->where('type', 'proforma')->first()->status);

        // --- 10. Release Delivery Order (Shipping) ---
        $releaseDoAction = app(ReleaseDeliveryOrderAction::class);
        $do = $releaseDoAction->execute($vendorCompany1, $po);
        $this->assertEquals('shipping', $po->fresh()->status);
        $this->assertEquals('shipped', $do->status);
        $this->assertNotNull($do->do_number);

        // --- 11. Confirm Delivery Order & Goods Receipt ---
        $confirmDoAction = app(ConfirmDeliveryOrderAction::class);
        $do = $confirmDoAction->execute($buyerCompany, $do);
        $this->assertEquals('delivered', $do->status);

        $createReceiptAction = app(CreateGoodsReceiptAction::class);
        $receipt = $createReceiptAction->execute($do, [
            'received_qty' => 5,
            'handover_document_path' => 'handover_docs/custom_signature.pdf'
        ]);
        $this->assertEquals('received', $do->fresh()->status);
        $this->assertEquals('completed', $po->fresh()->status);
        $this->assertEquals('completed', $receipt->status);
        $this->assertEquals(5, $receipt->received_qty);

        // 12. Final Invoice Released
        $finalInvoice = Invoice::where('purchase_order_id', $po->id)->where('type', 'final')->first();
        $this->assertNotNull($finalInvoice);
        $this->assertEquals('pending_finance', $finalInvoice->status);

        // --- 13. Finance Approval Payment Schema ---
        $approveFinalInvoiceAction = app(ApproveFinalInvoiceAction::class);
        $finalInvoice = $approveFinalInvoiceAction->execute($financeUser, $finalInvoice);
        $this->assertEquals('paid', $finalInvoice->status);
    }
}

<?php

use Illuminate\Support\Facades\Route;
use App\Domain\Company\Http\Controllers\CompanyController;
use App\Domain\Company\Http\Controllers\CompanyAccessController;
use App\Http\Controllers\DocumentController;

// Public: get invitation info by token (no auth needed — used by register page to pre-fill phone)
Route::get('api/invitations/info', [CompanyController::class, 'invitationInfo'])->middleware(['api', 'cors']);

// Document download routes - require authentication
Route::prefix('api/documents')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('/rfq/{rfqId}', [DocumentController::class, 'downloadRfqDocument']);
    Route::get('/company/{documentId}', [DocumentController::class, 'downloadCompanyDocument']);
    Route::get('/assets/{path}', [DocumentController::class, 'downloadAsset'])->where('path', '.*');
    Route::get('/assets/url', [DocumentController::class, 'getAssetUrl']);
});

Route::prefix('api/companies')->middleware(['api', 'cors', 'auth:api'])->group(function () {
    Route::post('', [CompanyController::class, 'store']);
    Route::post('verify-npwp', [CompanyController::class, 'verifyNpwp']);
    Route::put('{company}', [CompanyController::class, 'update']);
    Route::get('my', [CompanyController::class, 'myCompanies']);
    Route::post('documents/upload', [CompanyController::class, 'uploadDocument']);
    Route::delete('{company}/documents/{document}', [CompanyController::class, 'deleteDocument']);
    Route::post('logo/upload', [CompanyController::class, 'uploadLogo']);
    Route::post('invite', [CompanyController::class, 'invite']);
    Route::post('accept-invitation', [CompanyController::class, 'acceptInvitation']);
    Route::get('{company}/members', [CompanyController::class, 'teamMembers']);
    Route::put('{company}/users/role', [CompanyController::class, 'updateUserRole']);
    Route::get('{company}/access', [CompanyAccessController::class, 'show']);
    Route::post('{company}/access/roles', [CompanyAccessController::class, 'storeRole']);
    Route::put('{company}/access/roles/{role}', [CompanyAccessController::class, 'updateRole']);
    Route::delete('{company}/access/roles/{role}', [CompanyAccessController::class, 'destroyRole']);
    Route::post('{company}/access/permissions', [CompanyAccessController::class, 'storePermission']);
    Route::post('{company}/access/assignments', [CompanyAccessController::class, 'assignRole']);
    Route::post('{company}/approval-flows', [CompanyAccessController::class, 'storeFlow']);
    Route::put('{company}/approval-flows/{flow}', [CompanyAccessController::class, 'updateFlow']);
    Route::delete('{company}/approval-flows/{flow}', [CompanyAccessController::class, 'destroyFlow']);
    Route::get('{company}/approval-requests', [CompanyAccessController::class, 'queue']);
    Route::post('{company}/approval-requests/{approvalRequest}/approve', [CompanyAccessController::class, 'approve']);
    Route::post('{company}/approval-requests/{approvalRequest}/reject', [CompanyAccessController::class, 'reject']);
    Route::get('{company}/diagnose-roles', [CompanyController::class, 'diagnoseRoles']);
    Route::post('{company}/fix-owner-role', [CompanyController::class, 'fixCompanyOwnerRole']);
    Route::post('{company}/switch-active', [CompanyController::class, 'switchActiveCompany']);

});

Route::prefix('api/admin')->middleware(['api', 'admin.session'])->group(function () {
    Route::get('companies', [\App\Domain\Company\Http\Controllers\AdminCompanyController::class, 'listCompanies']);
    Route::post('companies/{company}/audit', [\App\Domain\Company\Http\Controllers\AdminCompanyController::class, 'auditCompany']);
    Route::get('companies/{company}/imports', [\App\Domain\Company\Http\Controllers\AdminCompanyController::class, 'getImportData']);
    Route::get('companies/{company}/subscription', [\App\Domain\Company\Http\Controllers\AdminCompanyController::class, 'getSubscription']);
    Route::post('companies/{company}/subscription', [\App\Domain\Company\Http\Controllers\AdminCompanyController::class, 'activateSubscription']);
    Route::post('companies/{company}/enterprise-plan', [\App\Domain\Company\Http\Controllers\AdminCompanyController::class, 'assignEnterprisePlan']);
    Route::post('companies/{company}/enterprise-trial', [\App\Domain\Company\Http\Controllers\AdminCompanyController::class, 'grantEnterpriseTrial']);
    Route::delete('companies/{company}/enterprise-entitlement', [\App\Domain\Company\Http\Controllers\AdminCompanyController::class, 'revokeEnterpriseEntitlement']);
    Route::post('companies/{company}/subscription-scheme', [\App\Domain\Company\Http\Controllers\AdminCompanyController::class, 'updateCompanySubscriptionScheme']);
    Route::get('subscription-settings', [\App\Domain\Company\Http\Controllers\AdminCompanyController::class, 'getSubscriptionSettings']);
    Route::post('subscription-settings', [\App\Domain\Company\Http\Controllers\AdminCompanyController::class, 'updateSubscriptionSettings']);
    Route::post('subscription-settings/bulk-apply', [\App\Domain\Company\Http\Controllers\AdminCompanyController::class, 'bulkApplySubscription']);
});

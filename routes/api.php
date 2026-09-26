<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CandidateController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanyDocumentTemplateController;
use App\Http\Controllers\CountryController;
use App\Http\Controllers\DocumentTypeController;
use App\Http\Controllers\DriveDocumentController;
use App\Http\Controllers\LedgerController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OcrController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\SystemSettingController;
use Illuminate\Support\Facades\Route;

Route::post('ocr/extract', [OcrController::class, 'extract']);
Route::get('candidates/share/{token}', [CandidateController::class, 'showShared']);
Route::match(['get', 'post'], 'candidates/share/{token}/export', [CandidateController::class, 'exportSharedExcel']);
Route::get('candidates/agreement/{token}', [CandidateController::class, 'showAgreement']);
Route::post('candidates/agreement/{token}/sign', [CandidateController::class, 'signAgreement']);
Route::get('countries', [CountryController::class, 'index']);

// Global system settings (read is public so app can load format before/after login)
Route::get('settings/date-format', [SystemSettingController::class, 'getDateFormat']);

// Direct endpoints and /auth prefix endpoints
Route::post('login', [AuthController::class, 'login']);
Route::post('register', [AuthController::class, 'register']);

Route::prefix('auth')->group(function (): void {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('refresh', [AuthController::class, 'refresh']);

    Route::middleware('auth:sanctum')->post('logout', [AuthController::class, 'logout']);
    Route::middleware('auth:sanctum')->post('fcm-token', [NotificationController::class, 'registerToken']);
});

Route::middleware('auth:sanctum')->group(function (): void {
    // Notification inbox (the signed-in user's own)
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::get('notifications/tokens', [NotificationController::class, 'tokens']);
    Route::patch('notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::delete('notifications/token', [NotificationController::class, 'removeToken']);
    Route::post('notifications/test', [NotificationController::class, 'test']);
    Route::patch('notifications/{id}/read', [NotificationController::class, 'markRead'])->whereNumber('id');

    // Global system settings — change requires authentication
    Route::post('settings/date-format', [SystemSettingController::class, 'setDateFormat']);

    // Companies
    Route::get('companies', [CompanyController::class, 'index']);
    Route::post('companies', [CompanyController::class, 'store']);
    Route::get('companies/{company}', [CompanyController::class, 'show']);
    Route::get('companies/{company}/logs', [CompanyController::class, 'logs']);
    Route::get('companies/{company}/templates', [CompanyDocumentTemplateController::class, 'index']);
    Route::post('companies/{company}/templates', [CompanyDocumentTemplateController::class, 'store']);
    Route::patch('companies/{company}/templates/{template}', [CompanyDocumentTemplateController::class, 'update']);
    Route::delete('companies/{company}/templates/{template}', [CompanyDocumentTemplateController::class, 'destroy']);
    Route::post('companies/{company}/templates/{template}/generated', [CompanyDocumentTemplateController::class, 'generated']);
    Route::patch('companies/{company}', [CompanyController::class, 'update']);
    Route::delete('companies/{company}', [CompanyController::class, 'destroy']);

    // Document types
    Route::get('document-types', [DocumentTypeController::class, 'index']);
    Route::post('document-types', [DocumentTypeController::class, 'store']);
    Route::patch('document-types/{documentType}', [DocumentTypeController::class, 'update']);
    Route::delete('document-types/{documentType}', [DocumentTypeController::class, 'destroy']);

    // Countries
    Route::post('countries', [CountryController::class, 'store']);
    Route::patch('countries/{country}', [CountryController::class, 'update']);
    Route::delete('countries/{country}', [CountryController::class, 'destroy']);

    // Staff
    Route::get('staff/me', [StaffController::class, 'me']);
    Route::get('staff/permissions', [StaffController::class, 'permissions']);
    Route::get('staff', [StaffController::class, 'index']);
    Route::post('staff', [StaffController::class, 'store']);
    Route::get('staff/{user}', [StaffController::class, 'show']);
    Route::patch('staff/{user}/permissions', [StaffController::class, 'updatePermissions']);
    Route::patch('staff/{user}', [StaffController::class, 'update']);
    Route::patch('staff/{user}/approve', [StaffController::class, 'approve']);
    Route::delete('staff/{user}', [StaffController::class, 'destroy']);

    // Company Drive
    Route::get('drive/documents', [DriveDocumentController::class, 'index']);
    Route::post('drive/documents', [DriveDocumentController::class, 'store']);
    Route::post('drive/documents/{driveDocument}', [DriveDocumentController::class, 'update']);
    Route::patch('drive/documents/{driveDocument}', [DriveDocumentController::class, 'update']);
    Route::delete('drive/documents/{driveDocument}', [DriveDocumentController::class, 'destroy']);

    // Ledger & Financial Records
    Route::get('ledger', [LedgerController::class, 'index']);
    Route::post('ledger', [LedgerController::class, 'store']);
    Route::patch('ledger/{ledgerEntry}', [LedgerController::class, 'update']);
    Route::delete('ledger/{ledgerEntry}', [LedgerController::class, 'destroy']);

    // Candidates — list & create
    Route::get('candidates', [CandidateController::class, 'index']);
    Route::post('candidates', [CandidateController::class, 'store']);

    // Candidates — batch actions (must be before {candidate} wildcard)
    Route::get('candidates/template/download', [CandidateController::class, 'downloadTemplate']);
    Route::post('candidates/bulk', [CandidateController::class, 'bulkStore']);
    Route::post('candidates/shift', [CandidateController::class, 'shiftToCompany']);
    Route::post('candidates/share', [CandidateController::class, 'createShare']);
    Route::post('candidates/export', [CandidateController::class, 'exportExcel']);
    Route::post('candidates/return-batch', [CandidateController::class, 'returnBatchToPool']);

    // Candidates — single resource
    Route::get('candidates/{candidate}', [CandidateController::class, 'show']);
    Route::post('candidates/{candidate}', [CandidateController::class, 'update']); // FormData/multipart support
    Route::patch('candidates/{candidate}', [CandidateController::class, 'update']);
    Route::delete('candidates/{candidate}', [CandidateController::class, 'destroy']);
    Route::patch('candidates/{candidate}/fields', [CandidateController::class, 'updateFields']);
    Route::patch('candidates/{candidate}/stage', [CandidateController::class, 'updateStage']);
    Route::patch('candidates/{candidate}/status', [CandidateController::class, 'updateStatus']);
    Route::patch('candidates/{candidate}/return', [CandidateController::class, 'returnToPool']);
    Route::post('candidates/{candidate}/withdraw', [CandidateController::class, 'withdraw']);
    Route::post('candidates/{candidate}/reactivate', [CandidateController::class, 'reactivate']);
    Route::post('candidates/{candidate}/renew-passport', [CandidateController::class, 'renewPassport']);
    Route::post('candidates/{candidate}/signature', [CandidateController::class, 'updateSignature']);
    Route::post('candidates/{candidate}/cv', [CandidateController::class, 'saveCv']);

    // Candidate Documents
    Route::post('candidates/{candidate}/documents', [CandidateController::class, 'storeDocument']);
    Route::match(['post', 'delete'], 'candidates/{candidate}/documents/batch-delete', [CandidateController::class, 'destroyDocumentsBatch']);
    Route::post('candidates/{candidate}/documents/{document}', [CandidateController::class, 'updateDocument']);
    Route::patch('candidates/{candidate}/documents/{document}', [CandidateController::class, 'updateDocument']);
    Route::delete('candidates/{candidate}/documents/{document}', [CandidateController::class, 'destroyDocument']);
});
<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CandidateController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DocumentTypeController;
use App\Http\Controllers\StaffController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('refresh', [AuthController::class, 'refresh']);

    Route::middleware('auth:sanctum')->post('logout', [AuthController::class, 'logout']);
});

Route::middleware('auth:sanctum')->group(function (): void {
    // Companies
    Route::get('companies', [CompanyController::class, 'index']);
    Route::post('companies', [CompanyController::class, 'store']);
    Route::patch('companies/{company}', [CompanyController::class, 'update']);
    Route::delete('companies/{company}', [CompanyController::class, 'destroy']);

    // Document types
    Route::get('document-types', [DocumentTypeController::class, 'index']);
    Route::post('document-types', [DocumentTypeController::class, 'store']);
    Route::patch('document-types/{documentType}', [DocumentTypeController::class, 'update']);

    // Staff
    Route::get('staff', [StaffController::class, 'index']);
    Route::post('staff', [StaffController::class, 'store']);
    Route::patch('staff/{user}', [StaffController::class, 'update']);
    Route::patch('staff/{user}/approve', [StaffController::class, 'approve']);
    Route::delete('staff/{user}', [StaffController::class, 'destroy']);

    // Candidates — list & create
    Route::get('candidates', [CandidateController::class, 'index']);
    Route::post('candidates', [CandidateController::class, 'store']);

    // Candidates — batch actions (must be before {candidate} wildcard)
    Route::post('candidates/shift', [CandidateController::class, 'shiftToCompany']);

    // Candidates — single resource
    Route::get('candidates/{candidate}', [CandidateController::class, 'show']);
    Route::post('candidates/{candidate}', [CandidateController::class, 'update']); // FormData/multipart support
    Route::patch('candidates/{candidate}', [CandidateController::class, 'update']);
    Route::delete('candidates/{candidate}', [CandidateController::class, 'destroy']);
    Route::patch('candidates/{candidate}/stage', [CandidateController::class, 'updateStage']);
    Route::patch('candidates/{candidate}/status', [CandidateController::class, 'updateStatus']);
    Route::patch('candidates/{candidate}/return', [CandidateController::class, 'returnToPool']);
    Route::post('candidates/{candidate}/withdraw', [CandidateController::class, 'withdraw']);

    // Candidate Documents
    Route::post('candidates/{candidate}/documents', [CandidateController::class, 'storeDocument']);
    Route::post('candidates/{candidate}/documents/{document}', [CandidateController::class, 'updateDocument']);
    Route::patch('candidates/{candidate}/documents/{document}', [CandidateController::class, 'updateDocument']);
    Route::delete('candidates/{candidate}/documents/{document}', [CandidateController::class, 'destroyDocument']);
});
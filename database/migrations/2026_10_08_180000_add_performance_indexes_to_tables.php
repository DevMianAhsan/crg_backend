<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Helper function to safely add index if it doesn't already exist
        $addIndexSafely = function (string $table, array|string $columns, string $indexName): void {
            if (!Schema::hasTable($table)) {
                return;
            }

            $cols = (array) $columns;
            foreach ($cols as $col) {
                if (!Schema::hasColumn($table, $col)) {
                    return;
                }
            }

            try {
                Schema::table($table, function (Blueprint $tableBlueprint) use ($columns, $indexName): void {
                    $tableBlueprint->index($columns, $indexName);
                });
            } catch (\Throwable $e) {
                // Index may already exist or database dialect warning
            }
        };

        // 1. candidates
        $addIndexSafely('candidates', 'passport_number', 'candidates_passport_number_idx');
        $addIndexSafely('candidates', 'cnic_number', 'candidates_cnic_number_idx');
        $addIndexSafely('candidates', 'first_name', 'candidates_first_name_idx');
        $addIndexSafely('candidates', 'last_name', 'candidates_last_name_idx');
        $addIndexSafely('candidates', 'status', 'candidates_status_idx');
        $addIndexSafely('candidates', 'recruitment_stage', 'candidates_recruitment_stage_idx');
        $addIndexSafely('candidates', 'current_company_id', 'candidates_current_company_id_idx');
        $addIndexSafely('candidates', 'trade', 'candidates_trade_idx');
        $addIndexSafely('candidates', 'agreement_token', 'candidates_agreement_token_idx');
        $addIndexSafely('candidates', 'joined_date', 'candidates_joined_date_idx');
        $addIndexSafely('candidates', 'created_at', 'candidates_created_at_idx');
        $addIndexSafely('candidates', ['status', 'current_company_id'], 'candidates_status_company_idx');
        $addIndexSafely('candidates', ['current_company_id', 'created_at'], 'candidates_company_created_idx');

        // 2. candidate_documents
        $addIndexSafely('candidate_documents', 'candidate_id', 'candidate_docs_candidate_id_idx');
        $addIndexSafely('candidate_documents', 'document_type_id', 'candidate_docs_doc_type_id_idx');
        $addIndexSafely('candidate_documents', 'status', 'candidate_docs_status_idx');
        $addIndexSafely('candidate_documents', 'expiry_date', 'candidate_docs_expiry_date_idx');
        $addIndexSafely('candidate_documents', ['candidate_id', 'document_type_id'], 'candidate_docs_cand_type_idx');
        $addIndexSafely('candidate_documents', ['candidate_id', 'status'], 'candidate_docs_cand_status_idx');

        // 3. candidate_submissions
        $addIndexSafely('candidate_submissions', 'candidate_id', 'candidate_submissions_candidate_id_idx');
        $addIndexSafely('candidate_submissions', 'company_id', 'candidate_submissions_company_id_idx');
        $addIndexSafely('candidate_submissions', 'shifted_at', 'candidate_submissions_shifted_at_idx');

        // 4. candidate_withdrawals
        $addIndexSafely('candidate_withdrawals', 'candidate_id', 'candidate_withdrawals_candidate_id_idx');
        $addIndexSafely('candidate_withdrawals', 'status', 'candidate_withdrawals_status_idx');
        $addIndexSafely('candidate_withdrawals', 'withdrawn_at', 'candidate_withdrawals_withdrawn_at_idx');

        // 5. candidate_shares
        $addIndexSafely('candidate_shares', 'company_id', 'candidate_shares_company_id_idx');
        $addIndexSafely('candidate_shares', 'expires_at', 'candidate_shares_expires_at_idx');

        // 6. companies
        $addIndexSafely('companies', 'name', 'companies_name_idx');
        $addIndexSafely('companies', 'status', 'companies_status_idx');
        $addIndexSafely('companies', 'country', 'companies_country_idx');
        $addIndexSafely('companies', 'created_at', 'companies_created_at_idx');

        // 7. company_logs
        $addIndexSafely('company_logs', 'company_id', 'company_logs_company_id_idx');
        $addIndexSafely('company_logs', 'user_id', 'company_logs_user_id_idx');
        $addIndexSafely('company_logs', 'action', 'company_logs_action_idx');
        $addIndexSafely('company_logs', 'created_at', 'company_logs_created_at_idx');
        $addIndexSafely('company_logs', ['company_id', 'created_at'], 'company_logs_comp_created_idx');

        // 8. company_documents
        $addIndexSafely('company_documents', 'company_id', 'company_documents_company_id_idx');
        $addIndexSafely('company_documents', 'status', 'company_documents_status_idx');
        $addIndexSafely('company_documents', 'created_at', 'company_documents_created_at_idx');

        // 9. company_document_templates
        $addIndexSafely('company_document_templates', 'company_id', 'company_doc_templates_comp_id_idx');
        $addIndexSafely('company_document_templates', 'created_at', 'company_doc_templates_created_idx');

        // 10. document_types
        $addIndexSafely('document_types', 'is_mandatory', 'document_types_is_mandatory_idx');
        $addIndexSafely('document_types', 'priority', 'document_types_priority_idx');
        $addIndexSafely('document_types', 'sort_order', 'document_types_sort_order_idx');
        $addIndexSafely('document_types', 'code', 'document_types_code_idx');

        // 11. drive_documents
        $addIndexSafely('drive_documents', 'uploaded_by', 'drive_documents_uploaded_by_idx');
        $addIndexSafely('drive_documents', 'created_at', 'drive_documents_created_at_idx');
        $addIndexSafely('drive_documents', 'updated_at', 'drive_documents_updated_at_idx');

        // 12. ledger_entries
        $addIndexSafely('ledger_entries', 'candidate_id', 'ledger_entries_candidate_id_idx');
        $addIndexSafely('ledger_entries', 'company_id', 'ledger_entries_company_id_idx');
        $addIndexSafely('ledger_entries', 'type', 'ledger_entries_type_idx');
        $addIndexSafely('ledger_entries', 'status', 'ledger_entries_status_idx');
        $addIndexSafely('ledger_entries', 'date', 'ledger_entries_date_idx');
        $addIndexSafely('ledger_entries', 'created_at', 'ledger_entries_created_at_idx');
        $addIndexSafely('ledger_entries', ['candidate_id', 'type'], 'ledger_entries_cand_type_idx');
        $addIndexSafely('ledger_entries', ['company_id', 'date'], 'ledger_entries_comp_date_idx');

        // 13. app_notifications
        $addIndexSafely('app_notifications', 'user_id', 'app_notifications_user_id_idx');
        $addIndexSafely('app_notifications', 'read_at', 'app_notifications_read_at_idx');
        $addIndexSafely('app_notifications', 'created_at', 'app_notifications_created_at_idx');
        $addIndexSafely('app_notifications', ['user_id', 'read_at', 'created_at'], 'app_notifs_user_read_created_idx');

        // 14. fcm_tokens
        $addIndexSafely('fcm_tokens', 'user_id', 'fcm_tokens_user_id_idx');
        $addIndexSafely('fcm_tokens', 'token', 'fcm_tokens_token_idx');

        // 15. users
        $addIndexSafely('users', 'role', 'users_role_idx');
        $addIndexSafely('users', 'status', 'users_status_idx');

        // 16. countries
        $addIndexSafely('countries', 'is_active', 'countries_is_active_idx');
        $addIndexSafely('countries', 'sort_order', 'countries_sort_order_idx');
        $addIndexSafely('countries', 'name', 'countries_name_idx');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $dropIndexSafely = function (string $table, string $indexName): void {
            if (!Schema::hasTable($table)) {
                return;
            }
            try {
                Schema::table($table, function (Blueprint $tableBlueprint) use ($indexName): void {
                    $tableBlueprint->dropIndex($indexName);
                });
            } catch (\Throwable) {
                // Ignore if not present
            }
        };

        // candidates
        $dropIndexSafely('candidates', 'candidates_passport_number_idx');
        $dropIndexSafely('candidates', 'candidates_cnic_number_idx');
        $dropIndexSafely('candidates', 'candidates_first_name_idx');
        $dropIndexSafely('candidates', 'candidates_last_name_idx');
        $dropIndexSafely('candidates', 'candidates_status_idx');
        $dropIndexSafely('candidates', 'candidates_recruitment_stage_idx');
        $dropIndexSafely('candidates', 'candidates_current_company_id_idx');
        $dropIndexSafely('candidates', 'candidates_trade_idx');
        $dropIndexSafely('candidates', 'candidates_agreement_token_idx');
        $dropIndexSafely('candidates', 'candidates_joined_date_idx');
        $dropIndexSafely('candidates', 'candidates_created_at_idx');
        $dropIndexSafely('candidates', 'candidates_status_company_idx');
        $dropIndexSafely('candidates', 'candidates_company_created_idx');

        // candidate_documents
        $dropIndexSafely('candidate_documents', 'candidate_docs_candidate_id_idx');
        $dropIndexSafely('candidate_documents', 'candidate_docs_doc_type_id_idx');
        $dropIndexSafely('candidate_documents', 'candidate_docs_status_idx');
        $dropIndexSafely('candidate_documents', 'candidate_docs_expiry_date_idx');
        $dropIndexSafely('candidate_documents', 'candidate_docs_cand_type_idx');
        $dropIndexSafely('candidate_documents', 'candidate_docs_cand_status_idx');

        // candidate_submissions
        $dropIndexSafely('candidate_submissions', 'candidate_submissions_candidate_id_idx');
        $dropIndexSafely('candidate_submissions', 'candidate_submissions_company_id_idx');
        $dropIndexSafely('candidate_submissions', 'candidate_submissions_shifted_at_idx');

        // candidate_withdrawals
        $dropIndexSafely('candidate_withdrawals', 'candidate_withdrawals_candidate_id_idx');
        $dropIndexSafely('candidate_withdrawals', 'candidate_withdrawals_status_idx');
        $dropIndexSafely('candidate_withdrawals', 'candidate_withdrawals_withdrawn_at_idx');

        // candidate_shares
        $dropIndexSafely('candidate_shares', 'candidate_shares_company_id_idx');
        $dropIndexSafely('candidate_shares', 'candidate_shares_expires_at_idx');

        // companies
        $dropIndexSafely('companies', 'companies_name_idx');
        $dropIndexSafely('companies', 'companies_status_idx');
        $dropIndexSafely('companies', 'companies_country_idx');
        $dropIndexSafely('companies', 'companies_created_at_idx');

        // company_logs
        $dropIndexSafely('company_logs', 'company_logs_company_id_idx');
        $dropIndexSafely('company_logs', 'company_logs_user_id_idx');
        $dropIndexSafely('company_logs', 'company_logs_action_idx');
        $dropIndexSafely('company_logs', 'company_logs_created_at_idx');
        $dropIndexSafely('company_logs', 'company_logs_comp_created_idx');

        // company_documents
        $dropIndexSafely('company_documents', 'company_documents_company_id_idx');
        $dropIndexSafely('company_documents', 'company_documents_status_idx');
        $dropIndexSafely('company_documents', 'company_documents_created_at_idx');

        // company_document_templates
        $dropIndexSafely('company_document_templates', 'company_doc_templates_comp_id_idx');
        $dropIndexSafely('company_document_templates', 'company_doc_templates_created_idx');

        // document_types
        $dropIndexSafely('document_types', 'document_types_is_mandatory_idx');
        $dropIndexSafely('document_types', 'document_types_priority_idx');
        $dropIndexSafely('document_types', 'document_types_sort_order_idx');
        $dropIndexSafely('document_types', 'document_types_code_idx');

        // drive_documents
        $dropIndexSafely('drive_documents', 'drive_documents_uploaded_by_idx');
        $dropIndexSafely('drive_documents', 'drive_documents_created_at_idx');
        $dropIndexSafely('drive_documents', 'drive_documents_updated_at_idx');

        // ledger_entries
        $dropIndexSafely('ledger_entries', 'ledger_entries_candidate_id_idx');
        $dropIndexSafely('ledger_entries', 'ledger_entries_company_id_idx');
        $dropIndexSafely('ledger_entries', 'ledger_entries_type_idx');
        $dropIndexSafely('ledger_entries', 'ledger_entries_status_idx');
        $dropIndexSafely('ledger_entries', 'ledger_entries_date_idx');
        $dropIndexSafely('ledger_entries', 'ledger_entries_created_at_idx');
        $dropIndexSafely('ledger_entries', 'ledger_entries_cand_type_idx');
        $dropIndexSafely('ledger_entries', 'ledger_entries_comp_date_idx');

        // app_notifications
        $dropIndexSafely('app_notifications', 'app_notifications_user_id_idx');
        $dropIndexSafely('app_notifications', 'app_notifications_read_at_idx');
        $dropIndexSafely('app_notifications', 'app_notifications_created_at_idx');
        $dropIndexSafely('app_notifications', 'app_notifs_user_read_created_idx');

        // fcm_tokens
        $dropIndexSafely('fcm_tokens', 'fcm_tokens_user_id_idx');
        $dropIndexSafely('fcm_tokens', 'fcm_tokens_token_idx');

        // users
        $dropIndexSafely('users', 'users_role_idx');
        $dropIndexSafely('users', 'users_status_idx');

        // countries
        $dropIndexSafely('countries', 'countries_is_active_idx');
        $dropIndexSafely('countries', 'countries_sort_order_idx');
        $dropIndexSafely('countries', 'countries_name_idx');
    }
};

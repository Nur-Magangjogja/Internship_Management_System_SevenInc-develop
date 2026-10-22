<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for SERTIF Master Plan V1 Fase 2.
     * All tables and columns are strictly additive; zero drop/rename of legacy structures.
     */
    public function up(): void
    {
        // 1. Spreadsheet Tab Profiles (explicit header_row and data_start_row per connection + sheet_id)
        if (!Schema::hasTable('spreadsheet_tab_profiles')) {
            Schema::create('spreadsheet_tab_profiles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('spreadsheet_connection_id')->constrained('spreadsheet_connections')->cascadeOnDelete();
                $table->unsignedBigInteger('sheet_id')->comment('Google numeric sheetId (stable across tab rename)');
                $table->string('tab_name', 150);
                $table->unsignedSmallInteger('header_row')->default(1);
                $table->unsignedSmallInteger('data_start_row')->default(2);
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['spreadsheet_connection_id', 'sheet_id'], 'conn_sheet_id_unique');
                $table->index(['spreadsheet_connection_id', 'tab_name'], 'conn_tab_name_idx');
            });
        }

        // 2. Sheet Discovery Snapshots (audit trail of header scanning with canonical schema_hash)
        if (!Schema::hasTable('sheet_discovery_snapshots')) {
            Schema::create('sheet_discovery_snapshots', function (Blueprint $table) {
                $table->id();
                $table->uuid('public_uuid')->unique();
                $table->foreignId('spreadsheet_connection_id')->constrained('spreadsheet_connections')->cascadeOnDelete();
                $table->string('spreadsheet_id', 150);
                $table->unsignedBigInteger('sheet_id')->nullable();
                $table->string('tab_name', 150);
                $table->unsignedSmallInteger('header_row')->default(1);
                $table->unsignedSmallInteger('data_start_row')->default(2);
                $table->string('schema_hash', 64)->comment('Deterministic SHA-256 hash of ordered headers');
                $table->unsignedSmallInteger('columns_count')->default(0);
                $table->json('scan_metadata')->nullable();
                $table->foreignId('scanned_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('scanned_at');
                $table->timestamps();

                $table->index(['spreadsheet_connection_id', 'tab_name'], 'snap_conn_tab_idx');
                $table->index('schema_hash', 'snap_schema_hash_idx');
            });
        }

        // 3. Sheet Discovered Columns (column-level details from a specific snapshot)
        if (!Schema::hasTable('sheet_discovered_columns')) {
            Schema::create('sheet_discovered_columns', function (Blueprint $table) {
                $table->id();
                $table->foreignId('snapshot_id')->constrained('sheet_discovery_snapshots')->cascadeOnDelete();
                $table->string('column_identity', 100)->nullable()->comment('Opaque stable identity or provisional hash');
                $table->string('col_letter', 10);
                $table->unsignedSmallInteger('physical_index');
                $table->string('observed_label', 255);
                $table->string('inferred_type', 50)->default('string');
                $table->boolean('has_formula')->default(false);
                $table->json('sample_values')->nullable();
                $table->boolean('is_duplicate_label')->default(false);
                $table->boolean('is_system_reserved')->default(false);
                $table->timestamps();

                $table->index(['snapshot_id', 'physical_index'], 'col_snap_idx');
                $table->index(['snapshot_id', 'column_identity'], 'col_snap_ident_idx');
            });
        }

        // 4. Sheet Mapping Proposals (draft mapping proposals with optimistic lock)
        if (!Schema::hasTable('sheet_mapping_proposals')) {
            Schema::create('sheet_mapping_proposals', function (Blueprint $table) {
                $table->id();
                $table->uuid('public_uuid')->unique();
                $table->foreignId('spreadsheet_connection_id')->constrained('spreadsheet_connections')->cascadeOnDelete();
                $table->foreignId('snapshot_id')->constrained('sheet_discovery_snapshots')->cascadeOnDelete();
                $table->string('expected_schema_hash', 64);
                $table->unsignedSmallInteger('year')->nullable();
                $table->foreignId('form_schema_id')->nullable()->constrained('form_schemas')->nullOnDelete();
                $table->unsignedInteger('version')->default(1)->comment('Optimistic version lock against concurrent edits');
                $table->string('status', 50)->default('draft')->comment('draft, approved_to_draft, rejected, applied, conflict');
                $table->text('conflict_reason')->nullable();
                $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('applied_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['spreadsheet_connection_id', 'status'], 'prop_conn_status_idx');
            });
        }

        // 5. Sheet Mapping Proposal Items (decision per discovered column)
        if (!Schema::hasTable('sheet_mapping_proposal_items')) {
            Schema::create('sheet_mapping_proposal_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('proposal_id')->constrained('sheet_mapping_proposals')->cascadeOnDelete();
                $table->foreignId('discovered_column_id')->nullable()->constrained('sheet_discovered_columns')->nullOnDelete();
                $table->string('column_identity', 100)->nullable();
                $table->string('col_letter', 10);
                $table->unsignedSmallInteger('physical_index');
                $table->string('observed_label', 255);
                $table->string('action', 50)->default('ignore')->comment('link_existing, create_draft_field, external_only, ignore, system_protected');
                $table->string('field_key', 100)->nullable();
                $table->string('suggested_label', 255)->nullable();
                $table->string('suggested_field_type', 50)->nullable();
                $table->decimal('confidence_score', 4, 2)->default(0.00);
                $table->string('confidence_level', 50)->default('UNMATCHED');
                $table->string('match_reason', 255)->nullable();
                $table->boolean('has_collision')->default(false);
                $table->string('collision_detail', 255)->nullable();
                $table->timestamps();

                $table->index(['proposal_id', 'field_key'], 'item_prop_key_idx');
            });
        }

        // 6. Integration Outbox Events (durable outbox for remote sync with idempotency)
        if (!Schema::hasTable('integration_outbox_events')) {
            Schema::create('integration_outbox_events', function (Blueprint $table) {
                $table->id();
                $table->uuid('public_uuid')->unique();
                $table->string('event_type', 100)->comment('form_schema.publish_sync, spreadsheet.schema_sync, etc.');
                $table->foreignId('target_connection_id')->nullable()->constrained('spreadsheet_connections')->nullOnDelete();
                $table->string('aggregate_type', 100);
                $table->string('aggregate_id', 100);
                $table->string('idempotency_key', 150)->unique();
                $table->string('expected_schema_hash', 64)->nullable();
                $table->string('status', 50)->default('pending')->comment('pending, processing, verified, failed, skipped');
                $table->unsignedSmallInteger('attempt_count')->default(0);
                $table->unsignedSmallInteger('max_attempts')->default(5);
                $table->timestamp('last_attempt_at')->nullable();
                $table->text('last_error')->nullable();
                $table->json('payload')->nullable();
                $table->json('response_context')->nullable();
                $table->timestamps();

                $table->index(['status', 'attempt_count'], 'outbox_status_attempts_idx');
                $table->index(['aggregate_type', 'aggregate_id'], 'outbox_agg_idx');
            });
        }

        // 7. Additive columns on existing spreadsheet_column_mappings
        if (Schema::hasTable('spreadsheet_column_mappings')) {
            Schema::table('spreadsheet_column_mappings', function (Blueprint $table) {
                if (!Schema::hasColumn('spreadsheet_column_mappings', 'column_identity')) {
                    $table->string('column_identity', 100)->nullable()->after('public_uuid')->comment('Stable opaque column identifier');
                }
                if (!Schema::hasColumn('spreadsheet_column_mappings', 'sheet_id')) {
                    $table->unsignedBigInteger('sheet_id')->nullable()->after('spreadsheet_schema_version_id')->comment('Numeric Google sheetId');
                }
                if (!Schema::hasColumn('spreadsheet_column_mappings', 'mapping_status')) {
                    $table->string('mapping_status', 50)->default('active')->after('is_required')->comment('active, unmapped, provisional, external_only');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('spreadsheet_column_mappings')) {
            Schema::table('spreadsheet_column_mappings', function (Blueprint $table) {
                if (Schema::hasColumn('spreadsheet_column_mappings', 'mapping_status')) {
                    $table->dropColumn('mapping_status');
                }
                if (Schema::hasColumn('spreadsheet_column_mappings', 'sheet_id')) {
                    $table->dropColumn('sheet_id');
                }
                if (Schema::hasColumn('spreadsheet_column_mappings', 'column_identity')) {
                    $table->dropColumn('column_identity');
                }
            });
        }

        Schema::dropIfExists('integration_outbox_events');
        Schema::dropIfExists('sheet_mapping_proposal_items');
        Schema::dropIfExists('sheet_mapping_proposals');
        Schema::dropIfExists('sheet_discovered_columns');
        Schema::dropIfExists('sheet_discovery_snapshots');
        Schema::dropIfExists('spreadsheet_tab_profiles');
    }
};

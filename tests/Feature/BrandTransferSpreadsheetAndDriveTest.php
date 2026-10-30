<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\CloudFolderRegistry;
use App\Models\CloudStorageSetting;
use App\Models\InternshipRegistration;
use App\Models\RegistrationAttachment;
use App\Models\SpreadsheetColumnMapping;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetRowSync;
use App\Models\SpreadsheetSchemaVersion;
use App\Models\User;
use App\Services\SpreadsheetSyncEngineService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BrandTransferSpreadsheetAndDriveTest extends TestCase
{
    use DatabaseTransactions;

    protected string $endpointUrl = 'https://script.google.com/macros/s/AKfy_mock_transfer_test/exec';
    protected string $sharedSecret = 'test_secret_brand_transfer_123';
    protected string $canonicalRootId = 'ROOT_DRIVE_FOLDER_TEST';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.google_apps_script.enabled', true);
        Config::set('services.google_apps_script.endpoint_url', $this->endpointUrl);
        Config::set('services.google_apps_script.shared_secret', $this->sharedSecret);
        Config::set('services.google_apps_script.timeout_seconds', 60);

        CloudStorageSetting::updateOrCreate(
            ['id' => 1],
            [
                'name'                        => 'Cloud Storage System Test',
                'canonical_parent_folder_id'  => $this->canonicalRootId,
                'canonical_parent_folder_url' => "https://drive.google.com/drive/folders/{$this->canonicalRootId}",
                'is_enabled'                  => true,
                'is_verified'                 => true,
                'connection_status'           => 'connected',
            ]
        );
    }

    public function test_brand_transfer_moves_spreadsheet_data_and_google_drive_folder(): void
    {
        // 1. Setup Brand A (SEVEN INC) dan Brand B (Ada Kamar)
        $brandA = Brand::updateOrCreate(
            ['code' => 'SI_TEST'],
            [
                'name'               => 'SEVEN INC',
                'is_default'         => true,
                'lifecycle_status'   => Brand::STATUS_ACTIVE,
                'domain_verified_at' => now(),
                'registration_host'  => 'daftar.seveninc.test',
                'primary_domain'     => 'seveninc.test',
            ]
        );

        $brandB = Brand::updateOrCreate(
            ['code' => 'AK_TEST'],
            [
                'name'               => 'Ada Kamar',
                'is_default'         => false,
                'lifecycle_status'   => Brand::STATUS_ACTIVE,
                'domain_verified_at' => now(),
                'registration_host'  => 'daftar.adakamar.test',
                'primary_domain'     => 'adakamar.test',
            ]
        );

        // 2. Setup Koneksi Spreadsheet Brand A dan Brand B
        $connA = SpreadsheetConnection::create([
            'connection_key'           => SpreadsheetConnection::KEY_REGISTRATION,
            'brand_id'                 => $brandA->id,
            'display_name'             => 'Spreadsheet Pendaftaran (SEVEN INC)',
            'spreadsheet_url'          => 'https://docs.google.com/spreadsheets/d/SHEET_A_SEVENINC_111/edit',
            'spreadsheet_id'           => 'SHEET_A_SEVENINC_111',
            'active_spreadsheet_id'    => 'SHEET_A_SEVENINC_111',
            'drive_folder_url'         => 'https://drive.google.com/drive/folders/' . $this->canonicalRootId,
            'drive_folder_id'          => $this->canonicalRootId,
            'apps_script_endpoint_url' => $this->endpointUrl,
            'is_enabled'               => true,
            'connection_status'        => 'connected',
            'target_generation'        => 1,
        ]);

        $connB = SpreadsheetConnection::create([
            'connection_key'           => SpreadsheetConnection::KEY_REGISTRATION,
            'brand_id'                 => $brandB->id,
            'display_name'             => 'Spreadsheet Pendaftaran (Ada Kamar)',
            'spreadsheet_url'          => 'https://docs.google.com/spreadsheets/d/SHEET_B_ADAKAMAR_222/edit',
            'spreadsheet_id'           => 'SHEET_B_ADAKAMAR_222',
            'active_spreadsheet_id'    => 'SHEET_B_ADAKAMAR_222',
            'drive_folder_url'         => 'https://drive.google.com/drive/folders/' . $this->canonicalRootId,
            'drive_folder_id'          => $this->canonicalRootId,
            'apps_script_endpoint_url' => $this->endpointUrl,
            'is_enabled'               => true,
            'connection_status'        => 'connected',
            'target_generation'        => 1,
        ]);

        // 3. Setup Skema & Pemetaan Kolom untuk Koneksi A & B
        foreach ([$connA, $connB] as $conn) {
            $schema = SpreadsheetSchemaVersion::create([
                'spreadsheet_connection_id' => $conn->id,
                'year'                      => 2026,
                'sheet_tab_name'            => '2026',
                'schema_version'            => 'v1',
                'is_active'                 => true,
            ]);

            SpreadsheetColumnMapping::create([
                'spreadsheet_schema_version_id' => $schema->id,
                'column_identity'               => "col_nama_{$conn->id}",
                'column_order'                  => 1,
                'sheet_header'                  => 'NAMA LENGKAP',
                'target_field'                  => 'fullname',
                'transform_key'                 => 'fullname',
                'data_type'                     => 'string',
                'direction'                     => 'outbound',
                'is_required'                   => true,
                'mapping_status'                => 'active',
            ]);

            SpreadsheetColumnMapping::create([
                'spreadsheet_schema_version_id' => $schema->id,
                'column_identity'               => "col_ket_{$conn->id}",
                'column_order'                  => 2,
                'sheet_header'                  => 'KET',
                'target_field'                  => 'ket',
                'transform_key'                 => 'ket',
                'data_type'                     => 'string',
                'direction'                     => 'outbound',
                'is_required'                   => true,
                'mapping_status'                => 'active',
            ]);

            SpreadsheetColumnMapping::create([
                'spreadsheet_schema_version_id' => $schema->id,
                'column_identity'               => "col_folder_{$conn->id}",
                'column_order'                  => 3,
                'sheet_header'                  => 'FOLDER DRIVE',
                'target_field'                  => 'attachment_url',
                'transform_key'                 => 'attachment_url',
                'data_type'                     => 'string',
                'direction'                     => 'outbound',
                'is_required'                   => false,
                'mapping_status'                => 'active',
            ]);
        }

        // 4. Buat Pendaftar di Brand A
        $user = User::create([
            'name'     => 'Nabila Maulana Wibowo',
            'email'    => 'nabila.transfer.' . uniqid() . '@example.com',
            'password' => bcrypt('secret123'),
            'role'     => 'pemagang',
        ]);

        $reg = InternshipRegistration::create([
            'user_id'           => $user->id,
            'brand_id'          => $brandA->id,
            'fullname'          => 'Nabila Maulana Wibowo',
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
            'created_at'        => Carbon::create(2026, 3, 10, 10, 0, 0),
        ]);

        // Buat attachment bundle folder
        RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => RegistrationAttachment::TYPE_LEGACY_BUNDLE,
            'category'                   => RegistrationAttachment::TYPE_LEGACY_BUNDLE,
            'file_path'                  => 'drive_root:' . $this->canonicalRootId,
            'original_name'              => 'Folder_nabila_maulana_wibowo_REG' . $reg->id,
            'file_size'                  => 0,
            'mime_type'                  => 'application/vnd.google-apps.folder',
            'drive_file_id'              => 'DRIVE_FOLDER_NABILA_123',
            'drive_web_view_link'        => 'https://drive.google.com/drive/folders/DRIVE_FOLDER_NABILA_123',
            'drive_sync_status'          => RegistrationAttachment::STATUS_SYNCED,
            'drive_synced_at'            => now(),
        ]);

        // Catat folder pendaftar di CloudFolderRegistry pada Brand A
        $applicantFolderSlug = $reg->getDriveApplicantFolderName();
        $oldBasePath = "REGISTRASI/2026/SEVEN INC/{$applicantFolderSlug}";
        $newBasePath = "REGISTRASI/2026/Ada Kamar/{$applicantFolderSlug}";

        CloudFolderRegistry::create([
            'canonical_root_id' => $this->canonicalRootId,
            'owner_type'        => 'registration',
            'owner_id'          => $reg->id,
            'category'          => 'registration_folder',
            'folder_path'       => $oldBasePath,
            'drive_folder_id'   => 'DRIVE_FOLDER_NABILA_123',
            'is_verified'       => true,
        ]);

        CloudFolderRegistry::create([
            'canonical_root_id' => $this->canonicalRootId,
            'owner_type'        => 'registration',
            'owner_id'          => $reg->id,
            'category'          => 'cv',
            'folder_path'       => "{$oldBasePath}/Curriculum Vitae (CV)",
            'drive_folder_id'   => 'DRIVE_CV_NABILA_456',
            'is_verified'       => true,
        ]);

        // Catat baris sync yang sudah tersinkronisasi di Spreadsheet Brand A
        $oldRowSync = SpreadsheetRowSync::create([
            'spreadsheet_connection_id' => $connA->id,
            'syncable_type'             => InternshipRegistration::class,
            'syncable_id'               => $reg->id,
            'public_uuid'               => (string) \Illuminate\Support\Str::uuid(),
            'external_sync_id'          => 'REG-' . $reg->id,
            'target_sheet_tab'          => '2026',
            'target_spreadsheet_id'     => $connA->spreadsheet_id,
            'target_generation'         => 1,
            'schema_version'            => 'v1',
            'sync_status'               => SpreadsheetRowSync::STATUS_SYNCED,
            'row_index'                 => 5,
            'last_synced_at'            => now(),
            'payload_checksum'          => 'old_checksum_123',
        ]);

        // 5. Mock HTTP Response Apps Script
        Http::fake([
            $this->endpointUrl . '*' => function ($request) use ($connA, $connB) {
                $body = json_decode($request->body(), true);
                $action = $body['payload']['action'] ?? $body['action'] ?? null;

                if ($action === 'row_update_only') {
                    return Http::response([
                        'success'   => true,
                        'action'    => 'row_update_only',
                        'row_index' => 5,
                        'sync_id'   => $body['payload']['sync_id'] ?? null,
                    ], 200);
                }

                if ($action === 'drive_resolve_folder') {
                    return Http::response([
                        'success'     => true,
                        'action'      => 'drive_resolve_folder',
                        'folder_id'   => 'PARENT_ADA_KAMAR_FOLDER_ID',
                        'folder_name' => 'Ada Kamar',
                        'folder_path' => $body['payload']['folder_path'] ?? null,
                    ], 200);
                }

                if ($action === 'drive_move_folder') {
                    return Http::response([
                        'success'          => true,
                        'action'           => 'drive_move_folder',
                        'folder_id'        => $body['payload']['folder_id'] ?? null,
                        'target_parent_id' => 'PARENT_ADA_KAMAR_FOLDER_ID',
                        'target_folder_path' => $body['payload']['target_folder_path'] ?? null,
                    ], 200);
                }

                if ($action === 'row_upsert') {
                    return Http::response([
                        'success'   => true,
                        'action'    => 'row_upsert',
                        'row_index' => 2,
                        'sync_id'   => $body['payload']['sync_id'] ?? null,
                    ], 200);
                }

                return Http::response(['success' => true], 200);
            },
        ]);

        // 6. Jalankan pemindahan brand melalui handleBrandTransfer
        $syncEngine = app(SpreadsheetSyncEngineService::class);
        $syncEngine->handleBrandTransfer($reg, $brandA->id, $brandB->id);

        // 7. VERIFIKASI SPREADSHEET BRAND LAMA
        // Memastikan update baris dikirim ke connection A dengan KET = "Dipindahkan ke Brand Ada Kamar"
        Http::assertSent(function ($request) use ($connA) {
            $body = json_decode($request->body(), true);
            $p = $body['payload'] ?? [];
            return in_array($p['action'] ?? null, ['update_row', 'row_update_only'], true)
                && ($p['spreadsheet_id'] ?? null) === $connA->spreadsheet_id
                && isset($p['payload']['KET'])
                && $p['payload']['KET'] === 'Dipindahkan ke Brand Ada Kamar';
        });

        // 8. VERIFIKASI GOOGLE DRIVE FOLDER MOVE
        // Memastikan request drive_move_folder dikirim untuk memindahkan folder pendaftar ke folder brand baru
        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            $p = $body['payload'] ?? [];
            return ($p['action'] ?? null) === 'drive_move_folder'
                && ($p['folder_id'] ?? null) === 'DRIVE_FOLDER_NABILA_123'
                && ($p['target_folder_path'] ?? null) === 'REGISTRASI/2026/Ada Kamar';
        });

        // Memastikan database CloudFolderRegistry ter-update ke direktori Brand Baru
        $this->assertDatabaseHas('cloud_folder_registry', [
            'owner_id'    => $reg->id,
            'category'    => 'registration_folder',
            'folder_path' => $newBasePath,
        ]);
        $this->assertDatabaseHas('cloud_folder_registry', [
            'owner_id'    => $reg->id,
            'category'    => 'cv',
            'folder_path' => "{$newBasePath}/Curriculum Vitae (CV)",
        ]);
        $this->assertDatabaseMissing('cloud_folder_registry', [
            'owner_id'    => $reg->id,
            'folder_path' => $oldBasePath,
        ]);

        // 9. VERIFIKASI SPREADSHEET BRAND BARU
        // Memastikan row_upsert dikirim ke connection B dengan keterangan pindahan dan tautan folder Drive
        Http::assertSent(function ($request) use ($connB) {
            $body = json_decode($request->body(), true);
            $p = $body['payload'] ?? [];
            return in_array($p['action'] ?? null, ['upsert_row', 'row_upsert'], true)
                && ($p['spreadsheet_id'] ?? null) === $connB->spreadsheet_id
                && isset($p['payload']['KET'])
                && str_contains($p['payload']['KET'], 'Pindahan dari SEVEN INC')
                && isset($p['payload']['FOLDER DRIVE'])
                && str_contains($p['payload']['FOLDER DRIVE'], 'DRIVE_FOLDER_NABILA_123');
        });

        // Memastikan tercatat SpreadsheetRowSync pada brand baru
        $this->assertDatabaseHas('spreadsheet_row_syncs', [
            'spreadsheet_connection_id' => $connB->id,
            'syncable_id'               => $reg->id,
            'sync_status'               => SpreadsheetRowSync::STATUS_SYNCED,
        ]);
    }
}

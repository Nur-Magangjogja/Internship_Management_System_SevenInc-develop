<?php

namespace Tests\Feature\CloudStorage;

use App\Models\CloudStorageSetting;
use App\Models\RegistrationAttachment;
use App\Models\SpreadsheetConnection;
use App\Models\User;
use App\Services\CloudStorage\SystemStorageConfigService;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VerifyAppsScriptDriveCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected string $endpointUrl = 'https://script.google.com/macros/s/AKfy_verify_cmd_test/exec';
    protected string $sharedSecret = 'super_secret_verify_key_987654';
    protected string $canonicalRootId = 'ROOT_CANONICAL_VERIFY_FOLDER';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.google_apps_script.endpoint_url', $this->endpointUrl);
        Config::set('services.google_apps_script.shared_secret', $this->sharedSecret);

        CloudStorageSetting::query()->delete();
        CloudStorageSetting::create([
            'name'                       => 'Testing Apps Script Storage',
            'provider'                   => CloudStorageSetting::PROVIDER_APPS_SCRIPT_DRIVE,
            'canonical_parent_folder_id' => $this->canonicalRootId,
            'is_verified'                => true,
            'connection_status'          => CloudStorageSetting::STATUS_CONNECTED,
        ]);

        SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)->delete();
        SpreadsheetConnection::create([
            'connection_key'          => SpreadsheetConnection::KEY_REGISTRATION,
            'display_name'            => 'Registration Sheet',
            'spreadsheet_id'          => 'REG_SHEET_12345',
            'sheet_name'              => 'Pendaftaran',
            'drive_folder_id'         => $this->canonicalRootId,
            'apps_script_endpoint_url'=> $this->endpointUrl,
            'is_active'               => true,
            'sync_status'             => 'idle',
        ]);
    }

    /**
     * Test 1: Perintah BLOCKED jika konfigurasi (misal folder kanonikal) kosong.
     */
    public function test_verify_command_blocks_when_config_is_missing(): void
    {
        CloudStorageSetting::query()->update(['canonical_parent_folder_id' => null]);
        SpreadsheetConnection::query()->update(['drive_folder_id' => null]);

        $this->artisan('storage:verify-apps-script')
            ->expectsOutputToContain('KEPUTUSAN: BLOCKED FOR GAS-06')
            ->assertExitCode(Command::FAILURE);
    }

    /**
     * Test 2: Perintah BLOCKED jika Web App cloud merespon UNKNOWN_ACTION (deployment lama).
     */
    public function test_verify_command_blocks_when_apps_script_returns_unknown_action(): void
    {
        Http::fake([
            $this->endpointUrl . '*' => Http::response([
                'success'    => false,
                'error_code' => 'UNKNOWN_ACTION',
                'message'    => 'Aksi "drive_ping" tidak didukung oleh endpoint.',
            ], 400),
        ]);

        $this->artisan('storage:verify-apps-script')
            ->expectsOutputToContain('UNKNOWN_ACTION')
            ->expectsOutputToContain('KEPUTUSAN: BLOCKED FOR GAS-06')
            ->assertExitCode(Command::FAILURE);
    }

    /**
     * Test 3: Perintah PARTIALLY VERIFIED jika drive_ping sukses namun penulisan remote ditangguhkan (Safety Lock aktif).
     */
    public function test_verify_command_partially_verified_when_safety_lock_active(): void
    {
        Http::fake([
            $this->endpointUrl . '*' => Http::response([
                'success'    => true,
                'action'     => 'drive_ping',
                'accessible' => true,
                'root_folder_name' => 'Sertif_Live_Root',
            ], 200),
        ]);

        $this->artisan('storage:verify-apps-script')
            ->expectsOutputToContain('KEPUTUSAN: PARTIALLY VERIFIED (PENDING LIVE TRANSFER / SAFETY LOCK)')
            ->doesntExpectOutputToContain('KEPUTUSAN: VERIFIED FOR GAS-06')
            ->assertExitCode(Command::INVALID);
    }

    /**
     * Test 4: Perintah mengklasifikasikan sampel data uji sebagai DEMO_PLACEHOLDER berdasarkan bukti seeder.
     */
    public function test_verify_command_identifies_seeder_placeholders_with_evidence(): void
    {
        $this->createDummyAttachment('1demo_cv_1_testmock');

        Http::fake([
            '*' => function ($request) {
                $body = json_decode($request->body(), true) ?: [];
                $payload = $body['payload'] ?? $body;
                $action = $payload['action'] ?? '';

                if ($action === 'drive_ping') {
                    return Http::response([
                        'success'          => true,
                        'accessible'       => true,
                        'root_folder_name' => 'Sertif_Live_Root',
                    ], 200);
                }

                if ($action === 'drive_get_metadata') {
                    return Http::response([
                        'success'    => false,
                        'error_code' => 'FILE_NOT_FOUND',
                        'message'    => 'Berkas tidak ditemukan: No item with the given ID could be found.',
                    ], 200);
                }

                return Http::response(['success' => false, 'error_code' => 'UNKNOWN_ACTION'], 400);
            },
        ]);

        $this->artisan('storage:verify-apps-script', ['--audit-samples' => '1'])
            ->expectsOutputToContain('DEMO_PLACEHOLDER')
            ->expectsOutputToContain('CATATAN ANALISIS BUKTI HISTORIS')
            ->expectsOutputToContain('KEPUTUSAN: PARTIALLY VERIFIED')
            ->assertExitCode(Command::INVALID);
    }

    protected function createDummyAttachment(string $fakeDriveId = '1demo_cv_1_testmock'): RegistrationAttachment
    {
        $user = User::create([
            'name'     => 'Pemagang Dummy',
            'email'    => 'pemagang_' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'role'     => 'pemagang',
        ]);

        $unique = substr(md5(uniqid()), 0, 6);
        $institution = \App\Models\Institution::firstOrCreate(
            ['normalized_name' => 'univ test ' . $unique],
            ['name' => 'Univ Test ' . $unique, 'is_active' => true]
        );
        $faculty = \App\Models\Faculty::firstOrCreate(
            ['institution_id' => $institution->id],
            ['name' => 'Fakultas Teknik ' . $unique]
        );
        $program = \App\Models\StudyProgram::firstOrCreate(
            ['faculty_id' => $faculty->id],
            ['name' => 'Informatika ' . $unique]
        );
        $city = \App\Models\City::firstOrCreate(['name' => 'Yogyakarta']);
        $division = \App\Models\Division::firstOrCreate(
            ['name' => 'Software Engineer'],
            ['code' => 'SE', 'description' => 'Software Engineering']
        );

        $reg = \App\Models\InternshipRegistration::create([
            'user_id'                  => $user->id,
            'fullname'                 => 'Alice Pemagang',
            'email'                    => $user->email,
            'phone'                    => '081234567890',
            'student_id'               => 'NIM' . rand(1000, 9999),
            'institution_id'           => $institution->id,
            'institution_name'         => $institution->name,
            'faculty_id'               => $faculty->id,
            'faculty'                  => $faculty->name,
            'study_program_id'         => $program->id,
            'study_program'            => $program->name,
            'current_city_id'          => $city->id,
            'current_city'             => $city->name,
            'division_id'              => $division->id,
            'internship_type'          => 'online',
            'internship_arrangement'   => 'individual',
            'current_status'           => 'mahasiswa',
            'wfo_readiness'            => 'ready',
            'schedule_shift_readiness' => 'ready',
            'internship_status'        => \App\Models\InternshipRegistration::STATUS_ACCEPTED,
        ]);

        return RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv',
            'file_name'                  => 'CV_Dummy.pdf',
            'original_name'              => 'CV_Dummy.pdf',
            'file_path'                  => 'attachments/' . $reg->id . '/CV_Dummy.pdf',
            'mime_type'                  => 'application/pdf',
            'file_size'                  => 524288,
            'drive_file_id'              => $fakeDriveId,
            'drive_sync_status'          => RegistrationAttachment::STATUS_SYNCED,
        ]);
    }

    /**
     * Test 5: Perintah VERIFIED FOR GAS-06 jika siklus transfer nyata (upload, download, checksum, delete) berhasil 100%.
     */
    public function test_verify_command_fully_verifies_gas06_when_remote_write_succeeds(): void
    {
        $capturedPayload = '';

        Http::fake([
            $this->endpointUrl . '*' => function ($request) use (&$capturedPayload) {
                $body = json_decode($request->body(), true) ?: [];
                $payload = $body['payload'] ?? $body;
                $action = $payload['action'] ?? '';

                if ($action === 'drive_ping') {
                    return Http::response([
                        'success'          => true,
                        'accessible'       => true,
                        'root_folder_name' => 'Sertif_Live_Root',
                    ], 200);
                }

                if ($action === 'drive_upload' || $action === 'upload_file') {
                    $capturedPayload = base64_decode($payload['file_content'] ?? '');
                    return Http::response([
                        'success'         => true,
                        'file_id'         => 'DRIVE_DUMMY_REMOTE_FILE_777',
                        'file_name'       => $payload['file_name'] ?? 'test.txt',
                        'size_bytes'      => strlen($capturedPayload),
                        'checksum_sha256' => hash('sha256', $capturedPayload),
                    ], 200);
                }

                if ($action === 'drive_download_chunk') {
                    return Http::response([
                        'success'         => true,
                        'chunk_index'     => 0,
                        'total_chunks'    => 1,
                        'file_content'    => base64_encode($capturedPayload),
                        'checksum_sha256' => hash('sha256', $capturedPayload),
                    ], 200);
                }

                if ($action === 'drive_trash') {
                    return Http::response([
                        'success' => true,
                        'context' => [
                            'trashed' => true,
                            'file_id' => $payload['file_id'] ?? '',
                        ],
                    ], 200);
                }

                if ($action === 'drive_get_metadata') {
                    $reqFileId = $payload['file_id'] ?? '';
                    if ($reqFileId === 'DRIVE_DUMMY_REMOTE_FILE_777') {
                        return Http::response([
                            'success'  => true,
                            'metadata' => [
                                'id'         => $reqFileId,
                                'name'       => 'test.txt',
                                'size_bytes' => strlen($capturedPayload),
                                'trashed'    => false,
                            ],
                        ], 200);
                    }

                    return Http::response([
                        'success'    => false,
                        'error_code' => 'FILE_NOT_FOUND',
                        'message'    => 'File not found',
                    ], 200);
                }

                return Http::response(['success' => false, 'error_code' => 'UNKNOWN_ACTION'], 400);
            },
        ]);

        $this->artisan('storage:verify-apps-script', ['--allow-remote-write' => true, '--skip-large-files' => true])
            ->expectsOutputToContain('KEPUTUSAN: VERIFIED FOR GAS-06')
            ->expectsOutputToContain('BYTE-IDENTIK (SHA-256 Cocok 100%)')
            ->expectsOutputToContain('aman untuk dibersihkan pada tahap GAS-06')
            ->assertExitCode(Command::SUCCESS);
    }

    /**
     * Test 6: Perintah BLOCKED jika download dummy mengembalikan checksum tidak cocok.
     */
    public function test_verify_command_blocks_when_checksum_mismatched(): void
    {
        Http::fake([
            $this->endpointUrl . '*' => function ($request) {
                $body = json_decode($request->body(), true) ?: [];
                $payload = $body['payload'] ?? $body;
                $action = $payload['action'] ?? '';

                if ($action === 'drive_ping') {
                    return Http::response([
                        'success'          => true,
                        'accessible'       => true,
                        'root_folder_name' => 'Sertif_Live_Root',
                    ], 200);
                }

                if ($action === 'drive_upload' || $action === 'upload_file') {
                    return Http::response([
                        'success'         => true,
                        'file_id'         => 'DRIVE_DUMMY_REMOTE_FILE_777',
                        'file_name'       => 'test.txt',
                        'size_bytes'      => 100,
                        'checksum_sha256' => 'mock_checksum_original',
                    ], 200);
                }

                if ($action === 'drive_download_chunk') {
                    $corrupted = 'CORRUPTED_BYTES_DIFFERENT_PAYLOAD';
                    return Http::response([
                        'success'         => true,
                        'chunk_index'     => 0,
                        'total_chunks'    => 1,
                        'file_content'    => base64_encode($corrupted),
                        'checksum_sha256' => hash('sha256', $corrupted),
                    ], 200);
                }

                if ($action === 'drive_get_metadata') {
                    $reqFileId = $payload['file_id'] ?? '';
                    if ($reqFileId === 'DRIVE_DUMMY_REMOTE_FILE_777') {
                        return Http::response([
                            'success'  => true,
                            'metadata' => [
                                'id'         => $reqFileId,
                                'name'       => 'test.txt',
                                'size_bytes' => strlen($capturedPayload),
                                'trashed'    => false,
                            ],
                        ], 200);
                    }

                    return Http::response([
                        'success'    => false,
                        'error_code' => 'FILE_NOT_FOUND',
                        'message'    => 'File not found',
                    ], 200);
                }

                return Http::response(['success' => false], 400);
            },
        ]);

        $this->artisan('storage:verify-apps-script', ['--allow-remote-write' => true, '--skip-large-files' => true])
            ->expectsOutputToContain('Checksum tidak cocok!')
            ->expectsOutputToContain('KEPUTUSAN: BLOCKED FOR GAS-06')
            ->assertExitCode(Command::FAILURE);
    }

    /**
     * Test 7: Secret kredensial HMAC tidak pernah bocor dalam output console.
     */
    public function test_verify_command_sanitizes_credentials_and_secrets(): void
    {
        Http::fake([
            $this->endpointUrl . '*' => Http::response([
                'success' => true,
                'action'  => 'drive_ping',
                'context' => [
                    'accessible'       => true,
                    'root_folder_name' => 'Sertif_Live_Root',
                ],
            ], 200),
        ]);

        $this->artisan('storage:verify-apps-script')
            ->doesntExpectOutputToContain($this->sharedSecret);
    }

    /**
     * Test 8: Verifikasi transfer bertahap mencakup berkas 5 MB dan 10 MB sesuai batas SERTIF.
     */
    public function test_verify_command_stepped_large_files_5mb_and_10mb(): void
    {
        $capturedPayloads = [];

        Http::fake([
            $this->endpointUrl . '*' => function ($request) use (&$capturedPayloads) {
                $body = json_decode($request->body(), true) ?: [];
                $payload = $body['payload'] ?? $body;
                $action = $payload['action'] ?? '';

                if ($action === 'drive_ping') {
                    return Http::response([
                        'success'          => true,
                        'accessible'       => true,
                        'root_folder_name' => 'Sertif_Live_Root',
                    ], 200);
                }

                if ($action === 'drive_upload' || $action === 'upload_file') {
                    $content = base64_decode($payload['file_content'] ?? '');
                    $fileId = 'DRIVE_FILE_' . md5($payload['file_name'] ?? 'test');
                    $capturedPayloads[$fileId] = [
                        'content'  => $content,
                        'size'     => strlen($content),
                        'checksum' => hash('sha256', $content),
                        'name'     => $payload['file_name'] ?? 'test.bin',
                    ];

                    return Http::response([
                        'success'         => true,
                        'file_id'         => $fileId,
                        'file_name'       => $payload['file_name'] ?? 'test.bin',
                        'size_bytes'      => strlen($content),
                        'checksum_sha256' => hash('sha256', $content),
                    ], 200);
                }

                if ($action === 'drive_get_metadata') {
                    $reqId = $payload['file_id'] ?? '';
                    if (isset($capturedPayloads[$reqId])) {
                        return Http::response([
                            'success'  => true,
                            'metadata' => [
                                'id'         => $reqId,
                                'name'       => $capturedPayloads[$reqId]['name'],
                                'size_bytes' => $capturedPayloads[$reqId]['size'],
                                'trashed'    => false,
                            ],
                        ], 200);
                    }

                    return Http::response([
                        'success'    => false,
                        'error_code' => 'FILE_NOT_FOUND',
                        'message'    => 'Not found',
                    ], 200);
                }

                if ($action === 'drive_download_chunk') {
                    $reqId = $payload['file_id'] ?? '';
                    if (isset($capturedPayloads[$reqId])) {
                        return Http::response([
                            'success'         => true,
                            'chunk_index'     => 0,
                            'total_chunks'    => 1,
                            'file_content'    => base64_encode($capturedPayloads[$reqId]['content']),
                            'checksum_sha256' => $capturedPayloads[$reqId]['checksum'],
                        ], 200);
                    }
                }

                if ($action === 'drive_trash') {
                    return Http::response([
                        'success' => true,
                        'context' => [
                            'trashed' => true,
                            'file_id' => $payload['file_id'] ?? '',
                        ],
                    ], 200);
                }

                return Http::response(['success' => false, 'error_code' => 'UNKNOWN_ACTION'], 400);
            },
        ]);

        $this->artisan('storage:verify-apps-script', ['--allow-remote-write' => true])
            ->expectsOutputToContain('[5.1] Berkas Dummy Kecil (Teks ~1 KB)')
            ->expectsOutputToContain('[5.2] Berkas Uji 5 MB (Batas Standar SERTIF)')
            ->expectsOutputToContain('[5.3] Berkas Uji 10 MB (Batas Maksimum SERTIF)')
            ->expectsOutputToContain('BYTE-IDENTIK (SHA-256 Cocok 100%)')
            ->expectsOutputToContain('KEPUTUSAN: VERIFIED FOR GAS-06')
            ->assertExitCode(Command::SUCCESS);
    }
}

<?php

namespace Tests\Feature\Spreadsheet;

use App\Models\Brand;
use App\Models\FormField;
use App\Models\FormSchema;
use App\Models\HistoricalImportBatch;
use App\Models\InternshipRegistration;
use App\Models\SheetDiscoverySnapshot;
use App\Models\SheetMappingProposal;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetTabProfile;
use App\Models\User;
use App\Services\HistoricalImport\HistoricalImportPipelineService;
use App\Services\SpreadsheetTransport\MockSpreadsheetTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class BrandUniversalIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected MockSpreadsheetTransport $mockTransport;
    protected Brand $brandJogja;
    protected Brand $brandAreaKerja;
    protected SpreadsheetConnection $connectionJogja;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockTransport = new MockSpreadsheetTransport();
        $this->app->instance(\App\Services\SpreadsheetTransport\SpreadsheetTransportInterface::class, $this->mockTransport);

        $this->admin = User::create([
            'name'      => 'Admin Brand Integration',
            'email'     => 'admin.brand.int@seveninc.test',
            'password'  => bcrypt('password123'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);

        $this->brandJogja = Brand::create([
            'name'              => 'Brand Jogja',
            'code'              => 'JOG',
            'slug'              => 'brand-jogja',
            'registration_host' => 'jogja.seveninc.test',
            'is_active'         => true,
            'is_default'        => false,
        ]);

        $this->brandAreaKerja = Brand::create([
            'name'              => 'Brand Area Kerja',
            'code'              => 'AK',
            'slug'              => 'brand-areakerja',
            'registration_host' => 'areakerja.seveninc.test',
            'is_active'         => true,
            'is_default'        => true,
        ]);

        $this->connectionJogja = SpreadsheetConnection::create([
            'connection_key'            => SpreadsheetConnection::KEY_REGISTRATION,
            'brand_id'                  => $this->brandJogja->id,
            'display_name'              => 'Spreadsheet Pendaftaran Brand Jogja',
            'spreadsheet_url'           => 'https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit',
            'spreadsheet_id'            => '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms',
            'is_enabled'                => true,
            'connection_status'         => SpreadsheetConnection::STATUS_CONNECTED,
        ]);
    }

    public function test_form_settings_displays_brand_context_and_spreadsheet_connection(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.form-settings.fields', ['brand_id' => $this->brandJogja->id]));

        $response->assertStatus(200);
        $response->assertSee('jogja.seveninc.test');
        $response->assertSee('Spreadsheet Pendaftaran Brand Jogja');
        $response->assertSee(route('admin.spreadsheet-connections.show', $this->connectionJogja));
    }

    public function test_legacy_import_index_displays_brand_selector_and_connected_spreadsheet(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.legacy-import.index', ['brand_id' => $this->brandJogja->id]));

        $response->assertStatus(200);
        $response->assertSee('Brand Jogja');
        $response->assertSee('jogja.seveninc.test');
        $response->assertSee('Gunakan Spreadsheet Brand Ini');
    }

    public function test_legacy_import_staging_and_commit_scopes_to_brand_and_host(): void
    {
        // 1. Upload CSV to preview
        $csvContent = "Timestamp,Nama Lengkap,Email,No WhatsApp,Universitas\n2026-03-01 10:00:00,Budi Santoso,budi.santoso@example.test,081234567890,UGM\n";
        $file = UploadedFile::fake()->createWithContent('data_jogja.csv', $csvContent);

        $inspectResp = $this->actingAs($this->admin)
            ->post(route('admin.legacy-import.preview'), [
                'import_file' => $file,
            ]);

        $inspectResp->assertStatus(200);
        $inspectData = $inspectResp->json();
        $this->assertTrue($inspectData['success']);

        // 2. Stage batch with brand_id and spreadsheet_connection_id
        $mappingConfig = [
            ['header' => 'Timestamp', 'action' => 'map_existing', 'target_field' => 'created_at', 'data_type' => 'datetime'],
            ['header' => 'Nama Lengkap', 'action' => 'map_existing', 'target_field' => 'fullname', 'data_type' => 'text'],
            ['header' => 'Email', 'action' => 'map_existing', 'target_field' => 'email', 'data_type' => 'email'],
            ['header' => 'No WhatsApp', 'action' => 'map_existing', 'target_field' => 'phone', 'data_type' => 'phone'],
            ['header' => 'Universitas', 'action' => 'historical_only', 'canonical_label' => 'Universitas', 'data_type' => 'text', 'field_key' => 'hist_universitas'],
        ];

        $stageResp = $this->actingAs($this->admin)
            ->postJson(route('admin.legacy-import.stage'), [
                'source_type'               => 'csv',
                'temp_path'                 => $inspectData['temp_path'],
                'source_filename'           => 'data_jogja.csv',
                'column_mapping_config'     => $mappingConfig,
                'default_status'            => 'waiting',
                'brand_id'                  => $this->brandJogja->id,
                'spreadsheet_connection_id' => $this->connectionJogja->id,
            ]);

        $stageResp->assertStatus(200);
        $stageData = $stageResp->json();
        $this->assertTrue($stageData['success']);

        $batchId = $stageData['batch_id'];
        $batch = HistoricalImportBatch::findOrFail($batchId);
        $this->assertEquals($this->brandJogja->id, $batch->brand_id);
        $this->assertEquals($this->connectionJogja->id, $batch->spreadsheet_connection_id);

        // 3. Commit batch
        $commitResp = $this->actingAs($this->admin)
            ->post(route('admin.legacy-import.commit'), [
                'batch_id' => $batchId,
            ]);

        $commitResp->assertRedirect();
        
        $registration = InternshipRegistration::where('email', 'budi.santoso@example.test')->first();
        $this->assertNotNull($registration);
        $this->assertEquals($this->brandJogja->id, $registration->brand_id);
        $this->assertEquals('jogja.seveninc.test', $registration->registration_host);
    }

    public function test_tab_profile_explicit_save_and_proposal_lifecycle_with_custom_header_row(): void
    {
        // 1. Simpan Tab Profile Eksplisit
        $profileResp = $this->actingAs($this->admin)
            ->postJson(route('admin.spreadsheet-connections.tab-profile.save', $this->connectionJogja), [
                'sheet_id'       => 0,
                'tab_name'       => 'Form Pendaftaran Magang',
                'header_row'     => 10,
                'data_start_row' => 11,
            ]);

        $profileResp->assertStatus(200);
        $profile = SpreadsheetTabProfile::where('spreadsheet_connection_id', $this->connectionJogja->id)
            ->where('tab_name', 'Form Pendaftaran Magang')
            ->first();
        $this->assertNotNull($profile);
        $this->assertEquals(10, $profile->header_row);
        $this->assertEquals(11, $profile->data_start_row);

        // 2. Buat Snapshot & Usulan Pemetaan
        $proposalResp = $this->actingAs($this->admin)
            ->postJson(route('admin.spreadsheet-connections.proposals.store', $this->connectionJogja), [
                'tab_name'   => 'Form Pendaftaran Magang',
                'sheet_id'   => 0,
                'header_row' => 10,
                'year'       => 2026,
            ]);

        $proposalResp->assertStatus(200);
        $proposalData = $proposalResp->json();
        $this->assertTrue($proposalData['success']);
        $proposalId = $proposalData['proposal']['id'];

        $proposal = SheetMappingProposal::findOrFail($proposalId);
        $this->assertEquals('draft', $proposal->status);

        // 3. Hapus Usulan Pemetaan (Proposal Destroy)
        $deleteResp = $this->actingAs($this->admin)
            ->deleteJson(route('admin.spreadsheet-connections.proposals.destroy', [$this->connectionJogja, $proposal]));

        $deleteResp->assertStatus(200);
        $this->assertNull(SheetMappingProposal::find($proposalId));
    }

    public function test_proposal_creation_handles_long_header_and_long_match_reason(): void
    {
        $longHeader = "Kapan Anda berencana mulai Magang/PKL? (tulis lengkap tanggal, bulan, tahun) beserta durasi magang Contoh: 21 April 2025 - 21 Juli 2025 (3 Bulan)";

        $this->mockTransport->mockTabs = [
            ['id' => 101, 'name' => 'Form Pendaftaran Magang', 'index' => 0, 'row_count' => 100, 'column_count' => 3, 'header_row' => 10],
        ];

        $this->mockTransport->setMockHeaders('Form Pendaftaran Magang', [
            'Timestamp',
            'Nama Lengkap',
            $longHeader,
        ]);

        $resp = $this->actingAs($this->admin)
            ->postJson(route('admin.spreadsheet-connections.proposals.store', $this->connectionJogja), [
                'tab_name'   => 'Form Pendaftaran Magang',
                'sheet_id'   => 101,
                'header_row' => 10,
                'year'       => 2026,
            ]);

        $resp->assertStatus(200);
        $data = $resp->json();
        $this->assertTrue($data['success']);

        $proposal = SheetMappingProposal::with('items')->findOrFail($data['proposal']['id']);
        $item = $proposal->items->firstWhere('observed_label', $longHeader);
        $this->assertNotNull($item);
        $this->assertNotEmpty($item->match_reason);
    }
}

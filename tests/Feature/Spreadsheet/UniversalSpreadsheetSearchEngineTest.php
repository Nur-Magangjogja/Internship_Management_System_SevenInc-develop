<?php

namespace Tests\Feature\Spreadsheet;

use App\Models\Brand;
use App\Models\InternshipRegistration;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetSearchDocument;
use App\Models\SpreadsheetSearchIndexRun;
use App\Models\User;
use App\Services\SpreadsheetSearch\SpreadsheetIndexingPipelineService;
use App\Services\SpreadsheetSearch\UniversalSpreadsheetSearchService;
use App\Services\SpreadsheetTransport\MockSpreadsheetTransport;
use App\Services\SpreadsheetTransport\SpreadsheetTransportInterface;
use App\Services\SpreadsheetTransport\SpreadsheetTransportResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UniversalSpreadsheetSearchEngineTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected Brand $brandJogja;
    protected Brand $brandAreaKerja;
    protected SpreadsheetConnection $connJogja;
    protected SpreadsheetConnection $connAreaKerja;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::create([
            'name'      => 'Admin Search Tester',
            'email'     => 'admin.search.tester@seveninc.test',
            'password'  => bcrypt('password123'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);

        $this->brandJogja = Brand::create([
            'name'      => 'Magang Jogja',
            'code'      => 'MJ',
            'is_active' => true,
        ]);

        $this->brandAreaKerja = Brand::create([
            'name'      => 'Area Kerja',
            'code'      => 'AK',
            'is_active' => true,
        ]);

        $this->connJogja = SpreadsheetConnection::create([
            'brand_id'                  => $this->brandJogja->id,
            'connection_key'            => SpreadsheetConnection::KEY_REGISTRATION,
            'display_name'              => 'Spreadsheet Pendaftaran (Magang Jogja)',
            'spreadsheet_id'            => 'mock-jogja-sheet-id',
            'spreadsheet_url'           => 'https://docs.google.com/spreadsheets/d/mock-jogja-sheet-id/edit',
            'apps_script_endpoint_url'  => 'https://script.google.com/macros/s/mock-jogja/exec',
            'connection_status'         => SpreadsheetConnection::STATUS_CONNECTED,
            'is_enabled'                => true,
        ]);

        $this->connAreaKerja = SpreadsheetConnection::create([
            'brand_id'                  => $this->brandAreaKerja->id,
            'connection_key'            => SpreadsheetConnection::KEY_REGISTRATION,
            'display_name'              => 'Spreadsheet Pendaftaran (Area Kerja)',
            'spreadsheet_id'            => 'mock-areakerja-sheet-id',
            'spreadsheet_url'           => 'https://docs.google.com/spreadsheets/d/mock-areakerja-sheet-id/edit',
            'apps_script_endpoint_url'  => 'https://script.google.com/macros/s/mock-areakerja/exec',
            'connection_status'         => SpreadsheetConnection::STATUS_CONNECTED,
            'is_enabled'                => true,
        ]);
    }

    public function test_exact_matches_by_sync_id_email_and_phone(): void
    {
        SpreadsheetSearchDocument::create([
            'public_uuid'               => 'doc-uuid-1',
            'brand_id'                  => $this->brandJogja->id,
            'spreadsheet_connection_id' => $this->connJogja->id,
            'tab_name'                  => 'Form Pendaftaran Magang',
            'source_record_key'         => 'SERTIF-2026-0089',
            'source_row_number'         => 89,
            'source_row_fingerprint'    => hash('sha256', 'row-89'),
            'identity_type'             => SpreadsheetSearchDocument::IDENTITY_STABLE,
            'normalized_name'           => 'ahmad fauzi',
            'normalized_email'          => 'fauzi@example.test',
            'normalized_phone'          => '628123456789',
            'raw_phone'                 => '08123456789',
            'searchable_text'           => 'ahmad fauzi fauzi@example.test 08123456789 teknik informatika universitas gadjah mada',
            'searchable_payload'        => [
                'name'        => 'Ahmad Fauzi',
                'email'       => 'fauzi@example.test',
                'phone'       => '08123456789',
                'institution' => 'Universitas Gadjah Mada',
            ],
            'index_generation'          => 1,
        ]);

        $searchService = app(UniversalSpreadsheetSearchService::class);

        // 1. Exact match by SERTIF_SYNC_ID
        $resSync = $searchService->search($this->connJogja, 'SERTIF-2026-0089');
        $this->assertTrue($resSync['success']);
        $this->assertEquals(1, $resSync['total']);
        $this->assertEquals('Ahmad Fauzi', $resSync['results'][0]['display_name']);
        $this->assertEquals('exact_match', $resSync['results'][0]['match_type']);

        // 2. Exact match by Email (case-insensitive)
        $resEmail = $searchService->search($this->connJogja, 'FAUZI@EXAMPLE.TEST');
        $this->assertEquals(1, $resEmail['total']);
        $this->assertEquals('exact_match', $resEmail['results'][0]['match_type']);

        // 3. Exact match by Phone with leading zero
        $resPhone = $searchService->search($this->connJogja, '08123456789');
        $this->assertEquals(1, $resPhone['total']);
        $this->assertEquals('exact_match', $resPhone['results'][0]['match_type']);
    }

    public function test_prefix_and_multi_token_search(): void
    {
        SpreadsheetSearchDocument::create([
            'public_uuid'               => 'doc-uuid-2',
            'brand_id'                  => $this->brandJogja->id,
            'spreadsheet_connection_id' => $this->connJogja->id,
            'tab_name'                  => 'Form Pendaftaran Magang',
            'source_record_key'         => null,
            'source_row_number'         => 120,
            'source_row_fingerprint'    => hash('sha256', 'row-120'),
            'identity_type'             => SpreadsheetSearchDocument::IDENTITY_PROVISIONAL,
            'normalized_name'           => 'siti rahmawati',
            'normalized_email'          => 'siti.rahma@test.id',
            'normalized_phone'          => '628987654321',
            'raw_phone'                 => '08987654321',
            'searchable_text'           => 'siti rahmawati siti.rahma@test.id smk n 2 yogyakarta rekayasa perangkat lunak',
            'searchable_payload'        => [
                'name'        => 'Siti Rahmawati',
                'email'       => 'siti.rahma@test.id',
                'phone'       => '08987654321',
                'institution' => 'SMK N 2 Yogyakarta',
            ],
            'index_generation'          => 1,
        ]);

        $searchService = app(UniversalSpreadsheetSearchService::class);

        // Prefix match on Name
        $resPrefix = $searchService->search($this->connJogja, 'siti');
        $this->assertEquals(1, $resPrefix['total']);
        $this->assertEquals('Siti Rahmawati', $resPrefix['results'][0]['display_name']);

        // Multi-token fulltext match
        $resTokens = $searchService->search($this->connJogja, 'siti yogyakarta');
        $this->assertEquals(1, $resTokens['total']);
    }

    public function test_strict_multi_brand_isolation_cannot_leak_documents(): void
    {
        // Document belonging to Jogja
        SpreadsheetSearchDocument::create([
            'public_uuid'               => 'doc-jogja-1',
            'brand_id'                  => $this->brandJogja->id,
            'spreadsheet_connection_id' => $this->connJogja->id,
            'tab_name'                  => 'Form Pendaftaran Magang',
            'source_row_number'         => 1,
            'source_row_fingerprint'    => hash('sha256', 'jogja-1'),
            'normalized_name'           => 'budi santoso jogja',
            'normalized_email'          => 'budi.jogja@test.id',
            'searchable_text'           => 'budi santoso jogja budi.jogja@test.id',
            'searchable_payload'        => ['name' => 'Budi Santoso Jogja'],
            'index_generation'          => 1,
        ]);

        // Document belonging to Area Kerja
        SpreadsheetSearchDocument::create([
            'public_uuid'               => 'doc-areakerja-1',
            'brand_id'                  => $this->brandAreaKerja->id,
            'spreadsheet_connection_id' => $this->connAreaKerja->id,
            'tab_name'                  => 'Pendaftaran Area Kerja',
            'source_row_number'         => 1,
            'source_row_fingerprint'    => hash('sha256', 'areakerja-1'),
            'normalized_name'           => 'budi santoso areakerja',
            'normalized_email'          => 'budi.areakerja@test.id',
            'searchable_text'           => 'budi santoso areakerja budi.areakerja@test.id',
            'searchable_payload'        => ['name' => 'Budi Santoso AreaKerja'],
            'index_generation'          => 1,
        ]);

        $searchService = app(UniversalSpreadsheetSearchService::class);

        // Searching Jogja must NOT return Area Kerja
        $resJogja = $searchService->search($this->connJogja, 'budi');
        $this->assertEquals(1, $resJogja['total']);
        $this->assertEquals('Budi Santoso Jogja', $resJogja['results'][0]['display_name']);

        // Searching Area Kerja must NOT return Jogja
        $resAreaKerja = $searchService->search($this->connAreaKerja, 'budi');
        $this->assertEquals(1, $resAreaKerja['total']);
        $this->assertEquals('Budi Santoso Areakerja', $resAreaKerja['results'][0]['display_name']);
    }

    public function test_indexing_pipeline_creates_documents_and_promotes_generation(): void
    {
        // Mock transport readRowsPage to return 2 sample rows
        $mockTransport = $this->createMock(SpreadsheetTransportInterface::class);
        $mockTransport->expects($this->once())
            ->method('readRowsPage')
            ->with(
                $this->anything(),
                'Form Pendaftaran Magang',
                $this->anything(),
                1,
                200,
                false, // maskPii = false
                false, // fetchColors = false
                $this->anything()
            )
            ->willReturn(SpreadsheetTransportResponse::success(
                rowIndex: null,
                context: [
                    'total_rows' => 2,
                    'has_more'   => false,
                    'rows'       => [
                        [
                            'row_number' => 11,
                            'sync_id'    => 'SERTIF-2026-SYNC-01',
                            'data'       => [
                                'Nama Lengkap' => 'Indah Permata',
                                'Email'        => 'indah@permata.test',
                                'No WhatsApp'  => '081299998888',
                                'Asal Kampus'  => 'Universitas Brawijaya',
                            ],
                        ],
                        [
                            'row_number' => 12,
                            'sync_id'    => null,
                            'data'       => [
                                'Nama Lengkap' => 'Rian Hidayat',
                                'Email'        => 'rian@hidayat.test',
                                'No WhatsApp'  => '085711112222',
                                'Asal Kampus'  => 'Politeknik Negeri Malang',
                            ],
                        ],
                    ],
                ]
            ));

        $pipeline = new SpreadsheetIndexingPipelineService($mockTransport);

        // Start indexing without dispatching queue so we can control synchronously
        $run = $pipeline->startIndexRun($this->connJogja, 'Form Pendaftaran Magang', sheetId: 101, pageSize: 200, dispatchQueue: false);
        $this->assertEquals(1, $run->index_generation);

        // Process batch
        $batchRes = $pipeline->processPageBatch($run, 1);
        $this->assertTrue($batchRes['success']);
        $this->assertEquals(2, $batchRes['indexed']);
        $this->assertTrue($batchRes['is_completed']);

        // Verify documents are saved in database
        $this->assertEquals(2, SpreadsheetSearchDocument::where('spreadsheet_connection_id', $this->connJogja->id)->count());

        $doc1 = SpreadsheetSearchDocument::where('source_row_number', 11)->first();
        $this->assertNotNull($doc1);
        $this->assertEquals('indah permata', $doc1->normalized_name);
        $this->assertEquals('SERTIF-2026-SYNC-01', $doc1->source_record_key);
        $this->assertEquals(SpreadsheetSearchDocument::IDENTITY_STABLE, $doc1->identity_type);

        $doc2 = SpreadsheetSearchDocument::where('source_row_number', 12)->first();
        $this->assertNotNull($doc2);
        $this->assertEquals('rian hidayat', $doc2->normalized_name);
        $this->assertEquals(SpreadsheetSearchDocument::IDENTITY_PROVISIONAL, $doc2->identity_type);
    }

    public function test_search_api_endpoint_via_http(): void
    {
        SpreadsheetSearchDocument::create([
            'public_uuid'               => 'api-doc-1',
            'brand_id'                  => $this->brandJogja->id,
            'spreadsheet_connection_id' => $this->connJogja->id,
            'tab_name'                  => 'Form Pendaftaran Magang',
            'source_record_key'         => 'SERTIF-HTTP-TEST',
            'source_row_number'         => 50,
            'source_row_fingerprint'    => hash('sha256', 'row-50'),
            'identity_type'             => SpreadsheetSearchDocument::IDENTITY_STABLE,
            'normalized_name'           => 'dian ayu',
            'normalized_email'          => 'dian@ayu.test',
            'normalized_phone'          => '62811223344',
            'raw_phone'                 => '0811223344',
            'searchable_text'           => 'dian ayu dian@ayu.test 0811223344 sertif-http-test',
            'searchable_payload'        => [
                'name'  => 'Dian Ayu',
                'email' => 'dian@ayu.test',
                'phone' => '0811223344',
            ],
            'index_generation'          => 1,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson(route('admin.spreadsheet-connections.search', [
                'spreadsheetConnection' => $this->connJogja->id,
                'q'                     => 'dian',
            ]));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('total', 1);
        $response->assertJsonPath('results.0.display_name', 'Dian Ayu');
        $response->assertJsonPath('results.0.source_record_key', 'SERTIF-HTTP-TEST');
    }
}

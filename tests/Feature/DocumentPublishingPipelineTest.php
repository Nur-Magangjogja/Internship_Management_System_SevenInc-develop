<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\GeneratedDocument;
use App\Models\Institution;
use App\Models\InternshipRegistration;
use App\Models\User;
use App\Services\CloudStorage\LocalStorageFallbackDriver;
use App\Services\DocumentPublishingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class DocumentPublishingPipelineTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Brand $brand;
    private Institution $institution;
    private InternshipRegistration $internAccepted;
    private InternshipRegistration $internCompleted;
    private InternshipRegistration $internPending;
    private string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->unique = uniqid('pub_test_', true);

        $this->admin = User::create([
            'name'     => 'admin_' . substr($this->unique, 0, 8),
            'email'    => 'admin_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
        ]);

        $this->brand = Brand::create([
            'name' => 'Seven Inc ' . $this->unique,
            'code' => 'SEVEN',
        ]);

        $this->institution = Institution::create([
            'name'                => 'Institut Teknologi Bandung',
            'address'             => 'Bandung',
            'phone'               => '0222500935',
            'email'               => 'itb_' . $this->unique . '@test.com',
            'correspondence_unit' => 'Kerjasama',
            'is_active'           => true,
        ]);

        $fac = \App\Models\Faculty::firstOrCreate(['name' => 'Fakultas Teknik ITB ' . $this->unique, 'institution_id' => $this->institution->id]);
        $prog = \App\Models\StudyProgram::firstOrCreate(['name' => 'Teknik Informatika ITB ' . $this->unique, 'faculty_id' => $fac->id]);
        $div = \App\Models\Division::firstOrCreate(['name' => 'Software Dev ' . $this->unique], ['code' => 'DEV', 'is_active' => true]);

        $this->internAccepted = InternshipRegistration::create([
            'user_id'           => $this->admin->id,
            'fullname'          => 'Citra Kirana',
            'student_id'        => '13521099',
            'institution_id'    => $this->institution->id,
            'institution_name'  => $this->institution->name,
            'faculty_id'        => $fac->id,
            'study_program_id'  => $prog->id,
            'study_program'     => 'Teknik Informatika',
            'division_id'       => $div->id,
            'brand_id'          => $this->brand->id,
            'brand'             => $this->brand->name,
            'gender'            => 'Perempuan',
            'born_date'         => '2003-05-12',
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
        ]);

        $this->internCompleted = InternshipRegistration::create([
            'user_id'           => $this->admin->id,
            'fullname'          => 'Doni Salman',
            'student_id'        => '13521088',
            'institution_id'    => $this->institution->id,
            'institution_name'  => $this->institution->name,
            'faculty_id'        => $fac->id,
            'study_program_id'  => $prog->id,
            'study_program'     => 'Sistem Informasi',
            'division_id'       => $div->id,
            'brand_id'          => $this->brand->id,
            'brand'             => $this->brand->name,
            'gender'            => 'Laki-laki',
            'born_date'         => '2002-11-20',
            'internship_status' => InternshipRegistration::STATUS_COMPLETED,
        ]);

        $this->internPending = InternshipRegistration::create([
            'user_id'           => $this->admin->id,
            'fullname'          => 'Eko Purnomo',
            'student_id'        => '13521077',
            'institution_id'    => $this->institution->id,
            'institution_name'  => $this->institution->name,
            'faculty_id'        => $fac->id,
            'study_program_id'  => $prog->id,
            'study_program'     => 'Teknik Elektro',
            'division_id'       => $div->id,
            'brand_id'          => $this->brand->id,
            'brand'             => $this->brand->name,
            'gender'            => 'Laki-laki',
            'born_date'         => '2002-08-15',
            'internship_status' => InternshipRegistration::STATUS_PENDING,
        ]);
    }

    public function test_document_generation_respects_business_status_rules(): void
    {
        /** @var DocumentPublishingService $publisher */
        $publisher = app(DocumentPublishingService::class);

        // Pending intern cannot generate LOA
        $this->expectException(InvalidArgumentException::class);
        $publisher->publishInternDocument(
            intern: $this->internPending,
            documentType: GeneratedDocument::TYPE_LOA,
            htmlContent: '<h1>Draft LOA</h1>'
        );
    }

    public function test_publishing_loa_creates_physical_pdf_and_generated_document_record(): void
    {
        /** @var DocumentPublishingService $publisher */
        $publisher = app(DocumentPublishingService::class);
        $html = "<html><body><h1>Letter of Acceptance</h1><p>Pemagang: {$this->internAccepted->fullname}</p></body></html>";

        $doc = $publisher->publishInternDocument(
            intern: $this->internAccepted,
            documentType: GeneratedDocument::TYPE_LOA,
            htmlContent: $html,
            variableSnapshot: ['pemagang.nama' => $this->internAccepted->fullname],
            assetReferences: ['logo' => 'v1'],
            actor: $this->admin
        );

        $this->assertNotNull($doc);
        $this->assertEquals(GeneratedDocument::TYPE_LOA, $doc->document_type);
        $this->assertEquals($this->internAccepted->id, $doc->internship_registration_id);
        $this->assertTrue(Storage::disk('local')->exists($doc->file_path));
        $this->assertNotEmpty($doc->checksum_sha256);
        $this->assertGreaterThan(0, $doc->file_size_bytes);
        $this->assertContains($doc->status, [GeneratedDocument::STATUS_GENERATED, GeneratedDocument::STATUS_SYNC_PENDING, GeneratedDocument::STATUS_SYNCED]);
    }

    public function test_publishing_pipeline_is_idempotent(): void
    {
        /** @var DocumentPublishingService $publisher */
        $publisher = app(DocumentPublishingService::class);
        $html = "<html><body><h1>Surat Keterangan Lulus</h1><p>Pemagang: {$this->internCompleted->fullname}</p></body></html>";
        $fixedNumber = '001/SKL/DEV/SEVEN.SI/X/2026';

        $doc1 = $publisher->publishInternDocument(
            intern: $this->internCompleted,
            documentType: GeneratedDocument::TYPE_SKL,
            htmlContent: $html,
            customDocNumber: $fixedNumber,
            actor: $this->admin
        );

        // Simulate synced status
        $doc1->update(['status' => GeneratedDocument::STATUS_SYNCED]);

        $doc2 = $publisher->publishInternDocument(
            intern: $this->internCompleted,
            documentType: GeneratedDocument::TYPE_SKL,
            htmlContent: $html,
            customDocNumber: $fixedNumber,
            actor: $this->admin
        );

        // Should return the exact same instance without creating duplicate records
        $this->assertEquals($doc1->id, $doc2->id);
        $this->assertEquals(1, GeneratedDocument::where('document_number', $fixedNumber)->count());
    }
}

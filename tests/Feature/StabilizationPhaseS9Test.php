<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\FollowUpCycle;
use App\Models\Institution;
use App\Models\InstitutionContact;
use App\Models\Mou;
use App\Models\MouTemplate;
use App\Models\User;
use App\Services\MouService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StabilizationPhaseS9Test extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $hrStaff;
    private User $pemagang;
    private Institution $institution;
    private FollowUpCycle $cycle;
    private MouTemplate $template;
    private string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->unique = uniqid('s9_', true);

        $this->admin = User::create([
            'name'     => 'admin_' . substr($this->unique, 0, 8),
            'email'    => 'admin_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
        ]);

        $this->hrStaff = User::create([
            'name'     => 'hr_' . substr($this->unique, 0, 8),
            'email'    => 'hr_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'hr_staff',
        ]);

        $this->pemagang = User::create([
            'name'     => 'pemagang_' . substr($this->unique, 0, 8),
            'email'    => 'pemagang_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'pemagang',
        ]);

        $this->institution = Institution::create([
            'name'                 => 'Institut Teknologi S9 ' . $this->unique,
            'address'              => 'Jl. Ring Road Utara No. 1, Sleman',
            'phone'                => '0274-998877',
            'email'                => 'kampus_' . $this->unique . '@it-s9.ac.id',
            'correspondence_unit'  => 'Bagian Kemitraan & Magang',
            'is_active'            => true,
        ]);

        $contact = InstitutionContact::create([
            'institution_id' => $this->institution->id,
            'name'           => 'Bambang Sudibyo, M.Kom.',
            'position_title' => 'Ketua Jurusan Sistem Informasi',
            'phone'          => '081234567890',
            'email'          => 'bambang@it-s9.ac.id',
            'is_active'      => true,
        ]);

        $this->cycle = FollowUpCycle::create([
            'institution_id'         => $this->institution->id,
            'institution_contact_id' => $contact->id,
            'status'                 => FollowUpCycle::STATUS_COMPLETED,
            'final_result'           => FollowUpCycle::RESULT_WILLING,
            'cycle_year'             => (int) date('Y'),
        ]);

        $this->template = MouTemplate::create([
            'name'                 => 'Template Baku S9 ' . $this->unique,
            'version_number'       => 1,
            'template_html'        => '<h1>MOU [company_name] & [institution_name]</h1><p>Nomor: [company_mou_number]</p><p>Tempat: [agreement_place]</p>',
            'allowed_placeholders' => ['company_name', 'institution_name', 'company_mou_number', 'agreement_place'],
            'default_values'       => [
                'company_name'           => 'SEVEN INC',
                'company_signatory_name' => 'Rekso Pamungkas',
                'agreement_place'        => 'Yogyakarta',
            ],
            'is_active'            => true,
            'created_by'           => $this->admin->id,
        ]);
    }

    /**
     * Test 1: Pembuatan MOU tanpa konfirmasi kerjasama (cooperation_confirmed = false) ditolak.
     */
    public function test_generating_mou_without_cooperation_confirmation_is_rejected(): void
    {
        $mouService = app(MouService::class);

        $payload = [
            'institution_id'        => $this->institution->id,
            'followup_cycle_id'     => $this->cycle->id,
            'company_mou_number'    => 'MOU/7INC/S9/NO-CONF/' . substr($this->unique, 0, 6),
            'cooperation_confirmed' => false,
            'agreement_place'       => 'Yogyakarta',
            'agreement_date'        => now()->toDateString(),
            'effective_start'       => now()->toDateString(),
            'effective_end'         => now()->addYears(2)->toDateString(),
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Konfirmasi kesediaan kerja sama institusi diperlukan');

        $mouService->generateMou($payload, $this->hrStaff);
    }

    /**
     * Test 2: Validasi relasi follow-up cycle (mismatched institution atau hasil belum completed & willing).
     */
    public function test_generating_mou_with_mismatched_or_uncompleted_followup_cycle_is_rejected(): void
    {
        $mouService = app(MouService::class);

        // 1. Institusi lain
        $otherInstitution = Institution::create([
            'name'      => 'Universitas Lain ' . $this->unique,
            'address'   => 'Jl. Kaliurang KM 10',
            'phone'     => '0274-112233',
            'email'     => 'lain_' . $this->unique . '@test.com',
            'is_active' => true,
        ]);

        $otherContact = InstitutionContact::create([
            'institution_id' => $otherInstitution->id,
            'name'           => 'Dr. Bambang Lain',
            'position_title' => 'Dekan',
            'phone'          => '081234567899',
            'email'          => 'bambang_lain@test.com',
            'is_active'      => true,
        ]);

        $cycleOther = FollowUpCycle::create([
            'institution_id'         => $otherInstitution->id,
            'institution_contact_id' => $otherContact->id,
            'status'                 => FollowUpCycle::STATUS_COMPLETED,
            'final_result'           => FollowUpCycle::RESULT_WILLING,
            'cycle_year'             => (int) date('Y'),
        ]);

        // Coba pakai cycle institusi lain untuk target institution
        try {
            $mouService->generateMou([
                'institution_id'     => $this->institution->id,
                'followup_cycle_id'  => $cycleOther->id,
                'company_mou_number' => 'MOU/7INC/S9/MISMATCH/' . substr($this->unique, 0, 6),
                'agreement_place'    => 'Yogyakarta',
                'agreement_date'     => now()->toDateString(),
            ], $this->hrStaff);
            $this->fail('Harus gagal karena follow-up cycle bukan milik institusi target.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('tidak sesuai dengan institusi target', $e->getMessage());
        }

        // 2. Siklus milik institusi target tapi belum selesai atau belum bersedia (call_back)
        $targetContact2 = InstitutionContact::create([
            'institution_id' => $this->institution->id,
            'name'           => 'Kontak Kedua S9',
            'position_title' => 'Sekretaris Jurusan',
            'phone'          => '081234567891',
            'email'          => 'kontak2_' . $this->unique . '@it-s9.ac.id',
            'is_active'      => true,
        ]);

        $uncompletedCycle = FollowUpCycle::create([
            'institution_id'         => $this->institution->id,
            'institution_contact_id' => $targetContact2->id,
            'status'                 => FollowUpCycle::STATUS_IN_PROGRESS,
            'final_result'           => FollowUpCycle::RESULT_CALL_BACK,
            'cycle_year'             => (int) date('Y'),
        ]);

        try {
            $mouService->generateMou([
                'institution_id'     => $this->institution->id,
                'followup_cycle_id'  => $uncompletedCycle->id,
                'company_mou_number' => 'MOU/7INC/S9/UNCOMPLETED/' . substr($this->unique, 0, 6),
                'agreement_place'    => 'Yogyakarta',
                'agreement_date'     => now()->toDateString(),
            ], $this->hrStaff);
            $this->fail('Harus gagal karena siklus belum selesai dan bersedia.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('harus berstatus selesai dengan hasil bersedia (willing)', $e->getMessage());
        }
    }

    /**
     * Test 3: Active MOU Guard memblokir pembuatan MOU baru jika ada MOU aktif yang masih berlaku, kecuali supersede resmi.
     * MOU lama tetap ACTIVE sampai MOU baru diaktifkan dengan berkas bertandatangan.
     */
    public function test_generating_mou_for_institution_with_active_mou_is_blocked_unless_explicit_replacement(): void
    {
        $mouService = app(MouService::class);

        // Buat MOU aktif yang berlaku sampai tahun depan
        $activeMou = Mou::create([
            'institution_id'               => $this->institution->id,
            'company_mou_number'           => 'MOU/7INC/S9/ACTIVE/' . substr($this->unique, 0, 6),
            'first_party_institution_name' => $this->institution->name,
            'first_party_signatory_name'   => 'Bambang Sudibyo',
            'first_party_signatory_title'  => 'Ketua Jurusan',
            'second_party_company_name'    => 'SEVEN INC',
            'second_party_signatory_name'  => 'Rekso Pamungkas',
            'second_party_signatory_title' => 'Direktur Utama',
            'agreement_place'              => 'Yogyakarta',
            'agreement_date'               => now()->subMonths(6)->toDateString(),
            'effective_start'              => now()->subMonths(6)->toDateString(),
            'effective_end'                => now()->addMonths(18)->toDateString(),
            'status'                       => Mou::STATUS_ACTIVE,
            'signed_at'                    => now()->subMonths(6),
        ]);

        // Coba generate MOU baru tanpa flag penggantian (supersedes_mou_id)
        try {
            $mouService->generateMou([
                'institution_id'     => $this->institution->id,
                'followup_cycle_id'  => $this->cycle->id,
                'company_mou_number' => 'MOU/7INC/S9/NEW-TRY/' . substr($this->unique, 0, 6),
                'agreement_place'    => 'Yogyakarta',
                'agreement_date'     => now()->toDateString(),
                'effective_start'    => now()->toDateString(),
                'effective_end'      => now()->addYears(2)->toDateString(),
            ], $this->hrStaff);
            $this->fail('Harus gagal karena institusi sudah memiliki MOU aktif.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('sudah memiliki MOU aktif yang masih berlaku', $e->getMessage());
        }

        // Coba generate DENGAN menyertakan supersedes_mou_id -> harus berhasil
        $replacementMou = $mouService->generateMou([
            'institution_id'     => $this->institution->id,
            'followup_cycle_id'  => $this->cycle->id,
            'company_mou_number' => 'MOU/7INC/S9/REPLACEMENT/' . substr($this->unique, 0, 6),
            'supersedes_mou_id'  => $activeMou->id,
            'supersede_reason'   => 'Perpanjangan kemitraan lebih awal untuk penambahan kuota magang.',
            'agreement_place'    => 'Yogyakarta',
            'agreement_date'     => now()->toDateString(),
            'effective_start'    => now()->toDateString(),
            'effective_end'      => now()->addYears(3)->toDateString(),
        ], $this->hrStaff);

        $this->assertNotNull($replacementMou);
        $this->assertEquals(Mou::STATUS_GENERATED, $replacementMou->status);
        $this->assertEquals($activeMou->id, $replacementMou->supersedes_mou_id);

        // Verifikasi MOU lama TETAP ACTIVE saat naskah baru masih berstatus generated
        $activeMou->refresh();
        $this->assertEquals(Mou::STATUS_ACTIVE, $activeMou->status);

        // Setelah naskah baru diserahkan (delivered) dan diunggah naskah bertandatangan, MOU lama baru menjadi SUPERSEDED
        $mouService->markDelivered($replacementMou, [
            'delivery_method' => 'email',
            'delivered_at'    => now(),
        ], $this->hrStaff);

        $pdfFile = UploadedFile::fake()->create('signed_replacement.pdf', 300, 'application/pdf');
        $mouService->uploadSignedMou($replacementMou, $pdfFile, [
            'signed_at' => now()->toDateString(),
        ], $this->hrStaff);

        $replacementMou->refresh();
        $activeMou->refresh();

        $this->assertEquals(Mou::STATUS_ACTIVE, $replacementMou->status);
        $this->assertEquals(Mou::STATUS_SUPERSEDED, $activeMou->status);
        $this->assertEquals($replacementMou->id, $activeMou->superseded_by_id);
    }

    /**
     * Test 4: Institusi dengan MOU kadaluarsa (expired) atau tanpa MOU diizinkan generate normal.
     */
    public function test_expired_or_no_mou_allows_normal_generation(): void
    {
        $mouService = app(MouService::class);

        // Buat institusi dengan MOU lama yang sudah kedaluwarsa (effective_end di masa lalu)
        $expiredInstitution = Institution::create([
            'name'      => 'Kampus Expired ' . $this->unique,
            'address'   => 'Jl. Veteran No. 5',
            'phone'     => '0274-554433',
            'email'     => 'expired_' . $this->unique . '@test.com',
            'is_active' => true,
        ]);

        $expContact = InstitutionContact::create([
            'institution_id' => $expiredInstitution->id,
            'name'           => 'Pejabat Lama',
            'position_title' => 'Dekan',
            'phone'          => '081234567000',
            'email'          => 'exp_contact@test.com',
            'is_active'      => true,
        ]);

        $expCycle = FollowUpCycle::create([
            'institution_id'         => $expiredInstitution->id,
            'institution_contact_id' => $expContact->id,
            'status'                 => FollowUpCycle::STATUS_COMPLETED,
            'final_result'           => FollowUpCycle::RESULT_WILLING,
            'cycle_year'             => (int) date('Y'),
        ]);

        Mou::create([
            'institution_id'               => $expiredInstitution->id,
            'company_mou_number'           => 'MOU/7INC/S9/EXP/' . substr($this->unique, 0, 6),
            'first_party_institution_name' => $expiredInstitution->name,
            'first_party_signatory_name'   => 'Pejabat Lama',
            'first_party_signatory_title'  => 'Dekan',
            'second_party_company_name'    => 'SEVEN INC',
            'second_party_signatory_name'  => 'Rekso Pamungkas',
            'second_party_signatory_title' => 'Direktur Utama',
            'agreement_place'              => 'Yogyakarta',
            'agreement_date'               => now()->subYears(3)->toDateString(),
            'effective_start'              => now()->subYears(3)->toDateString(),
            'effective_end'                => now()->subDays(10)->toDateString(), // Sudah lewat!
            'status'                       => Mou::STATUS_EXPIRED,
        ]);

        // Generate normal tanpa supersede harus sukses
        $newMou = $mouService->generateMou([
            'institution_id'     => $expiredInstitution->id,
            'followup_cycle_id'  => $expCycle->id,
            'company_mou_number' => 'MOU/7INC/S9/FRESH/' . substr($this->unique, 0, 6),
            'agreement_place'    => 'Yogyakarta',
            'agreement_date'     => now()->toDateString(),
            'effective_start'    => now()->toDateString(),
            'effective_end'      => now()->addYears(2)->toDateString(),
        ], $this->hrStaff);

        $this->assertNotNull($newMou);
        $this->assertEquals(Mou::STATUS_GENERATED, $newMou->status);
    }

    /**
     * Test 5: Template HTML memangkas script tags, inline event handler, dan javascript URLs secara ketat.
     */
    public function test_mou_template_html_strips_script_tags_and_inline_event_handlers_and_javascript_urls(): void
    {
        $maliciousHtml = <<<'HTML'
<div>
    <h1>MOU Kerjasama [company_name]</h1>
    <script>alert('xss-danger');</script>
    <script type="text/javascript">window.location="http://evil.com";</script>
    <iframe src="https://evil.com/phishing"></iframe>
    <a href="javascript:alert(document.cookie)">Klik Disini</a>
    <img src="avatar.jpg" onerror="alert('img-error')" onload="evilHandler()">
    <p onclick="stealData()">Paragraf Normal</p>
</div>
HTML;

        $template = MouTemplate::create([
            'name'                 => 'XSS Test Template ' . $this->unique,
            'version_number'       => 2,
            'template_html'        => $maliciousHtml,
            'allowed_placeholders' => ['company_name'],
            'is_active'            => true,
            'created_by'           => $this->admin->id,
        ]);

        // Verifikasi pada template_html di basis data
        $this->assertStringNotContainsString('<script', $template->template_html);
        $this->assertStringNotContainsString('xss-danger', $template->template_html);
        $this->assertStringNotContainsString('<iframe', $template->template_html);
        $this->assertStringNotContainsString('javascript:', $template->template_html);
        $this->assertStringNotContainsString('onerror=', $template->template_html);
        $this->assertStringNotContainsString('onload=', $template->template_html);
        $this->assertStringNotContainsString('onclick=', $template->template_html);

        // Verifikasi pada renderContent
        $rendered = $template->renderContent(['company_name' => 'SEVEN INC SAFE']);
        $this->assertStringNotContainsString('<script', $rendered);
        $this->assertStringNotContainsString('onerror=', $rendered);
        $this->assertStringNotContainsString('javascript:', $rendered);
        $this->assertStringContainsString('SEVEN INC SAFE', $rendered);
    }

    /**
     * Test 6: Status 'generated' hanya diberikan setelah berkas fisik benar-benar ada dan path tersimpan di basis data.
     */
    public function test_generated_status_only_after_file_actually_exists_and_path_stored(): void
    {
        $mouService = app(MouService::class);
        $companyNumber = 'MOU/7INC/S9/PHYSICAL/' . substr($this->unique, 0, 6);

        $mou = $mouService->generateMou([
            'institution_id'     => $this->institution->id,
            'followup_cycle_id'  => $this->cycle->id,
            'company_mou_number' => $companyNumber,
            'agreement_place'    => 'Yogyakarta',
            'agreement_date'     => now()->toDateString(),
            'effective_start'    => now()->toDateString(),
            'effective_end'      => now()->addYears(2)->toDateString(),
        ], $this->hrStaff);

        $this->assertEquals(Mou::STATUS_GENERATED, $mou->status);
        $this->assertNotNull($mou->generated_file_path);

        // Pastikan berkas fisik benar-benar ada di storage disk public
        Storage::disk('public')->assertExists($mou->generated_file_path);

        // Pastikan isi berkas berisi naskah render
        $fileContent = Storage::disk('public')->get($mou->generated_file_path);
        $this->assertStringContainsString($companyNumber, $fileContent);
        $this->assertStringContainsString($this->institution->name, $fileContent);
    }

    /**
     * Test 7: Membuka pratinjau (preview / print) tidak mengubah status dokumen MOU.
     */
    public function test_preview_alone_does_not_mark_mou_as_generated(): void
    {
        // Buat MOU draf awal
        $mou = Mou::create([
            'institution_id'               => $this->institution->id,
            'company_mou_number'           => 'MOU/7INC/S9/DRAFT/' . substr($this->unique, 0, 6),
            'first_party_institution_name' => $this->institution->name,
            'first_party_signatory_name'   => 'Bambang Sudibyo',
            'first_party_signatory_title'  => 'Ketua Jurusan',
            'second_party_company_name'    => 'SEVEN INC',
            'second_party_signatory_name'  => 'Rekso Pamungkas',
            'second_party_signatory_title' => 'Direktur Utama',
            'agreement_place'              => 'Yogyakarta',
            'agreement_date'               => now()->toDateString(),
            'status'                       => Mou::STATUS_DRAFT,
        ]);

        $this->assertEquals(Mou::STATUS_DRAFT, $mou->status);

        // Buka halaman print / pratinjau
        $response = $this->actingAs($this->hrStaff)->get(route('hr.mous.print', $mou));
        $response->assertStatus(200);

        // Status harus tetap DRAFT, tidak otomatis menjadi GENERATED
        $mou->refresh();
        $this->assertEquals(Mou::STATUS_DRAFT, $mou->status);
    }

    /**
     * Test 8: Upload signed MOU mengubah status menjadi active setelah delivered, mempertahankan berkas generated awal, dan menandai MOU lama superseded.
     */
    public function test_uploading_signed_mou_activates_mou_and_retains_original_generated_file_and_supersedes_old_active_mou(): void
    {
        $mouService = app(MouService::class);

        // 1. Buat MOU lama yang berstatus active
        $oldMou = Mou::create([
            'institution_id'               => $this->institution->id,
            'company_mou_number'           => 'MOU/7INC/S9/OLD-ACT/' . substr($this->unique, 0, 6),
            'first_party_institution_name' => $this->institution->name,
            'first_party_signatory_name'   => 'Bambang Sudibyo',
            'first_party_signatory_title'  => 'Ketua Jurusan',
            'second_party_company_name'    => 'SEVEN INC',
            'second_party_signatory_name'  => 'Rekso Pamungkas',
            'second_party_signatory_title' => 'Direktur Utama',
            'agreement_place'              => 'Yogyakarta',
            'agreement_date'               => now()->subYear()->toDateString(),
            'effective_start'              => now()->subYear()->toDateString(),
            'effective_end'                => now()->addYear()->toDateString(),
            'status'                       => Mou::STATUS_ACTIVE,
        ]);

        // 2. Generate MOU baru dengan explicit supersede
        $newMou = $mouService->generateMou([
            'institution_id'     => $this->institution->id,
            'followup_cycle_id'  => $this->cycle->id,
            'company_mou_number' => 'MOU/7INC/S9/NEW-SIGNED/' . substr($this->unique, 0, 6),
            'supersedes_mou_id'  => $oldMou->id,
            'agreement_place'    => 'Yogyakarta',
            'agreement_date'     => now()->toDateString(),
            'effective_start'    => now()->toDateString(),
            'effective_end'      => now()->addYears(2)->toDateString(),
        ], $this->hrStaff);

        $initialGeneratedPath = $newMou->generated_file_path;
        $this->assertNotNull($initialGeneratedPath);
        Storage::disk('public')->assertExists($initialGeneratedPath);

        // Tandai delivered terlebih dahulu (Blocker 2 sequence requirement)
        $mouService->markDelivered($newMou, [
            'delivery_method' => 'kurir',
            'delivered_at'    => now(),
        ], $this->hrStaff);

        $this->assertEquals(Mou::STATUS_DELIVERED, $newMou->status);

        // 3. Upload signed PDF file via route
        $pdfFile = UploadedFile::fake()->create('naskah_mou_final.pdf', 300, 'application/pdf');

        $uploadResponse = $this->actingAs($this->hrStaff)->post(route('hr.mous.upload-signed', $newMou), [
            'signed_file'            => $pdfFile,
            'signed_at'              => now()->toDateString(),
            'institution_mou_number' => '099/IT-S9/MOU/2026',
        ]);

        $uploadResponse->assertStatus(302);
        $uploadResponse->assertSessionHas('success');

        $newMou->refresh();
        $oldMou->refresh();

        // New MOU status menjadi ACTIVE
        $this->assertEquals(Mou::STATUS_ACTIVE, $newMou->status);
        $this->assertNotNull($newMou->signed_file_path);
        Storage::disk('public')->assertExists($newMou->signed_file_path);

        // Berkas awal naskah generated tetap utuh dan tersimpan di path aslinya
        $this->assertEquals($initialGeneratedPath, $newMou->generated_file_path);
        Storage::disk('public')->assertExists($initialGeneratedPath);

        // Old MOU berstatus SUPERSEDED
        $this->assertEquals(Mou::STATUS_SUPERSEDED, $oldMou->status);
        $this->assertEquals($newMou->id, $oldMou->superseded_by_id);
    }

    /**
     * Test 9: HR staff dilarang memodifikasi template naskah baku / layout pasal (403 Forbidden).
     */
    public function test_hr_staff_cannot_edit_mou_template_pasal_or_layout(): void
    {
        // HR mencoba akses halaman create template
        $responseCreate = $this->actingAs($this->hrStaff)->get(route('admin.mou-templates.create'));
        $this->assertTrue(in_array($responseCreate->status(), [403, 302]));

        // HR mencoba akses halaman edit template
        $responseEdit = $this->actingAs($this->hrStaff)->get(route('admin.mou-templates.edit', $this->template));
        $this->assertTrue(in_array($responseEdit->status(), [403, 302]));

        // HR mencoba kirim request update pasal / template
        $responseUpdate = $this->actingAs($this->hrStaff)->put(route('admin.mou-templates.update', $this->template), [
            'name'          => 'Template Diretas',
            'template_html' => '<p>Pasal baru diubah HR</p>',
        ]);
        $this->assertTrue(in_array($responseUpdate->status(), [403, 302]));
    }

    /**
     * Test 10: Strict Signed Activation Sequence (Blocker 2).
     * Upload signed HANYA boleh dari status 'delivered', menolak draft, generated, cancelled, superseded, expired, active.
     */
    public function test_strict_signed_activation_sequence_rejects_non_delivered_statuses(): void
    {
        $mouService = app(MouService::class);
        $pdfFile = UploadedFile::fake()->create('test_sign.pdf', 200, 'application/pdf');

        $nonDeliveredStatuses = [
            Mou::STATUS_DRAFT,
            Mou::STATUS_GENERATED,
            Mou::STATUS_CANCELLED,
            Mou::STATUS_SUPERSEDED,
            Mou::STATUS_EXPIRED,
            Mou::STATUS_ACTIVE,
        ];

        foreach ($nonDeliveredStatuses as $invalidStatus) {
            $mou = Mou::create([
                'institution_id'               => $this->institution->id,
                'company_mou_number'           => 'MOU/7INC/S9/SEQ-' . strtoupper($invalidStatus) . '/' . substr($this->unique, 0, 6),
                'first_party_institution_name' => $this->institution->name,
                'first_party_signatory_name'   => 'Bambang',
                'first_party_signatory_title'  => 'Ketua Jurusan',
                'second_party_company_name'    => 'SEVEN INC',
                'second_party_signatory_name'  => 'Rekso',
                'second_party_signatory_title' => 'Direktur',
                'agreement_place'              => 'Yogyakarta',
                'agreement_date'               => now()->toDateString(),
                'status'                       => $invalidStatus,
            ]);

            try {
                $mouService->uploadSignedMou($mou, $pdfFile, ['signed_at' => now()->toDateString()], $this->hrStaff);
                $this->fail("Status '{$invalidStatus}' seharusnya ditolak untuk upload signed.");
            } catch (\DomainException $e) {
                $this->assertStringContainsString("Hanya dokumen MOU dengan status 'delivered'", $e->getMessage());
            }

            // Status tidak berubah
            $mou->refresh();
            $this->assertEquals($invalidStatus, $mou->status);
            $this->assertNull($mou->signed_file_path);
        }
    }

    /**
     * Test 11: Guardrail 1 - Active MOU Race Safety.
     * Mencegah aktivasi jika terjadi race condition di mana MOU aktif lain telah muncul untuk institusi yang sama.
     */
    public function test_guardrail_1_active_mou_race_safety_prevents_conflicting_active_mou(): void
    {
        $mouService = app(MouService::class);

        // Buat MOU 1 yang sudah DELIVERED (tanpa supersede)
        $mou1 = Mou::create([
            'institution_id'               => $this->institution->id,
            'company_mou_number'           => 'MOU/7INC/S9/RACE-1/' . substr($this->unique, 0, 6),
            'first_party_institution_name' => $this->institution->name,
            'first_party_signatory_name'   => 'Bambang',
            'first_party_signatory_title'  => 'Ketua Jurusan',
            'second_party_company_name'    => 'SEVEN INC',
            'second_party_signatory_name'  => 'Rekso',
            'second_party_signatory_title' => 'Direktur',
            'agreement_place'              => 'Yogyakarta',
            'agreement_date'               => now()->toDateString(),
            'status'                       => Mou::STATUS_DELIVERED,
        ]);

        // Simulasikan MOU aktif lain sudah ada untuk institusi yang sama
        $competingActiveMou = Mou::create([
            'institution_id'               => $this->institution->id,
            'company_mou_number'           => 'MOU/7INC/S9/RACE-COMPETE/' . substr($this->unique, 0, 6),
            'first_party_institution_name' => $this->institution->name,
            'first_party_signatory_name'   => 'Bambang',
            'first_party_signatory_title'  => 'Ketua Jurusan',
            'second_party_company_name'    => 'SEVEN INC',
            'second_party_signatory_name'  => 'Rekso',
            'second_party_signatory_title' => 'Direktur',
            'agreement_place'              => 'Yogyakarta',
            'agreement_date'               => now()->toDateString(),
            'status'                       => Mou::STATUS_ACTIVE,
            'signed_at'                    => now(),
        ]);

        $pdfFile = UploadedFile::fake()->create('race_sign.pdf', 200, 'application/pdf');

        // Mencoba aktifkan mou1 tanpa supersede harus ditolak karena competingActiveMou aktif
        try {
            $mouService->uploadSignedMou($mou1, $pdfFile, ['signed_at' => now()->toDateString()], $this->hrStaff);
            $this->fail('Harus gagal karena institusi sudah memiliki MOU aktif.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('telah memiliki MOU aktif yang berlaku', $e->getMessage());
        }

        $mou1->refresh();
        $this->assertEquals(Mou::STATUS_DELIVERED, $mou1->status);
    }

    /**
     * Test 12: Guardrail 2 - Signed File Storage Cleanup on Activation Failure.
     * Jika aktivasi gagal setelah file disimpan, file fisik PDF baru WAJIB dihapus dari storage.
     */
    public function test_guardrail_2_signed_file_storage_cleanup_on_activation_failure(): void
    {
        $mouService = app(MouService::class);

        // Buat MOU target yang sudah tidak aktif (misal expired)
        $inactiveOldMou = Mou::create([
            'institution_id'               => $this->institution->id,
            'company_mou_number'           => 'MOU/7INC/S9/INACT-OLD/' . substr($this->unique, 0, 6),
            'first_party_institution_name' => $this->institution->name,
            'first_party_signatory_name'   => 'Bambang',
            'first_party_signatory_title'  => 'Ketua Jurusan',
            'second_party_company_name'    => 'SEVEN INC',
            'second_party_signatory_name'  => 'Rekso',
            'second_party_signatory_title' => 'Direktur',
            'agreement_place'              => 'Yogyakarta',
            'agreement_date'               => now()->toDateString(),
            'status'                       => Mou::STATUS_EXPIRED,
        ]);

        // Buat MOU baru dengan supersedes_mou_id mengarah ke inactiveOldMou
        $mou = Mou::create([
            'institution_id'               => $this->institution->id,
            'company_mou_number'           => 'MOU/7INC/S9/CLEANUP/' . substr($this->unique, 0, 6),
            'first_party_institution_name' => $this->institution->name,
            'first_party_signatory_name'   => 'Bambang',
            'first_party_signatory_title'  => 'Ketua Jurusan',
            'second_party_company_name'    => 'SEVEN INC',
            'second_party_signatory_name'  => 'Rekso',
            'second_party_signatory_title' => 'Direktur',
            'agreement_place'              => 'Yogyakarta',
            'agreement_date'               => now()->toDateString(),
            'status'                       => Mou::STATUS_DELIVERED,
            'supersedes_mou_id'            => $inactiveOldMou->id,
        ]);

        $pdfFile = UploadedFile::fake()->create('cleanup_test.pdf', 300, 'application/pdf');

        try {
            $mouService->uploadSignedMou($mou, $pdfFile, ['signed_at' => now()->toDateString()], $this->hrStaff);
            $this->fail('Harus gagal karena target supersede tidak berstatus aktif.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('tidak berstatus aktif', $e->getMessage());
        }

        // Verifikasi tidak ada berkas orphan tersimpan di disk public
        $allFiles = Storage::disk('public')->allFiles('documents/mou/signed');
        $this->assertEmpty($allFiles, 'Berkas signed PDF harus dibersihkan (dihapus) jika aktivasi gagal.');

        $mou->refresh();
        $this->assertEquals(Mou::STATUS_DELIVERED, $mou->status);
        $this->assertNull($mou->signed_file_path);
    }

    /**
     * Test 13: Blocker 1 - Admin dilarang bypass prasyarat siklus follow-up.
     */
    public function test_blocker_1_admin_cannot_bypass_followup_cycle_prerequisite(): void
    {
        $mouService = app(MouService::class);

        // 1. Admin tanpa cycle
        try {
            $mouService->generateMou([
                'institution_id'     => $this->institution->id,
                'company_mou_number' => 'MOU/7INC/S9/ADMIN-NO-CYC/' . substr($this->unique, 0, 6),
                'agreement_place'    => 'Yogyakarta',
                'agreement_date'     => now()->toDateString(),
            ], $this->admin);
            $this->fail('Admin harus ditolak jika follow-up cycle tidak disertakan.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Siklus follow-up yang valid dan selesai dengan kesediaan kerja sama', $e->getMessage());
        }

        // 2. Admin dengan cycle declined
        $declinedContact = InstitutionContact::create([
            'institution_id' => $this->institution->id,
            'name'           => 'Kontak Menolak S9',
            'position_title' => 'Wadek III',
            'phone'          => '081234567899',
            'email'          => 'menolak_' . $this->unique . '@it-s9.ac.id',
            'is_active'      => true,
        ]);

        $declinedCycle = FollowUpCycle::create([
            'institution_id'         => $this->institution->id,
            'institution_contact_id' => $declinedContact->id,
            'status'                 => FollowUpCycle::STATUS_COMPLETED,
            'final_result'           => FollowUpCycle::RESULT_DECLINED,
            'cycle_year'             => (int) date('Y'),
        ]);

        try {
            $mouService->generateMou([
                'institution_id'     => $this->institution->id,
                'followup_cycle_id'  => $declinedCycle->id,
                'company_mou_number' => 'MOU/7INC/S9/ADMIN-DECLINED/' . substr($this->unique, 0, 6),
                'agreement_place'    => 'Yogyakarta',
                'agreement_date'     => now()->toDateString(),
            ], $this->admin);
            $this->fail('Admin harus ditolak jika cycle tidak bersedia (declined).');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('harus berstatus selesai dengan hasil bersedia (willing)', $e->getMessage());
        }
    }

    /**
     * Test 14: Template v2 Tri Dharma Perguruan Tinggi tersedia dan dapat di-render tanpa script.
     */
    public function test_template_v2_tri_dharma_is_available_and_renders_safely(): void
    {
        $templateV2 = MouTemplate::where('version_number', 2)->first();
        if (!$templateV2) {
            $templateV2 = MouTemplate::create([
                'name'                 => 'Nota Kesepahaman Tri Dharma S9 ' . $this->unique,
                'version_number'       => 2,
                'template_html'        => MouTemplate::getTriDharmaTemplateContent(),
                'allowed_placeholders' => array_keys(MouTemplate::getDefaultPlaceholders()),
                'is_active'            => true,
                'created_by'           => $this->admin->id,
            ]);
        }

        $this->assertNotNull($templateV2);
        $this->assertStringContainsString('TRI DHARMA PERGURUAN TINGGI', $templateV2->template_html);

        $rendered = $templateV2->renderContent([
            'institution_name'       => 'Universitas Muhammadiyah Purwokerto',
            'company_name'           => 'SEVEN INC INDONESIA',
            'company_mou_number'     => 'MOU/7INC/TEST/001',
            'institution_mou_number' => '045/UMP/2026',
        ]);

        $this->assertStringContainsString('Universitas Muhammadiyah Purwokerto', $rendered);
        $this->assertStringContainsString('SEVEN INC INDONESIA', $rendered);
        $this->assertStringContainsString('MOU/7INC/TEST/001', $rendered);
        $this->assertStringNotContainsString('<script', $rendered);
    }

    /**
     * Test 15: markExpiredMous mengubah status MOU aktif yang telah lewat masa berlakunya.
     */
    public function test_mark_expired_mous_updates_status(): void
    {
        $mouService = app(MouService::class);

        // Buat MOU aktif yang effective_end sudah kemarin
        $pastMou = Mou::create([
            'institution_id'               => $this->institution->id,
            'company_mou_number'           => 'MOU/7INC/S9/PAST/' . substr($this->unique, 0, 6),
            'first_party_institution_name' => $this->institution->name,
            'first_party_signatory_name'   => 'Bambang',
            'first_party_signatory_title'  => 'Ketua Jurusan',
            'second_party_company_name'    => 'SEVEN INC',
            'second_party_signatory_name'  => 'Rekso',
            'second_party_signatory_title' => 'Direktur',
            'agreement_place'              => 'Yogyakarta',
            'agreement_date'               => now()->subYears(2)->toDateString(),
            'effective_start'              => now()->subYears(2)->toDateString(),
            'effective_end'                => now()->subDay()->toDateString(), // Kemarin
            'status'                       => Mou::STATUS_ACTIVE,
            'signed_at'                    => now()->subYears(2),
        ]);

        $count = $mouService->markExpiredMous();
        $this->assertGreaterThanOrEqual(1, $count);

        $pastMou->refresh();
        $this->assertEquals(Mou::STATUS_EXPIRED, $pastMou->status);
    }
}

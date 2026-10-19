<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Institution;
use App\Models\InstitutionContact;
use App\Models\Mou;
use App\Models\MouTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MouLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $hrStaff;
    private User $pemagang;
    private Institution $institution;
    private MouTemplate $template;
    private string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->unique = uniqid('mou_life_', true);

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
            'name'                 => 'Politeknik Test ' . $this->unique,
            'address'              => 'Jl. Magelang KM 5, Sleman',
            'phone'                => '0274-123789',
            'email'                => 'politeknik_' . $this->unique . '@test.com',
            'correspondence_unit'  => 'Jurusan Teknik Informatika',
            'is_active'            => true,
        ]);

        InstitutionContact::create([
            'institution_id' => $this->institution->id,
            'name'           => 'Dr. Hendra Saputra',
            'position_title' => 'Ketua Jurusan TI',
            'phone'          => '081299998888',
            'email'          => 'hendra@test.com',
            'is_active'      => true,
        ]);

        $this->template = MouTemplate::create([
            'name'                 => 'Template Standar Test ' . $this->unique,
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

        $this->cycle = \App\Models\FollowUpCycle::create([
            'institution_id'         => $this->institution->id,
            'institution_contact_id' => $this->institution->contacts->first()->id,
            'status'                 => \App\Models\FollowUpCycle::STATUS_COMPLETED,
            'final_result'           => \App\Models\FollowUpCycle::RESULT_WILLING,
            'cycle_year'             => (int) date('Y'),
        ]);
    }

    public function test_admin_and_hr_can_view_mou_list()
    {
        $responseAdmin = $this->actingAs($this->admin)->get(route('admin.mous.index'));
        $responseAdmin->assertStatus(200);
        $responseAdmin->assertViewIs('hr.mou.index');
        $responseAdmin->assertSee('Dokumen Kerja Sama');

        $responseHr = $this->actingAs($this->hrStaff)->get(route('hr.mous.index'));
        $responseHr->assertStatus(200);
        $responseHr->assertViewIs('hr.mou.index');
    }

    public function test_admin_and_hr_can_view_create_mou_page()
    {
        $responseAdmin = $this->actingAs($this->admin)->get(route('admin.mous.create', ['institution_id' => $this->institution->id]));
        $responseAdmin->assertStatus(200);
        $responseAdmin->assertViewIs('hr.mou.create');
        $responseAdmin->assertSee($this->institution->name);

        $responseHr = $this->actingAs($this->hrStaff)->get(route('hr.mous.create', ['institution_id' => $this->institution->id]));
        $responseHr->assertStatus(200);
        $responseHr->assertViewIs('hr.mou.create');
    }

    public function test_generate_mou_stores_data_snapshot_and_logs_activity()
    {
        $companyNumber = 'MOU/7INC/TEST/' . substr($this->unique, 0, 6);

        $postData = [
            'institution_id'                  => $this->institution->id,
            'followup_cycle_id'               => $this->cycle->id,
            'company_mou_number'              => $companyNumber,
            'institution_mou_number'          => '001/POLTEK/2026',
            'agreement_place'                 => 'Yogyakarta',
            'agreement_date'                  => '2026-09-28',
            'effective_start'                 => '2026-09-28',
            'effective_end'                   => '2028-09-28',
            'first_party_institution_name'    => $this->institution->name,
            'first_party_signatory_name'      => 'Dr. Hendra Saputra',
            'first_party_signatory_title'     => 'Ketua Jurusan TI',
            'first_party_address'             => 'Jl. Magelang KM 5, Sleman',
            'first_party_phone'               => '0274-123789',
            'first_party_email'               => 'hendra@test.com',
            'first_party_correspondence_unit' => 'Jurusan Teknik Informatika',
            'second_party_company_name'       => 'SEVEN INC',
            'second_party_signatory_name'     => 'Rekso Pamungkas',
            'second_party_signatory_title'    => 'Direktur Utama',
            'second_party_address'            => 'Yogyakarta',
            'second_party_phone'              => '0274-888999',
            'second_party_email'              => 'hr@seveninc.com',
        ];

        $response = $this->actingAs($this->hrStaff)->post(route('hr.mous.store'), $postData);

        $response->assertStatus(302);
        $response->assertSessionHas('success');

        $mou = Mou::where('company_mou_number', $companyNumber)->first();
        $this->assertNotNull($mou);
        $this->assertEquals(Mou::STATUS_GENERATED, $mou->status);
        $this->assertNotNull($mou->rendered_document_html);
        $this->assertNotNull($mou->render_data_snapshot);
        $this->assertEquals($this->institution->name, $mou->render_data_snapshot['institution_name']);

        // Activity log tercatat
        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'mou_generated',
            'actor_user_id' => $this->hrStaff->id,
        ]);
    }

    public function test_cannot_generate_mou_with_duplicate_company_mou_number()
    {
        $companyNumber = 'MOU/7INC/DUP/' . substr($this->unique, 0, 6);

        // Buat MOU pertama
        Mou::create([
            'institution_id'               => $this->institution->id,
            'company_mou_number'           => $companyNumber,
            'first_party_institution_name' => $this->institution->name,
            'first_party_signatory_name'   => 'Dr. Hendra',
            'first_party_signatory_title'  => 'Ketua Jurusan',
            'second_party_company_name'    => 'SEVEN INC',
            'second_party_signatory_name'  => 'Rekso Pamungkas',
            'second_party_signatory_title' => 'Direktur Utama',
            'agreement_place'              => 'Yogyakarta',
            'agreement_date'               => '2026-09-28',
            'status'                       => Mou::STATUS_GENERATED,
            'generated_at'                 => now(),
            'generated_by'                 => $this->admin->id,
        ]);

        $postData = [
            'institution_id'               => $this->institution->id,
            'followup_cycle_id'            => $this->cycle->id,
            'company_mou_number'           => $companyNumber, // Duplikat!
            'agreement_place'              => 'Yogyakarta',
            'agreement_date'               => '2026-09-28',
            'effective_start'              => '2026-09-28',
            'effective_end'                => '2028-09-28',
            'first_party_institution_name' => $this->institution->name,
            'first_party_signatory_name'   => 'Dr. Hendra',
            'first_party_signatory_title'  => 'Ketua Jurusan',
            'second_party_company_name'    => 'SEVEN INC',
            'second_party_signatory_name'  => 'Rekso',
            'second_party_signatory_title' => 'Direktur',
        ];

        $response = $this->actingAs($this->admin)->post(route('admin.mous.store'), $postData);
        $response->assertSessionHasErrors(['company_mou_number']);
    }

    public function test_mark_delivered_updates_status_and_logs_activity()
    {
        $mou = Mou::create([
            'institution_id'               => $this->institution->id,
            'company_mou_number'           => 'MOU/7INC/DELIV/' . substr($this->unique, 0, 6),
            'first_party_institution_name' => $this->institution->name,
            'first_party_signatory_name'   => 'Dr. Hendra',
            'first_party_signatory_title'  => 'Ketua Jurusan',
            'second_party_company_name'    => 'SEVEN INC',
            'second_party_signatory_name'  => 'Rekso Pamungkas',
            'second_party_signatory_title' => 'Direktur Utama',
            'agreement_place'              => 'Yogyakarta',
            'agreement_date'               => '2026-09-28',
            'status'                       => Mou::STATUS_GENERATED,
            'generated_at'                 => now(),
            'generated_by'                 => $this->hrStaff->id,
        ]);

        $deliveryPayload = [
            'delivery_method'    => 'kurir',
            'delivery_recipient' => 'Sekretariat Dekanat TI',
            'delivered_at'       => now()->toDateTimeString(),
            'delivery_notes'     => 'Dikirim via JNE Express No. Resi 987654321',
        ];

        $response = $this->actingAs($this->hrStaff)->post(route('hr.mous.delivered', $mou), $deliveryPayload);

        $response->assertStatus(302);
        $response->assertSessionHas('success');

        $mou->refresh();
        $this->assertEquals(Mou::STATUS_DELIVERED, $mou->status);
        $this->assertEquals('kurir', $mou->delivery_method);
        $this->assertEquals('Sekretariat Dekanat TI', $mou->delivery_recipient);

        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'mou_delivered',
            'actor_user_id' => $this->hrStaff->id,
        ]);
    }

    public function test_upload_signed_mou_activates_mou_and_supersedes_previous_active_mou()
    {
        // 1. Buat MOU lama yang berstatus ACTIVE
        $oldMou = Mou::create([
            'institution_id'               => $this->institution->id,
            'company_mou_number'           => 'MOU/7INC/OLD/' . substr($this->unique, 0, 6),
            'first_party_institution_name' => $this->institution->name,
            'first_party_signatory_name'   => 'Dr. Hendra',
            'first_party_signatory_title'  => 'Ketua Jurusan',
            'second_party_company_name'    => 'SEVEN INC',
            'second_party_signatory_name'  => 'Rekso Pamungkas',
            'second_party_signatory_title' => 'Direktur Utama',
            'agreement_place'              => 'Yogyakarta',
            'agreement_date'               => '2024-09-28',
            'effective_start'              => '2024-09-28',
            'effective_end'                => '2026-09-28',
            'status'                       => Mou::STATUS_ACTIVE,
            'signed_at'                    => now()->subYears(2),
        ]);

        // 2. Buat MOU baru dengan status DELIVERED dan rujukan supersede ke oldMou
        $newMou = Mou::create([
            'institution_id'               => $this->institution->id,
            'company_mou_number'           => 'MOU/7INC/NEW/' . substr($this->unique, 0, 6),
            'first_party_institution_name' => $this->institution->name,
            'first_party_signatory_name'   => 'Dr. Hendra',
            'first_party_signatory_title'  => 'Ketua Jurusan',
            'second_party_company_name'    => 'SEVEN INC',
            'second_party_signatory_name'  => 'Rekso Pamungkas',
            'second_party_signatory_title' => 'Direktur Utama',
            'agreement_place'              => 'Yogyakarta',
            'agreement_date'               => '2026-09-28',
            'effective_start'              => '2026-09-28',
            'effective_end'                => '2028-09-28',
            'status'                       => Mou::STATUS_DELIVERED,
            'supersedes_mou_id'            => $oldMou->id,
            'generated_at'                 => now(),
            'delivered_at'                 => now(),
            'generated_by'                 => $this->hrStaff->id,
        ]);

        $pdfFile = UploadedFile::fake()->create('naskah_mou_sah.pdf', 500, 'application/pdf');

        $uploadPayload = [
            'signed_file'            => $pdfFile,
            'signed_at'              => '2026-09-28',
            'institution_mou_number' => 'POLTEK/MOU/IX/2026/088',
            'effective_start'        => '2026-09-28',
            'effective_end'          => '2028-09-28',
        ];

        $response = $this->actingAs($this->hrStaff)->post(route('hr.mous.upload-signed', $newMou), $uploadPayload);

        $response->assertStatus(302);
        $response->assertSessionHas('success');

        $newMou->refresh();
        $oldMou->refresh();

        // New MOU menjadi ACTIVE
        $this->assertEquals(Mou::STATUS_ACTIVE, $newMou->status);
        $this->assertNotNull($newMou->signed_file_path);
        Storage::disk('public')->assertExists($newMou->signed_file_path);

        // Old MOU otomatis menjadi SUPERSEDED
        $this->assertEquals(Mou::STATUS_SUPERSEDED, $oldMou->status);
        $this->assertEquals($newMou->id, $oldMou->superseded_by_id);

        // Activity log tercatat
        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'mou_activated',
            'actor_user_id' => $this->hrStaff->id,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'mou_superseded',
        ]);
    }

    public function test_cancel_mou_updates_status_and_logs_activity()
    {
        $mou = Mou::create([
            'institution_id'               => $this->institution->id,
            'company_mou_number'           => 'MOU/7INC/CNCL/' . substr($this->unique, 0, 6),
            'first_party_institution_name' => $this->institution->name,
            'first_party_signatory_name'   => 'Dr. Hendra',
            'first_party_signatory_title'  => 'Ketua Jurusan',
            'second_party_company_name'    => 'SEVEN INC',
            'second_party_signatory_name'  => 'Rekso Pamungkas',
            'second_party_signatory_title' => 'Direktur Utama',
            'agreement_place'              => 'Yogyakarta',
            'agreement_date'               => '2026-09-28',
            'status'                       => Mou::STATUS_GENERATED,
            'generated_at'                 => now(),
            'generated_by'                 => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->post(route('admin.mous.cancel', $mou), [
            'cancel_reason' => 'Institusi meminta penyesuaian pasal kerjasama di luar batas wewenang.',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHas('success');

        $mou->refresh();
        $this->assertEquals(Mou::STATUS_CANCELLED, $mou->status);
        $this->assertStringContainsString('Institusi meminta penyesuaian pasal', $mou->notes);

        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'mou_cancelled',
            'actor_user_id' => $this->admin->id,
        ]);
    }

    public function test_pemagang_and_guest_cannot_access_mou_module()
    {
        // Pemagang ditolak akses HR MOU
        $responsePemagangHr = $this->actingAs($this->pemagang)->get(route('hr.mous.index'));
        $this->assertTrue(in_array($responsePemagangHr->status(), [403, 302]));

        // Pemagang ditolak akses Admin MOU
        $responsePemagangAdmin = $this->actingAs($this->pemagang)->get(route('admin.mous.index'));
        $this->assertTrue(in_array($responsePemagangAdmin->status(), [403, 302]));

        // Tamu (guest) diarahkan ke login
        auth()->logout();
        $responseGuest = $this->get(route('hr.mous.index'));
        $responseGuest->assertRedirect(route('user.login'));
    }

    public function test_generate_mou_computes_indonesian_date_terbilang_and_embeds_self_contained_snapshot()
    {
        $wordTemplate = MouTemplate::create([
            'name'                 => 'Word Standard Template ' . $this->unique,
            'version_number'       => 3,
            'template_html'        => MouTemplate::getWordStandardTemplateContent(),
            'allowed_placeholders' => array_keys(MouTemplate::getDefaultPlaceholders()),
            'default_values'       => array_merge(
                MouTemplate::getDefaultCompanyValues(),
                [
                    '_page_config'  => MouTemplate::getDefaultPageConfig(),
                    '_media_assets' => MouTemplate::getDefaultMediaAssets(),
                ]
            ),
            'is_active'            => true,
            'created_by'           => $this->admin->id,
        ]);

        $companyNumber = 'MOU/7INC/WORD/' . substr($this->unique, 0, 6);

        $postData = [
            'institution_id'                   => $this->institution->id,
            'followup_cycle_id'                => $this->cycle->id,
            'mou_template_id'                  => $wordTemplate->id,
            'company_mou_number'               => $companyNumber,
            'institution_mou_number'           => '3285/I.0/KS/2023',
            'agreement_place'                  => 'Yogyakarta',
            'agreement_date'                   => '2026-10-17',
            'effective_start'                  => '2026-10-17',
            'effective_end'                    => '2031-10-17',
            'agreement_duration'               => '5 (lima) tahun',
            'first_party_institution_name'     => $this->institution->name,
            'first_party_signatory_name'       => 'Dr. Hendra Saputra',
            'first_party_signatory_title'      => 'Rektor',
            'first_party_address'              => 'Jl. Budi Utomo No. 10',
            'first_party_phone'                => '(0352) 481124',
            'first_party_fax'                  => '(0352) 461796',
            'first_party_email'                => 'akademik@umpo.ac.id',
            'first_party_correspondence_unit'  => 'Kepala Lembaga Kerjasama',
            'second_party_company_name'        => 'PT. SEVEN INC.',
            'second_party_signatory_name'      => 'Rekario Danny Sanjaya, S.Kom',
            'second_party_signatory_title'     => 'Direktur',
            'second_party_address'             => 'Jl. Raya Janti Gg. Harjuna No. 59, Bantul',
            'second_party_phone'               => '(0274) 444555',
            'second_party_fax'                 => '(0274) 444556',
            'second_party_email'               => 'partnership@seveninc.co.id',
            'second_party_correspondence_unit' => 'Bagian Hukum dan Kerjasama PT. Seven Inc.',
        ];

        $response = $this->actingAs($this->hrStaff)->post(route('hr.mous.store'), $postData);
        $response->assertStatus(302);

        $mou = Mou::where('company_mou_number', $companyNumber)->firstOrFail();

        // Pastikan terbilang tanggal otomatis terhitung dengan benar (2026-10-17 = Sabtu, Tujuh Belas Oktober Dua Ribu Dua Puluh Enam)
        $this->assertEquals('Sabtu', $mou->render_data_snapshot['agreement_day_name']);
        $this->assertEquals('Tujuh Belas', $mou->render_data_snapshot['agreement_day_words']);
        $this->assertEquals('Oktober', $mou->render_data_snapshot['agreement_month_name']);
        $this->assertEquals('Dua Ribu Dua Puluh Enam', $mou->render_data_snapshot['agreement_year_words']);

        // Pastikan snapshot HTML menyematkan CSS kanonis dan Base64 logo agar mandiri dari perubahan masa depan
        $this->assertStringContainsString('mou-canonical-snapshot-style', $mou->rendered_document_html);
        $this->assertStringContainsString('data:image/png;base64,', $mou->rendered_document_html);
        $this->assertStringContainsString('Tanggal tujuh belas, bulan Oktober tahun dua ribu dua puluh enam', $mou->rendered_document_html);
        $this->assertStringContainsString('Rekario Danny Sanjaya, S.Kom', $mou->rendered_document_html);
    }

    public function test_historical_mou_snapshot_is_immutable_when_template_is_modified_later()
    {
        $companyNumber = 'MOU/7INC/HIST/' . substr($this->unique, 0, 6);

        $this->actingAs($this->hrStaff)->post(route('hr.mous.store'), [
            'institution_id'               => $this->institution->id,
            'followup_cycle_id'            => $this->cycle->id,
            'mou_template_id'              => $this->template->id,
            'company_mou_number'           => $companyNumber,
            'agreement_place'              => 'Yogyakarta',
            'agreement_date'               => '2026-09-28',
            'effective_start'              => '2026-09-28',
            'effective_end'                => '2028-09-28',
            'first_party_institution_name' => $this->institution->name,
            'first_party_signatory_name'   => 'Dr. Hendra Saputra',
            'first_party_signatory_title'  => 'Ketua Jurusan TI',
            'second_party_company_name'    => 'PT. SEVEN INC.',
            'second_party_signatory_name'  => 'Rekario Danny Sanjaya, S.Kom',
            'second_party_signatory_title' => 'Direktur',
        ]);

        $mou = Mou::where('company_mou_number', $companyNumber)->firstOrFail();
        $originalSnapshotHtml = $mou->rendered_document_html;

        // Ubah template sumber secara drastis setelah MOU diterbitkan
        $this->template->update([
            'template_html' => '<div class="mou-doc-root"><p>ISI TEMPLATE SUDAH DIGANTI TOTAL OLEH ADMIN</p></div>',
        ]);

        $mou->refresh();
        $this->assertEquals($originalSnapshotHtml, $mou->rendered_document_html);
        $this->assertStringNotContainsString('ISI TEMPLATE SUDAH DIGANTI TOTAL OLEH ADMIN', $mou->rendered_document_html);

        // Pastikan tampilan detail MOU tetap menampilkan naskah asli saat diterbitkan
        $showResponse = $this->actingAs($this->hrStaff)->get(route('hr.mous.show', $mou));
        $showResponse->assertStatus(200);
        $showResponse->assertDontSee('ISI TEMPLATE SUDAH DIGANTI TOTAL OLEH ADMIN');
    }

    public function test_admin_and_hr_can_download_mou_draft_pdf_and_unauthorized_roles_are_blocked()
    {
        $mou = Mou::create([
            'institution_id'               => $this->institution->id,
            'mou_template_id'              => $this->template->id,
            'company_mou_number'           => 'MOU/7INC/PDF/' . substr($this->unique, 0, 6),
            'first_party_institution_name' => $this->institution->name,
            'first_party_signatory_name'   => 'Dr. Hendra',
            'first_party_signatory_title'  => 'Ketua Jurusan',
            'second_party_company_name'    => 'PT. SEVEN INC.',
            'second_party_signatory_name'  => 'Rekario Danny Sanjaya, S.Kom',
            'second_party_signatory_title' => 'Direktur',
            'agreement_place'              => 'Yogyakarta',
            'agreement_date'               => '2026-09-28',
            'rendered_document_html'       => $this->template->renderContent([
                'company_name'       => 'PT. SEVEN INC.',
                'institution_name'   => $this->institution->name,
                'company_mou_number' => 'MOU/7INC/PDF/01',
                'agreement_place'    => 'Yogyakarta',
            ], true),
            'status'                       => Mou::STATUS_GENERATED,
            'generated_at'                 => now(),
            'generated_by'                 => $this->hrStaff->id,
        ]);

        // HR Staff download PDF
        $hrPdfResponse = $this->actingAs($this->hrStaff)->get(route('hr.mous.download-pdf', $mou));
        $hrPdfResponse->assertStatus(200);
        $this->assertEquals('application/pdf', $hrPdfResponse->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-1.', $hrPdfResponse->getContent());

        // Admin download PDF
        $adminPdfResponse = $this->actingAs($this->admin)->get(route('admin.mous.download-pdf', $mou));
        $adminPdfResponse->assertStatus(200);
        $this->assertEquals('application/pdf', $adminPdfResponse->headers->get('Content-Type'));

        // Pemagang ditolak
        $pemagangResponse = $this->actingAs($this->pemagang)->get(route('hr.mous.download-pdf', $mou));
        $this->assertTrue(in_array($pemagangResponse->status(), [403, 302]));
    }

    public function test_v3_published_mou_snapshot_preserves_custom_header_footer_and_resized_paraf_when_template_changes()
    {
        $customPageConfig = array_merge(MouTemplate::getDefaultPageConfig(), [
            'header_html' => '<p style="text-align: center;"><strong>KOP RESMI {{institution_name}}</strong></p>',
            'footer_html' => '<table class="mou-paraf-table" data-table-width="250pt" style="width: 250pt;"><tbody><tr data-row-height="30pt" style="height: 30pt;"><td>Paraf {{institution_name}}</td><td>Paraf {{company_name}}</td></tr></tbody></table>',
        ]);

        $this->template->update([
            'default_values' => array_merge($this->template->default_values ?? [], [
                '_page_config' => $customPageConfig,
            ]),
        ]);

        $companyNumber = 'MOU/7INC/V3SNAP/' . substr($this->unique, 0, 6);
        $response = $this->actingAs($this->hrStaff)->post(route('hr.mous.store'), [
            'institution_id'               => $this->institution->id,
            'followup_cycle_id'            => $this->cycle->id,
            'mou_template_id'              => $this->template->id,
            'company_mou_number'           => $companyNumber,
            'agreement_place'              => 'Yogyakarta',
            'agreement_date'               => '2026-10-17',
            'effective_start'              => '2026-10-17',
            'effective_end'                => '2028-10-17',
            'first_party_institution_name' => $this->institution->name,
            'first_party_signatory_name'   => 'Dr. Hendra Saputra',
            'first_party_signatory_title'  => 'Ketua Jurusan TI',
            'second_party_company_name'    => 'PT. SEVEN INC.',
            'second_party_signatory_name'  => 'Rekario Danny Sanjaya, S.Kom',
            'second_party_signatory_title' => 'Direktur',
        ]);
        $response->assertStatus(302);

        $mou = Mou::where('company_mou_number', $companyNumber)->firstOrFail();
        $snapshotPageCfg = $mou->render_data_snapshot['_page_config'] ?? [];

        // Pastikan placeholder pada Header dan Footer ikut di-render ke dalam snapshot
        $this->assertStringContainsString('KOP RESMI ' . $this->institution->name, $snapshotPageCfg['header_html'] ?? '');
        $this->assertStringContainsString('Paraf ' . $this->institution->name, $snapshotPageCfg['footer_html'] ?? '');
        $this->assertStringContainsString('data-row-height="30pt"', $snapshotPageCfg['footer_html'] ?? '');

        // Ubah Header/Footer di template sumber setelah MOU terbit
        $this->template->update([
            'default_values' => array_merge($this->template->default_values ?? [], [
                '_page_config' => array_merge($customPageConfig, [
                    'header_html' => '<p>HEADER BARU SETELAH REVISI ADMIN</p>',
                    'footer_html' => '<p>FOOTER BARU SETELAH REVISI ADMIN</p>',
                ]),
            ]),
        ]);

        // Pastikan halaman show dan print tetap menggunakan snapshot historis
        $showRes = $this->actingAs($this->hrStaff)->get(route('hr.mous.show', $mou));
        $showRes->assertStatus(200);
        $showRes->assertSee('KOP RESMI ' . $this->institution->name);
        $showRes->assertDontSee('HEADER BARU SETELAH REVISI ADMIN');

        $printRes = $this->actingAs($this->hrStaff)->get(route('hr.mous.print', $mou));
        $printRes->assertStatus(200);
        $printRes->assertSee('KOP RESMI ' . $this->institution->name);
        $printRes->assertDontSee('FOOTER BARU SETELAH REVISI ADMIN');
    }

    public function test_hr_can_select_specific_mou_template_on_create_and_generate_mou_using_selected_template()
    {
        $alternateTemplate = MouTemplate::create([
            'name'                 => 'Template Khusus Vokasi ' . $this->unique,
            'version_number'       => 88,
            'template_html'        => '<div class="mou-doc-root"><p>NASKAH KHUSUS VOKASI ANTARA [institution_name] DAN [company_name]</p></div>',
            'allowed_placeholders' => ['institution_name', 'company_name'],
            'default_values'       => array_merge(MouTemplate::getDefaultCompanyValues(), [
                'company_name'            => 'PT SEVEN INC VOKASI',
                'company_signatory_name'  => 'Direktur Vokasi Seven Inc',
                'company_signatory_title' => 'Direktur Vokasi',
                '_page_config'            => MouTemplate::getDefaultPageConfig(),
                '_media_assets'           => MouTemplate::getDefaultMediaAssets(),
            ]),
            'is_active'            => false,
            'created_by'           => $this->admin->id,
        ]);

        // 1. Buka halaman /hr/mou/create dengan query mou_template_id pilihan
        $createRes = $this->actingAs($this->hrStaff)->get(route('hr.mous.create', [
            'institution_id'  => $this->institution->id,
            'mou_template_id' => $alternateTemplate->id,
        ]));

        $createRes->assertStatus(200);
        $createHtml = $createRes->getContent();
        $this->assertStringContainsString('id="mouTemplateSelect"', $createHtml);
        $this->assertStringContainsString('Template Khusus Vokasi ' . $this->unique, $createHtml);
        $this->assertStringContainsString('PT SEVEN INC VOKASI', $createHtml);
        $this->assertStringContainsString('Direktur Vokasi Seven Inc', $createHtml);

        // 2. Submit pembuatan MOU menggunakan template pilihan tersebut
        $companyNumber = 'MOU/7INC/VOKASI/' . substr($this->unique, 0, 6);
        $storeRes = $this->actingAs($this->hrStaff)->post(route('hr.mous.store'), [
            'institution_id'               => $this->institution->id,
            'followup_cycle_id'            => $this->cycle->id,
            'mou_template_id'              => $alternateTemplate->id,
            'company_mou_number'           => $companyNumber,
            'agreement_place'              => 'Yogyakarta',
            'agreement_date'               => '2026-10-17',
            'effective_start'              => '2026-10-17',
            'effective_end'                => '2029-10-17',
            'first_party_institution_name' => $this->institution->name,
            'first_party_signatory_name'   => 'Dr. Hendra Saputra',
            'first_party_signatory_title'  => 'Direktur Politeknik',
            'second_party_company_name'    => 'PT SEVEN INC VOKASI',
            'second_party_signatory_name'  => 'Direktur Vokasi Seven Inc',
            'second_party_signatory_title' => 'Direktur Vokasi',
        ]);
        $storeRes->assertStatus(302);

        $mou = Mou::where('company_mou_number', $companyNumber)->firstOrFail();
        $this->assertEquals($alternateTemplate->id, $mou->mou_template_id);
        $this->assertStringContainsString('NASKAH KHUSUS VOKASI ANTARA ' . $this->institution->name . ' DAN PT SEVEN INC VOKASI', $mou->rendered_document_html);
    }
}


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
}

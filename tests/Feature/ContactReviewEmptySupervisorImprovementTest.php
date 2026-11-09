<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Institution;
use App\Models\InstitutionContact;
use App\Models\RegistrationContactLink;
use App\Models\InternshipRegistration as IR;
use App\Services\ContactMatchingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ContactReviewEmptySupervisorImprovementTest extends TestCase
{
    use DatabaseTransactions;

    protected function makeUser(string $role = 'user'): User
    {
        $unique = uniqid('cr_test_', true);
        return User::create([
            'name'      => 'usr_' . Str::random(10),
            'email'     => 'test_' . $unique . '@example.com',
            'password'  => Hash::make('password123'),
            'role'      => $role,
            'is_banned' => 0,
        ]);
    }

    protected function makeRegistration(Institution $institution, array $overrides = []): IR
    {
        $user = $this->makeUser('user');
        return IR::create(array_merge([
            'user_id'          => $user->id,
            'institution_id'   => $institution->id,
            'fullname'         => 'Intern ' . Str::random(5),
            'email'            => $user->email,
            'phone_number'     => '0812' . rand(10000000, 99999999),
            'internship_status'=> IR::STATUS_NEW,
        ], $overrides));
    }

    public function test_review_queue_tabs_and_counts_separate_filled_and_empty_supervisors(): void
    {
        $institution = Institution::create(['name' => 'Kampus Uji Review Tabs ' . Str::random(5)]);

        // 1. Pemagang yang mengisi data pembimbing
        $regWithInput = $this->makeRegistration($institution, [
            'supervisor_name'    => 'Drs. Suparman Handoko',
            'supervisor_contact' => '081299991111',
        ]);

        // 2. Pemagang yang data pembimbingnya kosong / placeholder
        $regEmpty = $this->makeRegistration($institution, [
            'supervisor_name'    => null,
            'supervisor_contact' => null,
        ]);

        $regPlaceholder = $this->makeRegistration($institution, [
            'supervisor_name'    => '-',
            'supervisor_contact' => 'belum ada',
        ]);

        // Query needs_review: hanya $regWithInput
        $needsReviewIds = ContactMatchingService::getReviewQueueQuery('needs_review')->pluck('id')->all();
        $this->assertContains($regWithInput->id, $needsReviewIds);
        $this->assertNotContains($regEmpty->id, $needsReviewIds);
        $this->assertNotContains($regPlaceholder->id, $needsReviewIds);

        // Query empty: $regEmpty dan $regPlaceholder
        $emptyIds = ContactMatchingService::getReviewQueueQuery('empty')->pluck('id')->all();
        $this->assertNotContains($regWithInput->id, $emptyIds);
        $this->assertContains($regEmpty->id, $emptyIds);
        $this->assertContains($regPlaceholder->id, $emptyIds);

        // Query all: ketiga-tiganya ada
        $allIds = ContactMatchingService::getReviewQueueQuery('all')->pluck('id')->all();
        $this->assertContains($regWithInput->id, $allIds);
        $this->assertContains($regEmpty->id, $allIds);
        $this->assertContains($regPlaceholder->id, $allIds);

        // Counts
        $counts = ContactMatchingService::getReviewQueueCounts();
        $this->assertGreaterThanOrEqual(1, $counts['needs_review']);
        $this->assertGreaterThanOrEqual(2, $counts['empty']);
        $this->assertEquals($counts['needs_review'] + $counts['empty'], $counts['all']);
    }

    public function test_link_existing_syncs_supervisor_to_empty_registration(): void
    {
        $admin = $this->makeUser('admin');
        $institution = Institution::create(['name' => 'Kampus Uji Link Existing ' . Str::random(5)]);

        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Dr. Siti Rahmawati, M.Kom',
            'phone'          => '081344445555',
            'is_verified'    => true,
            'is_active'      => true,
        ]);

        $regEmpty = $this->makeRegistration($institution, [
            'supervisor_name'    => null,
            'supervisor_contact' => null,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.contact-review.link', $regEmpty), [
            'institution_contact_id' => $contact->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $regEmpty->refresh();
        $this->assertEquals('Dr. Siti Rahmawati, M.Kom', $regEmpty->supervisor_name);
        $this->assertEquals('081344445555', $regEmpty->supervisor_contact);
        $this->assertTrue($regEmpty->institutionContacts()->where('institution_contacts.id', $contact->id)->exists());
    }

    public function test_create_and_verify_syncs_supervisor_to_empty_registration(): void
    {
        $hr = $this->makeUser('hr_staff');
        $institution = Institution::create(['name' => 'Kampus Uji Create Verify ' . Str::random(5)]);

        $regEmpty = $this->makeRegistration($institution, [
            'supervisor_name'    => '-',
            'supervisor_contact' => '',
        ]);

        $response = $this->actingAs($hr)->post(route('hr.contact-review.create-and-verify', $regEmpty), [
            'name'           => 'Prof. Hendro Wicaksono',
            'position_title' => 'Ketua Program Studi',
            'phone'          => '081988887777',
            'email'          => 'hendro@kampus.ac.id',
            'notes'          => 'Ditambahkan melalui Review Kontak Pembimbing Kosong',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $regEmpty->refresh();
        $this->assertEquals('Prof. Hendro Wicaksono', $regEmpty->supervisor_name);
        $this->assertEquals('081988887777', $regEmpty->supervisor_contact);
        $this->assertCount(1, $regEmpty->institutionContacts);
        $this->assertEquals('Prof. Hendro Wicaksono', $regEmpty->institutionContacts->first()->name);
    }

    public function test_ui_renders_all_tabs_correctly_for_admin_and_hr(): void
    {
        $admin = $this->makeUser('admin');
        $hr    = $this->makeUser('hr_staff');

        foreach ([$admin, $hr] as $user) {
            $prefix = $user->role === 'admin' ? 'admin' : 'hr';

            // Tab needs_review
            $res1 = $this->actingAs($user)->get(route($prefix . '.contact-review.index', ['tab' => 'needs_review']));
            $res1->assertStatus(200);
            $res1->assertSee('Perlu Review (Ada Input)');
            $res1->assertSee('Belum Ada Pembimbing (Kosong)');
            $res1->assertSee('Semua Belum Tertaut');

            // Tab empty
            $res2 = $this->actingAs($user)->get(route($prefix . '.contact-review.index', ['tab' => 'empty']));
            $res2->assertStatus(200);

            // Tab all
            $res3 = $this->actingAs($user)->get(route($prefix . '.contact-review.index', ['tab' => 'all']));
            $res3->assertStatus(200);
        }
    }
}

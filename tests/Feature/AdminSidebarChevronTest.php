<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AdminSidebarChevronTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unique = uniqid('sidebar_', true);

        $this->admin = User::create([
            'name'     => 'admin_' . substr($this->unique, 0, 8),
            'email'    => 'admin_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
        ]);
    }

    private function parseSidebarNavGroups(string $html): array
    {
        $dom = new DOMDocument();
        // Suppress HTML5 parsing warnings on DOMDocument
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xpath = new DOMXPath($dom);

        $sections = $xpath->query('//section[@data-nav-group]');
        $groupData = [];

        foreach ($sections as $section) {
            $labelNode = $xpath->query('.//button[@data-nav-group-toggle]//span[contains(@class, "admin-sidebar-label")]', $section)->item(0);
            $chevronNode = $xpath->query('.//*[local-name()="svg"][@data-nav-chevron]', $section)->item(0);
            $itemsNode = $xpath->query('.//*[@data-nav-group-items]', $section)->item(0);
            $buttonNode = $xpath->query('.//button[@data-nav-group-toggle]', $section)->item(0);

            if (!$labelNode || !$chevronNode || !$itemsNode) {
                continue;
            }

            $label = trim($labelNode->textContent);
            $chevronClasses = explode(' ', (string) $chevronNode->getAttribute('class'));
            $itemsClasses = explode(' ', (string) $itemsNode->getAttribute('class'));
            $ariaExpanded = $buttonNode ? $buttonNode->getAttribute('aria-expanded') : null;

            $groupData[$label] = [
                'has_rotate_180' => in_array('rotate-180', $chevronClasses, true),
                'is_hidden'      => in_array('hidden', $itemsClasses, true),
                'aria_expanded'  => $ariaExpanded,
            ];
        }

        return $groupData;
    }

    public function test_sidebar_chevron_orientation_on_dashboard(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.dashboard.index'));
        $response->assertOk();

        $groupData = $this->parseSidebarNavGroups($response->getContent());

        $this->assertGreaterThanOrEqual(4, count($groupData));

        // 1. Dashboard & Monitoring is active: open, chevron facing DOWN (no rotate-180)
        $this->assertArrayHasKey('Dashboard & Monitoring', $groupData);
        $this->assertFalse(
            $groupData['Dashboard & Monitoring']['has_rotate_180'],
            'Dashboard & Monitoring chevron must NOT have rotate-180 (faces down when open).'
        );
        $this->assertFalse(
            $groupData['Dashboard & Monitoring']['is_hidden'],
            'Dashboard & Monitoring items must NOT be hidden when active.'
        );
        $this->assertSame('true', $groupData['Dashboard & Monitoring']['aria_expanded']);

        // 2. Pendaftaran & Status is inactive: closed, chevron facing UP (has rotate-180)
        $this->assertArrayHasKey('Pendaftaran & Status', $groupData);
        $this->assertTrue(
            $groupData['Pendaftaran & Status']['has_rotate_180'],
            'Pendaftaran & Status chevron MUST have rotate-180 (faces up when closed).'
        );
        $this->assertTrue(
            $groupData['Pendaftaran & Status']['is_hidden'],
            'Pendaftaran & Status items must be hidden when closed.'
        );
        $this->assertSame('false', $groupData['Pendaftaran & Status']['aria_expanded']);

        // 3. Dokumen & Sertifikat is inactive: closed, chevron facing UP (has rotate-180)
        $this->assertArrayHasKey('Dokumen & Sertifikat', $groupData);
        $this->assertTrue(
            $groupData['Dokumen & Sertifikat']['has_rotate_180'],
            'Dokumen & Sertifikat chevron MUST have rotate-180 (faces up when closed).'
        );
        $this->assertTrue(
            $groupData['Dokumen & Sertifikat']['is_hidden'],
            'Dokumen & Sertifikat items must be hidden when closed.'
        );
        $this->assertSame('false', $groupData['Dokumen & Sertifikat']['aria_expanded']);

        // 4. Webinar is inactive: closed, chevron facing UP (has rotate-180)
        $this->assertArrayHasKey('Webinar', $groupData);
        $this->assertTrue(
            $groupData['Webinar']['has_rotate_180'],
            'Webinar chevron MUST have rotate-180 (faces up when closed).'
        );
        $this->assertTrue(
            $groupData['Webinar']['is_hidden'],
            'Webinar items must be hidden when closed.'
        );
        $this->assertSame('false', $groupData['Webinar']['aria_expanded']);
    }

    public function test_sidebar_chevron_orientation_when_other_route_active(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.interns.pendaftar'));
        $response->assertOk();

        $groupData = $this->parseSidebarNavGroups($response->getContent());

        // Pendaftaran & Status is active: open, chevron facing DOWN (no rotate-180)
        $this->assertArrayHasKey('Pendaftaran & Status', $groupData);
        $this->assertFalse(
            $groupData['Pendaftaran & Status']['has_rotate_180'],
            'Pendaftaran & Status chevron must NOT have rotate-180 when active.'
        );
        $this->assertFalse(
            $groupData['Pendaftaran & Status']['is_hidden'],
            'Pendaftaran & Status items must NOT be hidden when active.'
        );

        // Dashboard & Monitoring is now inactive: closed, chevron facing UP (has rotate-180)
        $this->assertArrayHasKey('Dashboard & Monitoring', $groupData);
        $this->assertTrue(
            $groupData['Dashboard & Monitoring']['has_rotate_180'],
            'Dashboard & Monitoring chevron MUST have rotate-180 when inactive.'
        );
        $this->assertTrue(
            $groupData['Dashboard & Monitoring']['is_hidden'],
            'Dashboard & Monitoring items must be hidden when inactive.'
        );
    }
}

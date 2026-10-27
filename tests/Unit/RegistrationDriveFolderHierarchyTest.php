<?php

namespace Tests\Unit;

use App\Models\FormField;
use App\Models\InternshipRegistration;
use App\Models\RegistrationAttachment;
use App\Models\RegistrationFormSnapshot;
use App\Services\UniversalFileService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationDriveFolderHierarchyTest extends TestCase
{
    public function test_drive_folder_hierarchy_structure_and_subfolder_naming(): void
    {
        $reg = new InternshipRegistration();
        $reg->id = 15;
        $reg->fullname = 'Ahmad Fauzi';
        $reg->created_at = Carbon::create(2026, 3, 15, 10, 0, 0);

        // 1. Base applicant path must remain REGISTRASI/{TAHUN}/{NAMA_PEMAGANG}
        $expectedBase = 'REGISTRASI/2026/ahmad_fauzi_REG15';
        $this->assertSame($expectedBase, $reg->getDriveApplicantFolderPath());

        // 2. Subfolder resolution for standard attachment types
        $this->assertSame('Curriculum Vitae (CV)', $reg->resolveAttachmentDriveSubfolder('cv'));
        $this->assertSame('KTM atau KTP', $reg->resolveAttachmentDriveSubfolder('identity'));
        $this->assertSame('Portofolio', $reg->resolveAttachmentDriveSubfolder('portfolio'));
        $this->assertSame('Surat Pengantar', $reg->resolveAttachmentDriveSubfolder('cover_letter'));
        $this->assertSame('Proposal Magang', $reg->resolveAttachmentDriveSubfolder('proposal'));
        $this->assertSame('Dokumen Pendukung', $reg->resolveAttachmentDriveSubfolder('supporting_document'));
        $this->assertSame('Foto Profil', $reg->resolveAttachmentDriveSubfolder('profile_photo'));

        // 3. Full attachment folder path must be REGISTRASI/{TAHUN}/{NAMA_PEMAGANG}/{SUBFOLDER}
        $expectedCvPath = "{$expectedBase}/Curriculum Vitae (CV)";
        $this->assertSame($expectedCvPath, $reg->getDriveAttachmentFolderPath('cv'));

        $expectedPortfolioPath = "{$expectedBase}/Portofolio";
        $this->assertSame($expectedPortfolioPath, $reg->getDriveAttachmentFolderPath('portfolio'));

        // 4. Legacy bundle or folder attachments must NOT have subfolder (points to root applicant folder)
        $this->assertSame('', $reg->resolveAttachmentDriveSubfolder(RegistrationAttachment::TYPE_LEGACY_BUNDLE));
        $this->assertSame($expectedBase, $reg->getDriveAttachmentFolderPath(RegistrationAttachment::TYPE_LEGACY_BUNDLE));
    }

    public function test_subfolder_naming_adapts_to_form_snapshot_or_form_field_settings(): void
    {
        $reg = new InternshipRegistration();
        $reg->id = 20;
        $reg->fullname = 'Dewi Sartika';
        $reg->created_at = Carbon::create(2026, 5, 20, 8, 30, 0);

        // Pasang snapshot form tiruan dengan label kustom
        $snapshot = new RegistrationFormSnapshot();
        $snapshot->schema_snapshot = [
            'fields' => [
                [
                    'field_key'  => 'cv',
                    'label'      => 'Curriculum Vitae / Resume Terbaru',
                    'field_type' => 'file',
                ],
                [
                    'field_key'  => 'custom_sertifikat',
                    'label'      => 'Sertifikat Keahlian & Pelatihan',
                    'field_type' => 'file',
                ],
                [
                    'field_key'  => 'identity',
                    'label'      => 'Kartu Tanda Mahasiswa (KTM) / KTP',
                    'field_type' => 'file',
                ],
            ],
        ];

        $reg->setRelation('formSnapshot', $snapshot);

        // Label dengan slash '/' wajib disanitasi menjadi ' - '
        $this->assertSame('Curriculum Vitae - Resume Terbaru', $reg->resolveAttachmentDriveSubfolder('cv'));
        $this->assertSame('Sertifikat Keahlian & Pelatihan', $reg->resolveAttachmentDriveSubfolder('custom_sertifikat'));
        $this->assertSame('Kartu Tanda Mahasiswa (KTM) - KTP', $reg->resolveAttachmentDriveSubfolder('identity'));

        $expectedBase = 'REGISTRASI/2026/dewi_sartika_REG20';
        $this->assertSame("{$expectedBase}/Curriculum Vitae - Resume Terbaru", $reg->getDriveAttachmentFolderPath('cv'));
        $this->assertSame("{$expectedBase}/Sertifikat Keahlian & Pelatihan", $reg->getDriveAttachmentFolderPath('custom_sertifikat'));
    }

    public function test_attachment_model_instance_resolution(): void
    {
        $reg = new InternshipRegistration();
        $reg->id = 30;
        $reg->fullname = 'Budi Wicaksono';
        $reg->created_at = Carbon::create(2026, 7, 1, 0, 0, 0);

        $att = new RegistrationAttachment([
            'attachment_type' => 'portfolio',
            'category'        => 'portfolio',
        ]);
        $att->setRelation('registration', $reg);

        $this->assertSame('Portofolio', $reg->resolveAttachmentDriveSubfolder($att));
        $this->assertSame(
            'REGISTRASI/2026/budi_wicaksono_REG30/Portofolio',
            $reg->getDriveAttachmentFolderPath($att)
        );

        // Folder bundle
        $folderAtt = new RegistrationAttachment([
            'attachment_type' => RegistrationAttachment::TYPE_LEGACY_BUNDLE,
            'category'        => RegistrationAttachment::TYPE_LEGACY_BUNDLE,
            'mime_type'       => 'application/vnd.google-apps.folder',
        ]);
        $folderAtt->setRelation('registration', $reg);

        $this->assertSame('', $reg->resolveAttachmentDriveSubfolder($folderAtt));
        $this->assertSame(
            'REGISTRASI/2026/budi_wicaksono_REG30',
            $reg->getDriveAttachmentFolderPath($folderAtt)
        );
    }
}

<?php

namespace App\Services;

use App\Models\InternshipRegistration;
use App\Models\SpreadsheetColumnMapping;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetSchemaVersion;
use DomainException;
use InvalidArgumentException;

class RegistrationSpreadsheetMappingService
{
    /**
     * Verifikasi bukti penerimaan resmi oleh Admin SertIF secara tepercaya (S17.17).
     *
     * Invariants:
     * - Status 'accepted' resmi merupakan bukti penerimaan langsung.
     * - Untuk status turunan/penutupan (active, completed, exited, rejected post-acceptance),
     *   wajib memverifikasi catatan transisi terstruktur pada ActivityLog
     *   (metadata->new_status == 'accepted' ATAU after_data->internship_status == 'accepted').
     * - DILARANG menggunakan row_index, last_synced_at, atau sync_status = synced sebagai bukti.
     * - DILARANG menggunakan pencocokan teks bebas description LIKE '%accepted%'.
     * - DILARANG menganggap LOA / Member Card sebagai bukti tanpa verifikasi integritas/provenance.
     * - Fail-closed: jika tidak ada catatan terstruktur yang membuktikan penerimaan, return false.
     */
    public function hasVerifiedAdminAcceptance(InternshipRegistration $registration): bool
    {
        return app(\App\Services\RegistrationLifecycleService::class)->hasVerifiedAdminAcceptance($registration);
    }

    /**
     * Memeriksa apakah pendaftaran memiliki bukti riwayat sinkronisasi sebelumnya yang terverifikasi (remote presence).
     *
     * Invariants (S17.17):
     * - Jangan mengandalkan metadata row_index saja tanpa bukti rekonsiliasi remote presence.
     * - Wajib memiliki catatan sinkronisasi dengan status valid, last_synced_at, payload_checksum, dan row_index > 0.
     * - Fail-closed: jika tidak ada catatan sinkronisasi terverifikasi, return false.
     */
    public function hasVerifiedPriorSync(InternshipRegistration $registration, ?\App\Models\SpreadsheetRowSync $rowSync = null): bool
    {
        // 1. Periksa record rowSync spesifik jika diberikan
        if ($rowSync !== null) {
            if (!empty($rowSync->last_synced_at)
                && !empty($rowSync->row_index)
                && $rowSync->row_index > 0
                && !empty($rowSync->payload_checksum)) {
                return true;
            }
        }

        // 2. Periksa apakah ada record sinkronisasi lain milik registrasi ini yang pernah berhasil disinkronkan
        return \App\Models\SpreadsheetRowSync::where(function ($q) use ($registration) {
            $q->where('syncable_type', $registration->getMorphClass())
              ->orWhere('syncable_type', get_class($registration));
        })
        ->where('syncable_id', $registration->id)
        ->whereNotNull('last_synced_at')
        ->whereNotNull('payload_checksum')
        ->whereNotNull('row_index')
        ->where('row_index', '>', 0)
        ->exists();
    }

    /**
     * Cek apakah pendaftaran eligible untuk sinkronisasi ke Google Spreadsheet Registration (S17.17).
     *
     * Aturan Bisnis Resmi (S17.17 Final Hardening):
     * - Draft (is_draft = true) atau status kosong: STRICTLY DILARANG (return false).
     * - Status baru / belum diputuskan ('new', 'waiting', 'pending'): STRICTLY TIDAK ELIGIBLE (return false).
     * - Status 'accepted': ELIGIBLE (return true), terlepas dari status follow-up atau konfirmasi sekolah/kampus.
     * - Status 'active' dan 'completed': ELIGIBLE HANYA JIKA riwayat penerimaan admin dapat dibuktikan.
     * - Status 'exited' atau pembatalan ('rejected'): DITAHAN FAIL-CLOSED (return false)
     *   karena operasi ini memerlukan jaminan remote update-only (menolak insert jika baris tidak ditemukan),
     *   yang belum didukung oleh gateway Apps Script saat ini. Mencegah accidental insert di Google Sheets.
     * - Status 'rejected' murni (ditolak sebelum pernah diterima): STRICTLY TIDAK ELIGIBLE (return false).
     * - Follow-up sekolah/kampus (misal: "menunggu jawaban", "belum ACC"): tidak membatalkan penerimaan admin.
     * - Role 'pemagang', keberadaan LOA/MemberCard tanpa bukti transisi admin, atau metadata row_index mentah TIDAK mencukupi.
     */
    public function isEligibleForSync(InternshipRegistration $registration, ?\App\Models\SpreadsheetRowSync $rowSync = null): bool
    {
        if ($registration->is_draft || empty($registration->internship_status)) {
            return false;
        }

        $status = $registration->internship_status;

        // 1. Status belum diputuskan admin -> strictly ineligible
        if (in_array($status, [
            InternshipRegistration::STATUS_NEW,
            InternshipRegistration::STATUS_WAITING,
            InternshipRegistration::STATUS_PENDING,
        ], true)) {
            return false;
        }

        // 2. Status diterima resmi admin -> eligible (follow-up kampus tidak membatalkan)
        if ($status === InternshipRegistration::STATUS_ACCEPTED) {
            return true;
        }

        // 3. Status pelaksanaan aktif atau selesai -> wajib memiliki bukti riwayat penerimaan admin
        if (in_array($status, [InternshipRegistration::STATUS_ACTIVE, InternshipRegistration::STATUS_COMPLETED], true)) {
            return $this->hasVerifiedAdminAcceptance($registration);
        }

        // 4. Status exited (keluar setelah magang berjalan)
        // S17.18B: Menggunakan kontrak remote update-only.
        // Wajib memiliki: 1) Bukti penerimaan admin yang tepercaya, dan 2) Bukti sinkronisasi sebelumnya yang valid pada record lokal.
        // Jika syarat tidak terpenuhi, fail-closed (return false) agar tidak membuat baris baru.
        if ($status === InternshipRegistration::STATUS_EXITED) {
            if ($this->hasVerifiedAdminAcceptance($registration) && $this->hasVerifiedPriorSync($registration, $rowSync)) {
                return true;
            }
            return false;
        }

        // 5. Status rejected (ditolak sebelum penerimaan ATAU pembatalan setelah penerimaan):
        // Ditolak sebelum penerimaan admin: strictly ineligible (return false).
        // Pembatalan pasca-penerimaan (S17.18B): wajib memiliki bukti penerimaan admin dan bukti sinkronisasi sebelumnya.
        if ($status === InternshipRegistration::STATUS_REJECTED) {
            if ($this->hasVerifiedAdminAcceptance($registration) && $this->hasVerifiedPriorSync($registration, $rowSync)) {
                return true;
            }
            return false;
        }

        return false;
    }

    /**
     * Dapatkan schema version aktif untuk pendaftaran berdasarkan tahun canonical created_at.
     */
    public function getSchemaForRegistration(InternshipRegistration $registration): ?SpreadsheetSchemaVersion
    {
        if (!$registration->created_at) {
            return null;
        }

        $createdAt = $registration->created_at instanceof \DateTimeInterface
            ? $registration->created_at
            : \Illuminate\Support\Carbon::parse($registration->created_at);

        $year = (int) $createdAt->format('Y');

        $connection = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)->first();
        if (!$connection) {
            return null;
        }

        if (!app()->runningUnitTests()) {
            return $this->syncSpreadsheetSchemaWithFormSchema($year);
        }

        return SpreadsheetSchemaVersion::where('spreadsheet_connection_id', $connection->id)
            ->where('year', $year)
            ->where('is_active', true)
            ->with('columnMappings')
            ->first();
    }

    /**
     * Sinkronkan skema kolom Spreadsheet (SpreadsheetSchemaVersion & SpreadsheetColumnMapping)
     * secara otomatis mengikuti FormSchema yang dipublikasikan untuk tahun terkait.
     * - 35 kolom inti pertama tetap sesuai urutan kanonikal.
     * - Pertanyaan tambahan (custom fields) aktif disisipkan sebelum kolom sistem (KET, SERTIF_*).
     * - 5 kolom penutup sistem (KET, SERTIF_SYNC_ID, SERTIF_REGISTRATION_ID, SERTIF_SYNC_STATUS, SERTIF_LAST_SYNC_AT) selalu di akhir.
     */
    public function syncSpreadsheetSchemaWithFormSchema(int $year, ?\App\Models\FormSchema $formSchema = null): ?SpreadsheetSchemaVersion
    {
        $connections = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)->get();
        if ($connections->isEmpty()) {
            return null;
        }

        if (!$formSchema && \Illuminate\Support\Facades\Schema::hasTable('form_schemas')) {
            $formSchema = \App\Models\FormSchema::where('year', $year)
                ->where('status', \App\Models\FormSchema::STATUS_PUBLISHED)
                ->where('is_active', true)
                ->with('fields')
                ->first();
        }

        $canonical = \Database\Seeders\Spreadsheet2026SchemaSeeder::getCanonicalColumns();
        $coreCols = array_slice($canonical, 0, 35);
        $systemCols = array_slice($canonical, 35);

        $customCols = [];
        if ($formSchema) {
            $customFields = $formSchema->fields()
                ->where('is_system', false)
                ->where('is_active', true)
                ->where('is_archived', false)
                ->whereNotIn('field_key', \App\Services\FormBuilderService::CORE_FIELD_KEYS)
                ->orderBy('sort_order')
                ->get();

            $existingHeadersLower = array_map(fn($c) => mb_strtolower(trim($c['header'])), array_merge($coreCols, $systemCols));

            foreach ($customFields as $cf) {
                $headerLabel = trim((string) $cf->label);
                if ($headerLabel === '' || in_array(mb_strtolower($headerLabel), $existingHeadersLower, true)) {
                    $headerLabel = $headerLabel !== '' ? "{$headerLabel} ({$cf->field_key})" : $cf->field_key;
                }
                $existingHeadersLower[] = mb_strtolower($headerLabel);

                $customCols[] = [
                    'header'    => $headerLabel,
                    'entity'    => 'registration_custom_answers',
                    'field'     => $cf->field_key,
                    'type'      => in_array($cf->field_type, ['date', 'number', 'url'], true) ? $cf->field_type : 'string',
                    'direction' => 'outbound',
                    'transform' => 'custom_field',
                    'required'  => (bool) $cf->is_required,
                ];
            }
        }

        $mergedColumns = array_merge($coreCols, $customCols, $systemCols);
        $activeSchemaVersion = null;

        foreach ($connections as $connection) {
            $schemaVersion = SpreadsheetSchemaVersion::updateOrCreate(
                [
                    'spreadsheet_connection_id' => $connection->id,
                    'year'                      => $year,
                    'schema_version'            => 'v1',
                ],
                [
                    'sheet_tab_name' => (string) $year,
                    'is_active'      => true,
                    'notes'          => "Auto-synced Unified Registration Contract ({$year})",
                ]
            );

            \Illuminate\Support\Facades\DB::transaction(function () use ($schemaVersion, $mergedColumns) {
                SpreadsheetColumnMapping::where('spreadsheet_schema_version_id', $schemaVersion->id)->delete();

                foreach ($mergedColumns as $idx => $col) {
                    $order = $idx + 1;
                    SpreadsheetColumnMapping::create([
                        'public_uuid'                   => (string) \Illuminate\Support\Str::uuid(),
                        'spreadsheet_schema_version_id' => $schemaVersion->id,
                        'column_order'                  => $order,
                        'sheet_header'                  => $col['header'],
                        'target_entity'                 => $col['entity'],
                        'target_field'                  => $col['field'],
                        'data_type'                     => $col['type'],
                        'direction'                     => $col['direction'],
                        'transform_key'                 => $col['transform'],
                        'is_required'                   => $col['required'],
                    ]);
                }
            });

            if ($activeSchemaVersion === null) {
                $activeSchemaVersion = $schemaVersion->fresh(['columnMappings']);
            }
        }

        return $activeSchemaVersion;
    }

    /**
     * Bangun outbound row payload 29 kolom untuk baris pendaftaran secara deterministik.
     *
     * @throws DomainException
     */
    public function buildOutboundRowPayload(
        InternshipRegistration $registration,
        ?SpreadsheetSchemaVersion $schema = null,
        ?\App\Models\SpreadsheetRowSync $rowSync = null
    ): array {
        if ($registration->is_draft || empty($registration->internship_status) || $registration->internship_status === InternshipRegistration::STATUS_NEW) {
            throw new DomainException('Pendaftaran berstatus draft/belum submitted tidak eligible untuk sinkronisasi Spreadsheet.');
        }

        if (!$this->isEligibleForSync($registration, $rowSync)) {
            throw new DomainException('NOT_ELIGIBLE_UNACCEPTED: Pendaftaran tidak eligible untuk sinkronisasi Spreadsheet tanpa verifikasi penerimaan admin.');
        }

        if (!$registration->created_at) {
            throw new DomainException('Tanggal pembuatan pendaftaran (created_at) tidak valid atau kosong untuk penentuan tab tahunan.');
        }

        $createdAt = $registration->created_at instanceof \DateTimeInterface
            ? $registration->created_at
            : \Illuminate\Support\Carbon::parse($registration->created_at);

        $schemaVersion = $schema ?? $this->getSchemaForRegistration($registration);
        if (!$schemaVersion) {
            $year = $createdAt->format('Y');
            throw new DomainException("Schema Spreadsheet untuk tahun {$year} belum dikonfigurasi.");
        }

        $mappings = $schemaVersion->columnMappings()->orderBy('column_order', 'asc')->get();
        if ($mappings->isEmpty()) {
            throw new DomainException("Pemetaan kolom untuk schema versi '{$schemaVersion->schema_version}' tahun {$schemaVersion->year} masih kosong.");
        }

        $payload = [];

        foreach ($mappings as $mapping) {
            $value = $this->resolveColumnValue($registration, $mapping);
            $payload[$mapping->sheet_header] = self::escapeFormulaInjection($value);
        }

        return $payload;
    }

    /**
     * Mencegah formula injection (CWE-1236) pada saat menulis ke Spreadsheet.
     * Jika string diawali karakter formula (=, +, -, @, \t, \r), escape dengan apostrof tunggal di depan.
     */
    public static function escapeFormulaInjection(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        if ($value === '') {
            return '';
        }
        $firstChar = $value[0];
        if (in_array($firstChar, ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
    }

    /**
     * Resolusi nilai spesifik tiap kolom berdasarkan target_field dan transform_key.
     */
    public function resolveColumnValue(InternshipRegistration $registration, SpreadsheetColumnMapping $mapping): mixed
    {
        $transformKey = $mapping->transform_key ?? $mapping->target_field;

        return match ($transformKey) {
            'timestamp' => $this->formatDateTimeValue($registration->created_at),
            'fullname' => $registration->fullname,
            'student_id' => $registration->student_id ?? '-',
            'study_program' => $registration->study_program ?? '-',
            'email' => $registration->email ?? '-',
            'gender' => $registration->gender,
            'phone_number' => $registration->phone_number,
            'institution_name' => $registration->institution_name ?? '-',
            'current_city' => $registration->current_city ?? '-',
            'internship_reason' => $registration->internship_reason ?? '-',
            'internship_type' => $registration->internship_type ?? '-',
            'current_status' => $registration->current_status ?? '-',
            'wfo_readiness' => $registration->wfo_readiness ?? '-',
            'supervisor_info' => $this->formatSupervisorInfo($registration),
            'schedule_shift_readiness' => $registration->schedule_shift_readiness ?? '-',
            'has_laptop' => $registration->has_laptop ? 'YA' : 'TIDAK',
            'laptop_equipment' => !empty($registration->owned_tools) ? $registration->owned_tools : '-',
            'start_date' => $this->formatDateValue($registration->start_date),
            'planned_duration' => $registration->planned_duration ?? '-',
            'internship_info_sources' => !empty($registration->internship_info_sources) ? $registration->internship_info_sources : '-',
            'social_media_instagram' => $registration->social_media_instagram ?? '-',
            'boarding_info' => $registration->boarding_info ?? '-',
            'internship_interest' => $registration->internship_interest ?? '-',
            'attachment_url' => $this->resolveAttachmentUrl($registration),
            'birth_place' => $registration->birth_place ?? '-',
            'born_date', 'birth_date' => $this->formatDateValue($registration->born_date),
            'religion' => $registration->religion ?? '-',
            'link_cv' => $this->resolveSpecificAttachmentUrl($registration, \App\Models\RegistrationAttachment::TYPE_CV),
            'link_ktm_ktp' => $this->resolveSpecificAttachmentUrl($registration, \App\Models\RegistrationAttachment::TYPE_IDENTITY),
            'link_portofolio' => $this->resolveSpecificAttachmentUrl($registration, \App\Models\RegistrationAttachment::TYPE_PORTFOLIO),
            'link_surat_pengantar' => $this->resolveSpecificAttachmentUrl($registration, \App\Models\RegistrationAttachment::TYPE_COVER_LETTER),
            'link_proposal' => $this->resolveSpecificAttachmentUrl($registration, \App\Models\RegistrationAttachment::TYPE_PROPOSAL),
            'link_dokumen_pendukung' => $this->resolveSpecificAttachmentUrl($registration, \App\Models\RegistrationAttachment::TYPE_SUPPORTING_DOCUMENT),
            'link_pasfoto' => $this->resolveSpecificAttachmentUrl($registration, \App\Models\RegistrationAttachment::TYPE_PROFILE_PHOTO),
            'design_software' => !empty($registration->design_software) ? $registration->design_software : '-',
            'video_software' => !empty($registration->video_software) ? $registration->video_software : '-',
            'programming_languages' => !empty($registration->programming_languages) ? $registration->programming_languages : '-',
            'digital_marketing_type' => !empty($registration->digital_marketing_type) ? $registration->digital_marketing_type : '-',
            'equipment', 'owned_tools' => !empty($registration->owned_tools) ? $registration->owned_tools : '-',
            'supervisor_name' => $registration->supervisor_name ?? '-',
            'supervisor_contact' => $registration->supervisor_contact ?? '-',
            'current_activities' => $registration->current_activities ?? '-',
            'status_label', 'ket' => app(\App\Services\RegistrationLifecycleService::class)->resolveCurrentCondition($registration)['spreadsheet_column_y_label'],
            // Guardrail 2: S9B tidak menciptakan stable sync ID atau mengklaim status synced
            'sertif_sync_id' => null,
            'sertif_registration_id' => (string) $registration->id,
            'sertif_sync_status' => 'pending',
            'sertif_last_sync_at' => null,
            'custom_field' => $this->resolveCustomFieldAnswer($registration, $mapping->target_field),
            default => $this->fallbackResolve($registration, $mapping->target_field),
        };
    }

    /**
     * Resolusi nilai jawaban untuk pertanyaan tambahan (custom field) dari tabel registration_custom_answers.
     */
    protected function resolveCustomFieldAnswer(InternshipRegistration $registration, ?string $fieldKey): string
    {
        if (empty($fieldKey)) {
            return '-';
        }

        try {
            $ans = $registration->relationLoaded('customAnswers')
                ? $registration->customAnswers->firstWhere('field_key', $fieldKey)
                : $registration->customAnswers()->where('field_key', $fieldKey)->first();

            if (!$ans || $ans->answer_value === null || trim((string) $ans->answer_value) === '') {
                return '-';
            }

            $raw = (string) $ans->answer_value;
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $filtered = array_filter(array_map('trim', $decoded), fn($v) => $v !== '');
                return !empty($filtered) ? implode(', ', $filtered) : '-';
            }

            if (str_starts_with($raw, 'private/') || str_starts_with($raw, 'uploads/')) {
                return basename($raw);
            }

            return $raw;
        } catch (\Throwable) {
            return '-';
        }
    }

    /**
     * Format nilai tanggal secara aman (mencegah error call to a member function format() on string).
     */
    protected function formatDateValue(mixed $value): string
    {
        if (empty($value) || $value === '-') {
            return '-';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $str = trim((string) $value);
        if ($str === '' || $str === '-') {
            return '-';
        }

        try {
            return \Illuminate\Support\Carbon::parse($str)->format('Y-m-d');
        } catch (\Throwable) {
            return $str;
        }
    }

    /**
     * Format nilai datetime/timestamp secara aman.
     */
    protected function formatDateTimeValue(mixed $value): string
    {
        if (empty($value) || $value === '-') {
            return '-';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        $str = trim((string) $value);
        if ($str === '' || $str === '-') {
            return '-';
        }

        try {
            return \Illuminate\Support\Carbon::parse($str)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return $str;
        }
    }

    /**
     * Format informasi kontak dan nama dosen/guru pembimbing.
     */
    protected function formatSupervisorInfo(InternshipRegistration $registration): string
    {
        $name = trim((string) $registration->supervisor_name);
        $contact = trim((string) $registration->supervisor_contact);

        if (!empty($name) && !empty($contact)) {
            return "{$name} ({$contact})";
        }

        if (!empty($name)) {
            return $name;
        }

        if (!empty($contact)) {
            return $contact;
        }

        return '-';
    }

    /**
     * Fallback resolution jika tidak ada transformer spesifik.
     */
    protected function fallbackResolve(InternshipRegistration $registration, ?string $field): mixed
    {
        if (empty($field)) {
            return null;
        }

        $value = $registration->{$field} ?? null;
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value === null) {
            $customVal = $this->resolveCustomFieldAnswer($registration, $field);
            if ($customVal !== '-') {
                return $customVal;
            }
        }

        return $value;
    }

    /**
     * Validasi kecocokan header sheet aktual dengan schema yang diharapkan (Guardrail 1).
     */
    public function validateHeaderCompatibility(SpreadsheetSchemaVersion $schema, array $sheetHeaders): array
    {
        $expectedMappings = $schema->columnMappings()->orderBy('column_order', 'asc')->get();
        $expectedHeaders = $expectedMappings->pluck('sheet_header')->toArray();

        $missingHeaders = [];
        $misorderedHeaders = [];

        foreach ($expectedHeaders as $index => $expectedHeader) {
            $actualHeader = $sheetHeaders[$index] ?? null;

            if (!in_array($expectedHeader, $sheetHeaders, true)) {
                $missingHeaders[] = [
                    'expected_order' => $index + 1,
                    'header' => $expectedHeader,
                ];
            } elseif ($actualHeader !== $expectedHeader) {
                $misorderedHeaders[] = [
                    'order' => $index + 1,
                    'expected' => $expectedHeader,
                    'actual' => $actualHeader,
                ];
            }
        }

        $unexpectedHeaders = array_diff($sheetHeaders, $expectedHeaders);

        $isCompatible = empty($missingHeaders) && empty($misorderedHeaders) && empty($unexpectedHeaders);

        return [
            'is_compatible' => $isCompatible,
            'missing_headers' => $missingHeaders,
            'misordered_headers' => $misorderedHeaders,
            'unexpected_headers' => array_values($unexpectedHeaders),
            'expected_count' => count($expectedHeaders),
            'actual_count' => count($sheetHeaders),
        ];
    }

    /**
     * Resolve valid Google Drive link for registration attachment if synced (1 unified folder/bundle link).
     */
    protected function resolveAttachmentUrl(InternshipRegistration $registration): ?string
    {
        $attachments = $registration->attachments()
            ->where('drive_sync_status', \App\Models\RegistrationAttachment::STATUS_SYNCED)
            ->whereNotNull('drive_file_id')
            ->where('drive_file_id', 'not like', 'local_sim_%')
            ->whereNotNull('drive_web_view_link')
            ->get();

        if ($attachments->isEmpty()) {
            return null;
        }

        if ($attachments->count() === 1) {
            return $attachments->first()->drive_web_view_link;
        }

        // 1. Utamakan tautan 1 folder penyimpanan Google Drive atau bundle gabungan jika tersedia
        $folderOrBundle = $attachments->first(function ($att) {
            $link = (string) $att->drive_web_view_link;
            $cat  = (string) ($att->getRawOriginal('category') ?? $att->category);
            $type = (string) $att->attachment_type;

            return str_contains($link, '/drive/folders/')
                || in_array($cat, [\App\Models\RegistrationAttachment::TYPE_LEGACY_BUNDLE, 'legacy_bundle', 'folder'], true)
                || in_array($type, [\App\Models\RegistrationAttachment::TYPE_LEGACY_BUNDLE, 'legacy_bundle', 'folder'], true);
        });

        if ($folderOrBundle) {
            return (string) $folderOrBundle->drive_web_view_link;
        }

        // 2. Jika data multi-upload berasal dari seeder demo (1demo_*), kembalikan 1 link folder penyimpanan terpadu pendaftar
        $hasDemoAttachment = $attachments->contains(function ($att) {
            return str_starts_with((string) $att->drive_file_id, '1demo_')
                || str_contains((string) $att->drive_web_view_link, '1demo_');
        });

        if ($hasDemoAttachment) {
            $slug = \Illuminate\Support\Str::slug($registration->fullname ?: 'pemagang', '_');
            return "https://drive.google.com/drive/folders/1folder_reg_{$registration->id}_{$slug}?usp=sharing";
        }

        // 3. Fallback: kembalikan 1 tautan utama saja (jangan digabung dengan newline)
        $primary = $attachments->firstWhere('category', \App\Models\RegistrationAttachment::TYPE_CV)
            ?? $attachments->first();

        return $primary?->drive_web_view_link;
    }

    /**
     * Resolve specific attachment Google Drive link by category.
     */
    public function resolveSpecificAttachmentUrl(InternshipRegistration $registration, string $category): string
    {
        $aliases = match ($category) {
            \App\Models\RegistrationAttachment::TYPE_IDENTITY            => ['identity', 'ktm_ktp', 'ktm', 'ktp'],
            \App\Models\RegistrationAttachment::TYPE_PORTFOLIO           => ['portfolio', 'portofolio'],
            \App\Models\RegistrationAttachment::TYPE_COVER_LETTER        => ['cover_letter', 'surat_pengantar'],
            \App\Models\RegistrationAttachment::TYPE_SUPPORTING_DOCUMENT => ['supporting_document', 'dokumen_pendukung'],
            \App\Models\RegistrationAttachment::TYPE_PROFILE_PHOTO       => ['profile_photo', 'pasfoto'],
            default                                                      => [$category],
        };

        $attachment = $registration->attachments()
            ->where(function ($q) use ($aliases) {
                $q->whereIn('category', $aliases)
                  ->orWhereIn('attachment_type', $aliases);
            })
            ->where('drive_sync_status', \App\Models\RegistrationAttachment::STATUS_SYNCED)
            ->whereNotNull('drive_file_id')
            ->whereNotNull('drive_web_view_link')
            ->first();

        return $attachment?->drive_web_view_link ?? '-';
    }

    /**
     * Memeriksa apakah pendaftaran memerlukan sinkronisasi tab sidecar (ekstensi custom fields).
     *
     * Invariants (S17.18D.3 Final Legacy Hardening - Option 1: Placeholder Row 1:1):
     * - Registrasi non-eligible (draft, empty, waiting, pending, pure rejected) STRICTLY DILARANG (return false).
     * - Registrasi dengan form snapshot atau custom answers: memerlukan sinkronisasi (return true).
     * - Registrasi legacy tanpa form snapshot/answers: WAJIB memiliki baris sidecar 1:1 HANYA JIKA
     *   telah memiliki bukti sinkronisasi baris utama yang terverifikasi (hasVerifiedPriorSync = true).
     */
    public function requiresSidecarSync(InternshipRegistration $registration): bool
    {
        if (!$this->isEligibleForSync($registration)) {
            return false;
        }

        if ($registration->formSnapshot()->exists() || $registration->customAnswers()->exists()) {
            return true;
        }

        return $this->hasVerifiedPriorSync($registration);
    }

    /**
     * Bentuk payload jawaban kustom untuk tab 2026_Custom_Fields.
     */
    public function buildSidecarPayload(InternshipRegistration $registration): array
    {
        $snapshot = $registration->formSnapshot;
        $version = $snapshot ? (string) $snapshot->form_schema_version : '';
        $rawAnswers = $registration->customAnswers()->pluck('answer_value', 'field_key')->all();

        // Sanitasi nilai jawaban: jangan bocorkan internal storage path privat ke Google Sheets
        $sanitizedAnswers = [];
        foreach ($rawAnswers as $key => $val) {
            if (is_string($val) && (str_starts_with($val, 'private/') || str_starts_with($val, 'uploads/'))) {
                $sanitizedAnswers[$key] = basename($val);
            } else {
                $sanitizedAnswers[$key] = self::escapeFormulaInjection($val);
            }
        }

        return [
            'registration_id'     => $registration->id,
            'form_schema_version' => $version,
            'answers'             => $sanitizedAnswers,
        ];
    }
}

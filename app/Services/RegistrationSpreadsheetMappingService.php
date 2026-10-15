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

        $year = (int) $registration->created_at->format('Y');

        $connection = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)->first();
        if (!$connection) {
            return null;
        }

        return SpreadsheetSchemaVersion::where('spreadsheet_connection_id', $connection->id)
            ->where('year', $year)
            ->where('is_active', true)
            ->with('columnMappings')
            ->first();
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

        $schemaVersion = $schema ?? $this->getSchemaForRegistration($registration);
        if (!$schemaVersion) {
            $year = $registration->created_at->format('Y');
            throw new DomainException("Schema Spreadsheet untuk tahun {$year} belum dikonfigurasi.");
        }

        $mappings = $schemaVersion->columnMappings()->orderBy('column_order', 'asc')->get();
        if ($mappings->isEmpty()) {
            throw new DomainException("Pemetaan kolom untuk schema versi '{$schemaVersion->schema_version}' tahun {$schemaVersion->year} masih kosong.");
        }

        $payload = [];

        foreach ($mappings as $mapping) {
            $value = $this->resolveColumnValue($registration, $mapping);
            $payload[$mapping->sheet_header] = $value;
        }

        return $payload;
    }

    /**
     * Resolusi nilai spesifik tiap kolom berdasarkan target_field dan transform_key.
     */
    public function resolveColumnValue(InternshipRegistration $registration, SpreadsheetColumnMapping $mapping): mixed
    {
        $transformKey = $mapping->transform_key ?? $mapping->target_field;

        return match ($transformKey) {
            'timestamp' => $registration->created_at?->format('Y-m-d H:i:s'),
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
            'start_date' => $registration->start_date ?? '-',
            'planned_duration' => $registration->planned_duration ?? '-',
            'internship_info_sources' => !empty($registration->internship_info_sources) ? $registration->internship_info_sources : '-',
            'social_media_instagram' => $registration->social_media_instagram ?? '-',
            'boarding_info' => $registration->boarding_info ?? '-',
            'internship_interest' => $registration->internship_interest ?? '-',
            'attachment_url' => $this->resolveAttachmentUrl($registration),
            'status_label', 'ket' => app(\App\Services\RegistrationLifecycleService::class)->resolveCurrentCondition($registration)['spreadsheet_column_y_label'],
            // Guardrail 2: S9B tidak menciptakan stable sync ID atau mengklaim status synced
            'sertif_sync_id' => null,
            'sertif_registration_id' => (string) $registration->id,
            'sertif_sync_status' => 'pending',
            'sertif_last_sync_at' => null,
            default => $this->fallbackResolve($registration, $mapping->target_field),
        };
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

        return $registration->{$field} ?? null;
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
     * Resolve valid Google Drive link for registration attachment if synced.
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

        // Multi-upload: utamakan CV atau bundle berkas
        $primary = $attachments->firstWhere('category', \App\Models\RegistrationAttachment::TYPE_CV)
            ?? $attachments->firstWhere('category', \App\Models\RegistrationAttachment::TYPE_LEGACY_BUNDLE)
            ?? $attachments->first();

        return $primary?->drive_web_view_link;
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
                $sanitizedAnswers[$key] = $val;
            }
        }

        return [
            'registration_id'     => $registration->id,
            'form_schema_version' => $version,
            'answers'             => $sanitizedAnswers,
        ];
    }
}

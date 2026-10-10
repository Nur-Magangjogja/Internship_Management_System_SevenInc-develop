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
     * Cek apakah pendaftaran eligible untuk sinkronisasi ke Google Spreadsheet.
     * Draft (status kosong atau 'new') STRICTLY DILARANG untuk disinkronkan.
     */
    public function isEligibleForSync(InternshipRegistration $registration): bool
    {
        return !$registration->is_draft
            && !empty($registration->internship_status)
            && $registration->internship_status !== InternshipRegistration::STATUS_NEW;
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
    public function buildOutboundRowPayload(InternshipRegistration $registration, ?SpreadsheetSchemaVersion $schema = null): array
    {
        if (!$this->isEligibleForSync($registration)) {
            throw new DomainException('Pendaftaran berstatus draft/belum submitted tidak eligible untuk sinkronisasi Spreadsheet.');
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
            'status_label', 'ket' => $registration->status_label,
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
        $attachment = $registration->attachments()->whereNotNull('drive_web_view_link')->first();
        if ($attachment && !empty($attachment->drive_web_view_link)) {
            return $attachment->drive_web_view_link;
        }

        return null;
    }
}

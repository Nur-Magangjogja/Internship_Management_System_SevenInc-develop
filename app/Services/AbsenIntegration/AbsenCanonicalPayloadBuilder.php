<?php

namespace App\Services\AbsenIntegration;

class AbsenCanonicalPayloadBuilder
{
    public function __construct(
        protected ?AbsenMasterDataResolver $resolver = null
    ) {
        $this->resolver = $resolver ?? app(AbsenMasterDataResolver::class);
    }

    /**
     * Membangun payload request HTTP kanonikal yang sesuai dengan kontrak receiver Absen Djuragan.
     */
    public function buildHttpRequestPayload(
        array $internalSnapshot,
        int $payloadVersion,
        string $idempotencyKey
    ): array {
        $institutionId = (int) ($internalSnapshot['institution_id'] ?? 0);
        $divisionId    = (int) ($internalSnapshot['division_id'] ?? 0);
        $brandId       = (int) ($internalSnapshot['brand_id'] ?? 0);

        // Resolusi master data ID secara tepercaya (tidak wajib melempar jika null, dikembalikan null jika tidak ada)
        $schoolId         = $this->resolver->resolveSchoolId($institutionId, false);
        $targetDivisionId = $this->resolver->resolveDivisionId($divisionId, false);
        $targetBrandId    = $this->resolver->resolveBrandId($brandId, false);

        return [
            'external_system'          => 'ims_seveninc',
            'external_user_id'         => (string) ($internalSnapshot['user_id'] ?? ''),
            'external_registration_id' => (string) ($internalSnapshot['registration_id'] ?? ''),
            'payload_version'          => $payloadVersion,
            'idempotency_key'          => $idempotencyKey,
            'email'                    => strtolower(trim((string) ($internalSnapshot['email'] ?? ''))),
            'full_name'                => trim((string) ($internalSnapshot['fullname'] ?? '')),
            'phone'                    => trim((string) ($internalSnapshot['phone_number'] ?? '')),
            'gender'                   => trim((string) ($internalSnapshot['gender'] ?? '')),
            'religion'                 => trim((string) ($internalSnapshot['religion'] ?? '')),
            'born_date'                => !empty($internalSnapshot['birth_date']) ? substr((string) $internalSnapshot['birth_date'], 0, 10) : '',
            'birth_place'              => trim((string) ($internalSnapshot['birth_place'] ?? '')),
            'student_id'               => trim((string) ($internalSnapshot['student_id'] ?? '')),
            'school_id'                => $schoolId,
            'division_id'              => $targetDivisionId,
            'brand_id'                 => $targetBrandId,
            'start_date'               => !empty($internalSnapshot['start_date']) ? substr((string) $internalSnapshot['start_date'], 0, 10) : '',
            'end_date'                 => !empty($internalSnapshot['end_date']) ? substr((string) $internalSnapshot['end_date'], 0, 10) : '',
        ];
    }

    /**
     * Menghitung checksum SHA-256 kanonikal yang identik 100% dengan kalkulasi receiver Absen Djuragan.
     */
    public function calculateRemoteChecksum(array $requestData): string
    {
        $canonical = [
            'birth_place'              => trim((string) ($requestData['birth_place'] ?? '')),
            'born_date'                => !empty($requestData['born_date']) ? substr((string) $requestData['born_date'], 0, 10) : '',
            'brand_id'                 => isset($requestData['brand_id']) && $requestData['brand_id'] !== null ? (int) $requestData['brand_id'] : 0,
            'division_id'              => isset($requestData['division_id']) && $requestData['division_id'] !== null ? (int) $requestData['division_id'] : 0,
            'email'                    => strtolower(trim((string) ($requestData['email'] ?? ''))),
            'end_date'                 => !empty($requestData['end_date']) ? substr((string) $requestData['end_date'], 0, 10) : '',
            'external_registration_id' => trim((string) ($requestData['external_registration_id'] ?? '')),
            'external_system'          => trim((string) ($requestData['external_system'] ?? 'ims_seveninc')),
            'external_user_id'         => trim((string) ($requestData['external_user_id'] ?? '')),
            'full_name'                => trim((string) ($requestData['full_name'] ?? '')),
            'gender'                   => trim((string) ($requestData['gender'] ?? '')),
            'payload_version'          => (int) ($requestData['payload_version'] ?? 1),
            'phone'                    => trim((string) ($requestData['phone'] ?? '')),
            'school_id'                => isset($requestData['school_id']) && $requestData['school_id'] !== null ? (int) $requestData['school_id'] : 0,
            'start_date'               => !empty($requestData['start_date']) ? substr((string) $requestData['start_date'], 0, 10) : '',
            'student_id'               => trim((string) ($requestData['student_id'] ?? '')),
        ];

        ksort($canonical);

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}

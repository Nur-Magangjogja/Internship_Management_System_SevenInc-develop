<?php

namespace App\Services;

use App\Models\FollowUpCycle;
use App\Models\Institution;
use App\Models\Mou;
use App\Models\MouTemplate;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class MouService
{
    /**
     * Generate dokumen MOU baru dan simpan snapshot datanya secara atomik & mandiri (self-contained).
     */
    public function generateMou(array $data, ?User $actor = null): Mou
    {
        return DB::transaction(function () use ($data, $actor) {
            $institution = Institution::with('contacts')->findOrFail($data['institution_id']);

            // Validasi apakah institusi sedang aktif
            if (!$institution->is_active) {
                throw new InvalidArgumentException('Institusi yang dipilih sedang berstatus tidak aktif.');
            }

            // Prerequisite: Active MOU Guard
            $activeMou = Mou::where('institution_id', $institution->id)
                ->where('status', Mou::STATUS_ACTIVE)
                ->where(function ($query) {
                    $query->whereNull('effective_end')
                        ->orWhere('effective_end', '>=', now()->toDateString());
                })
                ->first();

            if ($activeMou && empty($data['supersedes_mou_id']) && empty($data['is_replacement'])) {
                throw new InvalidArgumentException(
                    "Institusi '{$institution->name}' sudah memiliki MOU aktif yang masih berlaku ({$activeMou->company_mou_number}). Pembuatan MOU baru hanya diizinkan melalui pergantian/perpanjangan (supersede) resmi."
                );
            }

            if (!empty($data['supersedes_mou_id'])) {
                $supersededMou = Mou::find($data['supersedes_mou_id']);
                if (!$supersededMou || (int) $supersededMou->institution_id !== (int) $institution->id || $supersededMou->status !== Mou::STATUS_ACTIVE) {
                    throw new InvalidArgumentException('Dokumen MOU yang akan digantikan tidak valid, bukan milik institusi ini, atau tidak sedang berstatus aktif.');
                }
            }

            // Prerequisite: Validasi keterkaitan follow-up cycle (wajib untuk HR maupun Admin)
            if (empty($data['followup_cycle_id'])) {
                throw new InvalidArgumentException('Siklus follow-up yang valid dan selesai dengan kesediaan kerja sama (willing) wajib disertakan.');
            }
            $cycle = FollowUpCycle::find($data['followup_cycle_id']);
            if (!$cycle || (int) $cycle->institution_id !== (int) $institution->id) {
                throw new InvalidArgumentException('Siklus follow-up yang dipilih tidak sesuai dengan institusi target.');
            }
            if ($cycle->status !== FollowUpCycle::STATUS_COMPLETED || $cycle->final_result !== FollowUpCycle::RESULT_WILLING) {
                throw new InvalidArgumentException('Siklus follow-up harus berstatus selesai dengan hasil bersedia (willing) kerja sama.');
            }

            // Prerequisite: Validasi konfirmasi kesediaan kerja sama
            if (array_key_exists('cooperation_confirmed', $data)) {
                $confirmed = filter_var($data['cooperation_confirmed'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($confirmed === false) {
                    throw new InvalidArgumentException('Konfirmasi kesediaan kerja sama institusi diperlukan sebelum menerbitkan MOU.');
                }
            }

            // Ambil template MOU yang ditentukan atau template aktif default
            $template = !empty($data['mou_template_id'])
                ? MouTemplate::findOrFail($data['mou_template_id'])
                : MouTemplate::active()->latest('version_number')->first();

            if (!$template) {
                throw new InvalidArgumentException('Belum ada template MOU aktif yang dapat digunakan. Silakan aktifkan template terlebih dahulu.');
            }

            // Cek keunikan nomor MOU perusahaan
            $companyMouNumber = trim($data['company_mou_number']);
            if (Mou::where('company_mou_number', $companyMouNumber)->exists()) {
                throw new InvalidArgumentException("Nomor MOU Perusahaan '{$companyMouNumber}' sudah pernah digunakan.");
            }

            // Siapkan default snapshot values dari template
            $templateDefaults = $template->getCompanyDefaultValues();
            $pageConfig       = $template->getPageConfig();
            $mediaAssets      = $template->getMediaAssets();

            $institutionLogoPath = !empty($data['institution_logo_path'])
                ? $data['institution_logo_path']
                : ($institution->logo_url ?? ($mediaAssets['institution_logo'] ?? '/images/mou/institution-logo-placeholder.png'));

            $companyLogoPath = !empty($data['company_logo_path'])
                ? $data['company_logo_path']
                : ($mediaAssets['company_logo'] ?? '/images/mou/seven-inc-logo.png');

            if (!empty($data['brand_id'])) {
                $brand = \App\Models\Brand::find($data['brand_id']);
                if ($brand && $brand->logo_url) {
                    $companyLogoPath = $brand->logo_url;
                }
            }

            $mediaAssets['company_logo'] = $companyLogoPath;
            $mediaAssets['institution_logo'] = $institutionLogoPath;
            $pageConfig['company_logo'] = $companyLogoPath;
            $pageConfig['company_logo_path'] = $companyLogoPath;
            $pageConfig['institution_logo'] = $institutionLogoPath;
            $pageConfig['institution_logo_path'] = $institutionLogoPath;

            $firstPartyName        = $data['first_party_institution_name'] ?? $institution->name;
            $firstPartySignatory   = $data['first_party_signatory_name'] ?? ($institution->contacts->first()?->name ?? '-');
            $firstPartyTitle       = $data['first_party_signatory_title'] ?? ($institution->contacts->first()?->position_title ?? 'Pimpinan Institusi');
            $firstPartyAddress     = $data['first_party_address'] ?? $institution->address;
            $firstPartyPhone       = $data['first_party_phone'] ?? $institution->phone;
            $firstPartyFax         = $data['first_party_fax'] ?? ($data['institution_fax'] ?? '-');
            $firstPartyEmail       = $data['first_party_email'] ?? $institution->email;
            $firstPartyUnit        = $data['first_party_correspondence_unit'] ?? ($institution->correspondence_unit ?: 'Kepala Bidang Kerjasama Dalam Negeri');

            $secondPartyName       = $data['second_party_company_name'] ?? ($templateDefaults['company_name'] ?? 'SEVEN INC');
            $secondPartySignatory  = $data['second_party_signatory_name'] ?? ($templateDefaults['company_signatory_name'] ?? 'Rekario Danny Sanjaya, S.Kom.');
            $secondPartyTitle      = $data['second_party_signatory_title'] ?? ($templateDefaults['company_signatory_title'] ?? 'Pimpinan');
            $secondPartyAddress    = $data['second_party_address'] ?? ($templateDefaults['company_address'] ?? 'Jl. Janti Gg. Arjuna No.59, Karangjambe, Banguntapan, Bantul, Yogyakarta 55198');
            $secondPartyPhone      = $data['second_party_phone'] ?? ($templateDefaults['company_phone'] ?? '089529002944');
            $secondPartyFax        = $data['second_party_fax'] ?? ($templateDefaults['company_fax'] ?? '-');
            $secondPartyEmail      = $data['second_party_email'] ?? ($templateDefaults['company_email'] ?? 'magangjogjadotcom@gmail.com');
            $secondPartyUnit       = $data['second_party_correspondence_unit'] ?? ($templateDefaults['company_correspondence_unit'] ?? 'CEO');

            $agreementPlace        = $data['agreement_place'] ?? ($templateDefaults['agreement_place'] ?? 'Yogyakarta');
            $agreementDate         = $data['agreement_date'] ?? now()->toDateString();
            $effectiveStart        = $data['effective_start'] ?? now()->toDateString();
            $effectiveEnd          = $data['effective_end'] ?? now()->addYears(5)->toDateString();

            // Hitung token tanggal Bahasa Indonesia & durasi dari tanggal perjanjian
            $computedDates = MouDocumentRenderer::computeDateAndDurationTokens(
                $agreementDate,
                $effectiveStart,
                $effectiveEnd,
                $data['agreement_duration'] ?? ($templateDefaults['agreement_duration'] ?? '5 (lima) tahun')
            );

            $agreementDuration = !empty($data['agreement_duration'])
                ? $data['agreement_duration']
                : $computedDates['agreement_duration'];

            // Kumpulkan snapshot payload lengkap (termasuk _page_config & _media_assets) agar historis 100% mandiri
            $snapshotData = [
                'institution_name'                => $firstPartyName,
                'institution_logo'                => $institutionLogoPath,
                'institution_logo_path'           => $institutionLogoPath,
                'institution_mou_number'          => $data['institution_mou_number'] ?? '-',
                'institution_address'             => $firstPartyAddress,
                'institution_correspondence_unit' => $firstPartyUnit,
                'institution_phone'               => $firstPartyPhone,
                'institution_fax'                 => $firstPartyFax,
                'institution_email'               => $firstPartyEmail,
                'institution_signatory_name'      => $firstPartySignatory,
                'institution_signatory_title'     => $firstPartyTitle,
                'company_name'                    => $secondPartyName,
                'company_logo'                    => $companyLogoPath,
                'company_logo_path'               => $companyLogoPath,
                'brand_id'                        => !empty($data['brand_id']) ? (int) $data['brand_id'] : null,
                'company_mou_number'              => $companyMouNumber,
                'company_address'                 => $secondPartyAddress,
                'company_correspondence_unit'     => $secondPartyUnit,
                'company_phone'                   => $secondPartyPhone,
                'company_fax'                     => $secondPartyFax,
                'company_email'                   => $secondPartyEmail,
                'company_signatory_name'          => $secondPartySignatory,
                'company_signatory_title'         => $secondPartyTitle,
                'agreement_place'                 => $agreementPlace,
                'agreement_day'                   => $computedDates['agreement_day'],
                'agreement_day_name'              => $computedDates['agreement_day_name'],
                'agreement_day_number'            => $computedDates['agreement_day_number'],
                'agreement_day_words'             => $computedDates['agreement_day_words'],
                'agreement_month_name'            => $computedDates['agreement_month_name'],
                'agreement_month_number'          => $computedDates['agreement_month_number'],
                'agreement_year_number'           => $computedDates['agreement_year_number'],
                'agreement_year_words'            => $computedDates['agreement_year_words'],
                'agreement_date'                  => \Carbon\Carbon::parse($agreementDate)->isoFormat('D MMMM Y'),
                'agreement_date_short'            => $computedDates['agreement_date_short'],
                'agreement_date_words'            => $computedDates['agreement_date_words'],
                'effective_start'                 => $effectiveStart ? \Carbon\Carbon::parse($effectiveStart)->isoFormat('D MMMM Y') : '-',
                'effective_end'                   => $effectiveEnd ? \Carbon\Carbon::parse($effectiveEnd)->isoFormat('D MMMM Y') : '-',
                'agreement_duration'              => $agreementDuration,
                'raw_agreement_date'              => $agreementDate,
                'raw_effective_start'             => $effectiveStart,
                'raw_effective_end'               => $effectiveEnd,
                '_page_config'                    => $pageConfig,
                '_media_assets'                   => $mediaAssets,
            ];

            // Pastikan seluruh token & aset gambar di dalam Header/Footer (_page_config) juga dirender ke dalam snapshot mandiri
            $snapshotData['_page_config'] = MouDocumentRenderer::renderPageConfig($template, $snapshotData, true);

            // Render naskah HTML dokumen sebagai snapshot terbit mandiri (Base64 images + inline canonical CSS)
            $renderedHtml = $template->renderContent($snapshotData, true);

            // Simpan berkas fisik dokumen draf ke media penyimpanan (storage disk public)
            $safeNumber = Str::slug(str_replace('/', '-', $companyMouNumber), '-');
            $generatedFileName = 'MOU-GENERATED-' . $safeNumber . '-' . time() . '.html';
            $generatedFilePath = 'documents/mou/generated/' . $generatedFileName;

            $stored = Storage::disk('public')->put($generatedFilePath, $renderedHtml);
            if (!$stored || !Storage::disk('public')->exists($generatedFilePath)) {
                throw new RuntimeException('Gagal menyimpan berkas fisik dokumen MOU ke media penyimpanan.');
            }

            // Simpan record MOU baru
            $mou = Mou::create([
                'institution_id'                  => $institution->id,
                'followup_cycle_id'               => $data['followup_cycle_id'] ?? null,
                'mou_template_id'                 => $template->id,
                'brand_id'                        => !empty($data['brand_id']) ? (int) $data['brand_id'] : null,
                'company_mou_number'              => $companyMouNumber,
                'institution_mou_number'          => $data['institution_mou_number'] ?? null,
                'first_party_institution_name'    => $firstPartyName,
                'first_party_signatory_name'      => $firstPartySignatory,
                'first_party_signatory_title'     => $firstPartyTitle,
                'first_party_address'             => $firstPartyAddress,
                'first_party_phone'               => $firstPartyPhone,
                'first_party_email'               => $firstPartyEmail,
                'first_party_correspondence_unit' => $firstPartyUnit,
                'second_party_company_name'       => $secondPartyName,
                'second_party_signatory_name'     => $secondPartySignatory,
                'second_party_signatory_title'    => $secondPartyTitle,
                'second_party_address'            => $secondPartyAddress,
                'second_party_phone'              => $secondPartyPhone,
                'second_party_email'              => $secondPartyEmail,
                'agreement_place'                 => $agreementPlace,
                'agreement_date'                  => $agreementDate,
                'effective_start'                 => $effectiveStart,
                'effective_end'                   => $effectiveEnd,
                'status'                          => Mou::STATUS_GENERATED,
                'rendered_document_html'          => $renderedHtml,
                'generated_file_path'             => $generatedFilePath,
                'generated_at'                    => now(),
                'generated_by'                    => $actor?->id,
                'render_data_snapshot'            => $snapshotData,
                'notes'                           => $data['notes'] ?? null,
                'supersedes_mou_id'               => $data['supersedes_mou_id'] ?? null,
                'supersede_reason'                => $data['supersede_reason'] ?? null,
            ]);

            ActivityLogger::log(
                action: 'mou_generated',
                description: "Men-generate dokumen MOU: {$mou->company_mou_number} untuk {$institution->name}",
                subject: $mou,
                after: [
                    'company_mou_number' => $mou->company_mou_number,
                    'institution'        => $institution->name,
                    'status'             => $mou->status,
                ],
                actor: $actor
            );

            // Integrasikan dengan DocumentPublishingService & GeneratedDocument
            try {
                if (class_exists(\App\Services\DocumentPublishingService::class)) {
                    app(\App\Services\DocumentPublishingService::class)->publishMouDocument($mou, $actor);
                }
            } catch (\Throwable $pubEx) {
                \Illuminate\Support\Facades\Log::warning("Gagal menerbitkan GeneratedDocument untuk MOU {$mou->company_mou_number}: " . $pubEx->getMessage());
            }

            return $mou;
        });
    }

    /**
     * Tandai dokumen telah dikirimkan / diserahkan ke pihak institusi mitra.
     */
    public function markDelivered(Mou $mou, array $deliveryData, ?User $actor = null): Mou
    {
        if ($mou->status !== Mou::STATUS_GENERATED) {
            throw new \DomainException("Hanya dokumen MOU dengan status 'generated' yang dapat ditandai serah terima (delivered).");
        }

        return DB::transaction(function () use ($mou, $deliveryData, $actor) {
            $beforeStatus = $mou->status;

            $mou->update([
                'status'             => Mou::STATUS_DELIVERED,
                'delivered_at'       => $deliveryData['delivered_at'] ?? now(),
                'delivery_method'    => $deliveryData['delivery_method'] ?? 'email',
                'delivery_recipient' => $deliveryData['delivery_recipient'] ?? null,
                'delivery_notes'     => $deliveryData['delivery_notes'] ?? null,
                'delivered_by'       => $actor?->id,
            ]);

            ActivityLogger::log(
                action: 'mou_delivered',
                description: "MOU {$mou->company_mou_number} telah diserahkan ke pihak {$mou->first_party_institution_name} via {$mou->delivery_method}",
                subject: $mou,
                before: ['status' => $beforeStatus],
                after: [
                    'status'             => $mou->status,
                    'delivery_method'    => $mou->delivery_method,
                    'delivery_recipient' => $mou->delivery_recipient,
                ],
                actor: $actor
            );

            return $mou;
        });
    }

    /**
     * Unggah berkas naskah yang telah ditandatangani lengkap & otomatis aktifkan MOU.
     * Menerapkan Guardrail 1 (Active MOU Race Safety) dan Guardrail 2 (Signed File Failure Cleanup).
     */
    public function uploadSignedMou(Mou $mou, UploadedFile $file, array $signedData, ?User $actor = null): Mou
    {
        // Validasi ekstensi dan tipe file PDF
        $extension = strtolower($file->getClientOriginalExtension());
        $mime = strtolower((string) $file->getMimeType());
        if ($extension !== 'pdf' || !str_contains($mime, 'pdf')) {
            throw new InvalidArgumentException('Berkas bertandatangan harus berformat PDF.');
        }

        // Strict signed activation sequence (Blocker 2): HANYA boleh dari status 'delivered'
        if ($mou->status !== Mou::STATUS_DELIVERED) {
            throw new \DomainException("Hanya dokumen MOU dengan status 'delivered' yang dapat diunggah naskah bertandatangan dan diaktifkan.");
        }

        $storedPath = null;
        try {
            // Simpan file ke storage public
            $safeNumber = Str::slug(str_replace('/', '-', $mou->company_mou_number), '-');
            $fileName = 'MOU-SIGNED-' . $safeNumber . '-' . time() . '.pdf';
            $storedPath = $file->storeAs('documents/mou/signed', $fileName, 'public');

            if (!$storedPath || !Storage::disk('public')->exists($storedPath)) {
                throw new RuntimeException('Gagal menyimpan berkas fisik bertandatangan ke media penyimpanan.');
            }

            return DB::transaction(function () use ($mou, $storedPath, $signedData, $actor) {
                // Lock current MOU row
                $lockedMou = Mou::where('id', $mou->id)->lockForUpdate()->firstOrFail();

                if ($lockedMou->status !== Mou::STATUS_DELIVERED) {
                    throw new \DomainException("Status MOU telah berubah dan tidak lagi berstatus 'delivered'.");
                }

                // Guardrail 1: Active MOU Race Safety
                if ($lockedMou->supersedes_mou_id) {
                    // Replacement flow: lock explicit old target
                    $oldMou = Mou::where('id', $lockedMou->supersedes_mou_id)
                        ->lockForUpdate()
                        ->first();

                    if (!$oldMou || (int) $oldMou->institution_id !== (int) $lockedMou->institution_id || $oldMou->status !== Mou::STATUS_ACTIVE) {
                        throw new \DomainException("Dokumen MOU target penggantian tidak valid, bukan milik institusi yang sama, atau sudah tidak berstatus aktif.");
                    }

                    // Pastikan tidak ada competing active MOU lain pada institusi selain old target
                    $competingActiveCount = Mou::where('institution_id', $lockedMou->institution_id)
                        ->where('id', '!=', $oldMou->id)
                        ->where('id', '!=', $lockedMou->id)
                        ->where('status', Mou::STATUS_ACTIVE)
                        ->lockForUpdate()
                        ->count();

                    if ($competingActiveCount > 0) {
                        throw new \DomainException("Terdapat konflik MOU aktif lain pada institusi target.");
                    }

                    // Supersede only explicit target
                    $oldMou->update([
                        'status'           => Mou::STATUS_SUPERSEDED,
                        'superseded_by_id' => $lockedMou->id,
                        'superseded_at'    => now(),
                        'supersede_reason' => $lockedMou->supersede_reason ?? ('Digantikan oleh MOU ' . $lockedMou->company_mou_number),
                    ]);

                    ActivityLogger::log(
                        action: 'mou_superseded',
                        description: "MOU {$oldMou->company_mou_number} digantikan oleh MOU {$lockedMou->company_mou_number}",
                        subject: $oldMou,
                        actor: $actor
                    );
                } else {
                    // Normal activation flow (non-replacement): must not have ANY active MOU for this institution
                    $activeExists = Mou::where('institution_id', $lockedMou->institution_id)
                        ->where('id', '!=', $lockedMou->id)
                        ->where('status', Mou::STATUS_ACTIVE)
                        ->lockForUpdate()
                        ->exists();

                    if ($activeExists) {
                        throw new \DomainException("Institusi ini telah memiliki MOU aktif yang berlaku. Gunakan alur pergantian (supersede) jika ingin menggantikan.");
                    }
                }

                $beforeStatus = $lockedMou->status;

                $updateData = [
                    'status'             => Mou::STATUS_ACTIVE,
                    'signed_file_path'   => $storedPath,
                    'signed_at'          => $signedData['signed_at'] ?? now(),
                    'signed_uploaded_by' => $actor?->id,
                ];

                if (!empty($signedData['institution_mou_number'])) {
                    $updateData['institution_mou_number'] = $signedData['institution_mou_number'];
                }
                if (!empty($signedData['effective_start'])) {
                    $updateData['effective_start'] = $signedData['effective_start'];
                }
                if (!empty($signedData['effective_end'])) {
                    $updateData['effective_end'] = $signedData['effective_end'];
                }

                $lockedMou->update($updateData);

                ActivityLogger::log(
                    action: 'mou_activated',
                    description: "MOU {$lockedMou->company_mou_number} untuk {$lockedMou->first_party_institution_name} kini berstatus AKTIF (Signed document uploaded)",
                    subject: $lockedMou,
                    before: ['status' => $beforeStatus],
                    after: [
                        'status'           => $lockedMou->status,
                        'signed_file_path' => $lockedMou->signed_file_path,
                        'signed_at'        => $lockedMou->signed_at,
                    ],
                    actor: $actor
                );

                return $lockedMou;
            });
        } catch (\Throwable $e) {
            // Guardrail 2: Signed File Failure Cleanup
            if ($storedPath && Storage::disk('public')->exists($storedPath)) {
                Storage::disk('public')->delete($storedPath);
            }
            throw $e;
        }
    }

    /**
     * Batalkan proses naskah MOU.
     */
    public function cancelMou(Mou $mou, string $reason, ?User $actor = null): Mou
    {
        return DB::transaction(function () use ($mou, $reason, $actor) {
            $beforeStatus = $mou->status;

            $mou->update([
                'status' => Mou::STATUS_CANCELLED,
                'notes'  => trim(($mou->notes ? $mou->notes . "\n" : '') . "Dibatalkan pada " . now()->toDateTimeString() . " dengan alasan: " . $reason),
            ]);

            ActivityLogger::log(
                action: 'mou_cancelled',
                description: "MOU {$mou->company_mou_number} dibatalkan. Alasan: {$reason}",
                subject: $mou,
                before: ['status' => $beforeStatus],
                after:  ['status' => $mou->status, 'reason' => $reason],
                actor: $actor
            );

            return $mou;
        });
    }

    /**
     * Otomatis tandai MOU aktif yang telah melewati batas effective_end sebagai expired.
     */
    public function markExpiredMous(): int
    {
        $expiredMous = Mou::where('status', Mou::STATUS_ACTIVE)
            ->whereNotNull('effective_end')
            ->where('effective_end', '<', now()->toDateString())
            ->get();

        $count = 0;
        foreach ($expiredMous as $mou) {
            $mou->update(['status' => Mou::STATUS_EXPIRED]);
            $count++;

            ActivityLogger::log(
                action: 'mou_expired',
                description: "MOU {$mou->company_mou_number} telah kedaluwarsa (effective_end: {$mou->effective_end->toDateString()})",
                subject: $mou
            );
        }

        return $count;
    }
}

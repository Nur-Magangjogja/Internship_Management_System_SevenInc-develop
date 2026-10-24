<?php

namespace App\Services\Admin;

use App\Models\InternshipRegistration;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AcceptedInternAnalyticsService
{
    public const TIMEZONE = 'Asia/Jakarta';

    public const SOURCE_DIRECT     = 'direct';
    public const SOURCE_INTEGRATED = 'integrated';

    public const LABEL_DIRECT     = 'Pendaftaran Langsung SERTIF';
    public const LABEL_INTEGRATED = 'Data Terintegrasi';

    public const COLOR_DIRECT_BG     = '#2D8659';
    public const COLOR_DIRECT_BORDER = '#1E5E3E';

    public const COLOR_INTEGRATED_BG     = '#4F46E5';
    public const COLOR_INTEGRATED_BORDER = '#3730A3';

    /**
     * Dapatkan data grafik statistik pemagang diterima berdasarkan periode dan filter.
     *
     * @param string $period 'daily' | 'monthly' | 'yearly' | 'custom'
     * @param array $options Filter parameter
     * @return array
     */
    public function getChartData(string $period = 'monthly', array $options = []): array
    {
        return match ($period) {
            'daily'   => $this->getDailyData($options),
            'monthly' => $this->getMonthlyData($options),
            'yearly'  => $this->getYearlyData($options),
            'custom'  => $this->getCustomData($options),
            default   => throw new InvalidArgumentException("Tipe periode '{$period}' tidak valid. Pilihan: daily, monthly, yearly, custom."),
        };
    }

    /**
     * 1. Periode Harian: dikelompokkan per jam.
     */
    public function getDailyData(array $options = []): array
    {
        $dateInput = $options['date'] ?? now(self::TIMEZONE)->toDateString();
        $startHour = isset($options['start_hour']) ? max(0, min(23, (int) $options['start_hour'])) : 0;
        $endHour   = isset($options['end_hour']) ? max(0, min(23, (int) $options['end_hour'])) : 23;

        if ($startHour > $endHour) {
            throw new InvalidArgumentException('Jam mulai tidak boleh lebih besar dari jam akhir.');
        }

        try {
            $baseDate = Carbon::parse($dateInput, self::TIMEZONE)->startOfDay();
        } catch (\Throwable) {
            throw new InvalidArgumentException("Format tanggal '{$dateInput}' tidak valid. Gunakan format YYYY-MM-DD.");
        }

        $startDate = (clone $baseDate)->setTime($startHour, 0, 0);
        $endDate   = (clone $baseDate)->setTime($endHour, 59, 59);

        // Buat bucket per jam
        $labels = [];
        $buckets = [];
        for ($h = $startHour; $h <= $endHour; $h++) {
            $bucketKey = sprintf('%02d:00', $h);
            $labels[] = $bucketKey;
            $buckets[$h] = [
                self::SOURCE_DIRECT     => 0,
                self::SOURCE_INTEGRATED => 0,
            ];
        }

        $records = $this->queryAcceptedRegistrations($startDate, $endDate);

        $seenFingerprints = [];
        foreach ($records as $reg) {
            if ($this->isDuplicateEvent($reg, $seenFingerprints)) {
                continue;
            }

            $dt = Carbon::parse($reg->created_at, self::TIMEZONE);
            $hour = (int) $dt->format('H');

            if (isset($buckets[$hour])) {
                $source = $this->resolveSourceCategory($reg);
                $buckets[$hour][$source]++;
            }
        }

        $directData = [];
        $integratedData = [];
        foreach ($buckets as $b) {
            $directData[]     = $b[self::SOURCE_DIRECT];
            $integratedData[] = $b[self::SOURCE_INTEGRATED];
        }

        $periodLabel = $baseDate->locale('id')->translatedFormat('l, d F Y');
        if ($startHour > 0 || $endHour < 23) {
            $periodLabel .= sprintf(' (%02d:00 - %02d:00)', $startHour, $endHour);
        }

        return $this->formatChartResponse(
            period: 'daily',
            labels: $labels,
            directData: $directData,
            integratedData: $integratedData,
            periodLabel: $periodLabel,
            startDate: $startDate,
            endDate: $endDate,
            metadata: ['group_type' => 'hourly', 'start_hour' => $startHour, 'end_hour' => $endHour]
        );
    }

    /**
     * 2. Periode Bulanan: dikelompokkan per tanggal dalam bulan tersebut.
     */
    public function getMonthlyData(array $options = []): array
    {
        $year  = isset($options['year']) ? (int) $options['year'] : (int) now(self::TIMEZONE)->format('Y');
        $month = isset($options['month']) ? (int) $options['month'] : (int) now(self::TIMEZONE)->format('n');

        if ($year < 2000 || $year > 2100) {
            throw new InvalidArgumentException("Tahun '{$year}' tidak valid.");
        }
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException("Bulan '{$month}' harus antara 1 dan 12.");
        }

        $startOfMonth = Carbon::create($year, $month, 1, 0, 0, 0, self::TIMEZONE)->startOfMonth();
        $endOfMonth   = (clone $startOfMonth)->endOfMonth();
        $daysInMonth  = $startOfMonth->daysInMonth;

        $labels = [];
        $buckets = [];
        $monthShort = $startOfMonth->locale('id')->translatedFormat('M');

        for ($d = 1; $d <= $daysInMonth; $d++) {
            $label = sprintf('%02d %s', $d, $monthShort);
            $labels[] = $label;
            $buckets[$d] = [
                self::SOURCE_DIRECT     => 0,
                self::SOURCE_INTEGRATED => 0,
            ];
        }

        $records = $this->queryAcceptedRegistrations($startOfMonth, $endOfMonth);

        $seenFingerprints = [];
        foreach ($records as $reg) {
            if ($this->isDuplicateEvent($reg, $seenFingerprints)) {
                continue;
            }

            $dt = Carbon::parse($reg->created_at, self::TIMEZONE);
            $day = (int) $dt->format('j');

            if (isset($buckets[$day])) {
                $source = $this->resolveSourceCategory($reg);
                $buckets[$day][$source]++;
            }
        }

        $directData = [];
        $integratedData = [];
        foreach ($buckets as $b) {
            $directData[]     = $b[self::SOURCE_DIRECT];
            $integratedData[] = $b[self::SOURCE_INTEGRATED];
        }

        $periodLabel = $startOfMonth->locale('id')->translatedFormat('F Y');

        return $this->formatChartResponse(
            period: 'monthly',
            labels: $labels,
            directData: $directData,
            integratedData: $integratedData,
            periodLabel: $periodLabel,
            startDate: $startOfMonth,
            endDate: $endOfMonth,
            metadata: ['group_type' => 'daily', 'year' => $year, 'month' => $month, 'days_in_month' => $daysInMonth]
        );
    }

    /**
     * 3. Periode Tahunan: dikelompokkan Januari sampai Desember.
     */
    public function getYearlyData(array $options = []): array
    {
        $year = isset($options['year']) ? (int) $options['year'] : (int) now(self::TIMEZONE)->format('Y');

        if ($year < 2000 || $year > 2100) {
            throw new InvalidArgumentException("Tahun '{$year}' tidak valid.");
        }

        $startOfYear = Carbon::create($year, 1, 1, 0, 0, 0, self::TIMEZONE)->startOfYear();
        $endOfYear   = (clone $startOfYear)->endOfYear();

        $monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        $labels = $monthNames;
        $buckets = [];

        for ($m = 1; $m <= 12; $m++) {
            $buckets[$m] = [
                self::SOURCE_DIRECT     => 0,
                self::SOURCE_INTEGRATED => 0,
            ];
        }

        $records = $this->queryAcceptedRegistrations($startOfYear, $endOfYear);

        $seenFingerprints = [];
        foreach ($records as $reg) {
            if ($this->isDuplicateEvent($reg, $seenFingerprints)) {
                continue;
            }

            $dt = Carbon::parse($reg->created_at, self::TIMEZONE);
            $month = (int) $dt->format('n');

            if (isset($buckets[$month])) {
                $source = $this->resolveSourceCategory($reg);
                $buckets[$month][$source]++;
            }
        }

        $directData = [];
        $integratedData = [];
        foreach ($buckets as $b) {
            $directData[]     = $b[self::SOURCE_DIRECT];
            $integratedData[] = $b[self::SOURCE_INTEGRATED];
        }

        $periodLabel = "Tahun {$year}";

        return $this->formatChartResponse(
            period: 'yearly',
            labels: $labels,
            directData: $directData,
            integratedData: $integratedData,
            periodLabel: $periodLabel,
            startDate: $startOfYear,
            endDate: $endOfYear,
            metadata: ['group_type' => 'monthly', 'year' => $year]
        );
    }

    /**
     * 4. Periode Rentang Khusus: dikelompokkan secara proporsional sesuai rentang tanggal.
     */
    public function getCustomData(array $options = []): array
    {
        $startInput = $options['start_datetime'] ?? ($options['start_date'] ?? now(self::TIMEZONE)->subDays(30)->toDateString());
        $endInput   = $options['end_datetime'] ?? ($options['end_date'] ?? now(self::TIMEZONE)->toDateString());

        try {
            $startDate = Carbon::parse($startInput, self::TIMEZONE)->startOfMinute();
            $endDate   = Carbon::parse($endInput, self::TIMEZONE)->endOfMinute();
        } catch (\Throwable) {
            throw new InvalidArgumentException('Format tanggal/waktu awal atau akhir tidak valid.');
        }

        if ($startDate->greaterThan($endDate)) {
            throw new InvalidArgumentException('Waktu awal rentang tidak boleh lebih besar dari waktu akhir.');
        }

        // Batasi rentang maksimal 5 tahun untuk menjaga stabilitas performa query
        if ($startDate->diffInDays($endDate) > 1826) {
            throw new InvalidArgumentException('Rentang khusus maksimal 5 tahun.');
        }

        $diffHours = $startDate->diffInHours($endDate);
        $diffDays  = $startDate->diffInDays($endDate);

        // Granularitas otomatis
        if ($diffHours <= 48) {
            // Rentang <= 2 hari -> per jam
            $groupType = 'hourly';
        } elseif ($diffDays <= 62) {
            // Rentang <= 2 bulan -> per tanggal
            $groupType = 'daily';
        } elseif ($diffDays <= 730) {
            // Rentang <= 2 tahun -> per bulan
            $groupType = 'monthly';
        } else {
            // Rentang > 2 tahun -> per tahun
            $groupType = 'yearly';
        }

        // Susun bucket dan label
        $labels = [];
        $buckets = [];
        $cursor = clone $startDate;

        if ($groupType === 'hourly') {
            $cursor->startOfHour();
            while ($cursor->lte($endDate)) {
                $key = $cursor->format('Y-m-d H:00');
                $labels[] = $cursor->format('d/m H:00');
                $buckets[$key] = [
                    self::SOURCE_DIRECT     => 0,
                    self::SOURCE_INTEGRATED => 0,
                ];
                $cursor->addHour();
            }
        } elseif ($groupType === 'daily') {
            $cursor->startOfDay();
            while ($cursor->lte($endDate)) {
                $key = $cursor->format('Y-m-d');
                $labels[] = $cursor->locale('id')->translatedFormat('d M');
                $buckets[$key] = [
                    self::SOURCE_DIRECT     => 0,
                    self::SOURCE_INTEGRATED => 0,
                ];
                $cursor->addDay();
            }
        } elseif ($groupType === 'monthly') {
            $cursor->startOfMonth();
            while ($cursor->lte($endDate)) {
                $key = $cursor->format('Y-m');
                $labels[] = $cursor->locale('id')->translatedFormat('M Y');
                $buckets[$key] = [
                    self::SOURCE_DIRECT     => 0,
                    self::SOURCE_INTEGRATED => 0,
                ];
                $cursor->addMonth();
            }
        } else {
            $cursor->startOfYear();
            while ($cursor->lte($endDate)) {
                $key = $cursor->format('Y');
                $labels[] = $cursor->format('Y');
                $buckets[$key] = [
                    self::SOURCE_DIRECT     => 0,
                    self::SOURCE_INTEGRATED => 0,
                ];
                $cursor->addYear();
            }
        }

        $records = $this->queryAcceptedRegistrations($startDate, $endDate);

        $seenFingerprints = [];
        foreach ($records as $reg) {
            if ($this->isDuplicateEvent($reg, $seenFingerprints)) {
                continue;
            }

            $dt = Carbon::parse($reg->created_at, self::TIMEZONE);
            $key = match ($groupType) {
                'hourly'  => $dt->format('Y-m-d H:00'),
                'daily'   => $dt->format('Y-m-d'),
                'monthly' => $dt->format('Y-m'),
                'yearly'  => $dt->format('Y'),
            };

            if (isset($buckets[$key])) {
                $source = $this->resolveSourceCategory($reg);
                $buckets[$key][$source]++;
            }
        }

        $directData = [];
        $integratedData = [];
        foreach ($buckets as $b) {
            $directData[]     = $b[self::SOURCE_DIRECT];
            $integratedData[] = $b[self::SOURCE_INTEGRATED];
        }

        $periodLabel = $startDate->locale('id')->translatedFormat('d M Y H:i') . ' — ' . $endDate->locale('id')->translatedFormat('d M Y H:i');

        return $this->formatChartResponse(
            period: 'custom',
            labels: $labels,
            directData: $directData,
            integratedData: $integratedData,
            periodLabel: $periodLabel,
            startDate: $startDate,
            endDate: $endDate,
            metadata: ['group_type' => $groupType]
        );
    }

    /**
     * Jalankan query terarah ke database untuk pendaftar yang memenuhi syarat penerimaan.
     * Menggunakan index created_at dan status penerimaan valid.
     */
    protected function queryAcceptedRegistrations(Carbon $startDate, Carbon $endDate): \Illuminate\Support\Collection
    {
        $acceptedStatuses = [
            InternshipRegistration::STATUS_ACCEPTED,
            InternshipRegistration::STATUS_ACTIVE,
            InternshipRegistration::STATUS_COMPLETED,
        ];

        // Format tanggal dalam standar UTC/string database sesuai koneksi
        $startStr = $startDate->toDateTimeString();
        $endStr   = $endDate->toDateTimeString();

        $columns = ['id', 'created_at', 'internship_status'];
        if (\Illuminate\Support\Facades\Schema::hasColumn('internship_registrations', 'is_outsider')) {
            $columns[] = 'is_outsider';
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn('internship_registrations', 'registration_provenance')) {
            $columns[] = 'registration_provenance';
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn('internship_registrations', 'event_fingerprint')) {
            $columns[] = 'event_fingerprint';
        }

        return InternshipRegistration::query()
            ->select($columns)
            ->where(function (Builder $query) use ($acceptedStatuses) {
                // Status diterima langsung
                $query->whereIn('internship_status', $acceptedStatuses);

                // Khusus status 'exited', hanya dihitung jika ada bukti log penerimaan sebelumnya
                $query->orWhere(function (Builder $exitedQuery) {
                    $exitedQuery->where('internship_status', InternshipRegistration::STATUS_EXITED);
                    if (\Illuminate\Support\Facades\Schema::hasTable('activity_logs')) {
                        $exitedQuery->whereExists(function ($sub) {
                            $sub->select(DB::raw(1))
                                ->from('activity_logs')
                                ->whereColumn('activity_logs.subject_id', 'internship_registrations.id')
                                ->where('activity_logs.subject_type', InternshipRegistration::class)
                                ->where(function ($logQ) {
                                    $logQ->where('metadata', 'LIKE', '%"new_status":"accepted"%')
                                         ->orWhere('metadata', 'LIKE', '%"new_status":"active"%')
                                         ->orWhere('metadata', 'LIKE', '%"new_status":"completed"%')
                                         ->orWhere('after_data', 'LIKE', '%"internship_status":"accepted"%')
                                         ->orWhere('after_data', 'LIKE', '%"internship_status":"active"%')
                                         ->orWhere('after_data', 'LIKE', '%"internship_status":"completed"%');
                                });
                        });
                    } else {
                        $exitedQuery->whereRaw('0 = 1');
                    }
                });
            })
            ->where('created_at', '>=', $startStr)
            ->where('created_at', '<=', $endStr)
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Tentukan apakah baris ini merupakan duplikasi peristiwa yang sama.
     */
    protected function isDuplicateEvent(InternshipRegistration $reg, array &$seenFingerprints): bool
    {
        $fingerprint = trim((string) ($reg->event_fingerprint ?? ''));

        if (!empty($fingerprint)) {
            if (isset($seenFingerprints[$fingerprint])) {
                return true; // Duplikat peristiwa pendaftaran yang identik
            }
            $seenFingerprints[$fingerprint] = true;
        }

        return false;
    }

    /**
     * Klasifikasikan sumber pendaftaran:
     * - 'direct'     : Pendaftaran Langsung SERTIF (regular web)
     * - 'integrated' : Data Terintegrasi (outsider / historical import / spreadsheet)
     */
    public function resolveSourceCategory(InternshipRegistration $reg): string
    {
        $isIntegrated = (!empty($reg->is_outsider))
            || ($reg->registration_provenance === 'historical_import')
            || ($reg->registration_provenance === 'reconciled_sheet');

        return $isIntegrated ? self::SOURCE_INTEGRATED : self::SOURCE_DIRECT;
    }

    /**
     * Format payload respons data grafik sesuai Chart.js stacked bar chart.
     */
    protected function formatChartResponse(
        string $period,
        array $labels,
        array $directData,
        array $integratedData,
        string $periodLabel,
        Carbon $startDate,
        Carbon $endDate,
        array $metadata = []
    ): array {
        $totalDirect     = array_sum($directData);
        $totalIntegrated = array_sum($integratedData);
        $totalAccepted   = $totalDirect + $totalIntegrated;

        return [
            'period'   => $period,
            'timezone' => self::TIMEZONE,
            'labels'   => $labels,
            'datasets' => [
                [
                    'label'           => self::LABEL_DIRECT,
                    'key'             => self::SOURCE_DIRECT,
                    'backgroundColor' => self::COLOR_DIRECT_BG,
                    'borderColor'     => self::COLOR_DIRECT_BORDER,
                    'borderWidth'     => 1,
                    'borderRadius'    => 4,
                    'data'            => $directData,
                    'total'           => $totalDirect,
                ],
                [
                    'label'           => self::LABEL_INTEGRATED,
                    'key'             => self::SOURCE_INTEGRATED,
                    'backgroundColor' => self::COLOR_INTEGRATED_BG,
                    'borderColor'     => self::COLOR_INTEGRATED_BORDER,
                    'borderWidth'     => 1,
                    'borderRadius'    => 4,
                    'data'            => $integratedData,
                    'total'           => $totalIntegrated,
                ],
            ],
            'summary' => [
                'total_accepted'   => $totalAccepted,
                'total_direct'     => $totalDirect,
                'total_integrated' => $totalIntegrated,
                'period_label'     => $periodLabel,
                'start_date'       => $startDate->format('Y-m-d H:i:s'),
                'end_date'         => $endDate->format('Y-m-d H:i:s'),
                'has_data'         => $totalAccepted > 0,
            ],
            'metadata' => $metadata,
        ];
    }
}

<?php

namespace Tests\Unit;

use App\Models\InternshipRegistration;
use App\Services\Admin\AcceptedInternAnalyticsService;
use Carbon\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class AcceptedInternAnalyticsServiceTest extends TestCase
{
    protected AcceptedInternAnalyticsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AcceptedInternAnalyticsService();
    }

    /**
     * Memverifikasi bahwa periode yang tidak didukung akan memicu InvalidArgumentException.
     */
    public function test_invalid_period_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Tipe periode 'quarterly' tidak valid");

        $this->service->getChartData('quarterly');
    }

    /**
     * Memverifikasi validasi rentang jam pada filter harian.
     */
    public function test_daily_invalid_hours_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Jam mulai tidak boleh lebih besar dari jam akhir.');

        $this->service->getDailyData([
            'date'       => '2026-10-24',
            'start_hour' => 18,
            'end_hour'   => 8,
        ]);
    }

    /**
     * Memverifikasi validasi format tanggal pada filter harian.
     */
    public function test_daily_invalid_date_format_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Format tanggal 'invalid-date' tidak valid.");

        $this->service->getDailyData([
            'date' => 'invalid-date',
        ]);
    }

    /**
     * Memverifikasi validasi bulan dan tahun pada filter bulanan.
     */
    public function test_monthly_invalid_parameters_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Bulan '13' harus antara 1 dan 12.");

        $this->service->getMonthlyData([
            'year'  => 2026,
            'month' => 13,
        ]);
    }

    /**
     * Memverifikasi validasi rentang kustom (start_date > end_date).
     */
    public function test_custom_period_validation_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Waktu awal rentang tidak boleh lebih besar dari waktu akhir.');

        $this->service->getCustomData([
            'start_date' => '2026-10-25 10:00:00',
            'end_date'   => '2026-10-20 10:00:00',
        ]);
    }

    /**
     * Memverifikasi klasifikasi sumber pendaftar (direct vs integrated).
     */
    public function test_resolve_source_category(): void
    {
        // 1. Pendaftaran Langsung SERTIF (Reguler web)
        $regDirect = new InternshipRegistration([
            'is_outsider'             => false,
            'registration_provenance' => 'regular_web',
        ]);
        $this->assertSame(
            AcceptedInternAnalyticsService::SOURCE_DIRECT,
            $this->service->resolveSourceCategory($regDirect)
        );

        // 2. Outsider via Apps Script / Sheets
        $regOutsider = new InternshipRegistration([
            'is_outsider'             => true,
            'registration_provenance' => 'direct_outsider',
        ]);
        $this->assertSame(
            AcceptedInternAnalyticsService::SOURCE_INTEGRATED,
            $this->service->resolveSourceCategory($regOutsider)
        );

        // 3. Impor Historis (meskipun is_outsider = false)
        $regHistorical = new InternshipRegistration([
            'is_outsider'             => false,
            'registration_provenance' => 'historical_import',
        ]);
        $this->assertSame(
            AcceptedInternAnalyticsService::SOURCE_INTEGRATED,
            $this->service->resolveSourceCategory($regHistorical)
        );

        // 4. Rekonsiliasi Lembar Kerja
        $regReconciled = new InternshipRegistration([
            'is_outsider'             => false,
            'registration_provenance' => 'reconciled_sheet',
        ]);
        $this->assertSame(
            AcceptedInternAnalyticsService::SOURCE_INTEGRATED,
            $this->service->resolveSourceCategory($regReconciled)
        );
    }

    /**
     * Memverifikasi revisi istilah Outsider pada model InternshipRegistration (Presentation only).
     */
    public function test_provenance_label_attribute_on_model(): void
    {
        // Pendaftaran Langsung SERTIF
        $reg1 = new InternshipRegistration(['is_outsider' => false]);
        $this->assertSame('Pendaftaran Langsung SERTIF', $reg1->provenance_label);

        // Pendaftar Terintegrasi (waiting / pending)
        $reg2 = new InternshipRegistration(['is_outsider' => true, 'internship_status' => 'waiting']);
        $this->assertSame('Pendaftar Terintegrasi', $reg2->provenance_label);

        // Pemagang Terintegrasi (accepted / active)
        $reg3 = new InternshipRegistration(['is_outsider' => true, 'internship_status' => 'accepted']);
        $this->assertSame('Pemagang Terintegrasi', $reg3->provenance_label);

        $reg4 = new InternshipRegistration(['is_outsider' => true, 'internship_status' => 'active']);
        $this->assertSame('Pemagang Terintegrasi', $reg4->provenance_label);

        // Alumni Terintegrasi (completed)
        $reg5 = new InternshipRegistration(['is_outsider' => true, 'internship_status' => 'completed']);
        $this->assertSame('Alumni Terintegrasi', $reg5->provenance_label);
    }

    /**
     * Memverifikasi deduplikasi event fingerprint melalui reflection.
     */
    public function test_event_fingerprint_deduplication(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('isDuplicateEvent');
        $method->setAccessible(true);

        $seen = [];

        // Registrasi pertama dengan fingerprint
        $reg1 = new InternshipRegistration(['event_fingerprint' => 'fp_abc123']);
        $isDup1 = $method->invokeArgs($this->service, [$reg1, &$seen]);
        $this->assertFalse($isDup1, 'Event pertama tidak boleh dianggap duplikat');

        // Registrasi kedua dengan fingerprint identik
        $reg2 = new InternshipRegistration(['event_fingerprint' => 'fp_abc123']);
        $isDup2 = $method->invokeArgs($this->service, [$reg2, &$seen]);
        $this->assertTrue($isDup2, 'Event kedua dengan fingerprint sama wajib dianggap duplikat');

        // Registrasi ketiga dengan fingerprint berbeda
        $reg3 = new InternshipRegistration(['event_fingerprint' => 'fp_xyz789']);
        $isDup3 = $method->invokeArgs($this->service, [$reg3, &$seen]);
        $this->assertFalse($isDup3, 'Event dengan fingerprint baru tidak boleh dianggap duplikat');

        // Registrasi tanpa fingerprint (string kosong)
        $reg4 = new InternshipRegistration(['event_fingerprint' => '']);
        $isDup4 = $method->invokeArgs($this->service, [$reg4, &$seen]);
        $this->assertFalse($isDup4, 'Event tanpa fingerprint tidak boleh diduplikasi oleh null/empty');
    }

    /**
     * Memverifikasi struktur data grafik bulanan (labels, datasets, summary, dsb).
     */
    public function test_monthly_chart_structure(): void
    {
        $result = $this->service->getMonthlyData([
            'year'  => 2026,
            'month' => 2, // Februari 2026 (28 hari)
        ]);

        $this->assertSame('monthly', $result['period']);
        $this->assertSame('Asia/Jakarta', $result['timezone']);
        $this->assertCount(28, $result['labels']);
        $this->assertCount(2, $result['datasets']);

        // Dataset 1: Pendaftaran Langsung SERTIF
        $dsDirect = $result['datasets'][0];
        $this->assertSame('direct', $dsDirect['key']);
        $this->assertSame('Pendaftaran Langsung SERTIF', $dsDirect['label']);
        $this->assertSame(AcceptedInternAnalyticsService::COLOR_DIRECT_BG, $dsDirect['backgroundColor']);
        $this->assertCount(28, $dsDirect['data']);

        // Dataset 2: Data Terintegrasi
        $dsIntegrated = $result['datasets'][1];
        $this->assertSame('integrated', $dsIntegrated['key']);
        $this->assertSame('Data Terintegrasi', $dsIntegrated['label']);
        $this->assertSame(AcceptedInternAnalyticsService::COLOR_INTEGRATED_BG, $dsIntegrated['backgroundColor']);
        $this->assertCount(28, $dsIntegrated['data']);

        // Summary structure
        $this->assertArrayHasKey('total_accepted', $result['summary']);
        $this->assertArrayHasKey('total_direct', $result['summary']);
        $this->assertArrayHasKey('total_integrated', $result['summary']);
        $this->assertArrayHasKey('has_data', $result['summary']);
    }

    /**
     * Memverifikasi struktur data grafik harian (rentang jam tertentu).
     */
    public function test_daily_chart_structure(): void
    {
        $result = $this->service->getDailyData([
            'date'       => '2026-10-24',
            'start_hour' => 8,
            'end_hour'   => 12,
        ]);

        $this->assertSame('daily', $result['period']);
        $this->assertCount(5, $result['labels']); // 08:00, 09:00, 10:00, 11:00, 12:00
        $this->assertSame('08:00', $result['labels'][0]);
        $this->assertSame('12:00', $result['labels'][4]);

        $this->assertCount(5, $result['datasets'][0]['data']);
        $this->assertCount(5, $result['datasets'][1]['data']);
    }

    /**
     * Memverifikasi struktur data grafik tahunan (12 bulan).
     */
    public function test_yearly_chart_structure(): void
    {
        $result = $this->service->getYearlyData([
            'year' => 2026,
        ]);

        $this->assertSame('yearly', $result['period']);
        $this->assertCount(12, $result['labels']); // Jan - Des
        $this->assertSame('Jan', $result['labels'][0]);
        $this->assertSame('Des', $result['labels'][11]);

        $this->assertCount(12, $result['datasets'][0]['data']);
        $this->assertCount(12, $result['datasets'][1]['data']);
    }

    /**
     * Memverifikasi rentang kustom memilih granularitas adaptif (hourly vs daily).
     */
    public function test_custom_period_granularity_adaptive(): void
    {
        // Rentang 1 hari -> hourly
        $resHourly = $this->service->getCustomData([
            'start_date' => '2026-10-24 00:00:00',
            'end_date'   => '2026-10-24 12:00:00',
        ]);
        $this->assertSame('hourly', $resHourly['metadata']['group_type']);

        // Rentang 10 hari -> daily
        $resDaily = $this->service->getCustomData([
            'start_date' => '2026-10-01 00:00:00',
            'end_date'   => '2026-10-10 23:59:59',
        ]);
        $this->assertSame('daily', $resDaily['metadata']['group_type']);
        $this->assertCount(10, $resDaily['labels']);
    }

    /**
     * Memverifikasi endpoint controller DashboardController::acceptedInternsChart.
     */
    public function test_controller_accepted_interns_chart_endpoint(): void
    {
        $controller = app(\App\Http\Controllers\Admin\DashboardController::class);

        // 1. Valid request (monthly)
        $requestMonthly = new \Illuminate\Http\Request(['period' => 'monthly', 'year' => 2026, 'month' => 10]);
        $responseMonthly = $controller->acceptedInternsChart($requestMonthly, $this->service);
        $this->assertSame(200, $responseMonthly->getStatusCode());

        $dataMonthly = $responseMonthly->getData(true);
        $this->assertTrue($dataMonthly['success']);
        $this->assertSame('monthly', $dataMonthly['data']['period']);
        $this->assertCount(2, $dataMonthly['data']['datasets']);

        // 2. Invalid request (invalid period) -> 422
        $requestInvalid = new \Illuminate\Http\Request(['period' => 'unknown_period']);
        $responseInvalid = $controller->acceptedInternsChart($requestInvalid, $this->service);
        $this->assertSame(422, $responseInvalid->getStatusCode());

        $dataInvalid = $responseInvalid->getData(true);
        $this->assertFalse($dataInvalid['success']);
        $this->assertStringContainsString('tidak valid', $dataInvalid['message']);
    }
}

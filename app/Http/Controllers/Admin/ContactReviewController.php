<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InstitutionContact;
use App\Models\InternshipRegistration;
use App\Services\ContactMatchingService;
use Illuminate\Http\Request;

class ContactReviewController extends Controller
{
    /**
     * Tampilkan antrean review pembimbing untuk HR Staff dan Admin.
     */
    public function index(Request $request)
    {
        $tab = $request->get('tab', 'needs_review');
        if (!in_array($tab, ['needs_review', 'empty', 'all'], true)) {
            $tab = 'needs_review';
        }

        $query = ContactMatchingService::getReviewQueueQuery($tab);

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('fullname', 'like', "%{$search}%")
                  ->orWhere('supervisor_name', 'like', "%{$search}%")
                  ->orWhere('supervisor_contact', 'like', "%{$search}%");
            });
        }

        if ($request->filled('institution_id')) {
            $query->where('institution_id', $request->institution_id);
        }

        $registrations = $query->paginate(15)->withQueryString();

        $counts = ContactMatchingService::getReviewQueueCounts();

        // Ambil daftar kontak kanonikal terverifikasi per institusi yang ada di halaman saat ini untuk dropdown saran
        $institutionIds = $registrations->pluck('institution_id')->filter()->unique();
        $canonicalContactsByInstitution = InstitutionContact::whereIn('institution_id', $institutionIds)
            ->where('is_active', true)
            ->where('is_verified', true)
            ->orderBy('name')
            ->get()
            ->groupBy('institution_id');

        return view('admin.contact-review.index', compact('registrations', 'canonicalContactsByInstitution', 'tab', 'counts'));
    }

    /**
     * Tautkan pendaftaran ke kontak kanonikal yang sudah ada.
     */
    public function linkExisting(Request $request, InternshipRegistration $registration)
    {
        if (empty($registration->institution_id)) {
            return back()->with('error', 'Peserta magang belum terhubung ke institusi mitra. Harap hubungkan institusi terlebih dahulu.');
        }

        $request->validate([
            'institution_contact_id' => 'required|integer|exists:institution_contacts,id',
        ]);

        ContactMatchingService::linkExisting(
            $registration,
            (int) $request->institution_contact_id,
            auth()->id()
        );

        return back()->with('success', 'Kontak pembimbing kanonikal berhasil ditautkan.');
    }

    /**
     * Buat kontak kanonikal baru dari hasil review HR/Admin, lalu verifikasi dan tautkan.
     */
    public function createAndVerify(Request $request, InternshipRegistration $registration)
    {
        if (empty($registration->institution_id)) {
            return back()->with('error', 'Peserta magang belum terhubung ke institusi mitra. Harap hubungkan institusi terlebih dahulu sebelum menambahkan kontak pembimbing.');
        }

        $validated = $request->validate([
            'name'           => 'required|string|max:255',
            'position_title' => 'nullable|string|max:100',
            'phone'          => 'nullable|string|max:30',
            'email'          => 'nullable|email|max:100',
            'notes'          => 'nullable|string|max:500',
        ]);

        ContactMatchingService::createAndVerify(
            $registration,
            $validated,
            auth()->id()
        );

        return back()->with('success', 'Kontak pembimbing resmi berhasil dibuat, diverifikasi, dan ditautkan.');
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

class InstitutionController extends Controller
{
    /**
     * Tampilkan daftar master institusi dengan pencarian dan filter status.
     */
    public function index(Request $request)
    {
        $search = $request->input('search');
        $status = $request->input('status'); // 'all', 'active', 'inactive'

        $query = Institution::query()->withCount('internshipRegistrations');

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('correspondence_unit', 'like', "%{$search}%");
            });
        }

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        $institutions = $query->orderBy('name')->paginate(15)->withQueryString();

        $stats = [
            'total'    => Institution::count(),
            'active'   => Institution::where('is_active', true)->count(),
            'inactive' => Institution::where('is_active', false)->count(),
        ];

        return view('admin.institutions.index', compact('institutions', 'stats', 'search', 'status'));
    }

    /**
     * Form tambah institusi baru.
     */
    public function create()
    {
        return view('admin.institutions.create');
    }

    /**
     * Simpan institusi baru.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name'                => 'required|string|max:255',
            'logo'                => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'address'             => 'nullable|string',
            'phone'               => 'nullable|string|max:30',
            'email'               => 'nullable|email|max:100',
            'fax'                 => 'nullable|string|max:30',
            'correspondence_unit' => 'nullable|string|max:100',
        ]);

        $normalized = Institution::normalizeName($request->name);
        $exists = Institution::where('normalized_name', $normalized)
            ->orWhereRaw('LOWER(TRIM(name)) = ?', [strtolower(trim($request->name))])
            ->exists();
        if ($exists) {
            return back()->withInput()->withErrors(['name' => 'Institusi dengan nama ini sudah terdaftar.']);
        }

        $logoPath = null;
        if ($request->hasFile('logo')) {
            $logoPath = $request->file('logo')->store('institutions', 'public');
        }

        $institution = Institution::create([
            'name'                => trim($request->name),
            'logo'                => $logoPath,
            'address'             => $request->address,
            'phone'               => $request->phone,
            'email'               => $request->email,
            'fax'                 => $request->fax,
            'correspondence_unit' => $request->correspondence_unit,
            'is_active'           => true,
        ]);

        ActivityLogger::log(
            action: 'institution_created',
            description: 'Menambahkan institusi baru: ' . $institution->name,
            subject: $institution,
            after: $institution->only(['name', 'phone', 'email', 'correspondence_unit', 'address'])
        );

        return redirect()->route('admin.institutions.index')
            ->with('success', 'Institusi berhasil ditambahkan.');
    }

    /**
     * Tampilkan detail institusi dan ringkasan pemagang terkait.
     */
    public function show(Institution $institution)
    {
        $interns = $institution->internshipRegistrations()
            ->with(['user' => fn($q) => $q->withTrashed(), 'division'])
            ->latest('id')
            ->paginate(15);

        $contacts = $institution->contacts()
            ->with(['currentYearCycle.latestAttempt', 'latestCycle.latestAttempt'])
            ->orderBy('name')
            ->get();

        $mous = $institution->mous()
            ->with(['generatedBy', 'signedUploadedBy'])
            ->latest('id')
            ->get();

        $followUpCycles = $institution->followUpCycles()
            ->with(['contact', 'latestAttempt', 'confirmer', 'lockedByUser', 'mous'])
            ->latest('cycle_year')
            ->latest('id')
            ->get();

        $cooperationSummary = app(\App\Services\InstitutionFollowUpWorkflowService::class)
            ->getInstitutionCooperationSummary($institution);

        return view('admin.institutions.show', compact('institution', 'interns', 'contacts', 'mous', 'followUpCycles', 'cooperationSummary'));
    }

    /**
     * Form edit data kontak institusi.
     */
    public function edit(Institution $institution)
    {
        return view('admin.institutions.edit', compact('institution'));
    }

    /**
     * Perbarui data institusi.
     */
    public function update(Request $request, Institution $institution)
    {
        $request->validate([
            'name'                => 'required|string|max:255',
            'logo'                => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'address'             => 'nullable|string',
            'phone'               => 'nullable|string|max:30',
            'email'               => 'nullable|email|max:100',
            'fax'                 => 'nullable|string|max:30',
            'correspondence_unit' => 'nullable|string|max:100',
        ]);

        $normalized = Institution::normalizeName($request->name);
        $exists = Institution::where('id', '!=', $institution->id)
            ->where(function ($q) use ($normalized, $request) {
                $q->where('normalized_name', $normalized)
                  ->orWhereRaw('LOWER(TRIM(name)) = ?', [strtolower(trim($request->name))]);
            })
            ->exists();
        if ($exists) {
            return back()->withInput()->withErrors(['name' => 'Institusi dengan nama serupa sudah terdaftar.']);
        }

        $updateData = [
            'name'                => trim($request->name),
            'address'             => $request->address,
            'phone'               => $request->phone,
            'email'               => $request->email,
            'fax'                 => $request->fax,
            'correspondence_unit' => $request->correspondence_unit,
        ];

        if ($request->hasFile('logo')) {
            // Addendum Final V4: Simpan logo baru tanpa hard delete file lama (menjaga immutability riwayat)
            $updateData['logo'] = $request->file('logo')->store('institutions', 'public');
        }

        $before = $institution->only(['name', 'address', 'phone', 'email', 'fax', 'correspondence_unit', 'logo']);

        $institution->update($updateData);

        ActivityLogger::log(
            action: 'institution_updated',
            description: 'Memperbarui data institusi: ' . $institution->name,
            subject: $institution,
            before: $before,
            after: $institution->only(['name', 'address', 'phone', 'email', 'fax', 'correspondence_unit'])
        );

        return redirect()->route('admin.institutions.index')
            ->with('success', 'Data institusi berhasil diperbarui.');
    }

    /**
     * Ubah status aktif / nonaktif institusi.
     */
    public function toggleStatus(Request $request, Institution $institution)
    {
        abort_unless(auth()->user()->role === 'admin', 403, 'Aksi ini hanya dapat dilakukan oleh Administrator.');

        if ($institution->is_active) {
            $request->validate([
                'inactive_reason' => 'required|string|max:500',
            ], [
                'inactive_reason.required' => 'Alasan penonaktifan institusi wajib diisi.',
            ]);

            $institution->is_active = false;
            $institution->inactive_reason = $request->inactive_reason;
            $desc = 'Menonaktifkan institusi: ' . $institution->name . ' (Alasan: ' . $request->inactive_reason . ')';
        } else {
            $institution->is_active = true;
            $institution->inactive_reason = null;
            $desc = 'Mengaktifkan kembali institusi: ' . $institution->name;
        }

        $institution->save();

        ActivityLogger::log(
            action: 'institution_status_toggled',
            description: $desc,
            subject: $institution,
            after: [
                'is_active'       => $institution->is_active,
                'inactive_reason' => $institution->inactive_reason,
            ]
        );

        return back()->with('success', 'Status institusi berhasil diperbarui.');
    }
}

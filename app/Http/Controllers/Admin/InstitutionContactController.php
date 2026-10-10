<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Models\InstitutionContact;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

class InstitutionContactController extends Controller
{
    /**
     * Simpan kontak pembimbing baru di bawah institusi tertentu.
     */
    public function store(Request $request, Institution $institution)
    {
        $request->validate([
            'name'           => 'required|string|max:255',
            'position_title' => 'nullable|string|max:100',
            'phone'          => 'nullable|string|max:30',
            'email'          => 'nullable|email|max:100',
            'notes'          => 'nullable|string',
        ]);

        $normalizedPhone = InstitutionContact::normalizePhone($request->phone);

        // Pencegahan duplikasi nama atau nomor telepon serupa dalam satu institusi
        $exists = $institution->contacts()
            ->where(function ($q) use ($request, $normalizedPhone) {
                $q->whereRaw('LOWER(TRIM(name)) = ?', [strtolower(trim($request->name))]);
                if ($normalizedPhone) {
                    $q->orWhere('normalized_phone', $normalizedPhone);
                }
            })
            ->exists();

        if ($exists) {
            return back()->withInput()->withErrors([
                'name' => 'Kontak pembimbing dengan nama atau nomor telepon serupa sudah terdaftar di institusi ini.'
            ]);
        }

        $contact = $institution->contacts()->create([
            'name'           => trim($request->name),
            'position_title' => $request->position_title,
            'phone'          => $request->phone,
            'email'          => $request->email,
            'notes'          => $request->notes,
            'is_active'      => true,
            'is_verified'    => true,
            'verified_by'    => auth()->id(),
            'verified_at'    => now(),
        ]);

        ActivityLogger::log(
            action: 'institution_contact_created',
            description: 'Menambahkan kontak pembimbing: ' . $contact->name . ' (' . $institution->name . ')',
            subject: $contact,
            after: $contact->only(['name', 'position_title', 'phone', 'email'])
        );

        return back()->with('success', 'Kontak pembimbing berhasil ditambahkan.');
    }

    /**
     * Perbarui data kontak pembimbing.
     */
    public function update(Request $request, InstitutionContact $contact)
    {
        $request->validate([
            'name'           => 'required|string|max:255',
            'position_title' => 'nullable|string|max:100',
            'phone'          => 'nullable|string|max:30',
            'email'          => 'nullable|email|max:100',
            'notes'          => 'nullable|string',
        ]);

        $normalizedPhone = InstitutionContact::normalizePhone($request->phone);

        $exists = InstitutionContact::where('institution_id', $contact->institution_id)
            ->where('id', '!=', $contact->id)
            ->where(function ($q) use ($request, $normalizedPhone) {
                $q->whereRaw('LOWER(TRIM(name)) = ?', [strtolower(trim($request->name))]);
                if ($normalizedPhone) {
                    $q->orWhere('normalized_phone', $normalizedPhone);
                }
            })
            ->exists();

        if ($exists) {
            return back()->withInput()->withErrors([
                'name' => 'Kontak pembimbing dengan nama atau nomor telepon serupa sudah ada di institusi ini.'
            ]);
        }

        $before = $contact->only(['name', 'position_title', 'phone', 'email', 'notes']);

        $contact->update([
            'name'           => trim($request->name),
            'position_title' => $request->position_title,
            'phone'          => $request->phone,
            'email'          => $request->email,
            'notes'          => $request->notes,
        ]);

        ActivityLogger::log(
            action: 'institution_contact_updated',
            description: 'Memperbarui data kontak pembimbing: ' . $contact->name,
            subject: $contact,
            before: $before,
            after: $contact->only(['name', 'position_title', 'phone', 'email', 'notes'])
        );

        return back()->with('success', 'Data kontak pembimbing berhasil diperbarui.');
    }

    /**
     * Ubah status aktif / nonaktif kontak pembimbing.
     */
    public function toggleStatus(InstitutionContact $contact)
    {
        $contact->is_active = !$contact->is_active;
        $contact->save();

        ActivityLogger::log(
            action: 'institution_contact_status_toggled',
            description: ($contact->is_active ? 'Mengaktifkan' : 'Menonaktifkan') . ' kontak pembimbing: ' . $contact->name,
            subject: $contact,
            after: ['is_active' => $contact->is_active]
        );

        return back()->with('success', 'Status kontak pembimbing berhasil diperbarui.');
    }

    /**
     * Hapus lembut (soft delete) kontak pembimbing.
     */
    public function destroy(InstitutionContact $contact)
    {
        $contactName = $contact->name;
        $contact->delete();

        ActivityLogger::log(
            action: 'institution_contact_deleted',
            description: 'Menghapus kontak pembimbing: ' . $contactName,
            subject: $contact
        );

        return back()->with('success', 'Kontak pembimbing berhasil dihapus.');
    }

    /**
     * Verifikasi kontak pembimbing (dari status kandidat menjadi kontak resmi).
     */
    public function verify(InstitutionContact $contact)
    {
        $contact->is_verified = true;
        $contact->verified_by = auth()->id();
        $contact->verified_at = now();
        $contact->save();

        // Konfirmasi link pendaftaran yang tertaut pada kontak ini
        $contact->registrationLinks()->whereNull('confirmed_at')->update([
            'confirmed_by' => auth()->id(),
            'confirmed_at' => now(),
        ]);

        ActivityLogger::log(
            action: 'institution_contact_verified',
            description: 'Memverifikasi kontak pembimbing resmi: ' . $contact->name,
            subject: $contact,
            after: ['is_verified' => true, 'verified_by' => auth()->id(), 'verified_at' => now()->toDateTimeString()]
        );

        return back()->with('success', 'Kontak pembimbing berhasil diverifikasi.');
    }
}


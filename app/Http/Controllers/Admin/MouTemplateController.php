<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Models\MouTemplate;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

class MouTemplateController extends Controller
{
    /**
     * Daftar seluruh template MOU dan riwayat versinya.
     */
    public function index()
    {
        $templates = MouTemplate::with(['createdBy', 'updatedBy'])
            ->latest('id')
            ->paginate(15);

        $activeTemplate = MouTemplate::active()->latest('version_number')->first();

        $stats = [
            'total'          => MouTemplate::count(),
            'active_name'    => $activeTemplate ? $activeTemplate->name . ' (v' . $activeTemplate->version_number . ')' : 'Belum Ada',
            'latest_version' => MouTemplate::max('version_number') ?? 1,
        ];

        return view('admin.mou-templates.index', compact('templates', 'activeTemplate', 'stats'));
    }

    /**
     * Form pembuatan template MOU baru.
     */
    public function create()
    {
        $placeholders = MouTemplate::getDefaultPlaceholders();
        $defaultContent = MouTemplate::getDefaultTemplateContent();
        $nextVersion = (MouTemplate::max('version_number') ?? 0) + 1;

        return view('admin.mou-templates.create', compact('placeholders', 'defaultContent', 'nextVersion'));
    }

    /**
     * Simpan template MOU baru.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name'                     => 'required|string|max:255',
            'version_number'           => 'required|integer|min:1|max:999',
            'template_html'            => 'required|string',
            'company_name'             => 'nullable|string|max:255',
            'company_address'          => 'nullable|string|max:500',
            'company_phone'            => 'nullable|string|max:100',
            'company_email'            => 'nullable|email|max:100',
            'company_signatory_name'   => 'nullable|string|max:255',
            'company_signatory_title'  => 'nullable|string|max:255',
            'agreement_place'          => 'nullable|string|max:100',
        ]);

        $defaultValues = [
            'company_name'            => $request->input('company_name', 'SEVEN INC'),
            'company_address'         => $request->input('company_address', 'Yogyakarta'),
            'company_phone'           => $request->input('company_phone'),
            'company_email'           => $request->input('company_email'),
            'company_signatory_name'  => $request->input('company_signatory_name'),
            'company_signatory_title' => $request->input('company_signatory_title', 'Direktur Utama'),
            'agreement_place'         => $request->input('agreement_place', 'Yogyakarta'),
        ];

        $isActive = $request->boolean('is_active', true);

        // Jika template baru dijadikan aktif, nonaktifkan template lainnya
        if ($isActive) {
            MouTemplate::where('is_active', true)->update(['is_active' => false]);
        }

        $template = MouTemplate::create([
            'name'                 => $request->name,
            'version_number'       => (int) $request->version_number,
            'template_html'        => $request->template_html,
            'allowed_placeholders' => array_keys(MouTemplate::getDefaultPlaceholders()),
            'default_values'       => $defaultValues,
            'is_active'            => $isActive,
            'created_by'           => auth()->id(),
        ]);

        ActivityLogger::log(
            action: 'mou_template_created',
            description: "Membuat template MOU baru: {$template->name} (v{$template->version_number})",
            subject: $template,
            after: [
                'name'           => $template->name,
                'version_number' => $template->version_number,
                'is_active'      => $template->is_active,
            ],
            actor: auth()->user()
        );

        return redirect()->route('admin.mou-templates.index')
            ->with('success', 'Template MOU berhasil dibuat dan disimpan.');
    }

    /**
     * Form ubah template MOU.
     */
    public function edit(MouTemplate $mouTemplate)
    {
        $placeholders = MouTemplate::getDefaultPlaceholders();

        return view('admin.mou-templates.edit', compact('mouTemplate', 'placeholders'));
    }

    /**
     * Update template MOU.
     */
    public function update(Request $request, MouTemplate $mouTemplate)
    {
        $request->validate([
            'name'                     => 'required|string|max:255',
            'version_number'           => 'required|integer|min:1|max:999',
            'template_html'            => 'required|string',
            'company_name'             => 'nullable|string|max:255',
            'company_address'          => 'nullable|string|max:500',
            'company_phone'            => 'nullable|string|max:100',
            'company_email'            => 'nullable|email|max:100',
            'company_signatory_name'   => 'nullable|string|max:255',
            'company_signatory_title'  => 'nullable|string|max:255',
            'agreement_place'          => 'nullable|string|max:100',
        ]);

        $defaultValues = [
            'company_name'            => $request->input('company_name', 'SEVEN INC'),
            'company_address'         => $request->input('company_address', 'Yogyakarta'),
            'company_phone'           => $request->input('company_phone'),
            'company_email'           => $request->input('company_email'),
            'company_signatory_name'  => $request->input('company_signatory_name'),
            'company_signatory_title' => $request->input('company_signatory_title', 'Direktur Utama'),
            'agreement_place'         => $request->input('agreement_place', 'Yogyakarta'),
        ];

        $beforeData = [
            'name'           => $mouTemplate->name,
            'version_number' => $mouTemplate->version_number,
            'is_active'      => $mouTemplate->is_active,
        ];

        $isActive = $request->boolean('is_active', $mouTemplate->is_active);

        if ($isActive && !$mouTemplate->is_active) {
            MouTemplate::where('id', '!=', $mouTemplate->id)->where('is_active', true)->update(['is_active' => false]);
        }

        $mouTemplate->update([
            'name'           => $request->name,
            'version_number' => (int) $request->version_number,
            'template_html'  => $request->template_html,
            'default_values' => $defaultValues,
            'is_active'      => $isActive,
            'updated_by'     => auth()->id(),
        ]);

        ActivityLogger::log(
            action: 'mou_template_updated',
            description: "Memperbarui template MOU: {$mouTemplate->name} (v{$mouTemplate->version_number})",
            subject: $mouTemplate,
            before: $beforeData,
            after: [
                'name'           => $mouTemplate->name,
                'version_number' => $mouTemplate->version_number,
                'is_active'      => $mouTemplate->is_active,
            ],
            actor: auth()->user()
        );

        return redirect()->route('admin.mou-templates.index')
            ->with('success', 'Template MOU berhasil diperbarui.');
    }

    /**
     * Pratinjau render dokumen template MOU dengan data dummy atau institusi nyata.
     */
    public function preview(Request $request, MouTemplate $mouTemplate)
    {
        $institutions = Institution::active()->orderBy('name')->get();
        $selectedInstitutionId = $request->input('institution_id');

        $selectedInstitution = $selectedInstitutionId
            ? Institution::with('contacts')->find($selectedInstitutionId)
            : null;

        // Data sampel untuk simulasi penggantian token
        $mockData = [
            'institution_name'                => $selectedInstitution?->name ?? 'UNIVERSITAS CONTOH INDONESIA',
            'institution_mou_number'          => '045/UNIV-CI/MOU/XI/' . date('Y'),
            'institution_address'             => $selectedInstitution?->address ?? 'Jl. Kaliurang KM 14.5, Sleman, D.I. Yogyakarta',
            'institution_correspondence_unit' => $selectedInstitution?->correspondence_unit ?? 'Fakultas Ilmu Komputer & Teknologi Informasi',
            'institution_phone'               => $selectedInstitution?->phone ?? '0274-888999',
            'institution_email'               => $selectedInstitution?->email ?? 'kerjasama@univ-contoh.ac.id',
            'institution_signatory_name'      => $selectedInstitution?->contacts->first()?->name ?? 'Prof. Dr. Ir. Sutrisno, M.T.',
            'institution_signatory_title'     => $selectedInstitution?->contacts->first()?->position_title ?? 'Dekan Fakultas Ilmu Komputer',
            'company_mou_number'              => 'MOU/7INC/IND/' . date('Y') . '/001',
            'agreement_place'                 => 'Yogyakarta',
            'agreement_date'                  => now()->isoFormat('D MMMM Y'),
            'effective_start'                 => now()->isoFormat('D MMMM Y'),
            'effective_end'                   => now()->addYears(2)->isoFormat('D MMMM Y'),
        ];

        $renderedHtml = $mouTemplate->renderContent($mockData);

        return view('admin.mou-templates.preview', compact('mouTemplate', 'institutions', 'selectedInstitution', 'renderedHtml'));
    }

    /**
     * Jadikan template ini sebagai template aktif utama.
     */
    public function toggleActive(MouTemplate $mouTemplate)
    {
        MouTemplate::where('id', '!=', $mouTemplate->id)->update(['is_active' => false]);
        $mouTemplate->update(['is_active' => true, 'updated_by' => auth()->id()]);

        ActivityLogger::log(
            action: 'mou_template_activated',
            description: "Mengaktifkan template MOU: {$mouTemplate->name} (v{$mouTemplate->version_number}) sebagai template aktif utama",
            subject: $mouTemplate,
            actor: auth()->user()
        );

        return back()->with('success', "Template {$mouTemplate->name} (v{$mouTemplate->version_number}) telah ditetapkan sebagai template aktif.");
    }

    /**
     * Soft delete template MOU.
     */
    public function destroy(MouTemplate $mouTemplate)
    {
        $templateName = $mouTemplate->name;
        $mouTemplate->delete();

        ActivityLogger::log(
            action: 'mou_template_deleted',
            description: "Menghapus template MOU: {$templateName}",
            subject: $mouTemplate,
            actor: auth()->user()
        );

        return back()->with('success', "Template {$templateName} berhasil dihapus.");
    }
}

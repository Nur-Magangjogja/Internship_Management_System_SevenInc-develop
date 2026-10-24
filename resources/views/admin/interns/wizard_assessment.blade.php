@extends('layouts.dashboard')

@section('content')
<div class="min-h-screen bg-[#F4F8F6] p-4 sm:p-6 lg:p-7" id="wizard-app">

    {{-- Header --}}
    <div class="mb-6">
        <div class="flex items-center gap-2 text-[#4B5F5A] text-sm mb-2">
            <a href="{{ route('interns.assessment.index') }}" class="hover:text-[#2D8659] flex items-center gap-1">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/></svg>
                Kembali
            </a>
            <span>/</span>
            <span>Tambah Penilaian (Bulk)</span>
        </div>
        <h1 class="text-2xl font-extrabold tracking-tight text-[#1B3A34] sm:text-[28px]">Form Penilaian Pemagang</h1>
    </div>

    {{-- Progress Steps --}}
    <div class="mb-6 flex items-center justify-between bg-white p-4 rounded-[12px] border border-[#DCE7E1] shadow-sm">
        <div class="text-[13px] font-semibold text-[#1B3A34]">
            Mengisi penilaian untuk <span id="current-intern-name" class="text-[#2D8659]"></span>
        </div>
        <div class="text-[12px] font-medium text-[#4B5F5A]">
            Pemagang <span id="current-step">1</span> dari <span id="total-steps">X</span>
        </div>
    </div>

    <div class="space-y-5">
        {{-- ===== SEKSI 1: Data Pemagang ===== --}}
        <div class="rounded-[12px] border border-[#DCE7E1] bg-white p-5 shadow-sm">
            <p class="mb-4 text-[11px] font-bold uppercase tracking-[0.08em] text-[#2D8659]">Data Pemagang</p>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="relative sm:col-span-2">
                    <label class="mb-1.5 block text-[12.5px] font-semibold text-[#1B3A34]">Nama Pemagang</label>
                    <input type="text" id="info-nama" readonly
                        class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#E8F5E9] px-3 py-2.5 text-[13px] text-[#1B3A34] outline-none cursor-not-allowed opacity-80">
                </div>

                <div>
                    <label class="mb-1.5 block text-[12.5px] font-semibold text-[#1B3A34]">Nomor Induk Mahasiswa / Nomor Induk Siswa Nasional</label>
                    <input type="text" id="info-nim" readonly
                        class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#E8F5E9] px-3 py-2.5 text-[13px] text-[#1B3A34] outline-none cursor-not-allowed opacity-80">
                </div>

                <div>
                    <label class="mb-1.5 block text-[12.5px] font-semibold text-[#1B3A34]">Program Studi</label>
                    <input type="text" id="info-prodi" readonly
                        class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#E8F5E9] px-3 py-2.5 text-[13px] text-[#1B3A34] outline-none cursor-not-allowed opacity-80">
                </div>

                <div>
                    <label class="mb-1.5 block text-[12.5px] font-semibold text-[#1B3A34]">Divisi / Kompetensi Keahlian</label>
                    <input type="text" id="info-divisi" readonly
                        class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#E8F5E9] px-3 py-2.5 text-[13px] text-[#1B3A34] outline-none cursor-not-allowed opacity-80">
                </div>

                <div>
                    <label class="mb-1.5 block text-[12.5px] font-semibold text-[#1B3A34]">Brand</label>
                    <input type="text" id="info-brand" readonly
                        class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#E8F5E9] px-3 py-2.5 text-[13px] text-[#1B3A34] outline-none cursor-not-allowed opacity-80">
                </div>
            </div>
        </div>

        {{-- ===== SEKSI 2: Data Perusahaan ===== --}}
        <div class="rounded-[12px] border border-[#DCE7E1] bg-white p-5 shadow-sm">
            <p class="mb-4 text-[11px] font-bold uppercase tracking-[0.08em] text-[#2D8659]">Data Perusahaan & Penandatangan</p>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="mb-1.5 block text-[12.5px] font-semibold text-[#1B3A34]">Nama Perusahaan</label>
                    <input type="text" id="info-company-name" readonly
                        class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#E8F5E9] px-3 py-2.5 text-[13px] text-[#1B3A34] outline-none cursor-not-allowed opacity-80">
                </div>

                <div class="sm:col-span-2">
                    <label class="mb-1.5 block text-[12.5px] font-semibold text-[#1B3A34]">Alamat Perusahaan</label>
                    <textarea id="info-company-address" rows="2" readonly
                        class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#E8F5E9] px-3 py-2.5 text-[13px] text-[#1B3A34] outline-none cursor-not-allowed opacity-80 resize-none"></textarea>
                </div>

                <div class="sm:col-span-1">
                    <label class="mb-1.5 block text-[12.5px] font-semibold text-[#1B3A34]">Nama Penandatangan</label>
                    <input type="text" id="info-signature-name" readonly
                        class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#E8F5E9] px-3 py-2.5 text-[13px] text-[#1B3A34] outline-none cursor-not-allowed opacity-80">
                </div>

                <div class="sm:col-span-1">
                    <label class="mb-1.5 block text-[12.5px] font-semibold text-[#1B3A34]">Jabatan Penandatangan</label>
                    <input type="text" id="info-signature-position" readonly
                        class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#E8F5E9] px-3 py-2.5 text-[13px] text-[#1B3A34] outline-none cursor-not-allowed opacity-80">
                </div>

                <div class="sm:col-span-1">
                    <label class="mb-1.5 block text-[12.5px] font-semibold text-[#1B3A34]">Logo Perusahaan</label>
                    <div class="flex items-center justify-center rounded-[8px] border border-[#DCE7E1] bg-[#E8F5E9] p-3 h-[100px] opacity-80">
                        <img id="info-logo" src="" alt="Logo" class="hidden max-h-full max-w-full object-contain">
                        <span id="info-logo-text" class="text-[12px] text-[#4B5F5A] italic">Belum ada logo</span>
                    </div>
                </div>

                <div class="sm:col-span-1">
                    <label class="mb-1.5 block text-[12.5px] font-semibold text-[#1B3A34]">Tanda Tangan</label>
                    <div class="flex items-center justify-center rounded-[8px] border border-[#DCE7E1] bg-[#E8F5E9] p-3 h-[100px] opacity-80">
                        <img id="info-signature" src="" alt="Tanda Tangan" class="hidden max-h-full max-w-full object-contain">
                        <span id="info-signature-text" class="text-[12px] text-[#4B5F5A] italic">Belum ada tanda tangan</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- ===== SEKSI 3: Aspek Penilaian ===== --}}
            <div class="rounded-[12px] border border-[#DCE7E1] bg-white p-5 shadow-sm">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-[16px] font-bold text-[#1B3A34]">Aspek Penilaian</h3>
                    <button type="button" onclick="addAspectRow()" class="flex items-center gap-1 rounded-[6px] bg-[#E8F5E9] px-3 py-1.5 text-[12px] font-bold text-[#1F5F3F] transition hover:bg-[#A5D6A7]">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Tambah Aspek
                    </button>
                </div>

                <div class="overflow-x-auto border border-[#DCE7E1] rounded-[8px]">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-[#F4F8F6]">
                            <tr>
                                <th class="px-4 py-3 text-[12px] font-bold text-[#1B3A34] w-[60%]">Aspek yang Dinilai</th>
                                <th class="px-4 py-3 text-[12px] font-bold text-[#1B3A34] w-[25%] text-center">Nilai (0-100)</th>
                                <th class="px-4 py-3 text-[12px] font-bold text-[#1B3A34] w-[15%] text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="aspects-container" class="divide-y divide-[#DCE7E1]">
                            {{-- Rows will be injected by JS --}}
                        </tbody>
                        <tfoot class="bg-[#F4F8F6]">
                            <tr>
                                <td class="px-4 py-3 text-right font-bold text-[#1B3A34]">Rata-rata:</td>
                                <td class="px-4 py-3 text-center font-bold text-[#2D8659]" id="average-score">0</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="mt-6 flex justify-between items-center border-t border-[#DCE7E1] pt-5">
                    <button type="button" id="btn-prev" onclick="prevIntern()" class="rounded-[8px] border border-[#DCE7E1] bg-white px-5 py-2.5 text-[13px] font-bold text-[#4B5F5A] transition hover:bg-[#F4F8F6] disabled:opacity-50 disabled:cursor-not-allowed">
                        &larr; Kembali
                    </button>
                    
                    <button type="button" id="btn-next" onclick="nextIntern()" class="rounded-[8px] bg-[#1B3A34] px-6 py-2.5 text-[13px] font-bold text-white transition hover:bg-[#0F2420]">
                        Selanjutnya &rarr;
                    </button>
                </div>
            </div>
    </div>
</div>

<script>
    // Data dari Controller
    const rawData = {!! $wizardData !!};
    
    // State aplikasi
    let internsData = [];
    let currentIndex = 0;
    
    // Storage Key
    const STORAGE_KEY = 'assessment_wizard_draft';

    // Inisialisasi
    document.addEventListener('DOMContentLoaded', () => {
        // Coba load dari draft
        const draft = localStorage.getItem(STORAGE_KEY);
        if (draft) {
            try {
                const parsed = JSON.parse(draft);
                // Validasi apakah draft ini untuk data yang sama
                const draftIds = parsed.map(p => p.intern_id).sort().join(',');
                const currentIds = rawData.map(r => r.intern_id).sort().join(',');
                
                if (draftIds === currentIds) {
                    internsData = parsed;
                } else {
                    initFromRawData();
                }
            } catch (e) {
                initFromRawData();
            }
        } else {
            initFromRawData();
        }

        renderStep();
    });

    function initFromRawData() {
        // Format rawData agar sesuai struktur state
        internsData = rawData.map(intern => ({
            ...intern,
            // Jika nilai belum ada (baru), set default 95
            aspects: intern.aspects.map(a => ({
                aspek: a.aspek,
                nilai: a.nilai || 95
            }))
        }));
        saveDraft();
    }

    function saveDraft() {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(internsData));
    }

    function renderStep() {
        const intern = internsData[currentIndex];
        
        // Update header & progress
        document.getElementById('current-intern-name').textContent = intern.fullname;
        document.getElementById('current-step').textContent = currentIndex + 1;
        document.getElementById('total-steps').textContent = internsData.length;

        // Update Info Sidebar
        // Update Info Pemagang
        document.getElementById('info-nama').value = intern.fullname || '-';
        document.getElementById('info-nim').value = intern.nim_nis || '-';
        document.getElementById('info-prodi').value = intern.study_program || '-';
        document.getElementById('info-brand').value = intern.brand || '-';
        document.getElementById('info-divisi').value = intern.division || '-';

        // Update Info Perusahaan
        document.getElementById('info-company-name').value = intern.signatory?.company_name || '-';
        document.getElementById('info-company-address').value = intern.signatory?.company_address || '-';
        document.getElementById('info-signature-name').value = intern.signatory?.signatory_name || '-';
        document.getElementById('info-signature-position').value = intern.signatory?.signatory_position || '-';
        
        if (intern.signatory?.company_logo_path) {
            document.getElementById('info-logo').src = '/storage/' + intern.signatory.company_logo_path;
            document.getElementById('info-logo').classList.remove('hidden');
            document.getElementById('info-logo-text').classList.add('hidden');
        } else {
            document.getElementById('info-logo').src = '';
            document.getElementById('info-logo').classList.add('hidden');
            document.getElementById('info-logo-text').classList.remove('hidden');
        }

        if (intern.signatory?.signature_image_path) {
            document.getElementById('info-signature').src = '/storage/' + intern.signatory.signature_image_path;
            document.getElementById('info-signature').classList.remove('hidden');
            document.getElementById('info-signature-text').classList.add('hidden');
        } else {
            document.getElementById('info-signature').src = '';
            document.getElementById('info-signature').classList.add('hidden');
            document.getElementById('info-signature-text').classList.remove('hidden');
        }

        // Update Aspect Table
        const container = document.getElementById('aspects-container');
        container.innerHTML = '';

        intern.aspects.forEach((aspect, index) => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="px-4 py-3">
                    <input type="text" class="aspect-name-input w-full rounded-[6px] border border-[#DCE7E1] px-3 py-1.5 text-[13px] focus:border-[#2D8659] focus:outline-none focus:ring-1 focus:ring-[#2D8659]" value="${aspect.aspek}" data-index="${index}" oninput="updateAspect(${index}, 'name', this.value)">
                </td>
                <td class="px-4 py-3">
                    <input type="number" min="0" max="100" class="aspect-score-input w-full text-center rounded-[6px] border border-[#DCE7E1] px-3 py-1.5 text-[13px] focus:border-[#2D8659] focus:outline-none focus:ring-1 focus:ring-[#2D8659]" value="${aspect.nilai}" data-index="${index}" oninput="updateAspect(${index}, 'score', this.value)">
                </td>
                <td class="px-4 py-3 text-center">
                    <button type="button" onclick="removeAspectRow(${index})" class="text-[#D32F2F] hover:bg-red-50 p-1.5 rounded-full transition" title="Hapus Aspek">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                    </button>
                </td>
            `;
            container.appendChild(tr);
        });

        calculateAverage();

        // Update Buttons
        const btnPrev = document.getElementById('btn-prev');
        const btnNext = document.getElementById('btn-next');

        btnPrev.disabled = currentIndex === 0;
        
        if (currentIndex === internsData.length - 1) {
            btnNext.textContent = 'Simpan Semua';
            btnNext.classList.remove('bg-[#1B3A34]');
            btnNext.classList.add('bg-[#2D8659]'); // Highlight final button
        } else {
            btnNext.textContent = 'Selanjutnya \u2192';
            btnNext.classList.add('bg-[#1B3A34]');
            btnNext.classList.remove('bg-[#2D8659]');
        }
    }

    function updateAspect(index, field, value) {
        if (field === 'name') {
            internsData[currentIndex].aspects[index].aspek = value;
        } else if (field === 'score') {
            let num = parseFloat(value);
            if (isNaN(num)) num = 0;
            if (num > 100) num = 100;
            if (num < 0) num = 0;
            internsData[currentIndex].aspects[index].nilai = num;
            calculateAverage();
        }
        saveDraft();
    }

    function addAspectRow() {
        internsData[currentIndex].aspects.push({
            aspek: 'Aspek Tambahan',
            nilai: 95
        });
        saveDraft();
        renderStep();
    }

    function removeAspectRow(index) {
        internsData[currentIndex].aspects.splice(index, 1);
        saveDraft();
        renderStep();
    }

    function calculateAverage() {
        const aspects = internsData[currentIndex].aspects;
        if (aspects.length === 0) {
            document.getElementById('average-score').textContent = '0';
            return;
        }
        
        const sum = aspects.reduce((acc, curr) => acc + (parseFloat(curr.nilai) || 0), 0);
        const avg = sum / aspects.length;
        document.getElementById('average-score').textContent = avg.toFixed(2);
    }

    function prevIntern() {
        if (currentIndex > 0) {
            currentIndex--;
            renderStep();
        }
    }

    async function nextIntern() {
        // Validasi input form sebelum pindah/simpan
        const aspects = internsData[currentIndex].aspects;
        if (aspects.length === 0) {
            alert('Silakan tambahkan minimal 1 aspek penilaian.');
            return;
        }

        let hasEmptyName = false;
        aspects.forEach(a => {
            if (!a.aspek || a.aspek.trim() === '') hasEmptyName = true;
        });

        if (hasEmptyName) {
            alert('Nama aspek tidak boleh kosong.');
            return;
        }

        if (currentIndex < internsData.length - 1) {
            currentIndex++;
            renderStep();
        } else {
            // Submit all data
            await submitData();
        }
    }

    async function submitData() {
        const btnNext = document.getElementById('btn-next');
        btnNext.disabled = true;
        btnNext.textContent = 'Menyimpan...';

        try {
            // Format data sesuai endpoint storeBulk
            const payload = {
                assessments: internsData.map(intern => ({
                    intern_id: intern.intern_id,
                    aspek: intern.aspects.map(a => a.aspek),
                    nilai: intern.aspects.map(a => a.nilai),
                    // Jika ada signatory settings dari model
                    company_name: intern.signatory ? intern.signatory.company_name : null,
                    company_address: intern.signatory ? intern.signatory.company_address : null,
                    signatory_name: intern.signatory ? intern.signatory.signatory_name : null,
                    signatory_position: intern.signatory ? intern.signatory.signatory_position : null,
                    company_logo_path: intern.signatory ? intern.signatory.company_logo_path : null,
                    signature_image_path: intern.signatory ? intern.signatory.signature_image_path : null,
                }))
            };

            const response = await fetch('{{ route("interns.assessment.store_bulk") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify(payload)
            });

            const result = await response.json();

            if (response.ok && result.success) {
                // Hapus draft
                localStorage.removeItem(STORAGE_KEY);
                // Redirect back to index
                window.location.href = '{{ route("interns.assessment.index") }}';
            } else {
                alert('Gagal menyimpan penilaian: ' + (result.message || 'Error tidak diketahui'));
                btnNext.disabled = false;
                btnNext.textContent = 'Simpan Semua';
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Terjadi kesalahan jaringan saat menyimpan.');
            btnNext.disabled = false;
            btnNext.textContent = 'Simpan Semua';
        }
    }

</script>
@endsection

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $mou->company_mou_number }} - Naskah Kerja Sama MOU</title>
    @vite(['resources/css/app.css'])
    <style>
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                background: white !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            .a4-container {
                border: none !important;
                box-shadow: none !important;
                padding: 0 !important;
                margin: 0 !important;
                max-width: 100% !important;
            }
        }
        @page {
            size: A4;
            margin: 20mm 15mm 20mm 15mm;
        }
    </style>
</head>
<body class="bg-slate-100 min-h-screen antialiased text-slate-900 py-6">

    <!-- Top Action Bar (No Print) -->
    <div class="no-print mx-auto max-w-4xl mb-6 px-4">
        <div class="flex items-center justify-between rounded-xl bg-white p-4 shadow-sm border border-slate-200">
            <div class="flex items-center gap-3">
                <span class="rounded-lg bg-blue-50 p-2 text-blue-600">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 6 2 18 2 18 9"/>
                        <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                        <rect x="6" y="14" width="12" height="8"/>
                    </svg>
                </span>
                <div>
                    <h1 class="text-sm font-bold text-slate-800">{{ $mou->company_mou_number }}</h1>
                    <p class="text-xs text-slate-500">Pratinjau Tata Letak Naskah Standar Perjanjian Kerja Sama A4</p>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <button
                    type="button"
                    onclick="window.close()"
                    class="rounded-lg border border-slate-200 bg-white px-3.5 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
                >
                    Tutup
                </button>
                <button
                    type="button"
                    onclick="window.print()"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-blue-700"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 6 2 18 2 18 9"/>
                        <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                        <rect x="6" y="14" width="12" height="8"/>
                    </svg>
                    Cetak / Simpan PDF
                </button>
            </div>
        </div>
    </div>

    <!-- Paper Container Simulation (A4 Look) -->
    <div class="a4-container mx-auto max-w-4xl rounded-xl border border-slate-200 bg-white p-10 md:p-14 shadow-lg print:shadow-none print:border-none print:p-0">
        {!! $mou->rendered_document_html !!}
    </div>

</body>
</html>

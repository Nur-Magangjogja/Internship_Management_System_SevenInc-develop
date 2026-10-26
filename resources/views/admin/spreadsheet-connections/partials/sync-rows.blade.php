@forelse ($rowSyncs as $sync)
    <tr class="hover:bg-slate-50/80 transition" id="row-sync-{{ $sync->id }}">
        <td class="px-4 py-3 text-center text-slate-400 font-mono">
            {{ $loop->iteration + ($rowSyncs->currentPage() - 1) * $rowSyncs->perPage() }}
        </td>
        <td class="px-4 py-3 font-mono font-semibold text-slate-800">
            {{ $sync->external_sync_id }}
        </td>
        <td class="px-4 py-3 font-mono text-slate-600">
            [{{ $sync->target_sheet_tab }}]
        </td>
        <td class="px-4 py-3">
            @php
                $statusClass = match($sync->sync_status) {
                    'synced'               => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                    'pending'              => 'bg-blue-50 text-blue-700 border-blue-200',
                    'processing'           => 'bg-purple-50 text-purple-700 border-purple-200',
                    'retrying'             => 'bg-amber-50 text-amber-700 border-amber-200',
                    'paused_target_rebind' => 'bg-amber-100 text-amber-900 border-amber-300 font-bold',
                    'failed'               => 'bg-rose-50 text-rose-700 border-rose-200',
                    default                => 'bg-slate-100 text-slate-700 border-slate-200',
                };
            @endphp
            <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-medium border {{ $statusClass }}">
                @if($sync->sync_status === 'processing')
                    <span class="h-1.5 w-1.5 rounded-full bg-purple-500 animate-ping"></span>
                @elseif($sync->sync_status === 'synced')
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                @elseif($sync->sync_status === 'pending')
                    <span class="h-1.5 w-1.5 rounded-full bg-blue-500"></span>
                @endif
                {{ $sync->sync_status === 'paused_target_rebind' ? 'Dibekukan (Target Berubah)' : ucfirst($sync->sync_status) }}
            </span>
        </td>
        <td class="px-4 py-3 font-mono text-slate-600">
            {{ $sync->attempt_count }} / {{ $sync->max_attempts }}
        </td>
        <td class="px-4 py-3 text-slate-600 font-mono text-[11px]">
            {{ $sync->last_synced_at ? $sync->last_synced_at->format('d M Y H:i:s') : ($sync->last_attempt_at ? $sync->last_attempt_at->format('d M Y H:i:s') : '-') }}
        </td>
        <td class="px-4 py-3 text-slate-600 max-w-xs truncate" title="{{ $sync->error_message }}">
            {{ $sync->error_message ?: '-' }}
        </td>
        <td class="px-4 py-3 text-right">
            @if ($sync->isPausedForTargetRebind())
                <div class="inline-flex items-center gap-1">
                    <form action="{{ route('admin.spreadsheet-connections.reconcile-queue', $connection) }}" method="POST" class="inline" onsubmit="return confirm('Pindahkan antrean baris ini ke target baru?');">
                        @csrf
                        <input type="hidden" name="decision" value="rebind_new_target">
                        <input type="hidden" name="row_sync_id" value="{{ $sync->id }}">
                        <button type="submit" class="px-2 py-0.5 text-[10px] font-semibold rounded bg-indigo-50 text-indigo-700 hover:bg-indigo-100 border border-indigo-200" title="Pindah ke target baru (deduplikasi remote)">
                            Pindah
                        </button>
                    </form>
                    <form action="{{ route('admin.spreadsheet-connections.reconcile-queue', $connection) }}" method="POST" class="inline" onsubmit="return confirm('Batalkan antrean sinkronisasi ini? Data pendaftar tetap utuh.');">
                        @csrf
                        <input type="hidden" name="decision" value="cancel_sync">
                        <input type="hidden" name="row_sync_id" value="{{ $sync->id }}">
                        <button type="submit" class="px-2 py-0.5 text-[10px] font-semibold rounded bg-rose-50 text-rose-700 hover:bg-rose-100 border border-rose-200" title="Batalkan antrean tanpa menghapus registrasi">
                            Batal
                        </button>
                    </form>
                </div>
            @elseif ($sync->canRetry() || $sync->isSynced())
                <form action="{{ route('admin.spreadsheet-connections.retry-row', [$connection, $sync]) }}" method="POST" class="inline-block">
                    @csrf
                    <button
                        type="submit"
                        class="inline-flex items-center gap-1 px-2.5 py-1 text-[11px] font-semibold rounded bg-slate-100 hover:bg-slate-200 text-slate-700 transition"
                        title="{{ $sync->isSynced() ? 'Sinkronkan ulang baris ini' : 'Retry baris ini' }}"
                    >
                        <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="23 4 23 10 17 10"/>
                            <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                        </svg>
                        {{ $sync->isSynced() ? 'Sync Ulang' : 'Retry' }}
                    </button>
                </form>
            @else
                <span class="text-slate-300">-</span>
            @endif
        </td>
    </tr>
@empty
    <tr>
        <td colspan="8" class="px-6 py-8 text-center text-slate-400 italic">
            Belum ada antrean sinkronisasi baris untuk koneksi ini.
        </td>
    </tr>
@endforelse

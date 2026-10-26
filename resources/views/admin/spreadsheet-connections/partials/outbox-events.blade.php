@forelse ($outboxEvents as $event)
    <tr class="hover:bg-slate-50/70 transition">
        <td class="px-4 py-2 font-mono font-medium text-slate-800">
            {{ $event->event_type }}
        </td>
        <td class="px-4 py-2 font-mono text-slate-600 text-[11px] truncate max-w-xs" title="{{ $event->idempotency_key }}">
            {{ $event->idempotency_key }}
        </td>
        <td class="px-4 py-2 text-center font-mono">
            {{ $event->attempt_count }} / {{ $event->max_attempts }}
        </td>
        <td class="px-4 py-2 text-center">
            @php
                $evBadge = match($event->status) {
                    'verified'   => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                    'pending'    => 'bg-blue-50 text-blue-700 border-blue-200',
                    'processing' => 'bg-purple-50 text-purple-700 border-purple-200',
                    'failed'     => 'bg-rose-50 text-rose-700 border-rose-200',
                    default      => 'bg-slate-100 text-slate-600 border-slate-200',
                };
            @endphp
            <span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-semibold border {{ $evBadge }}">
                {{ ucfirst($event->status) }}
            </span>
        </td>
        <td class="px-4 py-2 font-mono text-slate-500 text-[11px]">
            {{ $event->created_at ? $event->created_at->format('d M Y H:i:s') : '-' }}
        </td>
    </tr>
@empty
    <tr>
        <td colspan="5" class="px-4 py-4 text-center text-slate-400 italic">
            Belum ada antrean event outbox. Event dibuat otomatis saat Form Builder dipublikasikan.
        </td>
    </tr>
@endforelse

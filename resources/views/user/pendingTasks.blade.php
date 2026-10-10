@extends('layouts.dashboard')

@section('content')
<div class="px-4 pt-8 pb-6 lg:px-8 bg-primary-300">
    @if(auth()->user()->role === 'pemagang' && auth()->user()->internshipRegistration?->internship_status === 'active')
        <div class="max-w-4xl mx-auto">
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-lg p-6 border border-primary-100 dark:border-primary-800 transition hover:shadow-xl ring-1 ring-primary-200">

                <!-- Header -->
                <h2 class="text-2xl font-semibold text-primary-800 dark:text-primary-300 mb-2 flex items-center gap-2">
                    <i class="fa-solid fa-clipboard-list text-primary-600"></i>
                    Tugas Pending Pemagang
                </h2>
                <p class="text-gray-700 dark:text-gray-300 mb-5">
                    Berikut adalah tugas yang masih pending untuk Anda:
                </p>

                {{-- Daftar tugas pending --}}
                @if($pendingTasks->isEmpty())
                    <div class="p-4 mb-4 text-primary-800 border border-primary-200 rounded-lg bg-primary-50 dark:bg-gray-800 dark:text-primary-300 dark:border-primary-700">
                        Tidak ada tugas pending saat ini 🎉
                    </div>
                @else
                    <ul class="space-y-4 mb-6">
                        @foreach($pendingTasks as $task)
                            <li class="p-4 bg-primary-50/80 dark:bg-primary-900/20 rounded-xl border border-primary-200 dark:border-primary-700 hover:bg-primary-100/80 dark:hover:bg-primary-800/30 transition">
                                <h3 class="font-semibold text-primary-900 dark:text-primary-100">{{ $task->title }}</h3>
                                <p class="text-gray-700 dark:text-gray-300 mt-1">{{ $task->description }}</p>
                                <span class="inline-flex mt-2 px-3 py-1 text-xs font-semibold text-primary-800 bg-primary-100 rounded-full dark:bg-primary-700 dark:text-primary-100">
                                    Pending
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <hr class="my-6 border-primary-200 dark:border-primary-700">

                {{-- Form tambah tugas --}}
                <h4 class="text-lg font-semibold text-primary-800 dark:text-primary-300 mb-3 flex items-center gap-2">
                    <i class="fa-solid fa-plus text-primary-500"></i> Tambah Tugas Baru
                </h4>

                <form action="{{ route('user.storePendingTask') }}" method="POST" class="space-y-4">
                    @csrf

                    <div>
                        <label for="task_title" class="block mb-2 text-sm font-medium text-primary-800 dark:text-primary-300">
                            Judul Tugas
                        </label>
                        <input type="text" id="task_title" name="task_title" required
                            class="w-full rounded-lg border-primary-200 dark:border-primary-700 dark:bg-gray-900 dark:text-gray-100 
                                   focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition shadow-sm">
                    </div>

                    <div>
                        <label for="task_description" class="block mb-2 text-sm font-medium text-primary-800 dark:text-primary-300">
                            Deskripsi Tugas
                        </label>
                        <textarea id="task_description" name="task_description" rows="4" required
                            class="w-full rounded-lg border-primary-200 dark:border-primary-700 dark:bg-gray-900 dark:text-gray-100 
                                   focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition shadow-sm"></textarea>
                    </div>

                    <div>
                        <button type="submit"
                            class="inline-flex items-center px-5 py-2.5 text-sm font-semibold text-white bg-primary-600 rounded-lg 
                                   hover:bg-primary-700 focus:ring-4 focus:ring-primary-300 dark:focus:ring-primary-800 transition-all duration-150 ease-in-out">
                            <i class="fa-solid fa-paper-plane mr-2"></i>
                            Tambah Tugas
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @else
        <div class="max-w-2xl mx-auto">
            <div class="p-4 text-primary-800 border border-primary-300 rounded-lg bg-primary-50 dark:bg-gray-800 dark:text-primary-300 dark:border-primary-700">
                Anda belum memiliki magang aktif atau tidak memiliki peran <strong>pemagang</strong>.
            </div>
        </div>
    @endif
</div>
@endsection

@extends('layouts.dashboard')

@section('content')
<div class="px-4 pt-8 pb-6 lg:px-8 bg-primary-300">
  @if(auth()->user()->role === 'pemagang' && auth()->user()->internshipRegistration?->internship_status === 'active')
    <div class="max-w-4xl mx-auto bg-white dark:bg-gray-800 rounded-2xl shadow-lg overflow-hidden transition hover:shadow-xl ring-1 ring-primary-200">

      <!-- Header -->
      <div class="bg-primary-600 text-white px-6 py-4 flex items-center gap-3">
        <i class="fas fa-calendar-check text-2xl"></i>
        <h3 class="text-xl font-semibold tracking-wide">Permintaan Izin Pemagang</h3>
      </div>

      <!-- Body -->
      <div class="p-6 space-y-6">
        <p class="text-gray-700 dark:text-gray-200">Silakan ajukan permintaan izin Anda di bawah ini.</p>

        <form action="{{ route('user.storeLeaveRequest') }}" method="POST" class="space-y-5">
          @csrf

          <!-- Jenis Izin -->
          <div>
            <label for="leave_type" class="block text-sm font-medium text-primary-800 dark:text-primary-300 mb-1">
              Jenis Izin
            </label>
            <select 
              name="leave_type" 
              id="leave_type" 
              required
              class="w-full rounded-lg border-primary-200 dark:border-primary-700 dark:bg-gray-900 dark:text-gray-100 
                     focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition shadow-sm"
            >
              <option value="sick">Sakit</option>
              <option value="personal">Keperluan Pribadi</option>
              <option value="other">Lainnya</option>
            </select>
          </div>

          <!-- Tanggal Izin -->
          <div>
            <label for="leave_date" class="block text-sm font-medium text-primary-800 dark:text-primary-300 mb-1">
              Tanggal Izin
            </label>
            <input 
              type="date" 
              name="leave_date" 
              id="leave_date" 
              required
              class="w-full rounded-lg border-primary-200 dark:border-primary-700 dark:bg-gray-900 dark:text-gray-100 
                     focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition shadow-sm"
            >
          </div>

          <!-- Alasan Izin -->
          <div>
            <label for="reason" class="block text-sm font-medium text-primary-800 dark:text-primary-300 mb-1">
              Alasan Izin
            </label>
            <textarea 
              name="reason" 
              id="reason" 
              rows="4" 
              required
              placeholder="Tuliskan alasan izin Anda..."
              class="w-full rounded-lg border-primary-200 dark:border-primary-700 dark:bg-gray-900 dark:text-gray-100 
                     focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition shadow-sm"
            ></textarea>
          </div>

          <!-- Tombol Kirim -->
          <div>
            <button 
              type="submit" 
              class="w-full sm:w-auto px-6 py-2.5 bg-primary-600 text-white font-medium rounded-lg shadow 
                     hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 
                     transition-all duration-150 ease-in-out"
            >
              <i class="fas fa-paper-plane mr-2"></i> Ajukan Izin
            </button>
          </div>
        </form>
      </div>
    </div>
  @else
    <div class="max-w-3xl mx-auto mt-10">
      <div class="p-6 bg-primary-50 border-l-4 border-primary-500 text-primary-800 rounded-lg shadow">
        <p>Anda belum memiliki magang aktif atau tidak memiliki peran pemagang.</p>
      </div>
    </div>
  @endif
</div>
@endsection

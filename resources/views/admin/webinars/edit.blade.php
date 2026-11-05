@extends('layouts.dashboard')

@section('content')
<div class="min-h-screen bg-[#F4F8F6] p-4 sm:p-6 lg:p-7">

  <div class="mb-6 flex items-center gap-3">
    <a href="{{ route('admin.webinars.index') }}"
       class="w-9 h-9 flex items-center justify-center rounded-lg border border-[#DCE7E1] bg-white text-[#4B5F5A] hover:border-[#2D8659] hover:text-[#2D8659] transition">
      <i class="fas fa-arrow-left text-sm"></i>
    </a>
    <div>
      <p class="text-xs font-bold uppercase tracking-widest text-[#2D8659] mb-0.5">Webinar</p>
      <h1 class="text-xl font-extrabold text-[#1B3A34]">Edit: {{ $webinar->title }}</h1>
    </div>
  </div>

  <form id="editWebinarForm" action="{{ route('admin.webinars.update', $webinar) }}" method="POST" enctype="multipart/form-data">
    @csrf @method('PUT')
    @include('admin.webinars._form', ['webinar' => $webinar])
    <div class="mt-5 flex gap-3">
      <button type="submit"
              id="editSubmitBtn"
              data-loading-text="Menyimpan Perubahan..."
              class="inline-flex items-center justify-center px-6 py-2.5 text-sm font-semibold text-white rounded-xl transition hover:opacity-95 disabled:opacity-75 disabled:cursor-not-allowed"
              style="background-color:#2D8659;">
        <i class="fas fa-save text-xs mr-2"></i> <span>Simpan Perubahan</span>
      </button>
      <a href="{{ route('admin.webinars.index') }}"
         id="editCancelBtn"
         class="px-6 py-2.5 text-sm font-semibold text-[#4B5F5A] border border-[#DCE7E1] bg-white rounded-xl hover:bg-[#F4F8F6] transition">
        Batal
      </a>
    </div>
  </form>
</div>
@endsection

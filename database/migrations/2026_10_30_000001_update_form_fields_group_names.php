<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Models\FormField;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('form_fields')) {
            return;
        }

        $dataDiriKeys = [
            'fullname', 'student_id', 'birth_place', 'born_date',
            'gender', 'religion', 'institution_name', 'faculty',
            'study_program', 'current_city', 'email', 'phone_number',
            'current_status',
        ];
        $pilihanDurasiKeys = [
            'internship_interest', 'start_date', 'end_date', 'planned_duration',
            'internship_type', 'wfo_readiness', 'schedule_shift_readiness',
            'internship_arrangement', 'has_laptop', 'owned_tools',
            'internship_reason', 'design_software', 'video_software',
            'programming_languages', 'digital_marketing_type',
        ];
        $berkasKeys = [
            'cv', 'identity', 'portfolio', 'cover_letter', 'proposal',
            'supporting_document', 'profile_photo', 'cv_ktp_portofolio_pdf',
        ];
        $infoTambahanKeys = [
            'supervisor_name', 'supervisor_contact', 'boarding_info',
            'social_media_instagram', 'internship_info_sources', 'current_activities',
        ];

        DB::table('form_fields')
            ->whereIn('field_key', $dataDiriKeys)
            ->where(function ($q) {
                $q->whereNull('group_name')->orWhere('group_name', 'main');
            })
            ->update(['group_name' => FormField::GROUP_DATA_DIRI]);

        DB::table('form_fields')
            ->whereIn('field_key', $pilihanDurasiKeys)
            ->where(function ($q) {
                $q->whereNull('group_name')->orWhere('group_name', 'main');
            })
            ->update(['group_name' => FormField::GROUP_PILIHAN_DURASI]);

        DB::table('form_fields')
            ->where(function ($q) use ($berkasKeys) {
                $q->whereIn('field_key', $berkasKeys)
                  ->orWhere('field_type', 'file');
            })
            ->where(function ($q) {
                $q->whereNull('group_name')->orWhere('group_name', 'main');
            })
            ->update(['group_name' => FormField::GROUP_BERKAS]);

        DB::table('form_fields')
            ->whereIn('field_key', $infoTambahanKeys)
            ->where(function ($q) {
                $q->whereNull('group_name')->orWhere('group_name', 'main');
            })
            ->update(['group_name' => FormField::GROUP_INFORMASI_TAMBAHAN]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Safe to leave as is or rollback to null / informasi_tambahan
    }
};

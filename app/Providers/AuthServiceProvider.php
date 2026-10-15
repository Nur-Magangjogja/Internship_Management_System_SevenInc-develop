<?php

namespace App\Providers;

// use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Models\User;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        // 'App\Models\Model' => 'App\Policies\ModelPolicy',
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        // Admin boleh semua ability kecuali kemampuan yang memerlukan otorisasi fine-grained
        Gate::before(function (User $user, string $ability) {
            if (in_array($ability, ['view-sync-monitoring', 'retry-sync-monitoring', 'manage-sync-connections'], true)) {
                return null; // Evaluasi gate spesifik di bawah
            }

            return $user->role === 'admin' ? true : null;
        });

        // Gate: Izin melihat dashboard & monitoring sinkronisasi (Read-Only)
        Gate::define('view-sync-monitoring', function (User $user, ?\App\Models\SpreadsheetConnection $connection = null) {
            if (!$user->isActive() || !in_array($user->role, ['admin', 'superadmin', 'hr_staff'], true)) {
                return false;
            }

            if (isset($user->can_view_monitoring) && !$user->can_view_monitoring) {
                return false;
            }

            if ($connection !== null && method_exists($user, 'canAccessConnection')) {
                return $user->canAccessConnection($connection);
            }

            return true;
        });

        // Gate: Izin memicu retry manual / batch retry sinkronisasi (Write Mutation)
        Gate::define('retry-sync-monitoring', function (User $user, ?\App\Models\SpreadsheetConnection $connection = null) {
            if (!$user->isActive() || !in_array($user->role, ['admin', 'superadmin', 'hr_staff'], true)) {
                return false;
            }

            if (method_exists($user, 'canRetrySync') && !$user->canRetrySync()) {
                return false;
            }

            if ($connection !== null && method_exists($user, 'canAccessConnection')) {
                return $user->canAccessConnection($connection);
            }

            return true;
        });
    }
}

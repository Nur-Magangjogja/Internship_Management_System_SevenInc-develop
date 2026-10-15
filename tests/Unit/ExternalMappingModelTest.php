<?php

namespace Tests\Unit;

use App\Models\ExternalIdentityMapping;
use App\Models\ExternalRegistrationMapping;
use App\Models\InternshipRegistration;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Tests\TestCase;

class ExternalMappingModelTest extends TestCase
{
    /**
     * Memverifikasi konstanta dan status helpers pada ExternalIdentityMapping.
     */
    public function test_external_identity_mapping_constants_and_status_helpers(): void
    {
        $mapping = new ExternalIdentityMapping([
            'verification_status' => ExternalIdentityMapping::STATUS_PENDING_VERIFICATION,
        ]);

        $this->assertTrue($mapping->isPending());
        $this->assertFalse($mapping->isVerified());
        $this->assertFalse($mapping->isConflict());

        $mapping->verification_status = ExternalIdentityMapping::STATUS_VERIFIED;
        $this->assertTrue($mapping->isVerified());
        $this->assertFalse($mapping->isPending());

        $mapping->verification_status = ExternalIdentityMapping::STATUS_CONFLICT;
        $this->assertTrue($mapping->isConflict());
    }

    /**
     * Memverifikasi definisi relasi Eloquent pada ExternalIdentityMapping dan ExternalRegistrationMapping.
     */
    public function test_eloquent_relation_definitions(): void
    {
        $identity = new ExternalIdentityMapping();
        $this->assertInstanceOf(BelongsTo::class, $identity->user());
        $this->assertInstanceOf(BelongsTo::class, $identity->verifiedBy());
        $this->assertInstanceOf(HasMany::class, $identity->registrationMappings());

        $registrationMapping = new ExternalRegistrationMapping();
        $this->assertInstanceOf(BelongsTo::class, $registrationMapping->identityMapping());
        $this->assertInstanceOf(BelongsTo::class, $registrationMapping->registration());

        $user = new User();
        $this->assertInstanceOf(HasMany::class, $user->externalIdentityMappings());

        $internshipRegistration = new InternshipRegistration();
        $this->assertInstanceOf(HasMany::class, $internshipRegistration->externalRegistrationMappings());
    }

    /**
     * Memverifikasi Registration Ownership Invariant pada ExternalRegistrationMapping::validateOwnership().
     */
    public function test_registration_ownership_invariant_validation(): void
    {
        $mapping = new ExternalRegistrationMapping();

        // Kasus 1: Relasi belum terhubung -> false
        $this->assertFalse($mapping->validateOwnership());

        // Kasus 2: User ID sama -> true
        $identity = new ExternalIdentityMapping(['ims_user_id' => 10]);
        $registration = new InternshipRegistration(['user_id' => 10]);

        $mapping->setRelation('identityMapping', $identity);
        $mapping->setRelation('registration', $registration);

        $this->assertTrue($mapping->validateOwnership());

        // Kasus 3: User ID berbeda (mencoba menautkan registrasi orang lain) -> false
        $alienRegistration = new InternshipRegistration(['user_id' => 99]);
        $mapping->setRelation('registration', $alienRegistration);

        $this->assertFalse($mapping->validateOwnership());
    }
}

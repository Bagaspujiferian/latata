<?php

namespace Tests\Unit;

use App\Enums\AdminSubrole;
use App\Models\User;
use App\Security\AdminPermissionMatrix;
use App\Services\IndonesianNameMatcher;
use App\Services\PhoneNormalizer;
use DomainException;
use Tests\TestCase;

class Tahap1DomainPrimitivesTest extends TestCase
{
    public function test_phone_normalizer_canonicalizes_indonesian_numbers_and_rejects_invalid_inputs(): void
    {
        $normalizer = new PhoneNormalizer;

        $p1 = $normalizer->normalize('0812-3456-7890');
        $p2 = $normalizer->normalize('+62 812 3456 7890');
        $p3 = $normalizer->normalize('6281234567890');

        $this->assertSame('6281234567890', $p1);
        $this->assertSame('6281234567890', $p2);
        $this->assertSame('6281234567890', $p3);

        $h1 = $normalizer->computeBlindHash('0812-3456-7890');
        $h2 = $normalizer->computeBlindHash('+62 812 3456 7890');
        $h3 = $normalizer->computeBlindHash('6281234567890');

        $this->assertSame(64, strlen($h1));
        $this->assertSame($h1, $h2);
        $this->assertSame($h2, $h3);

        foreach (['12345', 'abc', '+15551234', '08123', ''] as $invalid) {
            try {
                $normalizer->normalize($invalid);
                $this->fail("Expected DomainException for invalid phone input [{$invalid}].");
            } catch (DomainException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_indonesian_name_matcher_strips_titles_and_compares_canonical_names(): void
    {
        $matcher = new IndonesianNameMatcher;

        $this->assertSame('budi santoso', $matcher->normalize('Budi Santoso, S.Kom.'));
        $this->assertSame('budi santoso', $matcher->normalize('Dr. Ir. H. Budi Santoso'));
        $this->assertSame('siti aminah', $matcher->normalize('Hj. Siti Aminah, S.E., M.M.'));

        $this->assertTrue($matcher->matches('Budi Santoso, S.Kom.', 'BUDI SANTOSO'));
        $this->assertTrue($matcher->matches('Dr. Ir. H. Budi Santoso', 'Budi Santoso'));
        $this->assertTrue($matcher->matches('Hj. Siti Aminah, S.E., M.M.', 'SITI AMINAH'));

        $this->assertFalse($matcher->matches('Budi Santoso, S.Kom.', 'Andi Wijaya'));
        $this->assertFalse($matcher->matches('Budi Santoso', ''));
    }

    public function test_admin_permission_matrix_enforces_strict_subrole_isolation(): void
    {
        $allPermissions = array_keys(AdminPermissionMatrix::PERMISSIONS);

        $superAdmin = new User(['role' => 'admin', 'admin_subrole' => AdminSubrole::SUPER_ADMIN]);
        foreach ($allPermissions as $perm) {
            $this->assertTrue(AdminPermissionMatrix::allows($superAdmin, $perm), "SuperAdmin should have {$perm}");
        }

        $kycOfficer = new User(['role' => 'admin', 'admin_subrole' => AdminSubrole::KYC_OFFICER]);
        $this->assertTrue(AdminPermissionMatrix::allows($kycOfficer, 'kyc.review'));
        $this->assertFalse(AdminPermissionMatrix::allows($kycOfficer, 'withdrawals.approve'));
        $this->assertFalse(AdminPermissionMatrix::allows($kycOfficer, 'disputes.resolve'));
        $this->assertFalse(AdminPermissionMatrix::allows($kycOfficer, 'finance.view'));
        $this->assertFalse(AdminPermissionMatrix::allows($kycOfficer, 'cms.manage'));
        $this->assertFalse(AdminPermissionMatrix::allows($kycOfficer, 'chaos.run'));

        $financeAdmin = new User(['role' => 'admin', 'admin_subrole' => AdminSubrole::FINANCE]);
        $this->assertTrue(AdminPermissionMatrix::allows($financeAdmin, 'finance.view'));
        $this->assertTrue(AdminPermissionMatrix::allows($financeAdmin, 'finance.audit'));
        $this->assertTrue(AdminPermissionMatrix::allows($financeAdmin, 'withdrawals.approve'));
        $this->assertFalse(AdminPermissionMatrix::allows($financeAdmin, 'kyc.review'));
        $this->assertFalse(AdminPermissionMatrix::allows($financeAdmin, 'disputes.resolve'));
        $this->assertFalse(AdminPermissionMatrix::allows($financeAdmin, 'cms.manage'));
        $this->assertFalse(AdminPermissionMatrix::allows($financeAdmin, 'chaos.run'));

        $csArbitrator = new User(['role' => 'admin', 'admin_subrole' => AdminSubrole::CS_ARBITRATOR]);
        $this->assertTrue(AdminPermissionMatrix::allows($csArbitrator, 'disputes.resolve'));
        $this->assertTrue(AdminPermissionMatrix::allows($csArbitrator, 'warranty.resolve'));
        $this->assertTrue(AdminPermissionMatrix::allows($csArbitrator, 'moderation.manage'));
        $this->assertFalse(AdminPermissionMatrix::allows($csArbitrator, 'withdrawals.approve'));
        $this->assertFalse(AdminPermissionMatrix::allows($csArbitrator, 'kyc.review'));
        $this->assertFalse(AdminPermissionMatrix::allows($csArbitrator, 'finance.view'));
        $this->assertFalse(AdminPermissionMatrix::allows($csArbitrator, 'chaos.run'));

        $nonAdmin = new User(['role' => 'buyer', 'admin_subrole' => AdminSubrole::SUPER_ADMIN]);
        foreach ($allPermissions as $perm) {
            $this->assertFalse(AdminPermissionMatrix::allows($nonAdmin, $perm));
        }
    }
}

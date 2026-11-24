<?php

namespace App\Enums;

enum AdminSubrole: string
{
    case SUPER_ADMIN = 'super_admin';
    case FINANCE = 'finance';
    case CS_ARBITRATOR = 'cs_arbitrator';
    case KYC_OFFICER = 'kyc_officer';

    public function label(): string
    {
        return match ($this) {
            self::SUPER_ADMIN => 'Super Admin',
            self::FINANCE => 'Finance Officer',
            self::CS_ARBITRATOR => 'CS & Arbitrator',
            self::KYC_OFFICER => 'KYC Officer',
        };
    }
}

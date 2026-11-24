<?php

namespace App\Enums;

enum WithdrawalApprovalStage: string
{
    case MAKER = 'maker';
    case CHECKER = 'checker';
}

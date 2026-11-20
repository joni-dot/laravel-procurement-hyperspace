<?php

namespace App\Enums;

enum MembershipStatus: string
{
    case Active = 'active';
    case Invited = 'invited';
    case InvitationExpired = 'invitation_expired';
    case Deactivated = 'deactivated';
}

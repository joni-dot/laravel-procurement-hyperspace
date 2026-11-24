import type { Role } from './auth';

export type MembershipStatus =
    | 'active'
    | 'invited'
    | 'invitation_expired'
    | 'deactivated';

export type RoleOption = {
    value: Role;
    label: string;
};

/** A row on the company's Users page: a member, or someone invited to become one. */
export type Member = {
    /** The membership's id. */
    id: number;
    name: string;
    email: string;
    role: Role;
    status: MembershipStatus;
    isYou: boolean;
    /** Runs the service; marked so the company notices one among its members. */
    isPlatformAdmin: boolean;
    can: {
        update: boolean;
        deactivate: boolean;
        reactivate: boolean;
        resendInvitation: boolean;
    };
};

/** An invitation on the person's own Invitations page. */
export type Invitation = {
    id: number;
    companyName: string;
    role: string;
    invitedBy: string | null;
    sentOn: string;
    isExpired: boolean;
};

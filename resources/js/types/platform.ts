/** A company as platform admins see it: no company data, only who it is and how many people it has. */
export type PlatformCompanyRow = {
    id: number;
    name: string;
    slug: string;
    is_suspended: boolean;
    /** Filled with made-up data; marked "Demo". */
    is_demo: boolean;
    active_members_count: number;
    pending_invitations_count: number;
    /** YYYY-MM-DD */
    created_at: string;
    /** Set only while the company has no active admin: whom the waiting admin invitation went to. */
    first_admin_invitation: {
        name: string | null;
        email: string | null;
    } | null;
    /**
     * Only for a company without an admin: how many of its active members (not counting you) could be
     * made its admin. When there are any, one of them is chosen instead of inviting someone.
     */
    members_to_promote_count: number;
};

/** A member who can be made the admin of a company without one, as the member search returns them. */
export type MemberToPromote = {
    id: number;
    name: string;
    email: string;
};

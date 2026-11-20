/** A company as platform admins see it: no company data, only who it is and how many people it has. */
export type PlatformCompanyRow = {
    id: number;
    name: string;
    slug: string;
    is_suspended: boolean;
    active_members_count: number;
    pending_invitations_count: number;
    /** YYYY-MM-DD */
    created_at: string;
    /** Set only while the company has no active admin: whom the waiting admin invitation went to. */
    first_admin_invitation: {
        name: string | null;
        email: string | null;
    } | null;
};

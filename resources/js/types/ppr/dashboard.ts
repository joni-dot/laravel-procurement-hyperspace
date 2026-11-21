/**
 * The headline numbers of one project, as app/Dashboard/ProjectAttention.php counts them.
 */
export type DashboardTiles = {
    total: number;
    open: number;
    red: number;
    amber: number;
    overdue: number;
    dueSoon: number;
    missingPeople: number;
    noNeedDate: number;
    flagged: number;
};

/**
 * A project on the dashboard. Its numbers come later, with the rest of DashboardAttention.
 */
export type DashboardProject = {
    id: number;
    name: string;
    code: string;
    // The person's role in it, in words.
    role: string;
};

/**
 * A milestone the person must act on: overdue, or expected within their notification period.
 */
export type DashboardAction = {
    projectId: number;
    projectCode: string;
    packageId: number;
    number: string;
    title: string;
    milestoneName: string;
    date: string;
    // Below zero when overdue.
    daysLeft: number;
    kind: 'overdue' | 'coming_up';
    asOwner: boolean;
    asBuyer: boolean;
};

export type DashboardAttention = {
    tiles: { projectId: number; tiles: DashboardTiles }[];
    // The first ones only; actionsTotal counts them all.
    actions: DashboardAction[];
    actionsTotal: number;
};

/** The home page's "My next actions" section (app/Modules/Ppr/Dashboard/HomeSections.php). */
export type HomeNextActions = {
    // The first ones only; actionsTotal counts them all.
    actions: DashboardAction[];
    actionsTotal: number;
    notificationDays: number;
};

/** The home page's "My projects" section: each project with its numbers. */
export type HomeProjects = {
    projects: (DashboardProject & { tiles: DashboardTiles })[];
    canSeeAllProjects: boolean;
};

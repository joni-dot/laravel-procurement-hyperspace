/** A step of the getting-started checklist on Home (App\Onboarding\OnboardingSteps). */
export type OnboardingStep = {
    key: string;
    title: string;
    description: string;
    url: string;
    done: boolean;
};

export type OnboardingChecklist = {
    steps: OnboardingStep[];
    doneCount: number;
};

export type OnboardingSummary = {
    doneCount: number;
    total: number;
};

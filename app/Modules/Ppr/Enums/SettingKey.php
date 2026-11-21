<?php

namespace App\Modules\Ppr\Enums;

use App\Models\Setting;

enum SettingKey: string
{
    /** A package turns amber when its spare time before it is needed drops below this many days. */
    case RiskAmberDays = 'risk_amber_days';

    /** A package turns red when its spare time drops below this many days. */
    case RiskRedDays = 'risk_red_days';

    /**
     * The value used until an admin saves their own, and for new projects.
     */
    public function defaultValue(): int
    {
        return match ($this) {
            self::RiskAmberDays => 7,
            self::RiskRedDays => 0,
        };
    }

    /**
     * The company's saved value, or the default until an admin saves one.
     */
    public function savedOrDefault(): int
    {
        return (int) (Setting::get($this) ?? $this->defaultValue());
    }
}

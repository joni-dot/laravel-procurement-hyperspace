<?php

namespace App\Modules\Ppr\Demo;

use App\Modules\Ppr\Enums\PurchaseType;

/**
 * The made-up project of a demo company: a small biomass power plant, its categories and its packages.
 */
final class DemoProjectWords
{
    public const string NAME = 'Riverside biomass plant';

    public const string CODE = 'DEMO';

    public const string DESCRIPTION = 'A made-up project for trying out the project register. Its dates move with today, so some packages are done, some due soon and some late.';

    /** Category code => name. */
    public const array CATEGORIES = [
        'ELE' => 'Electrical',
        'MEC' => 'Mechanical',
        'CIV' => 'Civil works',
        'AUT' => 'Automation',
        'PIP' => 'Piping',
    ];

    public const array PURCHASE_TYPES = [
        'ELE' => PurchaseType::Equipment,
        'MEC' => PurchaseType::Equipment,
        'CIV' => PurchaseType::Subcontract,
        'AUT' => PurchaseType::Service,
        'PIP' => PurchaseType::Material,
    ];

    /**
     * The package tree per category: each title with its sub-packages, in the same shape.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    public const array PACKAGES = [
        'ELE' => [
            'Main transformer' => ['Transformer bushings' => [], 'Oil containment' => []],
            'Medium-voltage switchgear' => ['Protection relays' => []],
            'Low-voltage distribution' => [],
            'Cabling and cable trays' => ['Power cables' => [], 'Control cables' => [], 'Cable trays' => []],
            'Emergency diesel generator' => [],
            'Lighting' => [],
        ],
        'MEC' => [
            'Biomass boiler' => [
                'Boiler pressure parts' => ['Superheaters' => [], 'Economiser' => []],
                'Grate system' => [],
                'Refractory lining' => [],
            ],
            'Fuel handling conveyors' => ['Belt conveyors' => [], 'Screw conveyors' => []],
            'Flue gas cleaning' => ['Electrostatic precipitator' => [], 'Flue gas condenser' => []],
            'Feedwater pumps' => [],
            'Steam turbine' => ['Turbine generator set' => [], 'Condenser' => []],
            'Ash handling' => [],
        ],
        'CIV' => [
            'Earthworks' => [],
            'Foundations' => ['Boiler foundation' => [], 'Turbine foundation' => []],
            'Boiler house steel structure' => [],
            'Fuel storage hall' => [],
            'Stack' => [],
        ],
        'AUT' => [
            'Distributed control system' => [
                'Control system hardware' => [],
                'Control system software' => ['Process graphics' => [], 'Boiler control logic' => []],
                'Operator stations' => [],
            ],
            'Field instruments' => ['Pressure transmitters' => [], 'Flow meters' => [], 'Analysers' => []],
            'Fire detection' => [],
        ],
        'PIP' => [
            'High-pressure steam piping' => ['Pipe supports' => [], 'Valves' => []],
            'District heating connection' => [],
            'Cooling water piping' => [],
            'Compressed air system' => [],
        ],
    ];
}

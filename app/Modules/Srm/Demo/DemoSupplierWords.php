<?php

namespace App\Modules\Srm\Demo;

/**
 * Fixed word lists for made-up suppliers. Names join two whimsical words and a trade, such as
 * "Puffinmoor Logistics AB", so they read like suppliers but are plainly invented. People's names
 * are common first and last names mixed at random; their addresses are at example.org, which never
 * receives mail.
 */
final class DemoSupplierWords
{
    public const array NAME_STARTS = [
        'Bramble', 'Brisk', 'Cobble', 'Dapple', 'Ember', 'Fable', 'Glimmer', 'Hollow', 'Jolly', 'Kettle',
        'Lumen', 'Marble', 'Nimble', 'Otter', 'Puffin', 'Quill', 'Ripple', 'Saffron', 'Thimble', 'Umber',
        'Velvet', 'Whistle', 'Yarrow', 'Zephyr',
    ];

    public const array NAME_ENDS = ['fern', 'wick', 'moor', 'brook', 'stead', 'holm', 'fold', 'ridge', 'mere', 'worth'];

    /** The demo categories, each with the trade a supplier in it puts in its name. */
    public const array CATEGORIES = [
        'IT hardware' => 'Computing',
        'Software' => 'Software',
        'Logistics' => 'Logistics',
        'Packaging' => 'Packaging',
        'Raw materials' => 'Materials',
        'Electronics' => 'Electronics',
        'Office supplies' => 'Office Supply',
        'Facility services' => 'Facility Services',
        'Consulting' => 'Consulting',
        'Chemicals' => 'Chemicals',
        'Metal parts' => 'Metalworks',
    ];

    /** For a company that already had categories of its own, whose names say nothing about a trade. */
    public const array GENERIC_TRADES = ['Trading', 'Industries', 'Supply', 'Solutions'];

    /** How many of the demo suppliers come from each country: mostly the Nordic and Baltic neighbours. */
    public const array COUNTRY_SHARES = ['FI' => 60, 'SE' => 30, 'DE' => 30, 'EE' => 20, 'NL' => 5, 'PL' => 5];

    public const array LEGAL_FORMS = ['FI' => 'Oy', 'SE' => 'AB', 'DE' => 'GmbH', 'EE' => 'OÜ', 'NL' => 'B.V.', 'PL' => 'Sp. z o.o.'];

    public const array CITIES = [
        'FI' => ['Helsinki', 'Espoo', 'Tampere', 'Turku', 'Oulu', 'Vaasa', 'Lahti', 'Jyväskylä'],
        'SE' => ['Stockholm', 'Göteborg', 'Malmö', 'Uppsala', 'Västerås'],
        'DE' => ['Hamburg', 'Berlin', 'München', 'Köln', 'Stuttgart', 'Leipzig'],
        'EE' => ['Tallinn', 'Tartu', 'Pärnu'],
        'NL' => ['Rotterdam', 'Eindhoven'],
        'PL' => ['Gdańsk', 'Poznań'],
    ];

    public const array STREETS = [
        'FI' => ['Teollisuuskatu', 'Satamatie', 'Konepajankuja', 'Varastotie'],
        'SE' => ['Industrigatan', 'Hamnvägen', 'Verkstadsgatan'],
        'DE' => ['Industriestraße', 'Hafenstraße', 'Werkstraße'],
        'EE' => ['Tööstuse tn', 'Sadama tn'],
        'NL' => ['Industrieweg', 'Havenstraat'],
        'PL' => ['ul. Przemysłowa', 'ul. Portowa'],
    ];

    public const array CURRENCIES = ['FI' => 'EUR', 'SE' => 'SEK', 'DE' => 'EUR', 'EE' => 'EUR', 'NL' => 'EUR', 'PL' => 'PLN'];

    public const array FIRST_NAMES = [
        'FI' => ['Aino', 'Eero', 'Helmi', 'Juho', 'Kaisa', 'Lauri', 'Minna', 'Oskari', 'Riikka', 'Ville'],
        'SE' => ['Anna', 'Erik', 'Johanna', 'Lars', 'Maja', 'Nils', 'Sofia', 'Oskar'],
        'DE' => ['Anna', 'Felix', 'Hannah', 'Jonas', 'Lena', 'Lukas', 'Marie', 'Paul'],
        'EE' => ['Kadri', 'Mart', 'Liis', 'Tanel', 'Triin', 'Rasmus'],
        'NL' => ['Daan', 'Emma', 'Sem', 'Julia'],
        'PL' => ['Anna', 'Jakub', 'Zofia', 'Piotr'],
    ];

    public const array LAST_NAMES = [
        'FI' => ['Virtanen', 'Korhonen', 'Nieminen', 'Mäkinen', 'Hämäläinen', 'Laine', 'Heikkinen', 'Koskinen'],
        'SE' => ['Andersson', 'Johansson', 'Karlsson', 'Nilsson', 'Eriksson', 'Larsson'],
        'DE' => ['Müller', 'Schmidt', 'Schneider', 'Fischer', 'Weber', 'Wagner'],
        'EE' => ['Tamm', 'Saar', 'Sepp', 'Mägi', 'Kask', 'Kukk'],
        'NL' => ['Jansen', 'Bakker', 'Visser', 'Smit'],
        'PL' => ['Nowak', 'Kowalczyk', 'Wójcik', 'Zieliński'],
    ];

    public const array JOB_TITLES = ['Sales Manager', 'Key Account Manager', 'Managing Director', 'Customer Service Lead', 'Export Manager', 'Sales Engineer'];

    public const array PAYMENT_TERMS = ['14 days net', '30 days net', '45 days net', '60 days net'];

    /** Certificate types, and whether every active supplier must have one. */
    public const array CERTIFICATE_TYPES = ['Code of conduct' => true, 'ISO 9001' => false, 'ISO 14001' => false, 'Liability insurance' => false];

    public const string REQUIRED_CERTIFICATE = 'Code of conduct';

    public const array CONTRACT_TITLES = [
        'Framework agreement for spare parts', 'Maintenance services', 'Office supplies purchase agreement',
        'Freight forwarding', 'Software licences and support', 'Cleaning services', 'Packaging materials supply',
        'Raw material supply', 'IT hardware leasing', 'Consulting framework', 'Chemicals supply', 'Machining services',
        'Electronic components supply', 'Warehouse services',
    ];
}

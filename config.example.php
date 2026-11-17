<?php
/**
 * LANDLORDS configuration.
 *
 * On the server this file is written by the "configure" server task from the
 * repository's secrets (see README). For local development, copy it to
 * config.php (in the project root, outside the web root) and fill it in.
 * config.php is ignored by git.
 */
return [
    // Database (IONOS: Hosting > Databases). MySQL and MariaDB both use 'mysql:'. Examples:
    //   mysql:host=db5000000000.hosting-data.io;dbname=dbs0000000;charset=utf8mb4
    //   sqlite:/path/to/landlords.sqlite   (local development only)
    'db' => [
        'dsn'      => 'mysql:host=localhost;dbname=landlords;charset=utf8mb4',
        'user'     => 'landlords',
        'password' => 'change-me',
    ],

    // Nottingham City Council: the ArcGIS map server and the published registers.
    'council' => [
        'housing_service_url' => 'https://maps164.nottinghamcity.gov.uk/server/rest/services/Core_services/Housing_and_Property/MapServer',
        'conservation_layer_url' => 'https://maps164.nottinghamcity.gov.uk/server/rest/services/Core_services/Local_Plan_2020/MapServer/49',
        'conservation_area_name' => 'The Park',
        // Also include properties within this many metres of the conservation area,
        // as NIMBY does (0 = the conservation area only).
        'buffer_metres' => 50,
        'geometry_service_url' => 'https://maps164.nottinghamcity.gov.uk/server/rest/services/Utilities/Geometry/GeometryServer',
        // Pages that link to the monthly register spreadsheets. The file names change
        // each time, so the sync finds the current link on these pages.
        'hmo_register_page' => 'https://www.nottinghamcity.gov.uk/additionalhmo',
        'selective_register_page' => 'https://www.nottinghamcity.gov.uk/information-for-residents/housing/private-rented-accommodation/information-for-landlords/licensing-for-landlords/selective-licensing',
        'timeout_seconds' => 60,
    ],

    // NIMBY, for links from student accommodation to its planning application.
    'nimby_url' => 'https://nimby.parknews.co.uk',

    // Where the sync job writes files the map page reads.
    'paths' => [
        'public_data' => __DIR__ . '/public/data',
        'cache'       => __DIR__ . '/data/cache',
    ],

    // Failure alerts from the sync job.
    'alerts' => [
        'admin_email' => 'landlords@npra.co.uk',
        'from_email'  => 'landlords@npra.co.uk',
    ],

    // Abort the sync (keeping yesterday's data) if the council returns fewer
    // than this share of the records we already hold.
    'sync' => [
        'min_ratio_of_known' => 0.8,
    ],
];

-- LANDLORDS schema, part 1: licences, student accommodation, change history, sync runs.
-- Applied by bin/migrate.php (the deploy and setup server tasks run it).

CREATE TABLE IF NOT EXISTS licences (
    lkey            VARCHAR(120)  NOT NULL PRIMARY KEY,  -- layer + licence reference + address hash
    ref             VARCHAR(40)   NOT NULL,              -- council reference, e.g. FS-Case-486116703
    kind            VARCHAR(10)   NOT NULL,              -- hmo | selective
    scheme          VARCHAR(12)   NOT NULL,              -- Mandatory | Additional | Selective
    stage           VARCHAR(12)   NOT NULL,              -- licence | application
    address         VARCHAR(255)  NOT NULL,
    addr_key        VARCHAR(255)  NOT NULL,              -- letters and digits only + postcode, for matching
    postcode        VARCHAR(10)   NULL,
    app_date        DATE          NULL,                  -- applications: date received
    date_issued     DATE          NULL,                  -- licences
    expiry_date     DATE          NULL,
    lat             DECIMAL(9,6)  NULL,
    lng             DECIMAL(9,6)  NULL,
    -- From the council's published registers (spreadsheets), matched by address or reference:
    max_occupants   INT           NULL,
    max_households  INT           NULL,
    register_type   VARCHAR(60)   NULL,                  -- e.g. "Renewal app rec - mandatory"
    property_type   VARCHAR(60)   NULL,                  -- e.g. "Non HMO Flat"
    first_seen_at   DATETIME      NOT NULL,              -- drives the weekly email
    last_seen_at    DATETIME      NOT NULL,
    updated_at      DATETIME      NULL,
    removed_at      DATETIME      NULL,                  -- set if it disappears from the council feed
    backfilled      TINYINT(1)    NOT NULL DEFAULT 0,    -- loaded by the first run; never emailed
    INDEX idx_licences_ref (ref),
    INDEX idx_licences_addr (addr_key),
    INDEX idx_licences_first_seen (first_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_schemes (
    scheme_id       VARCHAR(40)   NOT NULL PRIMARY KEY,
    name            VARCHAR(255)  NULL,
    address         VARCHAR(255)  NULL,
    postcode        VARCHAR(10)   NULL,
    bedspaces       INT           NULL,
    studios         INT           NULL,
    clusters        INT           NULL,
    scheme_type     VARCHAR(100)  NULL,
    academic_year   VARCHAR(20)   NULL,                  -- year completed or expected, or "Archive"
    planning_ref    VARCHAR(40)   NULL,
    provider        VARCHAR(255)  NULL,
    lat             DECIMAL(9,6)  NULL,
    lng             DECIMAL(9,6)  NULL,
    geometry        MEDIUMTEXT    NULL,                  -- GeoJSON outline
    first_seen_at   DATETIME      NOT NULL,
    last_seen_at    DATETIME      NOT NULL,
    updated_at      DATETIME      NULL,
    removed_at      DATETIME      NULL,
    backfilled      TINYINT(1)    NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS events (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity      VARCHAR(10)   NOT NULL,                  -- licence | student
    rkey        VARCHAR(120)  NOT NULL,
    field       VARCHAR(30)   NOT NULL,
    old_value   TEXT          NULL,
    new_value   TEXT          NULL,
    changed_at  DATETIME      NOT NULL,
    INDEX idx_events_key (entity, rkey),
    INDEX idx_events_changed (changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_runs (
    id           INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    started_at   DATETIME      NOT NULL,
    finished_at  DATETIME      NULL,
    status       VARCHAR(10)   NOT NULL,  -- running | ok | failed
    fetched      INT           NULL,
    inserted     INT           NULL,
    updated      INT           NULL,
    removed      INT           NULL,
    message      TEXT          NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    name   VARCHAR(50)  NOT NULL PRIMARY KEY,
    value  MEDIUMTEXT   NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

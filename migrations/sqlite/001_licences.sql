-- SQLite equivalent of migrations/mysql/001_licences.sql, for local development and tests.

CREATE TABLE IF NOT EXISTS licences (
    lkey            TEXT    NOT NULL PRIMARY KEY,
    ref             TEXT    NOT NULL,
    kind            TEXT    NOT NULL,
    scheme          TEXT    NOT NULL,
    stage           TEXT    NOT NULL,
    address         TEXT    NOT NULL,
    addr_key        TEXT    NOT NULL,
    postcode        TEXT    NULL,
    app_date        TEXT    NULL,
    date_issued     TEXT    NULL,
    expiry_date     TEXT    NULL,
    lat             REAL    NULL,
    lng             REAL    NULL,
    max_occupants   INTEGER NULL,
    max_households  INTEGER NULL,
    register_type   TEXT    NULL,
    property_type   TEXT    NULL,
    first_seen_at   TEXT    NOT NULL,
    last_seen_at    TEXT    NOT NULL,
    updated_at      TEXT    NULL,
    removed_at      TEXT    NULL,
    backfilled      INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_licences_ref ON licences (ref);
CREATE INDEX IF NOT EXISTS idx_licences_addr ON licences (addr_key);

CREATE TABLE IF NOT EXISTS student_schemes (
    scheme_id       TEXT    NOT NULL PRIMARY KEY,
    name            TEXT    NULL,
    address         TEXT    NULL,
    postcode        TEXT    NULL,
    bedspaces       INTEGER NULL,
    studios         INTEGER NULL,
    clusters        INTEGER NULL,
    scheme_type     TEXT    NULL,
    academic_year   TEXT    NULL,
    planning_ref    TEXT    NULL,
    provider        TEXT    NULL,
    lat             REAL    NULL,
    lng             REAL    NULL,
    geometry        TEXT    NULL,
    first_seen_at   TEXT    NOT NULL,
    last_seen_at    TEXT    NOT NULL,
    updated_at      TEXT    NULL,
    removed_at      TEXT    NULL,
    backfilled      INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    entity      TEXT    NOT NULL,
    rkey        TEXT    NOT NULL,
    field       TEXT    NOT NULL,
    old_value   TEXT    NULL,
    new_value   TEXT    NULL,
    changed_at  TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_events_key ON events (entity, rkey);

CREATE TABLE IF NOT EXISTS sync_runs (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    started_at   TEXT    NOT NULL,
    finished_at  TEXT    NULL,
    status       TEXT    NOT NULL,
    fetched      INTEGER NULL,
    inserted     INTEGER NULL,
    updated      INTEGER NULL,
    removed      INTEGER NULL,
    message      TEXT    NULL
);

CREATE TABLE IF NOT EXISTS settings (
    name   TEXT NOT NULL PRIMARY KEY,
    value  TEXT NULL
);

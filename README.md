# LANDLORDS – Rented homes in The Park

A map of licensed rented homes in and around The Park Conservation Area, Nottingham: houses in multiple occupation (HMOs), other privately rented homes with selective licences, pending licence applications, and purpose-built student accommodation. Residents can sign up for a weekly email of what has changed. Run by the Nottingham Park Residents' Association (NPRA) at [landlords.parknews.co.uk](https://landlords.parknews.co.uk), alongside [NIMBY](https://nimby.parknews.co.uk), the planning watch, whose code and look it shares.

## How it works

Twice a day (06:41 and 12:41 UK time), `bin/sync.php` asks Nottingham City Council's public map server for every licence, pending application and student scheme inside The Park Conservation Area or within 50 m of it (`buffer_metres` in `config.php`, as NIMBY). It stores them in MySQL, logs every change, adds details from the council's published registers, and writes the map's data files to `public/data/`. Visitors only ever load those static files.

| Source | What we use |
| --- | --- |
| [Housing and Property map service](https://maps164.nottinghamcity.gov.uk/server/rest/services/Core_services/Housing_and_Property/MapServer) | Layers 23 and 6 (mandatory and additional HMO licences), 16 (selective licences), 25, 26 and 27 (pending applications for each), 21 (purpose-built student accommodation): reference, address, dates, location; for student schemes, name, bed spaces, operator, year and planning reference |
| [HMO public register](https://www.nottinghamcity.gov.uk/additionalhmo) (.xls, monthly) | Maximum occupants and households; new licence or renewal. No licence reference, so matched by address |
| [Selective licensing register](https://www.nottinghamcity.gov.uk/information-for-residents/housing/private-rented-accommodation/information-for-landlords/licensing-for-landlords/selective-licensing) (.xlsx, "redacted") | Property type (house or flat), matched by licence reference |
| [Conservation Areas (Local Plan layer 49)](https://maps164.nottinghamcity.gov.uk/server/rest/services/Core_services/Local_Plan_2020/MapServer/49) | The Park outline, downloaded once and cached |

The register files change name with each edition, so the sync reads the current link from the council's page and only downloads a file when the link changes. Both spreadsheet formats are read by small built-in readers (`src/Registers/`), so nothing extra is needed on the server. A problem with a register never stops the sync: the map keeps the details it already has, and the sync log says what went wrong.

### What the data looked like (17 November 2026)

| Layer | Inside The Park | Including the 50 m margin |
| --- | --- | --- |
| Mandatory HMO licences | 13 | 71 |
| Additional HMO licences | 25 | 35 |
| HMO licence applications | 24 | 51 |
| Selective licences | 449 | 717 |
| Selective licence applications | 55 | 100 |
| Student accommodation schemes | 1 | 5 |

- 103 of the 106 licensed HMOs matched an entry in the HMO register by address.
- When a licence is granted or renewed it gets a **new** reference; the application's reference doesn't carry over. LANDLORDS links them by address instead, so a renewal is reported as a renewal, not as one licence ending and another starting.
- Old numeric references (e.g. "08399") appear more than once in a layer, so each record's key combines the layer, the reference and the address.
- Flats in one building share one point (e.g. Westcliffe Court); the map shows them as one pin listing every flat.

### What the map doesn't show

The names of licence holders, landlords and managing agents. The law requires the council's full register to hold them, but the extracts it publishes leave them out (the selective one is labelled "redacted"). Company owners could later be added from HM Land Registry's free "UK companies that own property" dataset; see the project notes.

## Weekly email

Residents sign up on the map page (double opt-in). On Monday mornings `bin/send-digest.php` emails each subscriber the week's changes, grouped into HMOs, other rented homes and student accommodation:

- **Newly licensed** and **Licence renewed** (a new licence where one was held before, or the register says "renewal")
- **New application** and **Renewal application** (the property already holds a licence)
- **Licence ended** (expired, or gone from the council's list without a replacement)
- **Application closed** (no longer pending, and no licence followed)
- **Licence dates changed**
- Student accommodation: **new schemes**, **details changed**, **no longer listed**

Each item links to its pin (`/?p=<id>`). Nothing is sent in a week with no changes. Everything already licensed when the site first ran is history and is never emailed.

The sign-up, unsubscribe, privacy and safe re-run rules are NIMBY's: see `public/privacy.php`. Sending is through IONOS as landlords@npra.co.uk.

## Setting up on IONOS

Deployment and server tasks run from GitHub Actions over SSH (`deploy/ionos.sh`), using the same IONOS account and deploy key as NIMBY and BINS.

### One-time steps

1. **Database.** In the IONOS control panel (Hosting > Databases), create a new MariaDB database for LANDLORDS. Note its host name, database name, user name and password.
2. **Mailbox.** Create the mailbox landlords@npra.co.uk. For good delivery, npra.co.uk needs SPF, DKIM and DMARC records; IONOS sets the first two up for domains it hosts.
3. **Subdomain.** Point `landlords.parknews.co.uk` at the webspace folder `/landlords/public`. The existing `*.parknews.co.uk` wildcard certificate covers it. (The site was first set up at landlords.npra.co.uk; that address still points at the same folder and `public/.htaccess` redirects it, and plain http://, to https://landlords.parknews.co.uk.)
4. **GitHub secrets.** Under **Settings > Secrets and variables > Actions > New repository secret**, add:
   - `IONOS_SSH_HOST`, `IONOS_SSH_USER`, `IONOS_SSH_KEY`: the same three values as in the NIMBY repository
   - `LANDLORDS_DB_HOST`, `LANDLORDS_DB_NAME`, `LANDLORDS_DB_USER`, `LANDLORDS_DB_PASSWORD`: from step 1
   - `LANDLORDS_MAIL_PASSWORD`: the mailbox password from step 2

   The repository is public. Host names, user names and passwords belong in secrets, never in committed files. Scripts filter them out of the public Actions logs.
5. **Run the server tasks** in this order (**Actions > Server tasks > Run workflow**): `deploy`, `configure`, `setup`. Then check the site, send a `test-digest`, and use `signup-open` when ready.

### Server tasks (Actions > Server tasks > Run workflow)

| Task | What it does |
| --- | --- |
| `check` | Reports PHP version, rsync, cron, config and database status |
| `configure` | Writes `config.php` and `config.mail.php` on the server from the secrets above. Run it again after changing a secret |
| `deploy` | Copies the code to the server and updates the database tables |
| `setup` | First-time setup: tables, first sync (records everything as history), cron jobs |
| `sync` | Runs the sync now and reports the result |
| `cron` | Installs the cron jobs: sync at 07:41 and 13:41, Monday digest at 08:11 (server time, CET; an hour ahead of the UK) |
| `test-digest` | Sends this week's digest to landlords@npra.co.uk only, marked [TEST] |
| `signup-open`, `signup-close`, `signup-status` | Shows or hides the sign-up form; reports subscriber numbers (never addresses) |

Every push to `main` also deploys automatically once all test jobs pass. Deployment never touches the server's `config.php`, `config.mail.php`, `data/` folder or the generated map data.

### By hand over SSH

```
cd ~/landlords
/usr/bin/php8.5-cli bin/sync.php          # run the sync now (--no-registers to skip the spreadsheets)
/usr/bin/php8.5-cli bin/healthcheck.php   # status report
tail data/cache/sync.log                  # output of the scheduled runs
```

## Project layout

```
bin/         sync.php (twice daily), send-digest.php (Mondays), migrate.php, signup.php, healthcheck.php
src/         Council map client, register readers, storage, change report, emails, map-file export
migrations/  database schema (MySQL for IONOS, SQLite for tests)
public/      web root: the site, plus data/ written by the sync
tests/       php tests/run.php; browser tests in tests/browser/
```

## Development

```
php tests/run.php
```

The tests use an in-memory SQLite database, a fake council server and small register files in `tests/fixtures/`, so they need no network or MySQL. GitHub Actions also runs them against MariaDB 11, and runs two browser tests (Playwright and Chromium): the map against realistic generated data, and the email sign-up, digest and unsubscribe walk-through:

```
php tests/browser/make-data.php public/data
php -S 127.0.0.1:8123 -t public &
node tests/browser/map.test.js http://127.0.0.1:8123 public/data
```

Leaflet, marker clustering and the Alegreya Sans font are served from `public/vendor/` (copied from NIMBY; versions in `public/vendor/VERSIONS.txt`), so visitors' browsers contact no other servers apart from OpenStreetMap for map tiles.

## Safeguards

- If the council renames a field we use, the sync stops and keeps the previous data.
- If a layer suddenly returns nothing while we hold records from it, or the council returns fewer than 80% of the records we hold, the sync stops and keeps the previous data.
- Database changes happen in one transaction, and map files are written atomically.
- Records that vanish from the council feed are marked as removed, never deleted, and are restored if they reappear.
- The admin is emailed only when there has been no successful sync for 30 hours.

## Data

Licensing and student accommodation data from Nottingham City Council. The council publishes its licence registers "for transparency and public interest" and not for marketing or commercial use; LANDLORDS is a non-commercial residents' service. © Crown copyright and database rights, OS AC0000822898. Map tiles © OpenStreetMap contributors.

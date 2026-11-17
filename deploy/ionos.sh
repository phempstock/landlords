#!/usr/bin/env bash
# Deployment and server tasks for IONOS web hosting, run by GitHub Actions.
#
# Adapted from NIMBY's deploy/ionos.sh (same server, same deploy key).
#
#   deploy/ionos.sh check      report what the server has (PHP, rsync, cron, config, database)
#   deploy/ionos.sh configure  write config.php and config.mail.php from the repository secrets
#   deploy/ionos.sh deploy     copy the code to the server, then update database tables
#   deploy/ionos.sh setup      first-time setup: tables, first sync, cron jobs
#   deploy/ionos.sh sync       run the sync now
#   deploy/ionos.sh cron       (re)install the cron jobs: daily sync, Monday digest
#   deploy/ionos.sh test-digest    send this week's digest to the site's own mailbox only
#   deploy/ionos.sh signup-open | signup-close | signup-status
#
# Needs environment variables SSH_HOST, SSH_USER and SSH_KEY (from repository
# secrets); "configure" also uses DB_HOST, DB_NAME, DB_USER, DB_PASSWORD and
# MAIL_PASSWORD. DEPLOY_PATH defaults to "landlords", relative to the hosting home folder.
#
# The repository is public, so its Actions logs are public too. Everything this
# script prints passes through redact(), and it never echoes host names, user
# names or server paths.
set -euo pipefail

DEPLOY_PATH="${DEPLOY_PATH:-landlords}"
HERE="$(cd "$(dirname "$0")" && pwd)"
PINS="$HERE/ionos-host-keys.txt"
SSH_DIR="${RUNNER_TEMP:-/tmp}/landlords-ssh"
KEY="$SSH_DIR/id_deploy"
KNOWN="$SSH_DIR/known_hosts"
CRON_TAG='# landlords-sync'
DIGEST_TAG='# landlords-digest'

note() { echo "::notice title=LANDLORDS server::$*"; }
fail() { echo "::error title=LANDLORDS server::$*"; exit 1; }

redact() {
  sed -E \
    -e "s/'[^']*'@'[^']*'/'***'@'***'/g" \
    -e 's#/(kunden|homepages)/[^ "]*#[server path]#g' \
    -e 's/db[0-9]+\.hosting-data\.io/[db host]/g' \
    -e 's/[a-z0-9.-]+\.1and1-data\.host/[ssh host]/g' \
    -e 's/\b(dbu|dbs|acc|u)[0-9]{6,}\b/***/g' \
    -e 's/[A-Za-z0-9._%+-]+@([A-Za-z0-9-]+\.)+[A-Za-z]{2,}/[email]/g'
}

ssh_opts=(-i "$KEY" -o "UserKnownHostsFile=$KNOWN" -o StrictHostKeyChecking=yes -o BatchMode=yes -o ConnectTimeout=20 -o LogLevel=ERROR)
remote() { ssh "${ssh_opts[@]}" "$SSH_USER@$SSH_HOST" "$@"; }

# Run a remote command and show its whole output as ONE multi-line notice
# (GitHub keeps only 10 notices per step, so one per line would be cut off).
report() {
  local status=0
  remote "$1" > "$SSH_DIR/out" 2>&1 || status=$?
  local body
  body="$(redact < "$SSH_DIR/out" | grep -v '^[[:space:]]*$' | sed -e 's/%/%25/g' | awk 'BEGIN{ORS="%0A"} {print}' | sed -e 's/%0A$//')"
  [ -n "$body" ] && note "$body"
  echo "$body" | sed 's/%0A/\n/g; s/%25/%/g'   # also readable in the step log
  return "$status"
}

connect() {
  if [ -z "${SSH_HOST:-}" ] || [ -z "${SSH_USER:-}" ] || [ -z "${SSH_KEY:-}" ]; then
    fail "The IONOS_SSH_HOST, IONOS_SSH_USER and IONOS_SSH_KEY repository secrets are not all set"
  fi
  mkdir -p "$SSH_DIR" && chmod 700 "$SSH_DIR"
  printf '%s\n' "$SSH_KEY" | tr -d '\r' > "$KEY" && chmod 600 "$KEY"

  # Only trust host keys whose fingerprints are pinned in deploy/ionos-host-keys.txt.
  ssh-keyscan -T 20 "$SSH_HOST" 2>/dev/null | grep -v '^#' > "$SSH_DIR/scanned" || true
  [ -s "$SSH_DIR/scanned" ] || fail "Could not reach the server's SSH port"
  : > "$KNOWN"
  local pinned; pinned="$(grep -v '^#' "$PINS" 2>/dev/null | awk 'NF {print $1}' || true)"
  while IFS= read -r line; do
    printf '%s\n' "$line" > "$SSH_DIR/one"
    local fp; fp="$(ssh-keygen -lf "$SSH_DIR/one" 2>/dev/null | awk '{print $2}')"
    if [ -n "$fp" ] && grep -qxF "$fp" <<< "$pinned"; then
      printf '%s\n' "$line" >> "$KNOWN"
    fi
  done < "$SSH_DIR/scanned"
  if [ ! -s "$KNOWN" ]; then
    ssh-keygen -lf "$SSH_DIR/scanned" | awk '{gsub(/[()]/, "", $NF); print $NF, $2}' | while read -r type fp; do
      note "Server offered host key $type $fp (not yet pinned)"
    done
    fail "The server's host key is not in deploy/ionos-host-keys.txt, so it was not trusted"
  fi

  remote true 2>&1 | redact || fail "SSH login with the deploy key was refused"
}

php_bin() {
  # IONOS ships CGI builds as /usr/bin/php8.x and command-line builds as /usr/bin/php8.x-cli.
  # Take the newest PHP 8 binary that really is the command-line build and is a
  # full release (IONOS also offers pre-releases, e.g. 8.6.0RC4 in November 2026).
  remote 'for p in $(ls -1 /usr/bin/php8.* 2>/dev/null | grep -E "/php8\.[0-9]+(-cli)?$" | sort -V -r); do
            if [ "$("$p" -r "echo PHP_SAPI, preg_match(\"/^[0-9.]+$/\", PHP_VERSION) ? \" ok\" : \" pre\";" 2>/dev/null)" = "cli ok" ]; then echo "$p"; break; fi
          done'
}

need_php() {
  PHP="$(php_bin)"
  [ -n "$PHP" ] || fail "No command-line PHP 8 found in /usr/bin on the server"
}

need_config() {
  remote "[ -f '$DEPLOY_PATH/config.php' ]" || fail "config.php is not on the server yet (expected in the $DEPLOY_PATH folder)"
}

task_check() {
  need_php
  note "PHP $(remote "$PHP -r 'echo PHP_VERSION;'") at $PHP"
  note "rsync: $(remote 'command -v rsync >/dev/null && echo available || echo missing')"
  note "crontab: $(remote 'command -v crontab >/dev/null && echo available || echo missing')"
  note "Server time: $(remote 'date "+%H:%M %Z"')"
  note "LANDLORDS folder: $(remote "[ -d '$DEPLOY_PATH' ] && echo present || echo missing")"
  note "Code deployed: $(remote "[ -f '$DEPLOY_PATH/bootstrap.php' ] && echo yes || echo no")"
  note "config.php present: $(remote "[ -f '$DEPLOY_PATH/config.php' ] && echo yes || echo no")"
  note "Cron jobs: sync $(remote "crontab -l 2>/dev/null | grep -q '$CRON_TAG' && echo installed || echo 'not installed'"), digest $(remote "crontab -l 2>/dev/null | grep -q '$DIGEST_TAG' && echo installed || echo 'not installed'")"
  if remote "[ -f '$DEPLOY_PATH/bin/healthcheck.php' ] && [ -f '$DEPLOY_PATH/config.php' ]"; then
    report "cd '$DEPLOY_PATH' && $PHP bin/healthcheck.php" || true
  fi
}

task_deploy() {
  local rev; rev="$(git rev-parse --short HEAD)"
  if remote 'command -v rsync >/dev/null'; then
    # --delete keeps the server in step with the repository; the P (protect) rules
    # stop it removing the server's own config.php, config.mail.php, data/ (database
    # cache, logs) and map data.
    rsync -az --delete \
      --filter='P /config.php' \
      --filter='P /config.mail.php' \
      --filter='P /data/' \
      --filter='P /public/data/*.geojson' \
      --filter='P /public/data/meta.json' \
      --filter='P /public/data/expected.json' \
      --exclude='/.git/' --exclude='/.github/' --exclude='/tests/' --exclude='/deploy/' \
      -e "ssh ${ssh_opts[*]}" \
      ./ "$SSH_USER@$SSH_HOST:$DEPLOY_PATH/" 2>&1 | redact
    note "Deployed $rev with rsync"
  else
    tar czf - --exclude=./.git --exclude=./.github --exclude=./tests --exclude=./deploy . \
      | remote "mkdir -p '$DEPLOY_PATH' && tar xzf - -C '$DEPLOY_PATH'" 2>&1 | redact
    note "Deployed $rev with tar (rsync is missing, so files deleted from the repository stay on the server)"
  fi

  if remote "[ -f '$DEPLOY_PATH/config.php' ]"; then
    need_php
    report "cd '$DEPLOY_PATH' && $PHP bin/migrate.php"
  else
    note "Database update skipped: config.php is not on the server yet"
  fi
}

task_setup() {
  need_php
  need_config
  remote "chmod 600 '$DEPLOY_PATH/config.php' && mkdir -p '$DEPLOY_PATH/data/cache' '$DEPLOY_PATH/public/data'"
  report "cd '$DEPLOY_PATH' && $PHP bin/migrate.php"
  report "cd '$DEPLOY_PATH' && $PHP bin/sync.php" || true

  install_cron
  report "cd '$DEPLOY_PATH' && $PHP bin/healthcheck.php"
}

# Sync twice a day at 07:41 and 13:41, and the Monday digest at 08:11, server time
# (CET/CEST, one hour ahead of the UK all year, so 06:41, 12:41 and 07:11 UK time).
# Not earlier: the council's map service is switched off overnight. The minutes
# differ from NIMBY's so the two sites never query the council at the same moment.
# Output goes to data/cache/*.log.
install_cron() {
  remote "dir=\$(cd ~/'$DEPLOY_PATH' && pwd) \
    && sync=\"41 7,13 * * * $PHP \$dir/bin/sync.php >> \$dir/data/cache/sync.log 2>&1 $CRON_TAG\" \
    && digest=\"11 8 * * 1 $PHP \$dir/bin/send-digest.php >> \$dir/data/cache/digest.log 2>&1 $DIGEST_TAG\" \
    && (crontab -l 2>/dev/null | grep -vF '$CRON_TAG' | grep -vF '$DIGEST_TAG'; echo \"\$sync\"; echo \"\$digest\") | crontab -"
  note "Cron jobs: $(remote "crontab -l 2>/dev/null | grep -cF -e '$CRON_TAG' -e '$DIGEST_TAG'") installed (sync 07:41 and 13:41, Monday digest 08:11, server time)"
}

# PHP single-quoted string literal for a value read from the environment (never printed).
php_string() {
  python3 -c 'import os, sys; v = os.environ.get(sys.argv[1], ""); print("\x27" + v.replace("\\", "\\\\").replace("\x27", "\\\x27") + "\x27")' "$1"
}

# Write config.php (database) and config.mail.php (mail password) on the server from
# the repository secrets, so no password is ever typed into a terminal or committed.
# Values go over SSH on stdin and are never echoed; the files are readable only by
# the hosting account.
task_configure() {
  local missing=()
  for v in DB_HOST DB_NAME DB_USER DB_PASSWORD; do
    [ -n "${!v:-}" ] || missing+=("LANDLORDS_$v")
  done
  [ ${#missing[@]} -eq 0 ] || fail "These repository secrets are not set: ${missing[*]}"
  remote "mkdir -p '$DEPLOY_PATH/data/cache' '$DEPLOY_PATH/public/data'"

  local dsn="mysql:host=${DB_HOST};dbname=${DB_NAME};charset=utf8mb4"
  {
    echo "<?php"
    echo "// Written by the 'configure' server task from the repository secrets. Run it again to change anything."
    echo "\$config = require __DIR__ . '/config.example.php';"
    echo "\$config['db'] = ['dsn' => $(DSN="$dsn" php_string DSN), 'user' => $(php_string DB_USER), 'password' => $(php_string DB_PASSWORD)];"
    echo "return \$config;"
  } | remote "umask 077 && cat > '$DEPLOY_PATH/config.php.new' && mv '$DEPLOY_PATH/config.php.new' '$DEPLOY_PATH/config.php'"
  note "config.php written (database settings from the LANDLORDS_DB_* secrets)"

  if [ -n "${MAIL_PASSWORD:-}" ]; then
    {
      echo "<?php"
      echo "// Written by the 'configure' server task from the repository secrets."
      echo "return ['mail' => ["
      echo "    'host' => 'smtp.ionos.co.uk', 'port' => 587, 'encryption' => 'tls',"
      echo "    'username' => 'landlords@npra.co.uk', 'password' => $(php_string MAIL_PASSWORD),"
      echo "    'from_email' => 'landlords@npra.co.uk', 'from_name' => 'LANDLORDS – Rented homes in The Park',"
      echo "    'reply_to' => 'landlords@npra.co.uk',"
      echo "]];"
    } | remote "umask 077 && cat > '$DEPLOY_PATH/config.mail.php.new' && mv '$DEPLOY_PATH/config.mail.php.new' '$DEPLOY_PATH/config.mail.php'"
    note "config.mail.php written (mail password from the LANDLORDS_MAIL_PASSWORD secret)"
  else
    note "Mail settings skipped: the LANDLORDS_MAIL_PASSWORD secret is not set yet"
  fi

  if remote "[ -f '$DEPLOY_PATH/bin/healthcheck.php' ]"; then
    need_php
    report "cd '$DEPLOY_PATH' && $PHP bin/healthcheck.php" || true
  fi
}

task_cron() {
  need_php
  install_cron
}

task_test_digest() {
  need_php
  need_config
  report "cd '$DEPLOY_PATH' && $PHP bin/send-digest.php --test"
}

task_signup() {
  need_php
  need_config
  report "cd '$DEPLOY_PATH' && $PHP bin/signup.php $1"
}

task_sync() {
  need_php
  need_config
  report "cd '$DEPLOY_PATH' && $PHP bin/sync.php"
  report "cd '$DEPLOY_PATH' && $PHP bin/healthcheck.php"
}

case "${1:-}" in
  check)  connect; task_check ;;
  configure) connect; task_configure ;;
  deploy) connect; task_deploy ;;
  setup)  connect; task_setup ;;
  sync)   connect; task_sync ;;
  cron)   connect; task_cron ;;
  test-digest)   connect; task_test_digest ;;
  signup-open)   connect; task_signup open ;;
  signup-close)  connect; task_signup close ;;
  signup-status) connect; task_signup status ;;
  *) echo "Usage: $0 check|configure|deploy|setup|sync|cron|test-digest|signup-open|signup-close|signup-status" >&2; exit 2 ;;
esac

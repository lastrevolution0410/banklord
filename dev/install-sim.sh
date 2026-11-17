#!/bin/sh
# End-to-end test of the browser setup on a fresh copy of the website with an
# empty database. Usage: sh dev/install-sim.sh
set -e
cd "$(dirname "$0")/.."; SIM=$(mktemp -d); J=$SIM/cookies
trap 'docker rm -f bl-instsim >/dev/null 2>&1; rm -rf "$SIM"' EXIT
for d in public src sql bin; do cp -R "backend/$d" "$SIM/"; done
docker compose -f dev/docker-compose.yml up -d --wait >/dev/null 2>&1
docker compose -f dev/docker-compose.yml exec -T db mariadb -uroot -pdevroot -e "DROP DATABASE IF EXISTS blinst; CREATE DATABASE blinst; GRANT ALL ON blinst.* TO 'banklord'@'%'"
docker run -d --name bl-instsim --network dev_default -p 8097:8097 -v "$SIM":/srv -w /srv -e APP_HTTPS=off \
  dev-php php -S 0.0.0.0:8097 -t public public/index.php >/dev/null
sleep 2
B=http://127.0.0.1:8097
curl -s $B/api/v1/session | grep -q SETUP_REQUIRED && echo "PASS: API reports SETUP_REQUIRED before setup"
curl -s $B/install -H "Accept-Language: en-US" | grep -q "Set up Banklord" && echo "PASS: setup page in English for non-German browsers"
PAGE=$(curl -s -c $J -b $J -H "Accept-Language: de-DE" $B/install)
echo "$PAGE" | grep -q 'Banklord einrichten' && echo "PASS: setup page shown"
CSRF=$(echo "$PAGE" | sed -n 's/.*name="csrf" value="\([a-f0-9]*\)".*/\1/p')
post() { curl -s -c $J -b $J -H "Accept-Language: de-DE" $B/install --data-urlencode "csrf=$CSRF" -d db_host=db -d db_name=blinst -d db_user=banklord -d db_pass=banklorddevpass \
  -d guild=Testgilde -d realm=Everlook -d region=EU -d email=admin@example.org -d name=Admin "$@"; }
post -d password=short -d password2=short | grep -q 'mindestens 14' && echo "PASS: weak password rejected"
post -d password=a-long-test-password -d password2=a-long-test-password | grep -q 'Fertig' && echo "PASS: setup completed"
test -f "$SIM/private/config.local.php" && grep -q "'DB_NAME' => 'blinst'" "$SIM/private/config.local.php" && echo "PASS: configuration written"
curl -s -H 'Accept-Language: de' $B/install | grep -q 'Banklord einrichten' && echo "FAIL: setup page still reachable" || echo "PASS: setup locked afterwards"
C=$(curl -s -c $J -b $J $B/api/v1/session | sed -n 's/.*"csrf":"\([a-f0-9]*\)".*/\1/p')
curl -s -c $J -b $J -H "X-CSRF-Token: $C" -H 'Content-Type: application/json' -d '{"email":"admin@example.org","password":"a-long-test-password"}' $B/api/v1/login | grep -q '"ok"\|user' && echo "PASS: admin can log in"
curl -s -b $J $B/api/v1/guilds | grep -q '"role":"admin"' && echo "PASS: admin of the new guild"

#!/bin/sh
# End-to-end test of the signed release channel against a throwaway copy of the
# website. Usage: sh dev/release-sim.sh 1.0.0 [base-git-ref base-version]
# Without a base ref the current backend is used as the starting point.
set -e
V=$1; cd "$(dirname "$0")/.."; ROOT=$PWD; SIM=$(mktemp -d); trap 'docker rm -f bl-relsim >/dev/null 2>&1; rm -rf "$SIM"' EXIT
if [ -n "$2" ]; then git archive "$2" backend | tar -x -C "$SIM" --strip-components=1; else cp -R backend/. "$SIM/"; fi
rm -rf "$SIM/private"; mkdir -p "$SIM/private"
echo "{\"version\":\"${3:-0.9.0}\"}" > "$SIM/private/upgrade-version.json"
NS=$(sed -n 's/^namespace \([A-Za-z]*\);.*/\1/p' "$SIM/src/Database.php")
docker compose -f dev/docker-compose.yml up -d >/dev/null 2>&1
docker compose -f dev/docker-compose.yml exec -T db mariadb -uroot -pdevroot -e "DROP DATABASE IF EXISTS blrel; CREATE DATABASE blrel; GRANT ALL ON blrel.* TO 'wgb'@'%'"
for f in backend/sql/*.sql; do docker compose -f dev/docker-compose.yml exec -T db mariadb -uroot -pdevroot blrel < "$f"; done
docker run -d --name bl-relsim --network dev_default -p 8098:8098 -v "$SIM":/srv -w /srv \
  -e DB_HOST=db -e DB_NAME=blrel -e DB_USER=wgb -e DB_PASS=wgbdevpass -e APP_HTTPS=off dev-php php -S 0.0.0.0:8098 -t public public/index.php >/dev/null
sleep 2
docker exec -e NS="$NS" bl-relsim php -r '
foreach (["Config","Domain","Database","WebUpdater","ReleasePublisher"] as $c) require "/srv/src/$c.php";
$ns=getenv("NS"); $db=($ns."\\Database")::connect();
$db->exec("INSERT INTO guilds (name,realm) VALUES (\"G\",\"Everlook\")");
$db->exec("INSERT INTO users (email,password_hash,display_name) VALUES (\"a@x.invalid\",\"x\",\"A\")");
$db->exec("INSERT INTO guild_memberships (guild_id,user_id,role) VALUES (1,1,\"admin\")");
$rp=$ns."\\ReleasePublisher"; echo (new $rp($db,"/srv"))->issueToken(1,"Mac")["token"];' > "$SIM/token"
python3 -I dev/tools/publish_release.py "$V" --server http://127.0.0.1:8098 --token-file "$SIM/token" --update-host wow-bank.mgc-nom.net | tail -22
B=http://127.0.0.1:8098/updates/preview
curl -sf $B/manifest.json -o "$SIM/m.json" && cmp -s "$SIM/m.json" "dist/Banklord-$V/07_SIGNIERT/manifest.json" && echo "PASS: manifest served"
D=$(shasum -a 256 "$SIM/m.json" | cut -c1-64); curl -sf $B/events.php | grep -q "$D" && echo "PASS: events digest"
for f in dist/Banklord-$V/07_SIGNIERT/files/*; do n=$(basename "$f"); curl -sf "$B/files/$n" | shasum -a 256 | grep -q "${n%%.*}" || echo "FAIL: $n"; done && echo "PASS: files downloadable and intact"
grep -q "\"version\":\"$V\"" "$SIM/private/upgrade-version.json" && ls "$SIM"/private/upgrades/backup-*/db-preupgrade.sql.gz >/dev/null && echo "PASS: website updated itself with backup"
curl -s -X POST -H "Authorization: Bearer wgbp1_$(printf '0%.0s' $(seq 64))" http://127.0.0.1:8098/api/v1/releases/publish -d @"$SIM/m.json" | grep -q PUBLISH_TOKEN_INVALID && echo "PASS: wrong token rejected"
sed 's/"signature": "A/"signature": "B/; s/"signature": "[^A]/"signature": "A/' "$SIM/m.json" > "$SIM/bad.json"
curl -s -X POST -H "Authorization: Bearer $(cat "$SIM/token")" http://127.0.0.1:8098/api/v1/releases/publish --data-binary @"$SIM/bad.json" | grep -q "MANIFEST_SIGNATURE_INVALID\|MANIFEST_INVALID" && echo "PASS: tampered manifest rejected"
curl -s -X POST -H "Authorization: Bearer $(cat "$SIM/token")" http://127.0.0.1:8098/api/v1/releases/publish --data-binary @"$SIM/m.json" | grep -q RELEASE_NOT_NEWER && echo "PASS: replay rejected"
docker run --rm -v "$ROOT/app":/src -v "$SIM":/sim -e BANKLORD_MANIFEST=/sim/m.json -w /src golang:1.23 go test -run TestPublishedManifestVerifies . >/dev/null && echo "PASS: Windows client accepts signature, rejects tampering"

#!/bin/sh
# Simulates the live upgrade 0.8.3 -> current release with the real upgrade-web.php.
# Usage: sh dev/upgrade-sim.sh 0.9.1
set -e
V=$1; cd "$(dirname "$0")/.."; SIM=$(mktemp -d); trap 'rm -rf "$SIM"' EXIT
git archive bcaa668 backend | tar -x -C "$SIM" && mv "$SIM/backend"/* "$SIM/" && rmdir "$SIM/backend"
mkdir -p "$SIM/private" && touch "$SIM/private/install.lock"
cp "dist/Banklord-$V/01_PUBLIC_TEMP/upgrade-web.php" "$SIM/public/"
cp "dist/Banklord-$V/02_WEB_PAYLOAD/Banklord-Web-Payload-$V.zip" "$SIM/payload.zip"
cat > "$SIM/private/config.local.php" <<'PHP'
<?php return ['DB_HOST'=>'127.0.0.1','DB_NAME'=>'blsim','DB_USER'=>'root','DB_PASS'=>'devroot','APP_HTTPS'=>'on'];
PHP
echo '<?php $_SERVER["HTTPS"]="on";' > "$SIM/https.php"
docker compose -f dev/docker-compose.yml exec -T db mariadb -uroot -pdevroot -e "DROP DATABASE IF EXISTS blsim; CREATE DATABASE blsim"
for f in $(git show bcaa668 --name-only --format= -- backend/sql | sort); do git show "bcaa668:$f" | docker compose -f dev/docker-compose.yml exec -T db mariadb -uroot -pdevroot blsim; done
DBC=$(docker compose -f dev/docker-compose.yml ps -q db)
docker run --rm --network "container:$DBC" -v "$SIM":/sim -w /sim dev-php sh -c '
set -e
php -r "
\$p=new PDO(\"mysql:host=127.0.0.1;dbname=blsim\",\"root\",\"devroot\");
\$p->exec(\"INSERT INTO guilds (name,realm) VALUES (\x27Demo Guild\x27,\x27Everlook\x27)\");
\$p->prepare(\"INSERT INTO users (email,password_hash,display_name) VALUES (?,?,?)\")->execute([\"admin@x.invalid\",password_hash(\"admin-password-123\",PASSWORD_DEFAULT),\"Admin\"]);
\$p->exec(\"INSERT INTO guild_memberships (guild_id,user_id,role) VALUES (1,1,\x27admin\x27)\");
\$p->exec(\"INSERT INTO bridge_tokens (guild_id,created_by,label,token_hash,scope,expires_at) VALUES (1,1,\x27alt\x27,REPEAT(\x27a\x27,64),\x27scan.auto\x27,UTC_TIMESTAMP()+INTERVAL 1 DAY)\");"
php -d auto_prepend_file=/sim/https.php -S 127.0.0.1:8099 -t /sim/public >/dev/null 2>&1 & sleep 1
U=http://127.0.0.1:8099/upgrade-web.php; J=/tmp/jar
csrf() { curl -s -b $J -c $J $U | sed -n "s/.*name=\"csrf\" value=\"\([0-9a-f]*\)\".*/\1/p" | head -1; }
C=$(csrf); curl -s -b $J -c $J -d "csrf=$C&action=login&email=admin@x.invalid&password=admin-password-123" $U >/dev/null
C=$(csrf); curl -s -b $J -c $J -F csrf=$C -F action=upload -F payload=@/sim/payload.zip $U | grep -o "Archiv authentifiziert[^<]*" || { echo "UPLOAD FAILED"; curl -s -b $J -c $J -F csrf=$(csrf) -F action=upload -F payload=@/sim/payload.zip $U | sed -e "s/<[^>]*>/ /g" | grep -i "fehl\|ungültig\|nicht\|bitte" | head -5; exit 1; }
C=$(csrf); curl -s -b $J -c $J -d "csrf=$C&action=apply&password=admin-password-123&confirm_backup=1&confirm_maintenance=1" $U | grep -o "Upgrade[^<]*abgeschlossen[^<]*\|erfolgreich[^<]*" | head -2
test -f /sim/src/IngameOrders.php && test -f /sim/src/icon-filedata.tsv.gz && echo "PASS: new files installed"
grep -q "member.orders" /sim/src/BridgeTokens.php && echo "PASS: updated code in place"
php -r "\$p=new PDO(\"mysql:host=127.0.0.1;dbname=blsim\",\"root\",\"devroot\"); echo (str_contains(\$p->query(\"SHOW COLUMNS FROM bridge_tokens LIKE \x27scope\x27\")->fetch()[\"Type\"],\"member.orders\")?\"PASS: migration 010 applied\":\"FAIL: migration 010\"),PHP_EOL; echo (\$p->query(\"SELECT COUNT(*) FROM bridge_tokens WHERE scope=\x27scan.auto\x27\")->fetchColumn()==1?\"PASS: existing data kept\":\"FAIL: data\"),PHP_EOL;"
ls /sim/private/upgrades/backup-*/db-preupgrade.sql.gz >/dev/null && echo "PASS: database backup written"
grep -q "\"version\":\"'"$V"'\"" /sim/private/upgrade-version.json && echo "PASS: version marker"
test ! -e /sim/public/upgrade-web.php && echo "PASS: upgrader removed itself" || echo "WARN: upgrader still present"
'

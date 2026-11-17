#!/bin/sh
# Runs all automated checks inside the dev containers. Usage: sh dev/run-tests.sh
set -e
cd "$(dirname "$0")"
docker compose up -d --wait >/dev/null 2>&1
if ! docker compose exec -T db mariadb -ubanklord -pbanklorddevpass banklord -e "SELECT 1 FROM schema_migrations LIMIT 1" >/dev/null 2>&1; then
  docker compose exec -T db mariadb -uroot -pdevroot -e "DROP DATABASE IF EXISTS banklord; CREATE DATABASE banklord; GRANT ALL ON banklord.* TO 'banklord'@'%'"
  for f in ../backend/sql/*.sql; do docker compose exec -T db mariadb -ubanklord -pbanklorddevpass banklord < "$f"; done && echo "PASS: all migrations"
else
  for f in ../backend/sql/*.sql; do n=$(basename "$f"); docker compose exec -T db mariadb -ubanklord -pbanklorddevpass banklord -N -e "SELECT 1 FROM schema_migrations WHERE name='$n'" | grep -q 1 || docker compose exec -T db mariadb -ubanklord -pbanklorddevpass banklord < "$f"; done
fi
docker compose exec -T php sh -c '
set -e
cd /app/backend && for f in public/*.php src/*.php bin/*.php ../upgrade-web.php; do php -l "$f" >/dev/null; done && echo "PASS: PHP syntax"
cd /app && for t in tests/*.php; do php "$t"; done
cd /app/Banklord && for f in *.lua; do luac5.1 -p "$f"; done && echo "PASS: Lua syntax" && lua5.1 ../tests/addon_load_smoke.lua'
docker run --rm -v "$PWD/../app":/src -w /src golang:1.23 sh -c 'go test ./companion/ ./cmd/... && for os in windows darwin linux; do GOOS=$os GOARCH=amd64 go vet ./companion/ ./cmd/companion/ || exit 1; done && GOOS=windows GOARCH=amd64 go build -o /dev/null ./cmd/companion && echo "PASS: companion tests + vet windows/darwin/linux + Windows build"'
node --check ../backend/public/app.js && node --check ../backend/public/import.js && echo "PASS: JS syntax"

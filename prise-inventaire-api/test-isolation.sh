#!/usr/bin/env bash
# Démo manuelle de l'isolation multi-tenant (Phase 0).
# Prérequis : API lancée sur http://localhost:8000 + seeder TenantIsolationDemoSeeder.
set -euo pipefail
BASE=${BASE:-http://localhost:8000/api}

token() { # $1=slug $2=email
  curl -s -X POST "$BASE/auth/login" -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d "{\"tenant_slug\":\"$1\",\"email\":\"$2\",\"password\":\"password\"}" \
    | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo $d["token"]??"NO_TOKEN";'
}

echo "== Login ALPHA & BETA =="
TA=$(token alpha admin@alpha.test)
TB=$(token beta  admin@beta.test)
echo "token ALPHA=${TA:0:12}...  token BETA=${TB:0:12}..."

echo; echo "== ALPHA voit ses taxes (slug alpha) =="
curl -s "$BASE/taxes" -H "Authorization: Bearer $TA" -H "X-Tenant-Slug: alpha" -H 'Accept: application/json'; echo

echo; echo "== BETA voit ses taxes (slug beta) =="
curl -s "$BASE/taxes" -H "Authorization: Bearer $TB" -H "X-Tenant-Slug: beta" -H 'Accept: application/json'; echo

echo; echo "== Durcissement : token ALPHA + slug BETA => 403 =="
curl -s -o /dev/null -w "HTTP %{http_code}\n" "$BASE/taxes" -H "Authorization: Bearer $TA" -H "X-Tenant-Slug: beta" -H 'Accept: application/json'

echo; echo "== IDOR : ALPHA tente de modifier la taxe de BETA (id=2) => 404 =="
curl -s -o /dev/null -w "HTTP %{http_code}\n" -X PUT "$BASE/taxes/2" \
  -H "Authorization: Bearer $TA" -H "X-Tenant-Slug: alpha" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"nom":"HACKED","taux":99,"par_defaut":true}'

#!/usr/bin/env bash
# Parcours complet : inscription → code reçu → vérification → connexion → /v1/me → /v1/home → déconnexion.
# Usage : tests/smoke.sh [URL de l'API]   (défaut : http://localhost:8000)
# Le serveur doit tourner en APP_ENV=local : le code est lu dans storage/logs/codes.log
# (adresse @example.com : aucun email n'est réellement envoyé).
set -u
API="${1:-http://localhost:8000}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CODES="$ROOT/storage/logs/codes.log"
STAMP="$(date +%s)"
EMAIL="smoke+$STAMP@example.com"
PHONE="+229019${STAMP: -7}"
PASSWORD="motdepasse-$STAMP"
FAILED=0

json() { php -r '$d = json_decode(stream_get_contents(STDIN), true); $v = $d; foreach (explode(".", $argv[1]) as $k) { $v = is_array($v) && array_key_exists($k, $v) ? $v[$k] : null; } echo is_bool($v) ? ($v ? "true" : "false") : (is_scalar($v) ? $v : json_encode($v));' "$1"; }
call() { # méthode chemin [corps JSON] [jeton] → corps, puis le code HTTP sur la dernière ligne
  local args=(-s -w '\n%{http_code}' -X "$1" "$API$2" -H 'Accept: application/json')
  [ -n "${3:-}" ] && args+=(-H 'Content-Type: application/json' -d "$3")
  [ -n "${4:-}" ] && args+=(-H "Authorization: Bearer $4")
  curl "${args[@]}"
}
check() { # libellé code_attendu réponse condition_supplémentaire
  local label="$1" expected="$2" response="$3" extra="${4:-true}"
  local status="${response##*$'\n'}"
  BODY="${response%$'\n'*}"
  if [ "$status" = "$expected" ] && [ "$(echo "$BODY" | json ok)" = "$( [ "${expected:0:1}" = 2 ] && echo true || echo false)" ] && eval "$extra"; then
    printf '  OK      %s (HTTP %s)\n' "$label" "$status"
  else
    printf '  ÉCHEC   %s (HTTP %s, attendu %s)\n          %s\n' "$label" "$status" "$expected" "${BODY:0:300}"
    FAILED=$((FAILED + 1))
  fi
}

echo "API : $API   compte de test : $EMAIL"

check "1. Inscription" 201 "$(call POST /v1/auth/register "{\"first_name\":\"Test\",\"last_name\":\"Fumée\",\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\",\"phone\":\"$PHONE\"}")" \
  '[ "$(echo "$BODY" | json data.user.role)" = client ] && [ "$(echo "$BODY" | json data.user.email_verified)" = false ]'

CODE="$(grep -F " $EMAIL verify_email " "$CODES" 2>/dev/null | tail -1 | awk '{print $NF}')"
if [[ "$CODE" =~ ^[0-9]{6}$ ]]; then echo "  OK      2. Code reçu ($CODE, lu dans storage/logs/codes.log)"; else echo "  ÉCHEC   2. Code introuvable dans $CODES"; FAILED=$((FAILED + 1)); fi

check "3. Connexion refusée avant vérification" 403 "$(call POST /v1/auth/login "{\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\"}")" \
  '[ "$(echo "$BODY" | json error.code)" = EMAIL_NOT_VERIFIED ]'

check "4. Mauvais code refusé" 422 "$(call POST /v1/auth/verify-email "{\"email\":\"$EMAIL\",\"code\":\"$( [ "$CODE" = 000000 ] && echo 111111 || echo 000000)\"}")" \
  '[ "$(echo "$BODY" | json error.code)" = CODE_INVALID ]'

check "5. Vérification de l'adresse" 200 "$(call POST /v1/auth/verify-email "{\"email\":\"$EMAIL\",\"code\":\"$CODE\",\"device\":\"smoke-test\"}")" \
  '[ "$(echo "$BODY" | json data.user.email_verified)" = true ] && [ ${#BODY} -gt 0 ]'

check "6. Mauvais mot de passe refusé" 401 "$(call POST /v1/auth/login "{\"email\":\"$EMAIL\",\"password\":\"faux-mot-de-passe\"}")" \
  '[ "$(echo "$BODY" | json error.code)" = INVALID_CREDENTIALS ]'

check "7. Connexion" 200 "$(call POST /v1/auth/login "{\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\",\"device\":\"smoke-test\"}")" \
  '[[ "$(echo "$BODY" | json data.token)" =~ ^[0-9a-f]{64}$ ]]'
TOKEN="$(echo "$BODY" | json data.token)"

check "8. GET /v1/me" 200 "$(call GET /v1/me '' "$TOKEN")" \
  '[ "$(echo "$BODY" | json data.email)" = "$EMAIL" ] && [ "$(echo "$BODY" | json data.name)" = "Test Fumée" ]'

check "9. GET /v1/home" 200 "$(call GET /v1/home '' "$TOKEN")" \
  '[ "$(echo "$BODY" | json data.city.id)" = cotonou ] && [ "$(echo "$BODY" | json data.aisles | cut -c1)" = "[" ]'

ETAG="$(curl -s -D - -o /dev/null "$API/v1/home" -H "Authorization: Bearer $TOKEN" | awk 'tolower($1)=="etag:" {print $2}' | tr -d '\r')"
STATUS="$(curl -s -o /dev/null -w '%{http_code}' "$API/v1/home" -H "Authorization: Bearer $TOKEN" -H "If-None-Match: $ETAG")"
if [ "$STATUS" = 304 ]; then echo "  OK      10. Cache : 304 Not Modified avec If-None-Match"; else echo "  ÉCHEC   10. Cache : HTTP $STATUS au lieu de 304"; FAILED=$((FAILED + 1)); fi

check "11. Déconnexion" 200 "$(call POST /v1/auth/logout '' "$TOKEN")" '[ "$(echo "$BODY" | json data.logged_out)" = true ]'

check "12. Jeton refusé après déconnexion" 401 "$(call GET /v1/me '' "$TOKEN")" '[ "$(echo "$BODY" | json error.code)" = UNAUTHENTICATED ]'

echo
if [ "$FAILED" -eq 0 ]; then echo "RÉSULTAT : OK, toutes les étapes passent."; else echo "RÉSULTAT : ÉCHEC, $FAILED étape(s) en erreur."; fi
exit "$FAILED"

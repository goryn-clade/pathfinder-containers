#!/usr/bin/env bash
# Pathfinder smoke test — run against a running local stack.
# Usage: APP_PASSWORD=yourpassword ./smoke-test.sh [BASE_URL]
#
# Requires: curl, a running 'docker compose -f compose.dev.yml up' stack

BASE="${1:-http://localhost}"
PF_USER="pf"
PF_PASS="${APP_PASSWORD:-secret}"
FAIL=0

check() {
  local label="$1" url="$2" expected_status="$3"
  shift 3
  local status
  status=$(curl -s -o /dev/null -w "%{http_code}" "$@" "$url")
  if [ "$status" == "$expected_status" ]; then
    printf "  PASS  %s (%s)\n" "$label" "$status"
  else
    printf "  FAIL  %s — expected %s, got %s\n" "$label" "$expected_status" "$status"
    FAIL=1
  fi
}

echo "=== Pathfinder smoke test === (${BASE})"
echo ""

# Root — login page, never 500
check "GET /"                             "$BASE/"                                      "200"

# Setup wizard — HTTP Basic auth required
check "GET /setup (basic auth)"          "$BASE/setup"                                 "200" \
  -u "${PF_USER}:${PF_PASS}"

# EVE server status API — JSON endpoint, exercises Redis + ESI client path
check "GET /api/User/getEveServerStatus" "$BASE/api/User/getEveServerStatus"           "200"

# Map — unauthenticated redirect (not 500)
check "GET /map (unauthed)"              "$BASE/map"                                   "302"

# Admin — unauthenticated redirect (not 500)
check "GET /admin (unauthed)"            "$BASE/admin"                                 "302"

echo ""
if [ "$FAIL" -eq 0 ]; then
  echo "All checks passed."
else
  echo "One or more checks FAILED — run: docker logs pathfinder | tail -50"
fi
exit "$FAIL"

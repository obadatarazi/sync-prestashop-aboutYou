#!/usr/bin/env bash
set -euo pipefail

BASE_URL_DEFAULT="https://partner.aboutyou.com/api/v1"
CATEGORY_ID_DEFAULT="1518"
REPORT_DIR_DEFAULT="reports/ay-support-probes"
BODY_PREVIEW_BYTES=600

usage() {
  cat <<'EOF'
About You support probe script.

Usage:
  bin/ay-support-probe.sh \
    --live-key "<LIVE_KEY>" \
    --sandbox-key "<SANDBOX_KEY>" \
    [--base-url "https://partner.aboutyou.com/api/v1"] \
    [--category-id "1518"] \
    [--report-dir "reports/ay-support-probes"]

Notes:
  - Tests both keys against several endpoints and captures status/content-type/body preview.
  - Generates a timestamped report and raw response files for support attachments.
EOF
}

LIVE_KEY=""
SANDBOX_KEY=""
BASE_URL="$BASE_URL_DEFAULT"
CATEGORY_ID="$CATEGORY_ID_DEFAULT"
REPORT_DIR="$REPORT_DIR_DEFAULT"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --live-key)
      LIVE_KEY="${2:-}"
      shift 2
      ;;
    --sandbox-key)
      SANDBOX_KEY="${2:-}"
      shift 2
      ;;
    --base-url)
      BASE_URL="${2:-}"
      shift 2
      ;;
    --category-id)
      CATEGORY_ID="${2:-}"
      shift 2
      ;;
    --report-dir)
      REPORT_DIR="${2:-}"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown argument: $1" >&2
      usage
      exit 1
      ;;
  esac
done

if [[ -z "$LIVE_KEY" || -z "$SANDBOX_KEY" ]]; then
  echo "Both --live-key and --sandbox-key are required." >&2
  usage
  exit 1
fi

mkdir -p "$REPORT_DIR"

TS="$(date +%Y%m%d_%H%M%S)"
RUN_DIR="$REPORT_DIR/ay_probe_$TS"
mkdir -p "$RUN_DIR"
REPORT_FILE="$RUN_DIR/report.txt"

json_escape() {
  sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'
}

run_probe() {
  local env_name="$1"
  local api_key="$2"
  local method="$3"
  local path="$4"
  local body="${5:-}"

  local safe_path
  safe_path="$(echo "$path" | tr '/?=&' '____' | tr -cd '[:alnum:]_.-')"
  local prefix="${env_name}_${method}_${safe_path}"
  local headers_file="$RUN_DIR/${prefix}.headers.txt"
  local body_file="$RUN_DIR/${prefix}.body.txt"

  local url="${BASE_URL}${path}"
  local curl_args=(
    -sS
    -D "$headers_file"
    -o "$body_file"
    -H "X-API-Key: $api_key"
    -H "Accept: application/json"
    -H "User-Agent: syncbridge/ay-support-probe"
    -X "$method"
    "$url"
  )

  if [[ -n "$body" ]]; then
    curl_args+=(-H "Content-Type: application/json" --data "$body")
  fi

  local status
  status="$(curl "${curl_args[@]}" -w "%{http_code}")"

  local content_type
  content_type="$(awk -F': ' 'tolower($1)=="content-type"{print $2}' "$headers_file" | tr -d '\r' | head -n 1)"
  [[ -z "$content_type" ]] && content_type="<missing>"

  local ray
  ray="$(awk -F': ' 'tolower($1)=="cf-ray"{print $2}' "$headers_file" | tr -d '\r' | head -n 1)"
  [[ -z "$ray" ]] && ray="<missing>"

  local preview
  preview="$(LC_ALL=C head -c "$BODY_PREVIEW_BYTES" "$body_file" | tr '\n' ' ' | tr -s ' ' | json_escape)"

  {
    echo "- ENV=${env_name} METHOD=${method} PATH=${path}"
    echo "  STATUS=${status}"
    echo "  CONTENT_TYPE=${content_type}"
    echo "  CF_RAY=${ray}"
    echo "  BODY_PREVIEW=\"${preview}\""
    echo "  HEADERS_FILE=$(basename "$headers_file")"
    echo "  BODY_FILE=$(basename "$body_file")"
    echo
  } >> "$REPORT_FILE"
}

{
  echo "About You API probe report"
  echo "Generated at: $(date -u +'%Y-%m-%dT%H:%M:%SZ') (UTC)"
  echo "Base URL: $BASE_URL"
  echo "Category ID: $CATEGORY_ID"
  echo "Host: $(hostname)"
  echo
  echo "Key fingerprints:"
  echo "  LIVE_KEY_SUFFIX=...${LIVE_KEY: -6}"
  echo "  SANDBOX_KEY_SUFFIX=...${SANDBOX_KEY: -6}"
  echo
  echo "Tests:"
  echo
} > "$REPORT_FILE"

# Smoke checks
run_probe "live" "$LIVE_KEY" "GET" "/orders/?per_page=1"
run_probe "sandbox" "$SANDBOX_KEY" "GET" "/orders/?per_page=1"

# Metadata endpoints matrix
run_probe "live" "$LIVE_KEY" "GET" "/categories/${CATEGORY_ID}/attribute-groups"
run_probe "sandbox" "$SANDBOX_KEY" "GET" "/categories/${CATEGORY_ID}/attribute-groups"
run_probe "live" "$LIVE_KEY" "GET" "/categories/${CATEGORY_ID}/attribute_groups"
run_probe "sandbox" "$SANDBOX_KEY" "GET" "/categories/${CATEGORY_ID}/attribute_groups"
run_probe "live" "$LIVE_KEY" "GET" "/categories/${CATEGORY_ID}/attributes"
run_probe "sandbox" "$SANDBOX_KEY" "GET" "/categories/${CATEGORY_ID}/attributes"
run_probe "live" "$LIVE_KEY" "GET" "/attributes?category_id=${CATEGORY_ID}&per_page=1"
run_probe "sandbox" "$SANDBOX_KEY" "GET" "/attributes?category_id=${CATEGORY_ID}&per_page=1"

# Product endpoint checks
run_probe "live" "$LIVE_KEY" "POST" "/products/" '{"items":[]}'
run_probe "sandbox" "$SANDBOX_KEY" "POST" "/products/" '{"items":[]}'

echo "Probe completed."
echo "Report file: $REPORT_FILE"
echo "Raw files dir: $RUN_DIR"

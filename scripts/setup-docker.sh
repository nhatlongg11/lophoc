#!/usr/bin/env bash
set -euo pipefail
umask 077

project_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
environment_path="$project_root/.env"

if [[ -e "$environment_path" || -L "$environment_path" ]]; then
    printf '%s\n' '.env already exists; keeping its keys and passwords. Run docker compose config --quiet to validate it.'
    exit 0
fi

if ! command -v openssl >/dev/null 2>&1; then
    printf '%s\n' 'OpenSSL is required. Install it with: sudo apt-get install openssl' >&2
    exit 1
fi

# Read dotenv values as data; never source or eval files containing credentials.
read_environment() {
    local file="$1" line key value
    local -n destination="$2"
    [[ -f "$file" ]] || return 0

    while IFS= read -r line || [[ -n "$line" ]]; do
        line="${line%$'\r'}"
        if [[ "$line" =~ ^[[:space:]]*([A-Za-z_][A-Za-z0-9_]*)=(.*)$ ]]; then
            key="${BASH_REMATCH[1]}"
            value="${BASH_REMATCH[2]}"
            value="${value#"${value%%[![:space:]]*}"}"
            value="${value%"${value##*[![:space:]]}"}"
            if [[ ${#value} -ge 2 && "${value:0:1}" == "'" && "${value: -1}" == "'" ]]; then
                value="${value:1:${#value}-2}"
                value="${value//\\\'/\'}"
            elif [[ ${#value} -ge 2 && "${value:0:1}" == '"' && "${value: -1}" == '"' ]]; then
                value="${value:1:${#value}-2}"
            fi
            destination["$key"]="$value"
        fi
    done < "$file"
}

declare -A saved=() backend=() saved_role=() values=()
read_environment "$project_root/.tools/compose.env" saved
read_environment "$project_root/backend/.env" backend
read_environment "$project_root/.tools/postgres-app.env" saved_role

# Preserve credentials used by this repository's existing database and uploads.
for key in POSTGRES_PASSWORD APP_DB_USERNAME APP_DB_PASSWORD S3_ACCESS_KEY S3_SECRET_KEY; do
    if [[ -n "${saved[$key]:-}" ]]; then
        values["$key"]="${saved[$key]}"
    fi
done
values[APP_DB_USERNAME]="${values[APP_DB_USERNAME]:-eduquest_app}"
if [[ -z "${values[APP_DB_PASSWORD]:-}" && -n "${saved_role[DB_PASSWORD]:-}" ]]; then
    values[APP_DB_PASSWORD]="${saved_role[DB_PASSWORD]}"
fi
if [[ -z "${values[APP_DB_PASSWORD]:-}" && "${backend[DB_CONNECTION]:-}" == pgsql && "${backend[DB_USERNAME]:-}" == "${values[APP_DB_USERNAME]}" ]]; then
    values[APP_DB_PASSWORD]="${backend[DB_PASSWORD]:-}"
fi
for key in POSTGRES_PASSWORD APP_DB_PASSWORD S3_ACCESS_KEY S3_SECRET_KEY; do
    if [[ -z "${values[$key]:-}" ]]; then
        values["$key"]="$(openssl rand -hex 32)"
    fi
done
values[APP_KEY]="${backend[APP_KEY]:-${saved[APP_KEY]:-}}"
if [[ -z "${values[APP_KEY]}" ]]; then
    values[APP_KEY]="base64:$(openssl rand -base64 32)"
fi

temporary_env="$(mktemp "$project_root/.env.setup.XXXXXX")"
trap 'rm -f -- "$temporary_env"' EXIT
while IFS= read -r line || [[ -n "$line" ]]; do
    line="${line%$'\r'}"
    if [[ "$line" =~ ^([A-Z_][A-Z0-9_]*)= && -v "values[${BASH_REMATCH[1]}]" ]]; then
        key="${BASH_REMATCH[1]}"
        value="${values[$key]}"
        if [[ "$value" == *$'\r'* || "$value" == *$'\n'* ]]; then
            printf 'Invalid multiline value for %s.\n' "$key" >&2
            exit 1
        fi
        value="${value//\'/\\\'}"
        printf "%s='%s'\n" "$key" "$value"
    else
        printf '%s\n' "$line"
    fi
done < "$project_root/.env.example" > "$temporary_env"

# A hard link publishes the complete file atomically and refuses to overwrite .env.
if ! ln -- "$temporary_env" "$environment_path"; then
    printf '%s\n' 'Could not create .env; an existing file was preserved.' >&2
    exit 1
fi

printf '%s\n' \
    'Created .env with saved credentials where available and random values for missing secrets.' \
    'Set APP_URL and production HTTPS settings in .env before deployment.' \
    'Start: docker compose up -d --build --wait' \
    'Stop:  docker compose down (keeps database and upload volumes)'

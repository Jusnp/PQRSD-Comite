#!/bin/sh
set -eu

db=${POSTGRES_DB:-}
owner=${POSTGRES_USER:-}
pass=${PQRSD_APP_DB_PASSWORD:-}

if [ -z "$db" ] || [ -z "$owner" ] || [ -z "$pass" ]; then
    echo "Faltan POSTGRES_DB, POSTGRES_USER o PQRSD_APP_DB_PASSWORD." >&2
    exit 1
fi

case "$db" in
    *[!A-Za-z0-9_]*)
        echo "POSTGRES_DB solo admite letras, numeros y guion bajo." >&2
        exit 1
        ;;
esac

case "$owner" in
    *[!A-Za-z0-9_]*)
        echo "POSTGRES_USER solo admite letras, numeros y guion bajo." >&2
        exit 1
        ;;
esac

if printf '%s' "$pass" | grep -q '[[:cntrl:]]'; then
    echo "PQRSD_APP_DB_PASSWORD no puede contener caracteres de control." >&2
    exit 1
fi

escaped=$(printf '%s' "$pass" | sed "s/'/''/g")

psql -v ON_ERROR_STOP=1 --username "$owner" --dbname "$db" <<SQL
DO \$\$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'pqrsd_app') THEN
        CREATE ROLE pqrsd_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD '${escaped}';
    ELSE
        ALTER ROLE pqrsd_app WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD '${escaped}';
    END IF;
END
\$\$;
REVOKE ALL ON DATABASE ${db} FROM PUBLIC;
GRANT CONNECT ON DATABASE ${db} TO ${owner};
GRANT CONNECT ON DATABASE ${db} TO pqrsd_app;
GRANT USAGE, CREATE ON SCHEMA public TO pqrsd_app;
ALTER SCHEMA public OWNER TO pqrsd_app;
SQL

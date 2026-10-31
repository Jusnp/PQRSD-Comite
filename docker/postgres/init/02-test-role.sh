#!/bin/sh
set -eu

if [ -z "${PQRSD_TEST_DB_PASSWORD:-}" ]; then
    echo "Falta PQRSD_TEST_DB_PASSWORD para crear el usuario de pruebas." >&2
    exit 1
fi

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres <<SQL
DO \$\$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'pqrsd_test') THEN
        CREATE ROLE pqrsd_test LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD '${PQRSD_TEST_DB_PASSWORD}';
    ELSE
        ALTER ROLE pqrsd_test WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD '${PQRSD_TEST_DB_PASSWORD}';
    END IF;
END
\$\$;
SELECT pg_terminate_backend(pid)
FROM pg_stat_activity
WHERE datname = 'pqrsd_postgres_test' AND pid <> pg_backend_pid();
DROP DATABASE IF EXISTS pqrsd_postgres_test;
CREATE DATABASE pqrsd_postgres_test OWNER pqrsd_test;
REVOKE CONNECT ON DATABASE pqrsd_postgres_dev FROM PUBLIC;
GRANT CONNECT ON DATABASE pqrsd_postgres_dev TO ${POSTGRES_USER};
REVOKE ALL ON DATABASE pqrsd_postgres_dev FROM pqrsd_test;
REVOKE CONNECT ON DATABASE pqrsd_postgres_test FROM PUBLIC;
GRANT CONNECT ON DATABASE pqrsd_postgres_test TO pqrsd_test;
SQL

\set ON_ERROR_STOP on
\getenv app_user APP_DB_USERNAME
\getenv app_password APP_DB_PASSWORD

-- Quote identifiers and passwords with PostgreSQL itself, never shell interpolation.
SELECT format('CREATE ROLE %I LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD %L', :'app_user', :'app_password')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'app_user')
\gexec

SELECT format('ALTER ROLE %I LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD %L', :'app_user', :'app_password')
\gexec

GRANT CONNECT ON DATABASE eduquest TO :"app_user";
GRANT USAGE, CREATE ON SCHEMA public TO :"app_user";
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO :"app_user";
GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO :"app_user";

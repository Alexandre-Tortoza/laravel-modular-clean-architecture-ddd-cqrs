-- Operator-only bootstrap after explicit database-change approval. Terraform
-- never executes this file. Connect to the selected database with TLS verify-full
-- using the separately managed administrator, and pass database_name and
-- application_username as psql variables. Existing roles fail without alteration.
-- No password is accepted here: set it through psql \password on a private TTY,
-- then store it in the approved application secret source (#27).
\set ON_ERROR_STOP on
SELECT current_database() = :'database_name' AS correct_database \gset
\if :correct_database
BEGIN;
CREATE ROLE :"application_username" WITH LOGIN NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
GRANT CONNECT ON DATABASE :"database_name" TO :"application_username";
GRANT USAGE, CREATE ON SCHEMA public TO :"application_username";
COMMIT;
\else
DO $$ BEGIN RAISE EXCEPTION 'Refusing application-role setup in an unexpected database'; END; $$;
\endif

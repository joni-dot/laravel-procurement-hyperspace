-- Lets the app's restricted database user read and write rows in one database.
-- Run it as the owner (the user that runs the migrations), once per database:
--   psql -U <owner> -d <database> -v app_user=<app user> -v owner=<owner> -f app-role.sql
--
-- The app user gets no right to create, change or drop tables. That keeps the companies' row-level
-- security in force for it: only a table's owner or a superuser could switch it off.
GRANT CONNECT ON DATABASE :"DBNAME" TO :"app_user";
GRANT USAGE ON SCHEMA public TO :"app_user";
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO :"app_user";
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO :"app_user";
ALTER DEFAULT PRIVILEGES FOR ROLE :"owner" IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO :"app_user";
ALTER DEFAULT PRIVILEGES FOR ROLE :"owner" IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO :"app_user";

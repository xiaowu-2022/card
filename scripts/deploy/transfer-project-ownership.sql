-- One-time repair authorized for the dedicated card_platform database.
-- Run as its administrator with psql -v ON_ERROR_STOP=1; pause application writers first.
-- No REASSIGN OWNED, role memberships, balance writes or trigger disabling.
BEGIN;
SET LOCAL lock_timeout = '10s';

DO $repair$
DECLARE
    obj record;
    object_kind text;
    relation_count integer := 0;
    routine_count integer := 0;
BEGIN
    IF current_database() <> 'card_platform' THEN
        RAISE EXCEPTION 'Expected database card_platform; connected to %', current_database();
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'card_platform' AND rolcanlogin) THEN
        RAISE EXCEPTION 'Expected existing login role card_platform';
    END IF;

    GRANT USAGE, CREATE ON SCHEMA public TO card_platform;

    -- Tables first: their indexes, row types and owned sequences follow ownership.
    FOR obj IN
        SELECT c.relname, c.relkind
        FROM pg_class c
        JOIN pg_namespace n ON n.oid = c.relnamespace
        JOIN pg_roles r ON r.oid = c.relowner
        WHERE n.nspname = 'public' AND r.rolname = 'postgres'
          AND c.relkind IN ('r', 'p', 'S', 'v', 'm', 'f')
          AND NOT EXISTS (
              SELECT 1 FROM pg_depend d
              WHERE d.classid = 'pg_class'::regclass AND d.objid = c.oid AND d.deptype = 'e'
          )
        ORDER BY CASE WHEN c.relkind IN ('r', 'p') THEN 0 WHEN c.relkind = 'S' THEN 2 ELSE 1 END, c.relname
    LOOP
        object_kind := CASE obj.relkind
            WHEN 'S' THEN 'SEQUENCE' WHEN 'v' THEN 'VIEW'
            WHEN 'm' THEN 'MATERIALIZED VIEW' WHEN 'f' THEN 'FOREIGN TABLE'
            ELSE 'TABLE' END;
        EXECUTE format('ALTER %s public.%I OWNER TO card_platform', object_kind, obj.relname);
        relation_count := relation_count + 1;
    END LOOP;

    -- Trigger functions also require ownership for future CREATE OR REPLACE migrations.
    FOR obj IN
        SELECT p.proname, p.prokind, pg_get_function_identity_arguments(p.oid) AS arguments
        FROM pg_proc p
        JOIN pg_namespace n ON n.oid = p.pronamespace
        JOIN pg_roles r ON r.oid = p.proowner
        WHERE n.nspname = 'public' AND r.rolname = 'postgres'
          AND p.prokind IN ('f', 'p', 'w')
          AND NOT EXISTS (
              SELECT 1 FROM pg_depend d
              WHERE d.classid = 'pg_proc'::regclass AND d.objid = p.oid AND d.deptype = 'e'
          )
        ORDER BY p.proname, p.oid
    LOOP
        object_kind := CASE obj.prokind WHEN 'p' THEN 'PROCEDURE' ELSE 'FUNCTION' END;
        EXECUTE format('ALTER %s public.%I(%s) OWNER TO card_platform', object_kind, obj.proname, obj.arguments);
        routine_count := routine_count + 1;
    END LOOP;

    RAISE NOTICE 'Transferred % relations and % routines to card_platform', relation_count, routine_count;
END;
$repair$;

COMMIT;

When I create or modify a database migration file, ALWAYS perform these steps automatically:

Read the Migration: Analyze the new database changes (new columns, tables, etc.).

Sync Schema: Automatically update the main schema file (e.g., schema.prisma or schema.sql) to match the migration.

Update Types: Update the corresponding ORM models and TypeScript types to ensure type safety.

Report: Give me a quick summary of the files you modified.
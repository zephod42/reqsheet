# Roadmap

This is an intentionally small, reviewable starting roadmap.

1. Confirm the department workflow, requisition fields, and printable output.
2. Define authentication, roles, ownership, and audit requirements.
3. Review the database foundation, then design the minimal application schema and migrations.
4. Build the server-rendered requisition workflow with validation.
5. Add browser-printable views and focused automated tests.
6. Document deployment and operational procedures after the application is stable.

No roadmap item authorizes implementation of product features in this bootstrap task.

The database foundation review and local MySQL development verification are complete. The initial application-domain schema is now defined in migrations for organisations, staff identities, timetable versions and slots, recurring lessons, dated lesson occurrences, and requisitions. Application services for validating timetable spans, generating occurrences, and editing requisitions remain future work.

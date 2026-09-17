# Roadmap

This is an intentionally small, reviewable starting roadmap.

1. Confirm the department workflow, requisition fields, and printable output.
2. Define authentication, roles, ownership, and audit requirements.
3. Review the database foundation, then design the minimal application schema and migrations.
4. Build the server-rendered requisition workflow with validation.
5. Add browser-printable views and focused automated tests.
6. Document deployment and operational procedures after the application is stable.

No roadmap item authorizes implementation of product features in this bootstrap task.

The database foundation and initial application-domain schema are complete and verified against local MySQL. Timetable configuration services now provide a create-only validated path for effective-dated versions, slots, and recurring lessons. The bounded occurrence-generation service validates timetable spans/conflicts and creates dated occurrences for explicit inclusive date ranges. Teacher entry, timetable editing UI, exception handling, and requisition editing remain future work.

# Product design

## Purpose

Reqsheet will help a science department prepare, review, and print lesson requisitions in a consistent format.

## Initial principles

- Keep the workflow understandable to non-technical school staff.
- Prefer server-rendered HTML and conventional forms.
- Make printed requisitions clear and reliable on ordinary office printers.
- Keep data ownership, validation, and permissions explicit.
- Avoid product scope that is not required by the department's workflow.

## Current database boundary

The initial application-domain schema now covers organisations, staff identities, effective-dated timetable configuration, recurring lessons, dated lesson occurrences, and requisitions. It deliberately contains no authentication, roles, timetable editing, occurrence generation service, printing implementation, or application-domain catalogues.

## Not designed yet

Authentication, roles, timetable editing, occurrence generation, approval workflow, reporting, and detailed requisition workflow remain open design work. No assumptions about those features are implemented in the bootstrap.

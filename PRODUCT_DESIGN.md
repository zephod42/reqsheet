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

The initial application-domain schema now covers organisations, staff identities, effective-dated timetable configuration, recurring lessons, dated lesson occurrences, and requisitions. Application services now provide the validated create path for timetable versions, slots, and recurring lessons; a bounded, explicit-date occurrence-generation service validates timetable spans and conflicts before inserting historical occurrences. These services deliberately provide no UI or update workflow. The project still contains no authentication, roles, teacher UI, technician UI, printing implementation, or application-domain catalogues.

## Not designed yet

Authentication, roles, timetable editing UI, approval workflow, reporting, and detailed requisition workflow remain open design work. Timetable configuration currently has create-only services: effective-date versions cannot overlap, slots are validated for coherent day/time order, and lessons are validated for contiguous teaching spans and teacher/room conflicts. Occurrence exceptions, holidays, cancellations, updates, and scheduled generation remain deferred.

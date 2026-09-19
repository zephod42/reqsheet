# Canonical timetable CSV

Reqsheet supports one application-owned timetable CSV format. It does not parse or infer SIMS, Arbor, iSAMS, Nova-T, or other third-party formats, and it does not call an AI service. External tools may populate the Reqsheet file, but Reqsheet remains responsible for deterministic validation.

## Milestone 1: blank export

An authenticated organisation administrator can download `/admin/timetable/export.csv?version=<id>` from the selected timetable builder using **Export Blank CSV**.

The exact columns are:

```csv
Day,Period,Room,Class,Teacher
```

Each row is one configured teaching slot and one current organisation room. Day uses the existing full weekday label, Period uses the configured slot label, and Room uses the existing organisation room code. Rows follow the template's first-day ordering, slot sequence, and repository room ordering. Non-teaching slots are omitted. Class and Teacher are always empty.

The exporter reads only the selected organisation-owned timetable version, its slots, and the organisation's rooms. It never queries recurring lessons, dated occurrences, requisitions, planning notes, risk assessments, or student data. Consequently, an empty template and an assignment-filled template with the same structure and rooms produce identical files. A template with no rooms or no teaching periods returns to the builder with a useful message rather than downloading a header-only file.

The response is UTF-8 CSV with RFC-style quoting, CRLF row endings, an attachment filename derived from the template label, `nosniff`, and private/no-store cache controls. Export is read-only and does not require CSRF; normal tenant resolution, session validation, and administrator authorization still apply.

## Existing model used by the importer

No second timetable model is required:

- `timetable_versions` and `timetable_slots` define the selected template and its ordered teaching/non-teaching structure.
- `organisation_rooms`, `organisation_classes`, and organisation users with the Teacher role are the only valid resource references.
- `recurring_lessons` is the assignment target. It already records version, teacher, weekday, start slot, duration, class and room resources/codes.
- `lesson_occurrences` and `requisitions` are dated historical records. Import must not update or delete them.
- `TimetableRules`, `RecurringLessonService`, `TimetableSlot`, and `PdoTimetableConfigurationStore` contain the ownership, span, adjacency, and conflict rules that an importer must reuse rather than reimplement inconsistently.
- Timetable activation remains the separate organisation-scoped operation already provided by `activateVersion()`.

## Milestone 2: upload, validation, and preview

An administrator selects an empty timetable and uploads the completed file through **Import CSV**. The upload is a CSRF-protected `POST` to `/admin/timetable/import`; only `.csv` files up to 2 MiB and 20,000 data rows are accepted. Uploaded bytes are read from PHP's temporary upload location and are not moved into the document root or retained after the request.

Validation is deterministic:

- the header must be exactly `Day,Period,Room,Class,Teacher`;
- text must be valid UTF-8 and every record must have five columns;
- the server regenerates the complete Day + Period + Room matrix from the selected organisation-owned version and current organisation rooms;
- every expected combination must occur exactly once, although row order may differ from the blank export;
- unknown, additional, missing, and duplicate combinations are rejected with CSV row details where a source row exists;
- Class and Teacher must either both be blank or both be populated;
- teachers resolve exactly by active, Teacher-role, organisation-scoped staff code; classes and rooms resolve exactly within the same organisation;
- occupied cells are checked for simultaneous teacher, class, and room conflicts.

Identical Class and Teacher values form one multi-period lesson only when they are in consecutive teaching slots in the same room and day. A gap, room/day change, Break, Lunch, or other non-teaching separator ends the lesson. Multi-period proposals are rejected when conjoined periods are disabled in organisation Settings. The selected timetable must be empty when previewed; no existing assignment is deleted, replaced, or merged.

Successful validation produces an escaped, read-only preview showing the selected timetable, proposed lesson and occupied/free counts, resources, and multi-period spans. It does not expose a confirmation action in this milestone and performs no inserts or updates. A separate **Export Resource Reference** download provides only eligible teacher codes/names, class codes, and room codes using `Resource Type,Code,Name`; it excludes emails, passwords, account state, and session information.

The preview's canonical proposed assignments are stored in the current administrator session for 15 minutes with a random draft ID, organisation ID, user ID, version ID, structure digest, and expiry. Only one current draft is retained, so a later valid preview supersedes it. Uploaded file bytes are not stored. The draft is tenant/user-bound and is deliberately non-authoritative.

## Milestone 3: confirmed atomic import

The validated preview now provides explicit **Import Timetable** and **Cancel** actions. Both are CSRF-protected posts containing only the random draft identifier; organisation, user, timetable version, proposal, and counts are read from the server-side session draft. The preview states that import does not activate the timetable.

Confirmation requires the same authenticated administrator, organisation, and unexpired session draft that created the preview. Cancel consumes the draft without changing the database. A successful import also consumes it and redirects to the selected builder with `Timetable imported successfully.` Expired, cancelled, completed, foreign-user, and foreign-tenant drafts cannot be reused.

Confirmation uses one InnoDB transaction on one PDO connection:

1. Lock the selected `timetable_versions` row with `FOR UPDATE` and verify organisation ownership.
2. Lock/check the version's `recurring_lessons` range and reject a timetable that is no longer empty.
3. Lock the current slots, rooms, classes, eligible teachers, and conjoined-period setting used for revalidation.
4. Regenerate the blank structure, compare its digest with the preview, reconstruct the proposed occupied rows from the server draft, and rerun the complete Milestone 2 resolver/grouping/conflict validator.
5. Compare the newly resolved canonical proposal and counts with the draft, then insert every `recurring_lessons` row through an organisation-constrained insert.
6. Commit after all inserts succeed; any validation or storage failure rolls back the whole transaction.

The version-row lock serialises imports targeting the same timetable. A second importer waits and then sees the committed assignments, so it fails the empty-timetable check rather than duplicating lessons. Manual activation remains separate. Import neither creates dated occurrences nor updates/deletes occurrences or requisitions, and it does not change the active timetable ID.

Ordinary state changes after preview—such as a populated target, changed structure/rooms, removed or changed teacher/class resources, disabled multi-period support, or new conflicts—produce an actionable validation response and no HTTP 503. Unexpected transaction failures are rolled back, logged through the request-ID exception logger, and shown without SQL details.

Preview text is HTML-escaped, and spreadsheet formula-like values remain inert strings in Reqsheet. Uploaded content is never executed or interpreted as HTML.

## Operational notes and remaining risks

- Confirm the 2 MiB and 20,000-row limits against the largest pilot timetable before broad deployment.
- Server-side session drafts are intentionally temporary. A durable/shared preview store would need a migration only if Reqsheet later moves to multiple web nodes or requires previews to survive session loss; that is not required now.
- Import intentionally creates recurring assignments only. Existing occurrence-generation procedures remain responsible for producing future dated occurrences when appropriate.

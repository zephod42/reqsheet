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
- Timetable activation remains the organisation-scoped operation already provided by `activateVersion()`; confirmed CSV imports call the same activation semantics inside their atomic transaction.

## Milestone 2: upload, validation, and preview

An administrator selects a source timetable and uploads the completed file through **Import CSV**. The source may already contain assignments; it is used only for structure and remains unchanged. The upload is a CSRF-protected `POST` to `/admin/timetable/import`; only `.csv` files up to 2 MiB and 20,000 data rows are accepted. Uploaded bytes are read from PHP's temporary upload location and are not moved into the document root or retained after the request.

Validation is deterministic:

- the header must be exactly `Day,Period,Room,Class,Teacher`;
- text must be valid UTF-8 and every record must have five columns;
- the server regenerates the complete Day + Period + Room matrix from the selected organisation-owned version and current organisation rooms;
- after rows for unrecognized rooms are discarded, every expected recognized-room combination must occur exactly once, although row order may differ from the blank export;
- missing and duplicate recognized-room combinations are rejected with CSV row details where a source row exists; discarded room rows are reported as warnings;
- Class and Teacher must either both be blank or both be populated;
- teachers resolve exactly by active, Teacher-role, organisation-scoped staff code; unknown or ineligible teachers block the import; existing classes are reused and missing classes are proposed for creation;
- occupied cells are checked for simultaneous teacher, class, and room conflicts.

Identical Class and Teacher values form one multi-period lesson only when they are in consecutive teaching slots in the same room and day. A gap, room/day change, Break, Lunch, or other non-teaching separator ends the lesson. Multi-period proposals are rejected when conjoined periods are disabled in organisation Settings. The selected source timetable does not need to be empty; no existing assignment is deleted, replaced, or merged.

Successful validation produces an escaped, read-only preview showing the source timetable, automatically proposed `<source>_imported_YYYYMMDD_HHMMSS` name, the immediate-activation destination, lesson and occupied/free counts, proposed new classes, skipped-room warnings, resources, and multi-period spans. It performs no inserts or updates. A separate **Export Resource Reference** download provides only eligible teacher codes/names, class codes, and room codes using `Resource Type,Code,Name`; it excludes passwords, account state, recovery data, and session information.

The preview's canonical proposed assignments are stored in the current administrator session for 15 minutes with a random draft ID, organisation ID, user ID, version ID, structure digest, and expiry. Only one current draft is retained, so a later preview supersedes it. The exact uploaded CSV bytes are retained in that same session draft for the same 15-minute period when validation reports missing resources, allowing those resources to be created inline and the CSV to be revalidated without another upload. The draft is tenant/user-bound and is deliberately non-authoritative; expiry removes it from the session.

When validation reports an unrecognised room or teacher, each contextual Add button sends a CSRF-protected request for that draft without navigating away. The server uses the normal room service or People account service, and returns a JSON confirmation only after the resource is committed. An archived room is reported separately with a contextual Restore Room action; restoration reactivates the same room ID and never creates a duplicate. The browser replaces only successful controls with accessible green `✓ Added` or `✓ Restored` statuses; failed requests remain retryable. Duplicate references are rendered once, and concurrent requests treat an already-created matching resource as an idempotent success. **Validate Again** reparses the retained original CSV against current tenant resources and returns either the normal confirmation preview or updated validation errors.

## Milestone 3: confirmed atomic import

The validated preview now provides explicit **Import Timetable** and **Cancel** actions. Both are CSRF-protected posts containing only the random draft identifier; organisation, user, source timetable, proposal, and counts are read from the server-side session draft. Confirmation creates a new timetable and activates it immediately; the source timetable is never the assignment target.

Confirmation requires the same authenticated administrator, organisation, and unexpired session draft that created the preview. Cancel consumes the draft without changing the database. A successful import also consumes it and redirects to the newly created timetable builder with its generated name, created classes, and skipped-room warnings. Expired, cancelled, completed, foreign-user, and foreign-tenant drafts cannot be reused.

Confirmation uses one InnoDB transaction on one PDO connection:

1. Lock the organisation activation state and selected source `timetable_versions` row with `FOR UPDATE` and verify organisation ownership.
2. Lock/check the source structure and resources; existing source `recurring_lessons` are not an import target and are not changed.
3. Lock the current slots, rooms, classes, eligible teachers, and conjoined-period setting used for revalidation.
4. Regenerate the blank structure, compare its digest with the preview, reconstruct the proposed occupied rows from the server draft, and rerun the complete Milestone 2 resolver/grouping/conflict validator.
5. Compare the newly resolved canonical proposal and counts with the draft, generate a unique timetable name, clone the complete source slot structure, and create missing organisation classes.
6. Insert every `recurring_lessons` row into the cloned version, activate that version through the normal organisation activation field, and commit only after all operations succeed.

The organisation lock serialises imports and activation changes. A second importer receives a unique generated name and a separate timetable rather than duplicating assignments into the source. Previous versions remain available for normal manual reactivation. Import neither creates dated occurrences nor updates/deletes occurrences or requisitions.

Ordinary state changes after preview—such as a populated target, changed structure/rooms, removed or changed teacher/class resources, disabled multi-period support, or new conflicts—produce an actionable validation response and no HTTP 503. Unexpected transaction failures are rolled back, logged through the request-ID exception logger, and shown without SQL details.

Preview text is HTML-escaped, and spreadsheet formula-like values remain inert strings in Reqsheet. Uploaded content is never executed or interpreted as HTML.

## Revised resource rules

- Unknown class codes are created once during successful confirmation only; they are listed in the preview and in the success message.
- Unknown room rows are discarded before structural validation, are never used to create rooms/classes/lessons, and are listed with row counts in the preview and success message.
- Unknown or ineligible teachers in recognized rooms block the entire import and no timetable, class, or lesson is committed.
- Every confirmed import creates a new structurally independent timetable and activates it immediately. The source timetable can remain populated and can later be reactivated through the normal timetable controls.

## Operational notes and remaining risks

- Confirm the 2 MiB and 20,000-row limits against the largest pilot timetable before broad deployment.
- Server-side session drafts and retained CSV bytes are intentionally temporary. A durable/shared preview store would need a migration only if Reqsheet later moves to multiple web nodes or requires previews to survive session loss; that is not required now.
- Import intentionally creates recurring assignments only. Existing occurrence-generation procedures remain responsible for producing future dated occurrences when appropriate.

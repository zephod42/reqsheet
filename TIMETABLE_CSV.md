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

## Planned upload, preview, and confirmation architecture

The subsequent import milestone should use four explicit stages:

1. **Upload and parse.** An admin-only, CSRF-protected POST accepts only the canonical CSV. A dedicated parser checks the exact five headers and order, UTF-8, well-formed rows, a bounded file size and row count, and treats every field as plain text. It performs no writes.
2. **Resolve and validate.** A validator loads the selected organisation-owned version, teaching slots, current rooms, classes, and eligible teachers. It resolves Day + Period + Room cells and tenant-scoped Class codes and Teacher initials exactly. Unknown or ambiguous values, partially filled Class/Teacher pairs, duplicates, and conflicts are errors. Empty Class and Teacher together mean an unoccupied cell.
3. **Preview.** Valid rows are grouped into proposed `RecurringLesson` values. Identical Class and Teacher values in consecutive teaching slots for the same day and room become one multi-period lesson; a break, lunch, non-teaching slot, changed resource, or gap ends the span. The escaped preview shows the complete proposal and errors but writes no assignments. A bounded, expiring server-side session draft keyed by a random preview ID is sufficient for the current single-host application; it must include organisation ID, user ID, version ID, canonical rows, creation time, and a digest.
4. **Confirm atomically.** A separate CSRF-protected POST consumes the preview ID. Inside one database transaction, the import store locks and rechecks the organisation-owned version, locks its recurring lessons and proves the timetable is still empty, reloads and resolves every resource, rebuilds the proposal, and reruns all span/conflict validation. Only then may it insert every recurring lesson and commit. Any error rolls back all inserts. It must not activate the version.

The final transaction needs a narrow bulk-import method in the timetable persistence layer because the current individual lesson methods do not own one transaction across an entire import. That method should use the existing tables and validation objects; it does not require a schema migration. Preview data must never be accepted as proof that resources still exist or that the timetable remains empty.

Initial safety limits should be explicit constants and covered by tests; a proposed starting point is 2 MiB and 20,000 data rows. Preview text must be HTML-escaped, and values beginning with spreadsheet formula characters remain inert strings in Reqsheet. Uploaded content must never be executed or interpreted as HTML.

## Remaining import-stage decisions

- Confirm the initial file-size and row-count limits against the largest pilot timetable.
- Decide whether the UI alone adequately lists valid teacher initials, class codes and rooms, or whether a separate non-sensitive reference CSV is useful. The five-column blank timetable format must not change.
- Server-side session drafts are intentionally temporary. A durable/shared preview store would need a migration only if Reqsheet later moves to multiple web nodes or requires previews to survive session loss; that is not required now.

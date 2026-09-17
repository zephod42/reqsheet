# Reqsheet legacy-workbook reference

This directory contains a sanitised reference derived from the existing school Excel requisition workflow.

## Scope

Only three parts of the legacy workbook matter for Reqsheet:

1. **Teacher entry view**
   - Each teacher sees a weekly timetable grid.
   - Timetable data supplies day, period, room and class context.
   - The teacher enters a requisition for each lesson.
   - `Nothing required` is an explicit meaningful state and is different from a blank/uncompleted request.

2. **Technician room view**
   - Technician preparation is organised primarily by **room**.
   - For each day/period/room, technicians can see the teacher/class context and the corresponding requisition.
   - This view is derived from teacher requests; technicians should not have to re-enter the same information.

3. **Timetable source**
   - Recurring timetable data links teacher + day + period + room + class.
   - It drives both teacher-facing and technician-facing views.
   - Reqsheet should use structured timetable records rather than reproduce legacy Excel lookup formulas.

## Important implementation rules

- The workbook data in `reqsheet_sanitised_reference.xlsx` is entirely synthetic/depersonalised.
- Do not attempt to reproduce the original workbook's VLOOKUP chains, external workbook links, helper sheets or other legacy formula machinery.
- Do not replicate handover sheets, old timetable sheets, lookup sheets or unrelated workbook features.
- The application should reproduce the **workflow and data relationships**, not Excel's implementation.
- Recurring timetable data and dated lesson/requisition records should remain separate so historical requisitions are not changed by later timetable edits.
- Class and room can remain free-text initially.
- Contiguous multi-period lessons may be represented as one lesson block in the application, even though the legacy workbook uses a period grid.

## Reference workbook sheets

- `Reference Guide` — overview and mapping from legacy workbook concepts to Reqsheet.
- `Teacher Entry Example` — synthetic example of the teacher-facing weekly requisition grid.
- `Technician Room View` — synthetic example of room-oriented technician preparation output.
- `Timetable Source` — normalised synthetic timetable records suitable for application modelling.
- `Reqsheet Semantics` — explicit field meanings and intended implementation direction.

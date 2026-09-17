# Roadmap

This is an intentionally small, reviewable roadmap. The next implementation milestone is a deliberately skeletal UI/admin foundation for real pilot iteration, not final visual polish.

1. Admin configuration foundation: people, rooms, working days, week start, and ordered timetable structure.
2. Timetable-version/admin editor UI: effective-dated versions, clone/copy, staff-member default view, and validated timetable editing by staff member, room, or day.
3. Teacher week-view skeleton and lesson editor: current week, previous/next/This week navigation, Select week navigation, clickable class codes, compact lesson tiles, and the Lesson outline/Requisitions/Risk assessment editor.
4. Teacher day/class planning skeletons: Select day and Today’s lessons navigation, chronological period/separator rows, direct editing of lesson outline/requisitions/risk assessment, and class-occurrence positioning/history.
5. Technician day-view skeleton and room preferences: current day, room columns, period rows, My rooms/All rooms/custom rooms, and teacher/room week inspection.
6. Print/PDF workflow: future-week selection, one A4 page per day, selected-room layouts, and practical page fitting.
7. Canonical CSV import/export: Reqsheet template export, deterministic validation/preview, and confirmed import.
8. Pilot-driven UI iteration: improve wording, layout, and workflow from real use while preserving the simple server-rendered architecture.

The database foundation and initial application-domain schema are complete and verified against local MySQL. Timetable configuration services provide a create-only validated path for effective-dated versions, slots, and recurring lessons. The bounded occurrence-generation service validates timetable spans/conflicts and creates dated occurrences for explicit inclusive date ranges. Product behaviour and the UI/admin scope for the next stages are canonical in `PRODUCT_DESIGN.md`; authentication/authorization and exception handling remain future work.

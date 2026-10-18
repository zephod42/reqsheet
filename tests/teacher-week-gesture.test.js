'use strict';

var assert = require('assert');
var teacherWeek = require('../public/assets/teacher-week.js');
var createGestureTracker = teacherWeek.createGestureTracker;

function touch(id, x, y) {
    return { id: id, type: 'touch', x: x, y: y, link: { id: 'lesson' } };
}

var tap = createGestureTracker();
assert.strictEqual(tap.down(touch(1, 20, 20)), true, 'Quick tap did not begin as a pending tile gesture.');
assert.strictEqual(tap.move(1, 24, 24), 'pending', 'Small tap movement was mistaken for a drag or scroll.');
assert.strictEqual(tap.isDragging(), false, 'Quick tap entered duplication mode.');

var swipe = createGestureTracker();
swipe.down(touch(2, 20, 20));
assert.strictEqual(swipe.move(2, 20, 32), 'scroll', 'Swipe before the hold threshold did not yield to timetable scrolling.');
assert.strictEqual(swipe.current(), null, 'Scrolling left a pending drag gesture active.');

var longPress = createGestureTracker();
longPress.down(touch(3, 20, 20));
assert.strictEqual(longPress.activate(3), true, 'Long press did not activate duplication mode.');
assert.strictEqual(longPress.move(3, 45, 45), 'drag', 'Movement after a long press did not remain a drag.');
assert.strictEqual(longPress.isDragging(), true, 'Long-press drag state was lost.');
longPress.reset();
assert.strictEqual(longPress.isDragging(), false, 'Cancelled drag did not reset without a copy operation.');

var mouse = createGestureTracker();
mouse.down({ id: 4, type: 'mouse', x: 10, y: 10, link: { id: 'lesson' } });
assert.strictEqual(mouse.move(4, 15, 10), 'pending', 'Small mouse movement started dragging too early.');
assert.strictEqual(mouse.move(4, 16, 10), 'start', 'Desktop primary-button movement did not start dragging.');
assert.strictEqual(mouse.isDragging(), true, 'Desktop drag state was not retained.');

var foreignPointer = createGestureTracker();
foreignPointer.down(touch(5, 0, 0));
assert.strictEqual(foreignPointer.move(99, 50, 50), 'ignore', 'A different pointer altered the active gesture.');

console.log('Teacher Week gesture checks passed.');

var lesson = { dataset: { planningSaving: 'true', planningPending: 'true', planningPopulated: 'false' } };
assert.strictEqual(teacherWeek.planningCopyAllowed(lesson), false, 'A pending planning save was available as a copy source.');
teacherWeek.applyPlanningState(lesson, {
    state: 'requirements_entered', requisitions: 'Latest requisitions', outline: 'Latest outline', risk: 'Latest risk',
    populated: true, planning_revision: 'authoritative-revision'
});
assert.strictEqual(lesson.dataset.requisitions, 'Latest requisitions', 'Saved requisitions were not synchronized to the lesson card.');
assert.strictEqual(lesson.dataset.planningPopulated, 'true', 'Saved populated state was not synchronized to the lesson card.');
assert.strictEqual(lesson.dataset.planningRevision, 'authoritative-revision', 'Saved planning revision was not synchronized to the lesson card.');
assert.strictEqual(teacherWeek.planningCopyAllowed(lesson), true, 'A successfully saved planning state remained blocked from copying.');
lesson.dataset.planningPending = 'true';
assert.strictEqual(teacherWeek.planningCopyAllowed(lesson), false, 'A failed/pending planning state was available for copying.');

console.log('Teacher Week planning synchronization checks passed.');

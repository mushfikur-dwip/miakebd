// Run with: npm test
// The shop runs on Bangladesh time; at UTC+6 a local midnight is the previous
// day in UTC, so any slip into UTC shows up as a wrong date here.
process.env.TZ = 'Asia/Dhaka';

import { mock, test } from 'node:test';
import assert from 'node:assert/strict';
import monthService from '../../resources/js/services/monthService.js';

const range = (from_date, to_date) => ({ from_date, to_date });

test('monthOf gives the first and last day of the month a moment falls in', () => {
    assert.deepEqual(monthService.monthOf(new Date(2026, 9, 1, 0, 0, 0)), range('2026-10-01', '2026-10-31'));
    assert.deepEqual(monthService.monthOf(new Date(2026, 9, 31, 23, 59, 59)), range('2026-10-01', '2026-10-31'));
    assert.deepEqual(monthService.monthOf(new Date(2026, 8, 15)), range('2026-09-01', '2026-09-30'));
});

test('monthOf knows how long February is', () => {
    assert.deepEqual(monthService.monthOf(new Date(2026, 1, 10)), range('2026-02-01', '2026-02-28'));
    assert.deepEqual(monthService.monthOf(new Date(2028, 1, 10)), range('2028-02-01', '2028-02-29'));
});

test('shift steps whole months, across years', () => {
    assert.deepEqual(monthService.shift(range('2026-01-01', '2026-01-31'), -1), range('2025-12-01', '2025-12-31'));
    assert.deepEqual(monthService.shift(range('2026-10-01', '2026-10-31'), 1), range('2026-11-01', '2026-11-30'));
    assert.deepEqual(monthService.shift(range('2026-12-01', '2026-12-31'), 1), range('2027-01-01', '2027-01-31'));
});

test('shift from a custom range starts at the month its first day is in', () => {
    assert.deepEqual(monthService.shift(range('2026-10-15', '2026-10-20'), -1), range('2026-09-01', '2026-09-30'));
    // The Filter panel's date picker stores Date objects, not strings.
    assert.deepEqual(monthService.shift(range(new Date(2026, 9, 15), new Date(2026, 9, 20)), 0), range('2026-10-01', '2026-10-31'));
});

test('without dates, months count from the current one', (t) => {
    t.mock.timers.enable({ apis: ['Date'], now: new Date(2026, 9, 1, 10, 30) });

    assert.deepEqual(monthService.monthOf(), range('2026-10-01', '2026-10-31'));
    assert.deepEqual(monthService.shift(range('', ''), -1), range('2026-09-01', '2026-09-30'));
    assert.deepEqual(monthService.shift(range(null, null), 0), range('2026-10-01', '2026-10-31'));
});

test('dates gives the local-midnight Date pair the Filter date picker shows', () => {
    assert.deepEqual(monthService.dates(range('2026-10-01', '2026-10-31')), [new Date(2026, 9, 1), new Date(2026, 9, 31)]);
    assert.equal(monthService.dates(range('', '')), null);
});

test('isWholeMonth is true only for the first to the last day of one month', () => {
    assert.equal(monthService.isWholeMonth(range('2026-10-01', '2026-10-31')), true);
    assert.equal(monthService.isWholeMonth(range('2026-02-01', '2026-02-28')), true);
    assert.equal(monthService.isWholeMonth(range(new Date(2026, 9, 1), new Date(2026, 9, 31))), true);

    assert.equal(monthService.isWholeMonth(range('2026-10-01', '2026-10-30')), false);
    assert.equal(monthService.isWholeMonth(range('2026-10-02', '2026-10-31')), false);
    assert.equal(monthService.isWholeMonth(range('2026-09-01', '2026-10-31')), false);
    assert.equal(monthService.isWholeMonth(range('', '')), false);
    assert.equal(monthService.isWholeMonth(range(null, null)), false);
});

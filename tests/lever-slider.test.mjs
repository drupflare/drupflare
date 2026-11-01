import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { test } from 'node:test';

const slider = createRequire(import.meta.url)('../js/lever-slider.js');
const marks = [
	{ value: 8000, label: 'Default and fastest', note: 'Keeps the site resident.' },
	{ value: 60000, label: 'Middle', note: 'Fewer wakes.' },
	{ value: 240000, label: 'Least energy', note: 'Fewest wakes.' }
];
const MIN = 8000;
const MAX = 240000;

test('log scale applies at a 20x ratio and above, linear below', () => {
	assert.equal(slider.isLog(100, 2000), true);
	assert.equal(slider.isLog(100, 1999), false);
	assert.equal(slider.isLog(0, 60000), false);
});

test('the ends of the track map to min and max', () => {
	for (const [min, max] of [
		[8000, 240000],
		[0, 60000],
		[100, 5000]
	]) {
		assert.equal(slider.posToValue(0, min, max), min);
		assert.equal(slider.posToValue(slider.TRACK, min, max), max);
		assert.equal(slider.valueToPos(min, min, max), 0);
		assert.equal(slider.valueToPos(max, min, max), slider.TRACK);
	}
});

test('position and value round trip within the 3 digit rounding', () => {
	for (const [min, max] of [
		[8000, 240000],
		[0, 60000]
	]) {
		for (const pos of [1, 100, 333, 500, 777, 999]) {
			const back = slider.valueToPos(slider.posToValue(pos, min, max), min, max);
			assert.ok(Math.abs(back - pos) <= 3, `${min}-${max} pos ${pos} came back as ${back}`);
		}
	}
});

test('a derived value keeps 3 significant digits and stays in range', () => {
	const v = slider.posToValue(437, MIN, MAX);
	assert.equal(v, Number(v.toPrecision(3)));
	assert.ok(v >= MIN && v <= MAX);
	assert.equal(slider.posToValue(-50, MIN, MAX), MIN);
	assert.equal(slider.posToValue(5000, MIN, MAX), MAX);
	assert.equal(slider.valueToPos(1, MIN, MAX), 0);
	assert.equal(slider.valueToPos(10 ** 9, MIN, MAX), slider.TRACK);
});

test('snaps within 12 track units of a mark and not at 13', () => {
	const midPos = slider.valueToPos(60000, MIN, MAX);
	assert.equal(slider.snap(midPos + 12, marks, MIN, MAX), 60000);
	assert.equal(slider.snap(midPos - 12, marks, MIN, MAX), 60000);
	assert.notEqual(slider.snap(midPos + 13, marks, MIN, MAX), 60000);
	assert.notEqual(slider.snap(midPos - 13, marks, MIN, MAX), 60000);
	assert.equal(slider.snap(5, marks, MIN, MAX), 8000);
});

test('with no marks nothing snaps', () => {
	assert.equal(slider.snap(500, [], 0, 60000), 30000);
	assert.equal(slider.nearestMark(500, [], 0, 60000), null);
});

test('nearest mark reports the mark and its distance', () => {
	const near = slider.nearestMark(slider.valueToPos(70000, MIN, MAX), marks, MIN, MAX);
	assert.equal(near.mark.value, 60000);
});

test('arrow keys leave a mark instead of snapping back to it', () => {
	const at = slider.valueToPos(60000, MIN, MAX);
	const right = slider.stepPos(at, 1, marks, MIN, MAX);
	const left = slider.stepPos(at, -1, marks, MIN, MAX);
	assert.notEqual(slider.snap(right, marks, MIN, MAX), 60000);
	assert.notEqual(slider.snap(left, marks, MIN, MAX), 60000);
	assert.ok(right > at && left < at);
});

const base = { min: 0, max: 60000, unit: 'ms', marks: [], def: undefined, deployed: undefined };

test('no marks and no default states only the range', () => {
	assert.equal(slider.describe({ ...base, value: 500 }), 'Range 0 ms to 60,000 ms.');
});

test('no marks with a default adds the default', () => {
	assert.equal(
		slider.describe({ ...base, value: 500, def: 8000 }),
		'Range 0 ms to 60,000 ms. Default 8,000 ms.'
	);
});

test('at a mark it gives label, value and note', () => {
	const text = slider.describe({ ...base, min: MIN, max: MAX, marks, value: 60000 });
	assert.equal(text, 'Middle, 60,000 ms. Fewer wakes.');
});

test('between marks it names both neighbours', () => {
	const text = slider.describe({ ...base, min: MIN, max: MAX, marks, value: 100000 });
	assert.equal(text, 'Between Middle (60,000 ms) and Least energy (240,000 ms).');
});

test('outside the marks it names the nearest one', () => {
	const state = { ...base, min: 0, max: MAX, marks };
	assert.equal(
		slider.describe({ ...state, value: 100 }),
		'Below Default and fastest (8,000 ms).'
	);
	const two = { ...state, marks: marks.slice(0, 2), value: 100000 };
	assert.equal(slider.describe(two), 'Above Middle (60,000 ms).');
});

test('an empty field states the deployed value, else the default, else no number', () => {
	assert.equal(
		slider.describe({ ...base, value: null, deployed: 90000, def: 8000 }),
		'The deployed value is in force: 90,000 ms.'
	);
	assert.equal(
		slider.describe({ ...base, value: null, def: 8000 }),
		'The deployed value is in force: 8,000 ms.'
	);
	const none = slider.describe({ ...base, value: null });
	assert.equal(none, 'The deployed value is in force.');
	assert.ok(!/\d/.test(none));
});

(function (Drupal, once) {
	const TRACK = 1000;
	const SNAP = 12;
	const KEY_STEP = 10;

	const t = (text, args) =>
		typeof Drupal !== 'undefined' && Drupal.t
			? Drupal.t(text, args)
			: text.replace(/@\w+/g, (name) => String(args && args[name]));

	function isLog(min, max) {
		return min > 0 && max / min >= 20;
	}

	function clamp(value, min, max) {
		return Math.min(max, Math.max(min, value));
	}

	function valueToPos(value, min, max) {
		if (max <= min) {
			return 0;
		}
		const v = clamp(value, min, max);
		const t01 = isLog(min, max)
			? Math.log(v / min) / Math.log(max / min)
			: (v - min) / (max - min);
		return Math.round(t01 * TRACK);
	}

	function posToValue(pos, min, max) {
		const t01 = clamp(pos, 0, TRACK) / TRACK;
		const raw = isLog(min, max) ? min * Math.pow(max / min, t01) : min + (max - min) * t01;
		return clamp(Math.round(Number(raw.toPrecision(3))), min, max);
	}

	function nearestMark(pos, marks, min, max) {
		let best = null;
		for (const mark of marks) {
			const dist = Math.abs(valueToPos(mark.value, min, max) - pos);
			if (best === null || dist < best.dist) {
				best = { mark, dist };
			}
		}
		return best;
	}

	function snap(pos, marks, min, max) {
		const near = nearestMark(pos, marks, min, max);
		return near !== null && near.dist <= SNAP ? near.mark.value : posToValue(pos, min, max);
	}

	function stepPos(pos, dir, marks, min, max) {
		const next = clamp(pos + dir * KEY_STEP, 0, TRACK);
		const here = nearestMark(pos, marks, min, max);
		const there = nearestMark(next, marks, min, max);
		if (here && there && here.dist <= SNAP && there.dist <= SNAP && here.mark === there.mark) {
			return clamp(valueToPos(here.mark.value, min, max) + dir * (SNAP + 1), 0, TRACK);
		}
		return next;
	}

	function amount(value, unit) {
		const text = Number(value).toLocaleString('en-US');
		return unit ? text + ' ' + unit : text;
	}

	function describe(state) {
		const { value, marks, min, max, unit } = state;
		const known = state.deployed !== undefined ? state.deployed : state.def;
		if (value === null) {
			return known === undefined
				? t('The deployed value is in force.')
				: t('The deployed value is in force: @value.', { '@value': amount(known, unit) });
		}
		if (marks.length === 0) {
			const range = t('Range @min to @max.', {
				'@min': amount(min, unit),
				'@max': amount(max, unit)
			});
			return state.def === undefined
				? range
				: range + ' ' + t('Default @value.', { '@value': amount(state.def, unit) });
		}
		const hit = marks.find((mark) => mark.value === value);
		if (hit) {
			const head = hit.label + ', ' + amount(hit.value, unit) + '.';
			return hit.note ? head + ' ' + hit.note : head;
		}
		const below = marks.filter((mark) => mark.value < value);
		const above = marks.filter((mark) => mark.value > value);
		const a = below[below.length - 1];
		const b = above[0];
		if (a && b) {
			return t('Between @lowlabel (@lowamount) and @highlabel (@highamount).', {
				'@lowlabel': a.label,
				'@lowamount': amount(a.value, unit),
				'@highlabel': b.label,
				'@highamount': amount(b.value, unit)
			});
		}
		const edge = a || b;
		return t(a ? 'Above @label (@amount).' : 'Below @label (@amount).', {
			'@label': edge.label,
			'@amount': amount(edge.value, unit)
		});
	}

	function parseState(input) {
		const num = (name) =>
			input.dataset[name] === undefined ? undefined : Number(input.dataset[name]);
		let marks = [];
		try {
			marks = JSON.parse(input.dataset.marks || '[]');
		} catch (e) {
			marks = [];
		}
		return {
			min: Number(input.dataset.min),
			max: Number(input.dataset.max),
			unit: input.dataset.unit || '',
			def: num('default'),
			deployed: num('deployed'),
			marks: marks.filter((mark) => Number.isFinite(mark.value))
		};
	}

	function el(tag, className, text) {
		const node = document.createElement(tag);
		node.className = className;
		if (text !== undefined) {
			node.textContent = text;
		}
		return node;
	}

	function build(input) {
		const wrapper = input.closest('.drupflare-lever-slider');
		const bar = wrapper.querySelector('.drupflare-lever-slider__bar');
		const state = parseState(input);
		const { min, max, marks, unit } = state;
		wrapper.classList.add('is-enhanced');

		const range = el('input', 'drupflare-lever-slider__range');
		range.type = 'range';
		range.min = '0';
		range.max = String(TRACK);
		range.step = '1';
		const title = input.id ? document.querySelector('label[for="' + input.id + '"]') : null;
		range.setAttribute('aria-label', title ? title.textContent : '');
		range.removeAttribute('name');

		const ticks = el(
			'div',
			'drupflare-lever-slider__ticks' + (marks.length > 1 ? ' has-stagger' : '')
		);
		const buttons = [];
		const addTick = (value, label, isMark, index) => {
			const pos = valueToPos(value, min, max);
			const button = el(
				'button',
				'drupflare-lever-slider__tick' +
					(isMark ? ' is-mark' : ' is-end') +
					(index % 2 ? ' is-low' : '') +
					(pos < 15 ? ' is-start' : pos > 85 ? ' is-finish' : '')
			);
			button.type = 'button';
			button.style.setProperty('--pos', pos / 10 + '%');
			button.setAttribute(
				'aria-label',
				t('@label: @value', { '@label': label, '@value': amount(value, unit) })
			);
			button.appendChild(el('span', 'drupflare-lever-slider__tick-label', label));
			button.appendChild(
				el('span', 'drupflare-lever-slider__tick-value', amount(value, unit))
			);
			button.addEventListener('click', () => {
				input.value = String(value);
				refresh();
			});
			ticks.appendChild(button);
			buttons.push(button);
		};
		const marked = (value) => marks.some((mark) => mark.value === value);
		if (!marked(min)) {
			addTick(min, t('Minimum'), false, 0);
		}
		marks.forEach((mark, i) => addTick(mark.value, mark.label, true, i + 1));
		if (!marked(max)) {
			addTick(max, t('Maximum'), false, 0);
		}

		const reset = el('button', 'drupflare-lever-slider__reset', t('Use deployed value'));
		reset.type = 'button';
		reset.addEventListener('click', () => {
			input.value = '';
			refresh();
		});

		const track = el('div', 'drupflare-lever-slider__track');
		track.appendChild(range);
		track.appendChild(ticks);
		wrapper.insertBefore(track, input);
		wrapper.insertBefore(reset, input);

		function refresh() {
			const typed = input.value.trim();
			const value = typed === '' || !Number.isFinite(Number(typed)) ? null : Number(typed);
			const base =
				value !== null
					? value
					: state.deployed !== undefined
						? state.deployed
						: state.def !== undefined
							? state.def
							: min;
			range.value = String(valueToPos(base, min, max));
			wrapper.classList.toggle('is-inherited', value === null);
			reset.hidden = value === null;
			if (bar) {
				bar.textContent = describe(Object.assign({ value }, state));
			}
		}

		const lock = () => {
			const off = input.disabled;
			range.disabled = off;
			reset.disabled = off;
			buttons.forEach((button) => {
				button.disabled = off;
			});
		};

		range.addEventListener('input', () => {
			input.value = String(snap(Number(range.value), marks, min, max));
			refresh();
		});
		range.addEventListener('keydown', (event) => {
			const dir = { ArrowRight: 1, ArrowUp: 1, ArrowLeft: -1, ArrowDown: -1 }[event.key];
			if (!dir) {
				return;
			}
			event.preventDefault();
			const pos = stepPos(Number(range.value), dir, marks, min, max);
			input.value = String(snap(pos, marks, min, max));
			refresh();
		});
		input.addEventListener('input', refresh);
		lock();
		refresh();
	}

	Drupal.behaviors.drupflareLeverSlider = {
		attach(context) {
			once('drupflare-lever-slider', 'input[data-drupflare-slider]', context).forEach(build);
		}
	};

	if (typeof module !== 'undefined') {
		module.exports = {
			isLog,
			valueToPos,
			posToValue,
			nearestMark,
			snap,
			stepPos,
			describe,
			SNAP,
			TRACK
		};
	}
})(
	typeof Drupal !== 'undefined' ? Drupal : { behaviors: {} },
	typeof once !== 'undefined' ? once : () => []
);

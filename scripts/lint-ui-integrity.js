#!/usr/bin/env node
/**
 * ExamplePress UI Integrity Linter
 *
 * Catches drift between three layers of the admin UI:
 *   1. PHP templates in examplepress-mu/src/Admin/Templates/*.php
 *      → source of truth for static DOM IDs and classes.
 *   2. CSS in examplepress-mu/assets/src/css/**-/*.css
 *      → visual contract, selectors must have a matching consumer.
 *   3. JS in examplepress-mu/assets/src/**-/*.js
 *      → behavioral contract, DOM lookups must hit a real element
 *        (either rendered by PHP or produced by another JS module).
 *
 * CHECKS
 * ──────
 * [ERROR]   Orphan JS files — anything under assets/src/ that is not
 *           transitively imported from a Vite entry point listed in
 *           vite.config.js. This catches ghost files from refactors
 *           (see Phase 1 of the UI triage plan).
 *
 * [WARN]    JS getElementById('foo') / querySelector('#foo') where
 *           `foo` is not present in any PHP template and is not created
 *           dynamically somewhere else in JS (document.createElement
 *           with `.id = 'foo'` or `id="foo"` literal in an HTML string).
 *
 * [WARN]    CSS selectors `.ep-*` / `#ep-*` that are never referenced
 *           from a PHP template or a JS file. These are dead styles.
 *
 * EXIT CODE
 * ─────────
 *   0 — no errors (warnings may still be present).
 *   1 — at least one ERROR category check failed.
 *
 * Keep this script dependency-free: it must run from a fresh clone
 * without `npm install`, using only Node's built-in `fs` and `path`.
 *
 * Run:
 *   node scripts/lint-ui-integrity.js
 */

import fs   from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);
const ROOT     = path.resolve(__dirname, '..');
const MU       = path.join(ROOT, 'examplepress-mu');
const TPL_DIR  = path.join(MU, 'src', 'Admin', 'Templates');
// PageController renders the shared page shell (logo, modal overlays)
// — its output is part of the admin DOM contract even though it lives
// outside Templates/, so the linter walks it explicitly.
const SHELL_PHP = path.join(MU, 'src', 'Admin', 'PageController.php');
const CSS_DIR  = path.join(MU, 'assets', 'src', 'css');
const JS_DIR   = path.join(MU, 'assets', 'src');
const VITE     = path.join(ROOT, 'vite.config.js');

/* ── Terminal colors (respect NO_COLOR) ──────────────────────────── */

const color = process.env.NO_COLOR ? {
	red: s => s, yellow: s => s, green: s => s, dim: s => s, bold: s => s,
} : {
	red:    s => `\x1b[31m${s}\x1b[0m`,
	yellow: s => `\x1b[33m${s}\x1b[0m`,
	green:  s => `\x1b[32m${s}\x1b[0m`,
	dim:    s => `\x1b[2m${s}\x1b[0m`,
	bold:   s => `\x1b[1m${s}\x1b[0m`,
};

/* ── File walking ────────────────────────────────────────────────── */

function walk(dir, matcher) {
	const out = [];
	if (!fs.existsSync(dir)) return out;
	const stack = [dir];
	while (stack.length) {
		const cur = stack.pop();
		const entries = fs.readdirSync(cur, { withFileTypes: true });
		for (const e of entries) {
			const p = path.join(cur, e.name);
			if (e.isDirectory()) stack.push(p);
			else if (e.isFile() && matcher(p)) out.push(p);
		}
	}
	return out;
}

const readFile = (f) => fs.readFileSync(f, 'utf8');

function relToRoot(abs) {
	return path.relative(ROOT, abs);
}

/* ── 1. Harvest PHP: ids, classes, inline string literals ───────── */

function harvestPhp() {
	const files = walk(TPL_DIR, p => p.endsWith('.php'));
	if (fs.existsSync(SHELL_PHP)) files.push(SHELL_PHP);
	const ids     = new Set();
	const classes = new Set();
	const fileIds = new Map(); // id → file it first appears in

	for (const f of files) {
		const src = readFile(f);

		// id="foo"
		const idRe = /\bid\s*=\s*["']([^"']+)["']/g;
		let m;
		while ((m = idRe.exec(src))) {
			const id = m[1].trim();
			if (!id) continue;
			ids.add(id);
			if (!fileIds.has(id)) fileIds.set(id, f);
		}

		// class="foo bar ep-baz"
		const classRe = /\bclass\s*=\s*["']([^"']+)["']/g;
		while ((m = classRe.exec(src))) {
			const tokens = m[1].split(/\s+/);
			for (const t of tokens) if (t) classes.add(t);
		}
	}

	return { files, ids, classes, fileIds };
}

/* ── 2. Harvest CSS: .ep-* and #ep-* selectors ──────────────────── */

function harvestCss() {
	const files = walk(CSS_DIR, p => p.endsWith('.css'));
	const classes = new Map(); // class → file where it was defined
	const ids     = new Map(); // id → file

	for (const f of files) {
		// Strip comments so commented-out selectors aren't counted.
		const src = readFile(f).replace(/\/\*[\s\S]*?\*\//g, '');

		// Match only identifiers that look like our namespace.
		const classRe = /\.(ep-[a-zA-Z][\w-]*)/g;
		const idRe    = /#(ep-[a-zA-Z][\w-]*)/g;
		let m;
		while ((m = classRe.exec(src))) {
			if (!classes.has(m[1])) classes.set(m[1], f);
		}
		while ((m = idRe.exec(src))) {
			if (!ids.has(m[1])) ids.set(m[1], f);
		}
	}

	return { files, classes, ids };
}

/* ── 3. Harvest JS: referenced ids/classes + entry graph ────────── */

function harvestJs() {
	const files = walk(JS_DIR, p => p.endsWith('.js'));

	// Selectors that JS looks up.
	const lookupIds     = new Map(); // id → [files]
	const lookupClasses = new Map(); // class → [files]

	// IDs the JS injects into the DOM via templating or createElement.
	const emittedIds     = new Set();
	const emittedClasses = new Set();

	// Import graph: which files imports which (relative and absolute).
	const imports = new Map(); // file → [resolved file paths]

	for (const f of files) {
		const rawSrc = readFile(f);
		const dir    = path.dirname(f);

		// For emitted-markup harvesting we flatten ${...} interpolations
		// to an empty placeholder so class="foo${bar}" parses as class="foo".
		// Lookups (getElementById etc.) still use the raw source, since
		// dynamic IDs like `#ep-health-panel-${slug}` are intentionally
		// skipped downstream.
		const src = rawSrc.replace(/\$\{[^{}]*\}/g, '');

		// getElementById('foo') / getElementById("foo") — skip dynamic keys.
		const gidRe = /getElementById\s*\(\s*['"`]([^'"`$]+)['"`]\s*\)/g;
		let m;
		while ((m = gidRe.exec(rawSrc))) {
			if (!lookupIds.has(m[1])) lookupIds.set(m[1], []);
			lookupIds.get(m[1]).push(f);
		}

		// querySelector('#foo') / querySelector('.foo') — single-selector only.
		const qsRe = /querySelector(?:All)?\s*\(\s*['"`]([^'"`,]+)['"`]\s*\)/g;
		while ((m = qsRe.exec(src))) {
			const sel = m[1].trim();
			// Very simple selector extractor: first #id or .class token.
			const idMatch    = sel.match(/#([a-zA-Z][\w-]*)/);
			const classMatch = sel.match(/\.([a-zA-Z][\w-]*)/);
			if (idMatch) {
				if (!lookupIds.has(idMatch[1])) lookupIds.set(idMatch[1], []);
				lookupIds.get(idMatch[1]).push(f);
			}
			if (classMatch) {
				if (!lookupClasses.has(classMatch[1])) lookupClasses.set(classMatch[1], []);
				lookupClasses.get(classMatch[1]).push(f);
			}
		}

		// id="foo" literals inside template strings / JS-rendered HTML.
		const litIdRe = /\bid\s*=\s*(?:\\?["'`])([^"'`$]+)(?:\\?["'`])/g;
		while ((m = litIdRe.exec(src))) emittedIds.add(m[1].trim());

		// el.id = 'foo'
		const setIdRe = /\.id\s*=\s*['"`]([^'"`]+)['"`]/g;
		while ((m = setIdRe.exec(src))) emittedIds.add(m[1].trim());

		// class="foo bar" literals inside template strings.
		const litClassRe = /\bclass\s*=\s*(?:\\?["'`])([^"'`$]+)(?:\\?["'`])/g;
		while ((m = litClassRe.exec(src))) {
			for (const t of m[1].split(/\s+/)) if (t) emittedClasses.add(t);
		}

		// el.className = 'foo bar'
		const setClassRe = /\.className\s*=\s*['"`]([^'"`]+)['"`]/g;
		while ((m = setClassRe.exec(src))) {
			for (const t of m[1].split(/\s+/)) if (t) emittedClasses.add(t);
		}

		// classList.add/toggle/remove('foo'[, 'bar', ...])
		const clListRe = /classList\.(?:add|toggle|remove)\s*\(\s*((?:['"`][^'"`]+['"`](?:\s*,\s*)?)+)\s*\)/g;
		while ((m = clListRe.exec(src))) {
			const args = m[1].match(/['"`]([^'"`]+)['"`]/g) || [];
			for (const a of args) emittedClasses.add(a.slice(1, -1));
		}

		// import statements → relative .js targets (ignore node_modules, CSS).
		const impRe = /import\s+(?:[^'"]*\s+from\s+)?['"]([^'"]+)['"]/g;
		const resolved = [];
		while ((m = impRe.exec(src))) {
			const spec = m[1];
			if (!spec.startsWith('./') && !spec.startsWith('../')) continue;
			if (spec.endsWith('.css')) continue;
			let target = path.resolve(dir, spec);
			if (!target.endsWith('.js')) target += '.js';
			if (fs.existsSync(target)) resolved.push(target);
		}
		imports.set(f, resolved);
	}

	return { files, lookupIds, lookupClasses, emittedIds, emittedClasses, imports };
}

/* ── 4. Read Vite entry points ──────────────────────────────────── */

function readEntryPoints() {
	const src = readFile(VITE);
	const entries = [];
	const re = /['"]?[\w-]+['"]?\s*:\s*resolve\(\s*__dirname\s*,\s*mu\s*,\s*['"]([^'"]+)['"]/g;
	let m;
	while ((m = re.exec(src))) {
		entries.push(path.join(MU, m[1]));
	}
	return entries;
}

/* ── 5. Compute reachable set from entry points ─────────────────── */

function reachableFrom(entries, imports) {
	const seen = new Set();
	const stack = [...entries];
	while (stack.length) {
		const f = stack.pop();
		if (seen.has(f)) continue;
		if (!fs.existsSync(f)) continue;
		seen.add(f);
		const imps = imports.get(f) || [];
		for (const i of imps) if (!seen.has(i)) stack.push(i);
	}
	return seen;
}

/* ── Main ─────────────────────────────────────────────────────── */

function main() {
	const php = harvestPhp();
	const css = harvestCss();
	const js  = harvestJs();

	const errors   = [];
	const warnings = [];

	/* ── Check 1: orphan JS files ─────────────────────────────── */

	const entries   = readEntryPoints();
	const reachable = reachableFrom(entries, js.imports);
	const orphans   = js.files.filter(f => !reachable.has(f));

	for (const f of orphans) {
		errors.push({
			category: 'orphan-js',
			message: `JS file not reachable from any Vite entry point: ${relToRoot(f)}`,
		});
	}

	/* ── Check 2: JS lookups for missing IDs ─────────────────── */

	for (const [id, files] of js.lookupIds) {
		if (php.ids.has(id)) continue;
		if (js.emittedIds.has(id)) continue;
		// Allow lookups whose ID is computed dynamically at build time.
		// (We already skipped template literals with ${}; assume safe.)
		warnings.push({
			category: 'js-missing-id',
			message: `JS looks up #${id} — not found in any PHP template or JS-emitted markup (${files.map(relToRoot).join(', ')})`,
		});
	}

	/* ── Check 3: JS class lookups for missing classes ───────── */

	for (const [cls, files] of js.lookupClasses) {
		if (!cls.startsWith('ep-')) continue; // third-party safety: only lint our namespace
		if (php.classes.has(cls)) continue;
		if (js.emittedClasses.has(cls)) continue;
		if (css.classes.has(cls)) continue; // style hook only — JS uses it, CSS styles it
		warnings.push({
			category: 'js-missing-class',
			message: `JS queries .${cls} — not found in any PHP template, JS markup, or CSS (${files.map(relToRoot).join(', ')})`,
		});
	}

	/* ── Check 4: dead CSS selectors ─────────────────────────── */

	const allPhpJsText = (() => {
		const parts = [];
		for (const f of php.files) parts.push(readFile(f));
		for (const f of js.files)  parts.push(readFile(f));
		return parts.join('\n');
	})();

	for (const [cls, declFile] of css.classes) {
		if (allPhpJsText.includes(cls)) continue;
		warnings.push({
			category: 'dead-css-class',
			message: `CSS class .${cls} (${relToRoot(declFile)}) is not referenced by any PHP template or JS file.`,
		});
	}

	for (const [id, declFile] of css.ids) {
		if (allPhpJsText.includes(id)) continue;
		warnings.push({
			category: 'dead-css-id',
			message: `CSS id #${id} (${relToRoot(declFile)}) is not referenced by any PHP template or JS file.`,
		});
	}

	/* ── Report ───────────────────────────────────────────────── */

	const report = {
		'orphan-js':        errors.filter(e => e.category === 'orphan-js'),
		'js-missing-id':    warnings.filter(w => w.category === 'js-missing-id'),
		'js-missing-class': warnings.filter(w => w.category === 'js-missing-class'),
		'dead-css-class':   warnings.filter(w => w.category === 'dead-css-class'),
		'dead-css-id':      warnings.filter(w => w.category === 'dead-css-id'),
	};

	console.log(color.bold('\nExamplePress UI Integrity Report'));
	console.log(color.dim('─'.repeat(60)));
	console.log(`PHP templates:  ${php.files.length} (${php.ids.size} ids, ${php.classes.size} classes)`);
	console.log(`CSS files:      ${css.files.length} (${css.classes.size} classes, ${css.ids.size} ids in .ep-* namespace)`);
	console.log(`JS files:       ${js.files.length} (${reachable.size} reachable from entries)`);
	console.log();

	const printGroup = (label, items, sev) => {
		if (items.length === 0) {
			console.log(`${color.green('OK')}   ${label}`);
			return;
		}
		const prefix = sev === 'error' ? color.red('FAIL') : color.yellow('WARN');
		console.log(`${prefix} ${label} (${items.length})`);
		for (const i of items) {
			console.log(`     ${color.dim('·')} ${i.message}`);
		}
	};

	printGroup('Orphan JS files',                      report['orphan-js'],        'error');
	printGroup('JS lookups for missing IDs',           report['js-missing-id'],    'warn');
	printGroup('JS lookups for missing classes',       report['js-missing-class'], 'warn');
	printGroup('Dead CSS classes',                     report['dead-css-class'],   'warn');
	printGroup('Dead CSS ids',                         report['dead-css-id'],      'warn');

	console.log();
	if (errors.length) {
		console.log(color.red(`✖ ${errors.length} error(s), ${warnings.length} warning(s).`));
		process.exit(1);
	}
	console.log(color.green(`✓ 0 errors, ${warnings.length} warning(s).`));
	process.exit(0);
}

main();

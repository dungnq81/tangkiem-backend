/**
 * Playwright HTTP Server — persistent browser for scraping.
 *
 * Endpoints:
 *   GET  /health  → { status, browser }
 *   POST /fetch   → Fetch HTML from URL with options
 *
 * Usage: node scripts/playwright-server.mjs
 * Env:   PLAYWRIGHT_SERVER_PORT (default: 3100)
 *
 * The browser instance is launched ONCE and reused across requests.
 * Each request gets its own BrowserContext (isolated cookies/session).
 */

import { createServer } from 'node:http';
import { chromium } from 'playwright-extra';
import StealthPlugin from 'puppeteer-extra-plugin-stealth';

// ─── Config ──────────────────────────────────────────────────────────────────
const PORT = parseInt(process.env.PLAYWRIGHT_SERVER_PORT || '3100', 10);
const DEFAULT_TIMEOUT = 30_000;
const CF_WAIT_MAX = 30_000; // Max time to wait for CF challenge to solve
const CF_POLL_INTERVAL = 3_000;
const MAX_CONCURRENT = 5; // Max simultaneous browser contexts

// ─── Stealth ─────────────────────────────────────────────────────────────────
chromium.use(StealthPlugin());

// ─── Browser lifecycle ───────────────────────────────────────────────────────
/** @type {import('playwright').Browser | null} */
let browser = null;

async function ensureBrowser() {
	if (browser?.isConnected()) return browser;

	console.log('[playwright] Launching browser...');
	browser = await chromium.launch({
		headless: true,
		args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-blink-features=AutomationControlled', '--disable-dev-shm-usage', '--disable-gpu', '--window-size=1920,1080'],
	});

	browser.on('disconnected', () => {
		console.log('[playwright] Browser disconnected, will relaunch on next request');
		browser = null;
	});

	console.log('[playwright] Browser launched');
	return browser;
}

// ─── CF detection helpers ────────────────────────────────────────────────────

const CF_TITLE_PATTERNS = ['Just a moment', 'Attention Required', 'Please Wait'];

// ─── Concurrency limiter ─────────────────────────────────────────────────────
let activeContexts = 0;
const waitQueue = [];

function acquireSlot() {
	if (activeContexts < MAX_CONCURRENT) {
		activeContexts++;
		return Promise.resolve();
	}

	return new Promise((resolve) => waitQueue.push(resolve));
}

function releaseSlot() {
	activeContexts--;
	if (waitQueue.length > 0) {
		activeContexts++;
		const next = waitQueue.shift();
		next();
	}
}

/**
 * Detect Cloudflare protection type from page state.
 * @param {import('playwright').Page} page
 * @returns {Promise<{detected: boolean, type: string|null, message: string|null}>}
 */
async function detectCloudflare(page) {
	const title = await page.title();

	// Check title patterns
	const isCfTitle = CF_TITLE_PATTERNS.some((p) => title.includes(p));
	if (!isCfTitle) {
		return { detected: false, type: null, message: null };
	}

	// Check for Turnstile iframe
	const hasTurnstile = await page
		.locator('iframe[src*="challenges.cloudflare.com"]')
		.count()
		.then((c) => c > 0)
		.catch(() => false);

	if (hasTurnstile) {
		return {
			detected: true,
			type: 'turnstile',
			message: `Cloudflare Turnstile detected — cannot bypass automatically. Title: "${title}"`,
		};
	}

	// Check body for CF signatures
	const bodyHtml = await page.content().catch(() => '');
	const hasManaged = bodyHtml.includes('managed_challenge') || bodyHtml.includes('cf-challenge-running');

	if (hasManaged) {
		return {
			detected: true,
			type: 'managed_challenge',
			message: `Cloudflare Managed Challenge detected. Title: "${title}"`,
		};
	}

	return {
		detected: true,
		type: 'js_challenge',
		message: `Cloudflare JS Challenge detected. Title: "${title}"`,
	};
}

/**
 * Wait for CF challenge to auto-solve.
 * Polls every CF_POLL_INTERVAL until title changes or timeout.
 * @param {import('playwright').Page} page
 * @returns {Promise<{solved: boolean, cf: object}>}
 */
async function waitForCfChallenge(page) {
	const startTime = Date.now();

	while (Date.now() - startTime < CF_WAIT_MAX) {
		await page.waitForTimeout(CF_POLL_INTERVAL);

		const cf = await detectCloudflare(page);
		if (!cf.detected) {
			return { solved: true, cf };
		}

		// Turnstile with interactive element → can't solve automatically
		if (cf.type === 'turnstile') {
			return { solved: false, cf };
		}

		console.log(`[playwright] CF challenge still active (${cf.type}), waiting...`);
	}

	// Timeout — still on CF page
	const finalCf = await detectCloudflare(page);
	return { solved: !finalCf.detected, cf: finalCf };
}

// ─── Resource blocking patterns ──────────────────────────────────────────────
const BLOCKED_RESOURCE_TYPES = ['image', 'media', 'font'];
const BLOCKED_URL_PATTERNS = [
	/\.woff2?(\?|$)/i,
	/\.ttf(\?|$)/i,
	/\.eot(\?|$)/i,
	/\.png(\?|$)/i,
	/\.jpg(\?|$)/i,
	/\.jpeg(\?|$)/i,
	/\.gif(\?|$)/i,
	/\.webp(\?|$)/i,
	/\.svg(\?|$)/i,
	/\.mp4(\?|$)/i,
	/\.webm(\?|$)/i,
	/google-analytics\.com/i,
	/googletagmanager\.com/i,
	/facebook\.net/i,
	/doubleclick\.net/i,
	/ads\./i,
];

// ─── Main fetch logic ────────────────────────────────────────────────────────

/**
 * @param {object} params
 * @param {string} params.url
 * @param {object} [params.headers]
 * @param {object} [params.options]
 * @returns {Promise<object>}
 */
async function fetchPage({ url, headers = {}, options = {} }) {
	const timeout = options.timeout || DEFAULT_TIMEOUT;
	const waitFor = options.waitFor || 'networkidle';
	const blockResources = options.blockResources !== false; // default: true
	const cfBypass = options.cfBypass || false;
	const returnCookies = options.returnCookies || false;
	const delay = options.delay || 0;

	const startTime = Date.now();
	const activeBrowser = await ensureBrowser();

	// Wait for available slot (max 5 concurrent contexts)
	await acquireSlot();

	// Create isolated browser context per request
	const contextOptions = {
		viewport: { width: 1920, height: 1080 },
		userAgent: headers['User-Agent'] || headers['user-agent'] || 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
		locale: 'vi-VN',
		extraHTTPHeaders: {},
	};

	// Pass through relevant headers (except Cookie and User-Agent which are handled separately)
	const skipHeaders = ['cookie', 'user-agent'];
	for (const [key, value] of Object.entries(headers)) {
		if (!skipHeaders.includes(key.toLowerCase())) {
			contextOptions.extraHTTPHeaders[key] = value;
		}
	}

	const context = await activeBrowser.newContext(contextOptions);

	try {
		// Set cookies if provided
		const cookieString = headers['Cookie'] || headers['cookie'] || '';
		if (cookieString) {
			const parsedUrl = new URL(url);
			const cookies = cookieString
				.split(';')
				.map((c) => {
					const [name, ...rest] = c.trim().split('=');
					return {
						name: name?.trim(),
						value: rest.join('=').trim(),
						domain: parsedUrl.hostname,
						path: '/',
					};
				})
				.filter((c) => c.name && c.value);

			if (cookies.length > 0) {
				await context.addCookies(cookies);
			}
		}

		const page = await context.newPage();

		// Block unnecessary resources for speed
		if (blockResources) {
			await page.route('**/*', (route) => {
				const request = route.request();
				const resourceType = request.resourceType();
				const requestUrl = request.url();

				if (BLOCKED_RESOURCE_TYPES.includes(resourceType)) {
					return route.abort();
				}

				if (BLOCKED_URL_PATTERNS.some((pattern) => pattern.test(requestUrl))) {
					return route.abort();
				}

				return route.continue();
			});
		}

		// Navigate
		const navStart = Date.now();
		const response = await page.goto(url, {
			waitUntil: waitFor,
			timeout,
		});
		const navTime = Date.now() - navStart;

		const statusCode = response?.status() ?? 0;

		// CF detection
		let cf = await detectCloudflare(page);
		let cfWaitTime = 0;

		if (cf.detected && cfBypass) {
			console.log(`[playwright] CF detected (${cf.type}), attempting bypass...`);
			const cfStart = Date.now();
			const result = await waitForCfChallenge(page);
			cfWaitTime = Date.now() - cfStart;
			cf = result.cf;

			if (result.solved) {
				console.log(`[playwright] CF bypass successful (${cfWaitTime}ms)`);
			} else {
				console.log(`[playwright] CF bypass failed: ${cf.message}`);
			}
		}

		// Extra delay if requested (for JS frameworks to finish rendering)
		if (delay > 0) {
			await page.waitForTimeout(delay);
		}

		// Get rendered HTML
		const html = await page.content();
		const totalTime = Date.now() - startTime;

		// TIER 5A: Extract cookies for reuse (only when requested, e.g., after CF bypass)
		let cookies = [];
		if (returnCookies) {
			try {
				cookies = await context.cookies();
			} catch {
				// ignore cookie extraction errors
			}
		}

		return {
			success: !cf.detected,
			html,
			statusCode,
			cf,
			cookies,
			timing: {
				total: totalTime,
				navigation: navTime,
				cfWait: cfWaitTime,
			},
		};
	} finally {
		await context.close();
		releaseSlot();
	}
}

// ─── HTTP Server ─────────────────────────────────────────────────────────────

function readBody(req) {
	return new Promise((resolve, reject) => {
		const chunks = [];
		req.on('data', (chunk) => chunks.push(chunk));
		req.on('end', () => {
			try {
				resolve(JSON.parse(Buffer.concat(chunks).toString()));
			} catch {
				reject(new Error('Invalid JSON body'));
			}
		});
		req.on('error', reject);
	});
}

function sendJson(res, statusCode, data) {
	res.writeHead(statusCode, { 'Content-Type': 'application/json' });
	res.end(JSON.stringify(data));
}

const server = createServer(async (req, res) => {
	const url = new URL(req.url || '/', `http://localhost:${PORT}`);

	// ── Health check ──
	if (req.method === 'GET' && url.pathname === '/health') {
		return sendJson(res, 200, {
			status: 'ok',
			browser: browser?.isConnected() ? 'connected' : 'disconnected',
			uptime: process.uptime(),
		});
	}

	// ── Fetch HTML ──
	if (req.method === 'POST' && url.pathname === '/fetch') {
		try {
			const body = await readBody(req);

			if (!body.url) {
				return sendJson(res, 400, { success: false, error: 'Missing "url" in request body' });
			}

			console.log(`[playwright] Fetching: ${body.url}`);
			const result = await fetchPage(body);
			console.log(`[playwright] Done: ${body.url} (${result.timing.total}ms, CF: ${result.cf.detected ? result.cf.type : 'none'})`);

			return sendJson(res, 200, result);
		} catch (err) {
			console.error(`[playwright] Error:`, err.message);

			return sendJson(res, 500, {
				success: false,
				error: err.message,
				html: '',
				statusCode: 0,
				cf: { detected: false, type: null, message: null },
				timing: { total: 0, navigation: 0, cfWait: 0 },
			});
		}
	}

	// ── 404 ──
	sendJson(res, 404, { error: 'Not found' });
});

// ─── Graceful shutdown ───────────────────────────────────────────────────────
async function shutdown(signal) {
	console.log(`\n[playwright] ${signal} received, shutting down...`);
	server.close();
	if (browser?.isConnected()) {
		await browser.close();
	}
	process.exit(0);
}

process.on('SIGINT', () => shutdown('SIGINT'));
process.on('SIGTERM', () => shutdown('SIGTERM'));

// ─── Start ───────────────────────────────────────────────────────────────────
server.listen(PORT, () => {
	console.log(`[playwright] Server listening on http://localhost:${PORT}`);
	console.log(`[playwright] Endpoints:`);
	console.log(`  GET  /health  → Health check`);
	console.log(`  POST /fetch   → Fetch HTML`);
	console.log(`[playwright] Browser will be launched on first request`);
});

<!DOCTYPE html>
<html lang="vi">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="robots" content="noindex, nofollow">
	<title>Tàng Kiếm API</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
	<style>
		*,
		*::before,
		*::after {
			margin: 0;
			padding: 0;
			box-sizing: border-box;
		}

		:root {
			--bg-primary: #0a0a0f;
			--bg-secondary: #12121a;
			--bg-card: #16161f;
			--border: #1e1e2e;
			--border-hover: #2a2a3e;
			--text-primary: #e4e4ef;
			--text-secondary: #8888a0;
			--text-muted: #55556a;
			--accent: #6366f1;
			--accent-glow: rgba(99, 102, 241, 0.15);
			--accent-soft: rgba(99, 102, 241, 0.08);
			--success: #22c55e;
			--success-glow: rgba(34, 197, 94, 0.15);
		}

		body {
			font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
			background: var(--bg-primary);
			color: var(--text-primary);
			min-height: 100vh;
			display: flex;
			align-items: center;
			justify-content: center;
			overflow: hidden;
			position: relative;
		}

		/* ─── Ambient Background ─────────────────────────────── */
		body::before {
			content: '';
			position: fixed;
			top: -50%;
			left: -50%;
			width: 200%;
			height: 200%;
			background:
				radial-gradient(ellipse 600px 400px at 20% 30%, var(--accent-glow), transparent),
				radial-gradient(ellipse 500px 350px at 80% 70%, rgba(99, 102, 241, 0.06), transparent),
				radial-gradient(ellipse 400px 300px at 50% 50%, rgba(139, 92, 246, 0.04), transparent);
			animation: ambientDrift 20s ease-in-out infinite alternate;
			z-index: 0;
		}

		@keyframes ambientDrift {
			0% {
				transform: translate(0, 0) rotate(0deg);
			}

			100% {
				transform: translate(-30px, -20px) rotate(3deg);
			}
		}

		/* ─── Grid Pattern ───────────────────────────────────── */
		body::after {
			content: '';
			position: fixed;
			inset: 0;
			background-image:
				linear-gradient(var(--border) 1px, transparent 1px),
				linear-gradient(90deg, var(--border) 1px, transparent 1px);
			background-size: 60px 60px;
			opacity: 0.3;
			z-index: 0;
			mask-image: radial-gradient(ellipse 70% 70% at 50% 50%, black 20%, transparent 70%);
			-webkit-mask-image: radial-gradient(ellipse 70% 70% at 50% 50%, black 20%, transparent 70%);
		}

		.container {
			position: relative;
			z-index: 1;
			text-align: center;
			max-width: 520px;
			padding: 2rem;
		}

		/* ─── Logo / Icon ────────────────────────────────────── */
		.logo-wrapper {
			margin-bottom: 2.5rem;
			display: flex;
			justify-content: center;
		}

		.logo {
			width: 72px;
			height: 72px;
			background: var(--bg-card);
			border: 1px solid var(--border);
			border-radius: 18px;
			display: flex;
			align-items: center;
			justify-content: center;
			position: relative;
			transition: border-color 0.3s ease, box-shadow 0.3s ease;
		}

		.logo:hover {
			border-color: var(--border-hover);
			box-shadow: 0 0 30px var(--accent-glow);
		}

		.logo svg {
			width: 32px;
			height: 32px;
			color: var(--accent);
		}

		.logo::after {
			content: '';
			position: absolute;
			inset: -1px;
			border-radius: 18px;
			background: linear-gradient(135deg, var(--accent-glow), transparent 60%);
			z-index: -1;
			opacity: 0;
			transition: opacity 0.3s ease;
		}

		.logo:hover::after {
			opacity: 1;
		}

		/* ─── Typography ─────────────────────────────────────── */
		h1 {
			font-size: 1.75rem;
			font-weight: 700;
			letter-spacing: -0.03em;
			margin-bottom: 0.5rem;
			background: linear-gradient(135deg, var(--text-primary) 0%, var(--text-secondary) 100%);
			-webkit-background-clip: text;
			-webkit-text-fill-color: transparent;
			background-clip: text;
		}

		.subtitle {
			font-size: 0.9rem;
			color: var(--text-secondary);
			font-weight: 400;
			line-height: 1.6;
			margin-bottom: 2.5rem;
		}

		/* ─── Status Badge ───────────────────────────────────── */
		.status {
			display: inline-flex;
			align-items: center;
			gap: 8px;
			padding: 8px 16px;
			background: var(--bg-card);
			border: 1px solid var(--border);
			border-radius: 100px;
			font-size: 0.8rem;
			color: var(--text-secondary);
			margin-bottom: 2.5rem;
			transition: border-color 0.3s ease;
		}

		.status:hover {
			border-color: var(--border-hover);
		}

		.status-dot {
			width: 7px;
			height: 7px;
			background: var(--success);
			border-radius: 50%;
			box-shadow: 0 0 8px var(--success-glow);
			animation: pulse 2s ease-in-out infinite;
		}

		@keyframes pulse {

			0%,
			100% {
				opacity: 1;
				box-shadow: 0 0 8px var(--success-glow);
			}

			50% {
				opacity: 0.6;
				box-shadow: 0 0 16px var(--success-glow);
			}
		}

		/* ─── Endpoint Cards ─────────────────────────────────── */
		.endpoints {
			display: flex;
			flex-direction: column;
			gap: 10px;
			margin-bottom: 2.5rem;
		}

		.endpoint {
			display: flex;
			align-items: center;
			gap: 12px;
			padding: 14px 18px;
			background: var(--bg-card);
			border: 1px solid var(--border);
			border-radius: 12px;
			text-decoration: none;
			color: var(--text-primary);
			transition: all 0.2s ease;
		}

		.endpoint:hover {
			border-color: var(--border-hover);
			background: var(--bg-secondary);
			transform: translateY(-1px);
		}

		.endpoint-method {
			font-size: 0.65rem;
			font-weight: 600;
			text-transform: uppercase;
			letter-spacing: 0.05em;
			padding: 4px 8px;
			border-radius: 6px;
			flex-shrink: 0;
		}

		.method-get {
			background: var(--accent-soft);
			color: var(--accent);
		}

		.method-panel {
			background: rgba(168, 85, 247, 0.1);
			color: #a855f7;
		}

		.endpoint-path {
			font-size: 0.85rem;
			font-family: 'SF Mono', 'Fira Code', 'Consolas', monospace;
			color: var(--text-secondary);
		}

		.endpoint-arrow {
			margin-left: auto;
			color: var(--text-muted);
			transition: transform 0.2s ease, color 0.2s ease;
		}

		.endpoint:hover .endpoint-arrow {
			transform: translateX(3px);
			color: var(--text-secondary);
		}

		/* ─── Footer ─────────────────────────────────────────── */
		.footer {
			font-size: 0.75rem;
			color: var(--text-muted);
			letter-spacing: 0.02em;
		}

		.footer span {
			color: var(--text-secondary);
		}

		/* ─── Animation ──────────────────────────────────────── */
		.container>* {
			animation: fadeUp 0.6s ease-out backwards;
		}

		.container>*:nth-child(1) {
			animation-delay: 0.05s;
		}

		.container>*:nth-child(2) {
			animation-delay: 0.1s;
		}

		.container>*:nth-child(3) {
			animation-delay: 0.15s;
		}

		.container>*:nth-child(4) {
			animation-delay: 0.2s;
		}

		.container>*:nth-child(5) {
			animation-delay: 0.25s;
		}

		.container>*:nth-child(6) {
			animation-delay: 0.3s;
		}

		@keyframes fadeUp {
			from {
				opacity: 0;
				transform: translateY(12px);
			}

			to {
				opacity: 1;
				transform: translateY(0);
			}
		}
	</style>
</head>

<body>
	<div class="container">
		<div class="logo-wrapper">
			<div class="logo">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
					stroke="currentColor">
					<path stroke-linecap="round" stroke-linejoin="round"
						d="M5.25 14.25h13.5m-13.5 0a3 3 0 0 1-3-3m3 3a3 3 0 1 0 0 6h13.5a3 3 0 1 0 0-6m-16.5-3a3 3 0 0 1 3-3h13.5a3 3 0 0 1 3 3m-19.5 0a4.5 4.5 0 0 1 .9-2.7L5.737 5.1a3.375 3.375 0 0 1 2.7-1.35h7.126c1.062 0 2.062.5 2.7 1.35l2.587 3.45a4.5 4.5 0 0 1 .9 2.7m0 0a3 3 0 0 1-3 3m0 3h.008v.008h-.008v-.008Zm0-6h.008v.008h-.008v-.008Z" />
				</svg>
			</div>
		</div>

		<h1>Tàng Kiếm API</h1>
		<p class="subtitle">Backend service cung cấp API và quản trị hệ thống Tàng Kiếm.</p>

		<div class="status">
			<span class="status-dot"></span>
			Hệ thống đang hoạt động
		</div>

		<div class="endpoints">
			<a href="/admin" class="endpoint">
				<span class="endpoint-method method-panel">Panel</span>
				<span class="endpoint-path">/admin</span>
				<svg class="endpoint-arrow" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none"
					viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
					<path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
				</svg>
			</a>
			<a href="/api/v1/stories" class="endpoint">
				<span class="endpoint-method method-get">GET</span>
				<span class="endpoint-path">/api/v1/stories</span>
				<svg class="endpoint-arrow" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none"
					viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
					<path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
				</svg>
			</a>
			<a href="/api/v1/categories" class="endpoint">
				<span class="endpoint-method method-get">GET</span>
				<span class="endpoint-path">/api/v1/categories</span>
				<svg class="endpoint-arrow" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none"
					viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
					<path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
				</svg>
			</a>
		</div>

		<p class="footer">Powered by · {{ config('app.name', 'Tàng Kiếm') }}</p>
	</div>
</body>

</html>

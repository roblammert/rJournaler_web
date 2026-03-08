<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Auth/require_auth.php';

use App\Auth\Auth;

$interfaceTheme = Auth::interfaceTheme();
$appVersion = (string) ($config['version'] ?? '1.0.0');
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Simple Analysis</title></head>
<style>
	:root {
		--font-ui: "Segoe UI", "Aptos", "Trebuchet MS", sans-serif;
		--radius-md: 10px;
		--shadow-sm: 0 4px 12px rgba(17, 24, 39, 0.08);
	}

	body[data-theme="light"] {
		--bg: #f7f9fc;
		--bg-accent: #edf3fb;
		--surface: #ffffff;
		--surface-soft: #f7faff;
		--border: #d6e0ef;
		--text: #1f2f43;
		--text-muted: #5b6f87;
		--heading: #152334;
		--link: #1f5f9a;
	}

	body[data-theme="neutral"] {
		--bg: #f4f3f1;
		--bg-accent: #eceae6;
		--surface: #fffdf8;
		--surface-soft: #f4f1ea;
		--border: #d8d1c5;
		--text: #2f3432;
		--text-muted: #676d69;
		--heading: #222827;
		--link: #3c5f72;
	}

	body[data-theme="dark"] {
		--bg: #171d23;
		--bg-accent: #1e2730;
		--surface: #222d38;
		--surface-soft: #263340;
		--border: #364757;
		--text: #dbe4ec;
		--text-muted: #98a9b9;
		--heading: #f1f5f8;
		--link: #8fc3f3;
		--shadow-sm: 0 4px 12px rgba(0, 0, 0, 0.28);
	}

	body {
		margin: 0;
		padding: 1rem;
		font-family: var(--font-ui);
		background: radial-gradient(circle at 18% 0%, var(--bg-accent), var(--bg) 38%);
		color: var(--text);
	}

	main { max-width: 1100px; margin: 0 auto; }

	.page-header {
		display: flex;
		justify-content: space-between;
		align-items: start;
		gap: 0.8rem;
		flex-wrap: wrap;
		margin-bottom: 0.6rem;
	}

	.page-header h1 { margin: 0; color: var(--heading); }

	.header-links {
		display: flex;
		align-items: center;
		gap: 0.5rem;
		flex-wrap: wrap;
	}

	.pill {
		display: inline-block;
		padding: 0.12rem 0.52rem;
		border-radius: 999px;
		font-size: 0.82rem;
		border: 1px solid var(--border);
		background: var(--surface-soft);
		color: var(--text);
		box-shadow: var(--shadow-sm);
	}

	.panel {
		border: 1px solid var(--border);
		border-radius: var(--radius-md);
		background: var(--surface);
		box-shadow: var(--shadow-sm);
		padding: 0.75rem;
	}

	.muted { color: var(--text-muted); }
	a { color: var(--link); text-decoration: none; }
	a:hover { text-decoration: underline; }
</style>
<body data-theme="<?php echo htmlspecialchars($interfaceTheme, ENT_QUOTES, 'UTF-8'); ?>">
<main>
	<header class="page-header">
		<h1>Simple Analysis</h1>
		<div class="header-links">
			<a class="pill" href="/index.php">Back to Dashboard</a>
			<span class="pill">Theme: <?php echo htmlspecialchars(ucfirst($interfaceTheme), ENT_QUOTES, 'UTF-8'); ?></span>
			<span class="pill">rJournaler_Web: v<?php echo htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8'); ?></span>
		</div>
	</header>

	<section class="panel">
		<p class="muted">Phase 4 placeholder for high-level analytics KPIs.</p>
	</section>
</main>
</body>
</html>

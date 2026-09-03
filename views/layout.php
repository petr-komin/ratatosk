<?php /** @var string $view */ ?>
<!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title ?? 'Ratatosk') ?> — Ratatosk</title>
<link rel="icon" href="/assets/ratatosk.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset_url('/assets/app.css')) ?>">
</head>
<body>
<header class="topbar">
    <a class="brand" href="/">
        <img src="/assets/ratatosk.svg" alt="" width="28" height="28">
        <span>Ratatosk</span>
    </a>
    <?php if (!empty($user)): ?>
        <nav>
            <a class="btn btn-primary" href="/record">Nový záznam</a>
            <span class="who"><?= e($user['email']) ?></span>
            <form method="post" action="/logout" class="inline">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <button class="btn btn-quiet" type="submit">Odhlásit</button>
            </form>
        </nav>
    <?php elseif (!empty($showLoginLink)): ?>
        <nav>
            <a class="btn btn-primary" href="/login">Přihlásit se</a>
        </nav>
    <?php endif; ?>
</header>

<main<?= !empty($mainClass) ? ' class="' . e($mainClass) . '"' : '' ?>>
<?php require __DIR__ . '/' . $view . '.php'; ?>
</main>
</body>
</html>

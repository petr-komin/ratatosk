<div class="card narrow">
    <h1>Přihlášení</h1>
    <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
    <form method="post" action="/login">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label>E-mail<input type="email" name="email" required autofocus autocomplete="username"></label>
        <label>Heslo<input type="password" name="password" required autocomplete="current-password"></label>
        <button class="btn btn-primary" type="submit">Přihlásit</button>
    </form>
    <p class="dim"><a href="/register">Založit účet</a> (potřebuješ zvací kód)</p>
</div>

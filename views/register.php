<div class="card narrow">
    <h1>Registrace</h1>
    <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
    <form method="post" action="/register">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label>Zvací kód<input type="text" name="invite" required autofocus></label>
        <label>E-mail<input type="email" name="email" required autocomplete="username"></label>
        <label>Heslo <span class="dim">(aspoň 10 znaků)</span>
            <input type="password" name="password" required minlength="10" autocomplete="new-password">
        </label>
        <button class="btn btn-primary" type="submit">Založit účet</button>
    </form>
    <p class="dim"><a href="/login">Zpět na přihlášení</a></p>
</div>

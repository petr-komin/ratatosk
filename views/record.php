<div class="card">
    <h1>Nový záznam</h1>

    <p class="dim">
        Po kliknutí na <em>Spustit</em> vyskočí systémový dialog, kde si vybereš
        zdroj — celou obrazovku, okno aplikace, nebo jen záložku prohlížeče.
        Komentář se bere z mikrofonu.
    </p>

    <label>Název <span class="dim">(nepovinný)</span>
        <input type="text" id="title" placeholder="např. Chyba v košíku — krok za krokem">
    </label>

    <div class="controls">
        <button class="btn btn-primary" id="start">Spustit nahrávání</button>
        <button class="btn" id="stop" disabled>Zastavit</button>
        <span id="timer" class="timer">0:00</span>
    </div>

    <p id="state" class="state"></p>

    <div id="progressWrap" hidden>
        <div class="progress"><div id="progressBar"></div></div>
    </div>

    <video id="preview" muted playsinline hidden></video>

    <div id="done" hidden class="done">
        <p>Hotovo. Odkaz začne fungovat, jakmile doběhne překódování do MP4.</p>
        <div class="sharebox">
            <input type="text" id="shareUrl" readonly>
            <button class="btn copy" id="copyShare">Kopírovat</button>
        </div>
        <p class="dim">Stav sleduj na <a href="/">přehledu záznamů</a>.</p>
    </div>
</div>

<script>window.CSRF = <?= json_encode(csrf_token()) ?>;</script>
<script src="/assets/record.js"></script>
<script src="/assets/copy.js"></script>

<div class="card">
    <h1>Nový záznam</h1>

    <label>Název <span class="dim">(nepovinný)</span>
        <input type="text" id="title" placeholder="např. Chyba v košíku — krok za krokem">
    </label>

    <fieldset class="group">
        <legend>Obraz</legend>

        <label class="row">Co chceš zabrat
            <select id="surface">
                <option value="browser">Karta prohlížeče</option>
                <option value="window">Okno aplikace</option>
                <option value="monitor">Celá obrazovka</option>
            </select>
        </label>

        <p class="hint">
            Tohle je jen <strong>předvolba dialogu</strong>. Konečný výběr zdroje
            dělá prohlížeč — po spuštění vyskočí systémové okno, kde klikneš na
            konkrétní kartu, okno nebo monitor. Stránka to za tebe udělat nesmí.
        </p>

        <label class="check">
            <input type="checkbox" id="excludeSelf" checked>
            Nenabízet v dialogu tuhle kartu <span class="dim">(ať omylem nenatočíš Ratatosk)</span>
        </label>
    </fieldset>

    <fieldset class="group">
        <legend>Zvuk</legend>

        <label class="row">Mikrofon
            <select id="micSelect"><option value="">Načítám…</option></select>
        </label>

        <div class="meterRow">
            <div class="meter"><div id="meterBar"></div></div>
            <button class="btn btn-quiet" id="micTest" type="button">Vyzkoušet</button>
        </div>
        <p class="hint" id="micHint">
            Zkouškou ověříš, že mluvíš do toho správného mikrofonu — ručička se
            musí hýbat.
        </p>

        <label class="check">
            <input type="checkbox" id="wantSystemAudio">
            Přibrat i zvuk ze sdílené plochy <span class="dim">(zvuk karty / systému)</span>
        </label>
        <p class="hint">
            Hodí se, když scénář obsahuje video nebo pípání appky. Zaškrtnutí ale
            nic negarantuje — jestli se zvuk připojí, rozhoduje prohlížeč a systém.
            Zvuk karty umí spolehlivě Chrome; systémový zvuk na Linuxu často
            nefunguje vůbec. Když nedorazí, nahraje se aspoň mikrofon.
        </p>
    </fieldset>

    <div class="controls">
        <button class="btn btn-primary" id="start">Spustit nahrávání</button>
        <button class="btn" id="stop" disabled>Zastavit</button>
        <span id="timer" class="timer">0:00</span>
    </div>

    <p id="state" class="state"></p>
    <p id="sources" class="hint" hidden></p>

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

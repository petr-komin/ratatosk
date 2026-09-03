<div class="watch-head">
    <h1><?= e($rec['title']) ?></h1>
    <p class="dim">
        Nahrál <?= e($rec['author']) ?>,
        <?= e(format_dt($rec['created_at'])) ?>
    </p>
</div>

<?php if ($rec['status'] === 'ready' && $videoUrl): ?>

    <video class="watch-video" controls playsinline preload="metadata" src="<?= e($videoUrl) ?>"></video>

<?php else: ?>

    <div class="watch-body">
    <?php if ($rec['status'] === 'failed'): ?>
        <p class="error">Překódování do MP4 selhalo.</p>
    <?php else: ?>
        <div class="processing" data-id="<?= e($rec['id']) ?>">
            <div class="spinner"></div>
            <p>Video se zpracovává (<?= e(STATUS_LABELS[$rec['status']] ?? $rec['status']) ?>).<br>
               Stránka se sama obnoví, až bude hotovo.</p>
        </div>
    <?php endif; ?>

    <?php if ($sourceUrl): ?>
        <div class="fallback" id="fallback" hidden>
            <p>
                Na MP4 se čeká hlavně kvůli Safari, které WebM spolehlivě
                nepřehraje. <strong>Tvůj prohlížeč to ale umí</strong> — původní
                záznam si můžeš pustit rovnou, ve stejné kvalitě.
            </p>
            <button class="btn" id="playSource" data-src="<?= e($sourceUrl) ?>">
                Přehrát původní záznam
            </button>
        </div>
        <video id="sourcePlayer" class="watch-video" controls playsinline preload="none" hidden></video>
    <?php endif; ?>
    </div>

    <script src="/assets/watch.js"></script>

<?php endif; ?>

<div class="watch-foot">
    <?php if ($viewer): ?>
        <a class="btn btn-primary" href="/record">+ Nahrát další záznam</a>
    <?php else: ?>
        <a class="btn btn-primary" href="/login">Přihlásit se</a>
    <?php endif; ?>
</div>

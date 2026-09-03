<div class="card">
    <h1><?= e($rec['title']) ?></h1>
    <p class="dim">
        Nahrál <?= e($rec['author']) ?>,
        <?= e(format_dt($rec['created_at'])) ?>
    </p>

    <?php if ($rec['status'] === 'ready' && $videoUrl): ?>
        <video controls playsinline preload="metadata" src="<?= e($videoUrl) ?>"></video>
    <?php elseif ($rec['status'] === 'failed'): ?>
        <p class="error">Překódování selhalo. Záznam se bohužel nepodařilo připravit.</p>
    <?php else: ?>
        <div class="processing" data-id="<?= e($rec['id']) ?>">
            <div class="spinner"></div>
            <p>Video se zpracovává (<?= e(STATUS_LABELS[$rec['status']] ?? $rec['status']) ?>).<br>
               Stránka se sama obnoví, až bude hotovo.</p>
        </div>
        <script src="/assets/watch.js"></script>
    <?php endif; ?>
</div>

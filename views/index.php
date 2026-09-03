<h1>Záznamy</h1>

<?php if (!$recordings): ?>
    <p class="empty">Zatím nic. <a href="/record">Nahraj první záznam.</a></p>
<?php else: ?>
<table class="recs">
    <thead>
    <tr><th>Název</th><th>Kdo</th><th>Kdy</th><th>Délka</th><th>Stav</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($recordings as $r): ?>
        <tr>
            <td>
                <?php if ($r['status'] === 'ready'): ?>
                    <a href="/w/<?= e($r['id']) ?>"><?= e($r['title']) ?></a>
                <?php else: ?>
                    <?= e($r['title']) ?>
                <?php endif; ?>
            </td>
            <td class="dim"><?= e($r['author']) ?></td>
            <td class="dim"><?= e(format_dt($r['created_at'])) ?></td>
            <td class="dim"><?= e(format_duration($r['duration_ms'] === null ? null : (int) $r['duration_ms'])) ?></td>
            <td>
                <span class="status status-<?= e($r['status']) ?>">
                    <?= e(STATUS_LABELS[$r['status']] ?? $r['status']) ?>
                </span>
                <?php if ($r['status'] === 'failed' && $r['error']): ?>
                    <span class="dim" title="<?= e($r['error']) ?>">ⓘ</span>
                <?php endif; ?>
            </td>
            <td class="right">
                <?php if ($r['status'] === 'ready'): ?>
                    <button class="btn btn-quiet copy" data-url="<?= e(share_url($r['id'])) ?>">Kopírovat odkaz</button>
                <?php endif; ?>
                <?php if ((int) $r['user_id'] === (int) $user['id']): ?>
                    <form method="post" action="/recordings/<?= e($r['id']) ?>/delete"
                          class="inline delete-form" data-title="<?= e($r['title']) ?>">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <button class="btn btn-quiet danger" type="submit">Smazat</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<script src="<?= e(asset_url('/assets/copy.js')) ?>"></script>
<?php endif; ?>

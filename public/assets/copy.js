document.addEventListener('click', async (e) => {
  const btn = e.target.closest('.copy');
  if (!btn) return;

  const url = btn.dataset.url || document.getElementById('shareUrl')?.value;
  if (!url) return;

  try {
    await navigator.clipboard.writeText(url);
  } catch {
    return; // clipboard bez HTTPS nebo bez povolení — nic se neděje
  }
  const original = btn.textContent;
  btn.textContent = 'Zkopírováno';
  setTimeout(() => { btn.textContent = original; }, 1500);
});

// Mazání je nevratné (smaže i objekty v R2), takže si vyžádá potvrzení.
document.addEventListener('submit', (e) => {
  const form = e.target.closest('.delete-form');
  if (!form) return;

  const title = form.dataset.title || 'tento záznam';
  if (!confirm(`Smazat záznam „${title}“? Tohle nejde vrátit zpět.`)) {
    e.preventDefault();
  }
});

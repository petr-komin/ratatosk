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

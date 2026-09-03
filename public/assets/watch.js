(() => {
  'use strict';
  const box = document.querySelector('.processing');
  if (!box) return;

  const id = box.dataset.id;
  let delay = 5000;

  const poll = async () => {
    try {
      const res = await fetch(`/api/recordings/${id}/status`, { cache: 'no-store' });
      const data = await res.json();
      if (data.status === 'ready' || data.status === 'failed') {
        location.reload();
        return;
      }
    } catch (e) {
      // síť občas blbne, prostě zkusíme znovu za chvíli
    }
    delay = Math.min(delay * 1.3, 30000); // ať to po pár minutách netepe zbytečně
    setTimeout(poll, delay);
  };

  setTimeout(poll, delay);
})();

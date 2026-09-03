(() => {
  'use strict';

  const box      = document.querySelector('.processing');
  const fallback = document.getElementById('fallback');
  const btn      = document.getElementById('playSource');
  const player   = document.getElementById('sourcePlayer');

  let watchingSource = false;

  /* ------------------------------------ nabídka původního WebM ---------- */

  // Nabízíme jen tam, kde to prohlížeč opravdu zvládne. Safari sem nespadne,
  // a přesně kvůli němu se na MP4 čeká.
  const canPlayWebm = () => {
    const probe = document.createElement('video');
    return ['video/webm; codecs="vp9,opus"', 'video/webm; codecs="vp8,opus"', 'video/webm']
      .some((t) => probe.canPlayType(t) === 'probably' || probe.canPlayType(t) === 'maybe');
  };

  if (btn && player && canPlayWebm()) {
    fallback.hidden = false;

    btn.addEventListener('click', () => {
      // preload="none" v HTML šetří přenos, dokud o video nikdo nestojí.
      // Teď o něj stojí, takže načítání zapneme a vynutíme — jinak by při
      // zablokovaném autoplay zůstal jen prázdný černý obdélník bez délky.
      player.preload = 'metadata';
      player.src = btn.dataset.src;
      player.hidden = false;
      fallback.hidden = true;
      watchingSource = true;
      player.load();
      player.play().catch(() => { /* autoplay blokovaný — ovládání zůstává */ });
    });
  }

  /* ----------------------------------------------- čekání na překódování */

  if (!box) return;

  const id = box.dataset.id;
  let delay = 5000;

  // Když se uživatel mezitím dívá na původní verzi, přebít mu to reloadem
  // uprostřed přehrávání by bylo protivné — jen nabídneme.
  const announceReady = () => {
    box.classList.remove('processing');
    box.className = 'readyNote';
    box.innerHTML = '<p>Překódovaná verze (MP4) je hotová. '
                  + '<a href="">Načíst přehrávač</a></p>';
  };

  const poll = async () => {
    try {
      const res = await fetch(`/api/recordings/${id}/status`, { cache: 'no-store' });
      const data = await res.json();
      if (data.status === 'ready' || data.status === 'failed') {
        if (watchingSource) {
          announceReady();
        } else {
          location.reload();
        }
        return;
      }
    } catch {
      // síť občas blbne, zkusíme znovu za chvíli
    }
    delay = Math.min(delay * 1.3, 30000); // ať to po pár minutách netepe zbytečně
    setTimeout(poll, delay);
  };

  setTimeout(poll, delay);
})();

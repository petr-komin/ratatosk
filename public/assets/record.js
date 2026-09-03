(() => {
  'use strict';

  const $ = (id) => document.getElementById(id);
  const startBtn = $('start');
  const stopBtn = $('stop');
  const stateEl = $('state');
  const preview = $('preview');
  const timerEl = $('timer');
  const progressWrap = $('progressWrap');
  const progressBar = $('progressBar');

  let recorder = null;
  let chunks = [];
  let tracks = [];
  let startedAt = 0;
  let timerId = null;

  const say = (msg, cls = '') => {
    stateEl.textContent = msg;
    stateEl.className = 'state ' + cls;
  };

  // Prohlížeče se liší v tom, co MediaRecorder umí — bereme první podporovaný.
  const pickMimeType = () => {
    const candidates = [
      'video/webm;codecs=vp9,opus',
      'video/webm;codecs=vp8,opus',
      'video/webm',
    ];
    return candidates.find((t) => MediaRecorder.isTypeSupported(t)) || '';
  };

  const stopAllTracks = () => {
    tracks.forEach((t) => t.stop());
    tracks = [];
    preview.srcObject = null;
  };

  const tick = () => {
    const s = Math.floor((Date.now() - startedAt) / 1000);
    timerEl.textContent = `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
  };

  startBtn.addEventListener('click', async () => {
    startBtn.disabled = true;
    say('Vyber zdroj v dialogu prohlížeče…');

    let display, mic;
    try {
      // Zvuk obrazovky neřešíme, komentář bereme z mikrofonu — spolehlivější.
      display = await navigator.mediaDevices.getDisplayMedia({
        video: { displaySurface: 'browser' },
        audio: false,
      });
    } catch (err) {
      startBtn.disabled = false;
      say('Výběr zdroje zrušen.', 'error');
      return;
    }

    try {
      mic = await navigator.mediaDevices.getUserMedia({
        audio: { echoCancellation: true, noiseSuppression: true },
      });
    } catch (err) {
      say('Mikrofon není dostupný — nahrávám bez komentáře.', 'warn');
      mic = null;
    }

    const combined = new MediaStream([
      ...display.getVideoTracks(),
      ...(mic ? mic.getAudioTracks() : []),
    ]);
    tracks = combined.getTracks();

    preview.hidden = false;
    preview.srcObject = new MediaStream(display.getVideoTracks());
    preview.play().catch(() => {});

    chunks = [];
    const mimeType = pickMimeType();
    recorder = new MediaRecorder(combined, mimeType ? { mimeType } : undefined);
    recorder.ondataavailable = (e) => { if (e.data.size) chunks.push(e.data); };
    recorder.onstop = () => finish(mimeType);
    recorder.start(1000);

    startedAt = Date.now();
    timerId = setInterval(tick, 250);

    // Uživatel může nahrávání ukončit i nativním „Stop sharing" pruhem.
    display.getVideoTracks()[0].addEventListener('ended', () => stop());

    stopBtn.disabled = false;
    say('Nahrávám…', 'rec');
  });

  const stop = () => {
    if (!recorder || recorder.state === 'inactive') return;
    stopBtn.disabled = true;
    clearInterval(timerId);
    recorder.stop();
  };

  stopBtn.addEventListener('click', stop);

  async function finish(mimeType) {
    const durationMs = Date.now() - startedAt;
    stopAllTracks();

    const blob = new Blob(chunks, { type: mimeType || 'video/webm' });
    chunks = [];
    say(`Nahráno ${(blob.size / 1048576).toFixed(1)} MB, nahrávám do úložiště…`);

    try {
      const rec = await createRecording();
      await uploadBlob(rec.uploadUrl, blob);
      await completeRecording(rec.id, { durationMs, sizeBytes: blob.size });
      showDone(rec.shareUrl);
    } catch (err) {
      console.error(err);
      say('Upload selhal: ' + err.message + ' — záznam zůstal jen v prohlížeči.', 'error');
      offerDownload(blob);
      startBtn.disabled = false;
    }
  }

  async function createRecording() {
    const res = await fetch('/api/recordings', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF },
      body: JSON.stringify({ title: $('title').value }),
    });
    if (!res.ok) throw new Error('server odmítl založit záznam (' + res.status + ')');
    return res.json();
  }

  // XHR kvůli progressu — fetch upload progress zatím není všude.
  function uploadBlob(url, blob) {
    return new Promise((resolve, reject) => {
      progressWrap.hidden = false;
      const xhr = new XMLHttpRequest();
      xhr.open('PUT', url, true);
      xhr.setRequestHeader('Content-Type', 'video/webm');
      xhr.upload.onprogress = (e) => {
        if (!e.lengthComputable) return;
        const pct = Math.round((e.loaded / e.total) * 100);
        progressBar.style.width = pct + '%';
        say(`Nahrávám do úložiště… ${pct} %`);
      };
      xhr.onload = () => (xhr.status >= 200 && xhr.status < 300)
        ? resolve()
        : reject(new Error('R2 vrátilo ' + xhr.status));
      xhr.onerror = () => reject(new Error('síťová chyba (zkontroluj CORS na bucketu)'));
      xhr.send(blob);
    });
  }

  async function completeRecording(id, meta) {
    const res = await fetch(`/api/recordings/${id}/complete`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF },
      body: JSON.stringify(meta),
    });
    if (!res.ok) throw new Error('nepodařilo se potvrdit upload');
  }

  function showDone(shareUrl) {
    progressWrap.hidden = true;
    say('Nahráno.', 'ok');
    $('shareUrl').value = shareUrl;
    $('done').hidden = false;
  }

  // Když upload spadne, ať uživatel nepřijde o záznam.
  function offerDownload(blob) {
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'ratatosk-zaznam.webm';
    a.textContent = 'Stáhnout záznam do počítače';
    a.className = 'btn';
    stateEl.after(a);
  }
})();

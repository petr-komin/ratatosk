(() => {
  'use strict';

  const $ = (id) => document.getElementById(id);
  const els = {
    start: $('start'), stop: $('stop'), state: $('state'), sources: $('sources'),
    preview: $('preview'), timer: $('timer'),
    progressWrap: $('progressWrap'), progressBar: $('progressBar'),
    surface: $('surface'), excludeSelf: $('excludeSelf'),
    micSelect: $('micSelect'), micTest: $('micTest'), micHint: $('micHint'),
    micCount: $('micCount'), permNote: $('permNote'), micPerm: $('micPerm'),
    meterBar: $('meterBar'), wantSystemAudio: $('wantSystemAudio'),
    title: $('title'), popOut: $('popOut'), pipNotice: $('pipNotice'),
  };

  let pipWindow = null;

  const PREFS = 'ratatosk.prefs';
  let recorder = null, chunks = [], startedAt = 0, timerId = null, isRecording = false;
  let liveTracks = [], audioCtx = null, meterStream = null, meterRaf = 0;

  const say = (msg, cls = '') => {
    els.state.textContent = msg;
    els.state.className = 'state ' + cls;
  };

  /* ------------------------------------------------ zapamatovaná nastavení */

  const loadPrefs = () => {
    let p = {};
    try { p = JSON.parse(localStorage.getItem(PREFS) || '{}'); } catch { /* nevadí */ }
    if (p.surface) els.surface.value = p.surface;
    if (typeof p.excludeSelf === 'boolean') els.excludeSelf.checked = p.excludeSelf;
    if (typeof p.systemAudio === 'boolean') els.wantSystemAudio.checked = p.systemAudio;
    return p;
  };

  const savePrefs = () => {
    try {
      localStorage.setItem(PREFS, JSON.stringify({
        surface: els.surface.value,
        excludeSelf: els.excludeSelf.checked,
        systemAudio: els.wantSystemAudio.checked,
        micId: els.micSelect.value,
      }));
    } catch { /* privátní okno, nevadí */ }
  };

  const prefs = loadPrefs();
  [els.surface, els.excludeSelf, els.wantSystemAudio, els.micSelect]
    .forEach((el) => el.addEventListener('change', savePrefs));

  /* -------------------------------------------------------- výběr mikrofonu */

  // Dokud uživatel nepovolí přístup, vrací prohlížeč jediný anonymní vstup
  // s prázdným deviceId i labelem — ochrana proti fingerprintingu. Skutečná
  // zařízení (webkamery, zvukovky) se objeví teprve po udělení permission.
  async function listMics(preferId) {
    let devices = [];
    try {
      devices = (await navigator.mediaDevices.enumerateDevices())
        .filter((d) => d.kind === 'audioinput');
    } catch {
      els.micSelect.innerHTML = '<option value="">Zařízení nelze načíst</option>';
      return false;
    }

    if (!devices.length) {
      els.micSelect.innerHTML = '<option value="none">Žádné zvukové zařízení</option>';
      els.micCount.textContent = '';
      return false;
    }

    // Windows/PulseAudio přidávají zástupce "default" a "communications",
    // které jen ukazují na jiné zařízení ze seznamu — jsou jen matoucí.
    const alias = /^(default|communications)$/;
    const real = devices.filter((d) => !alias.test(d.deviceId));
    const list = real.length ? real : devices;

    const named = list.some((d) => d.label);
    els.micSelect.innerHTML = '';
    els.micSelect.add(new Option('Bez mikrofonu (jen obraz)', 'none'));
    list.forEach((d, i) => {
      els.micSelect.add(new Option(d.label || `Zvukový vstup ${i + 1}`, d.deviceId));
    });

    const wanted = preferId || prefs.micId;
    if (wanted && [...els.micSelect.options].some((o) => o.value === wanted)) {
      els.micSelect.value = wanted;
    } else {
      els.micSelect.selectedIndex = 1;
    }

    els.permNote.hidden = named;
    // "zařízení" má stejný tvar v jednotném i množném čísle, tvar s dvojtečkou
    // se tedy nemůže rozejít s počtem
    els.micCount.textContent = named ? `Zvukových zařízení: ${list.length}` : '';

    return named;
  }

  /** Jediný způsob, jak z prohlížeče dostat názvy zařízení, je sáhnout na mikrofon. */
  async function revealDevices() {
    els.micPerm.disabled = true;
    try {
      const s = await navigator.mediaDevices.getUserMedia({ audio: true });
      s.getTracks().forEach((t) => t.stop());
    } catch (err) {
      els.micPerm.disabled = false;
      say(err.name === 'NotAllowedError'
        ? 'Přístup k mikrofonu je zamítnutý. Povol ho v prohlížeči (ikona vlevo v adresním řádku) a načti stránku znovu.'
        : 'Mikrofon se nepodařilo otevřít: ' + err.name, 'error');
      return;
    }
    els.micPerm.disabled = false;
    await listMics();
    say('');
  }

  els.micPerm.addEventListener('click', revealDevices);

  /* ------------------------------------------------------- ukazatel hlasitosti */

  function stopMeter() {
    cancelAnimationFrame(meterRaf);
    meterRaf = 0;
    meterStream?.getTracks().forEach((t) => t.stop());
    meterStream = null;
    els.meterBar.style.width = '0%';
    els.micTest.textContent = 'Vyzkoušet';
  }

  async function startMeter() {
    const id = els.micSelect.value;
    if (id === 'none') {
      say('Mikrofon je vypnutý — přepni ho v seznamu.', 'warn');
      return;
    }

    try {
      meterStream = await navigator.mediaDevices.getUserMedia({ audio: micConstraint(id) });
    } catch (err) {
      say('Mikrofon se nepodařilo otevřít: ' + err.name, 'error');
      return;
    }

    // Labely jsou k dispozici až teď — seznam přepíšeme, ať jsou vidět názvy.
    await listMics(id || els.micSelect.value);

    audioCtx ||= new (window.AudioContext || window.webkitAudioContext)();
    await audioCtx.resume();

    const analyser = audioCtx.createAnalyser();
    analyser.fftSize = 1024;
    audioCtx.createMediaStreamSource(meterStream).connect(analyser);

    const buf = new Float32Array(analyser.fftSize);
    els.micTest.textContent = 'Zastavit zkoušku';

    const tick = () => {
      analyser.getFloatTimeDomainData(buf);
      let sum = 0;
      for (const v of buf) sum += v * v;
      const rms = Math.sqrt(sum / buf.length);
      els.meterBar.style.width = Math.min(100, rms * 400).toFixed(1) + '%';
      meterRaf = requestAnimationFrame(tick);
    };
    tick();
  }

  els.micTest.addEventListener('click', () => (meterStream ? stopMeter() : startMeter()));

  const micConstraint = (deviceId) => ({
    deviceId: deviceId ? { exact: deviceId } : undefined,
    echoCancellation: true,
    noiseSuppression: true,
  });

  /* ------------------------------------------------------------- nahrávání */

  const pickMimeType = () => [
    'video/webm;codecs=vp9,opus',
    'video/webm;codecs=vp8,opus',
    'video/webm',
  ].find((t) => MediaRecorder.isTypeSupported(t)) || '';

  els.start.addEventListener('click', async () => {
    els.start.disabled = true;
    stopMeter();
    savePrefs();
    say('Vyber zdroj v dialogu prohlížeče…');

    let display;
    try {
      display = await navigator.mediaDevices.getDisplayMedia({
        video: {
          // Jen předvolba dialogu — uživatel může vybrat cokoli jiného.
          displaySurface: els.surface.value,
        },
        // Zvuk plochy si vyžádáme, jen když o něj uživatel stojí; jinak by
        // dialog zbytečně nabízel zaškrtávátko, které stejně nechceme.
        audio: els.wantSystemAudio.checked
          ? { echoCancellation: false, noiseSuppression: false, autoGainControl: false }
          : false,
        systemAudio: els.wantSystemAudio.checked ? 'include' : 'exclude',
        selfBrowserSurface: els.excludeSelf.checked ? 'exclude' : 'include',
        surfaceSwitching: 'include',
      });
    } catch (err) {
      els.start.disabled = false;
      say(err.name === 'NotAllowedError'
        ? 'Výběr zdroje zrušen.'
        : 'Obraz se nepodařilo zachytit: ' + err.message, 'error');
      return;
    }

    // Mikrofon až po výběru zdroje — kdyby uživatel dialog zrušil, ať mu
    // zbytečně nesvítí kontrolka nahrávání.
    let mic = null;
    const micId = els.micSelect.value;
    // Prázdné deviceId znamená "zatím bez permission" — pak bereme výchozí
    // zařízení. Vypnutý mikrofon je jen explicitní volba 'none'.
    if (micId !== 'none') {
      try {
        mic = await navigator.mediaDevices.getUserMedia({ audio: micConstraint(micId) });
      } catch (err) {
        say('Mikrofon není dostupný (' + err.name + ') — nahrávám bez komentáře.', 'warn');
      }
    }

    const displayAudio = display.getAudioTracks();
    const audioTrack = buildAudioTrack(mic, displayAudio);

    const videoTrack = display.getVideoTracks()[0];
    const combined = new MediaStream(audioTrack ? [videoTrack, audioTrack] : [videoTrack]);

    // Držíme si i původní tracky, ať je na konci zavřeme úplně všechny.
    liveTracks = [videoTrack, ...displayAudio, ...(mic ? mic.getTracks() : [])];
    if (audioTrack) liveTracks.push(audioTrack);

    reportSources(videoTrack, mic, displayAudio);

    els.preview.hidden = false;
    els.preview.srcObject = new MediaStream([videoTrack]);
    els.preview.play().catch(() => {});

    chunks = [];
    const mimeType = pickMimeType();
    recorder = new MediaRecorder(combined, mimeType ? { mimeType } : undefined);
    recorder.ondataavailable = (e) => { if (e.data.size) chunks.push(e.data); };
    recorder.onstop = () => finish(mimeType);
    recorder.start(1000);
    isRecording = true;

    startedAt = Date.now();
    timerId = setInterval(tick, 250);
    videoTrack.addEventListener('ended', stop); // nativní pruh „Stop sharing"

    els.stop.disabled = false;
    say('Nahrávám…', 'rec');
    if ('documentPictureInPicture' in window) els.popOut.hidden = false;
  });

  /** Mikrofon + zvuk plochy je potřeba smíchat — MediaRecorder bere jednu stopu. */
  function buildAudioTrack(mic, displayAudio) {
    const micTracks = mic ? mic.getAudioTracks() : [];
    if (!micTracks.length && !displayAudio.length) return null;

    // Jeden zdroj -> žádné míchání, ať do cesty nelezeme resamplingem navíc.
    if (micTracks.length && !displayAudio.length) return micTracks[0];
    if (!micTracks.length && displayAudio.length) return displayAudio[0];

    audioCtx ||= new (window.AudioContext || window.webkitAudioContext)();
    const dest = audioCtx.createMediaStreamDestination();
    audioCtx.createMediaStreamSource(new MediaStream(micTracks)).connect(dest);
    audioCtx.createMediaStreamSource(new MediaStream(displayAudio)).connect(dest);

    return dest.stream.getAudioTracks()[0];
  }

  /** Ať je po startu vidět, co se doopravdy chytlo — ne co bylo zaškrtnuté. */
  function reportSources(videoTrack, mic, displayAudio) {
    const surfaceNames = {
      monitor: 'celá obrazovka', window: 'okno aplikace', browser: 'karta prohlížeče',
    };
    const s = videoTrack.getSettings?.() || {};
    const parts = ['Obraz: ' + (surfaceNames[s.displaySurface] || 'sdílená plocha')];

    parts.push('Mikrofon: ' + (mic
      ? (mic.getAudioTracks()[0].label || 'zapnutý')
      : 'vypnutý'));

    if (els.wantSystemAudio.checked) {
      parts.push('Zvuk plochy: ' + (displayAudio.length
        ? 'zachycen'
        : 'nedorazil (systém ho nenabídl)'));
    }

    els.sources.textContent = parts.join(' · ');
    els.sources.hidden = false;
  }

  const tick = () => {
    const s = Math.floor((Date.now() - startedAt) / 1000);
    els.timer.textContent = `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
  };

  const stop = () => {
    if (!recorder || recorder.state === 'inactive') return;
    isRecording = false;
    els.stop.disabled = true;
    clearInterval(timerId);
    if (pipWindow) pipWindow.close(); // spustí closePip() přes 'pagehide'
    recorder.stop();
  };

  els.stop.addEventListener('click', stop);

  /* --------------------------------------- plovoucí okno se stopkami a stopem
   *
   * Chrome umí vystrčit kus stránky do vlastního okna, které zůstává nad
   * ostatními okny na ploše — hodí se, když se při nahrávání přepneš jinam
   * a nechceš pak hledat kartu s Ratatoskem, aby ses dostal k tlačítku
   * Zastavit. Přesouváme SKUTEČNÉ prvky (ne kopie), takže časovač i tlačítko
   * fungují úplně stejně jako na stránce — žádná duplicitní logika.
   *
   * Chrome tohle okno záměrně vylučuje z toho, co se nahrává (je to
   * zdokumentovaný účel API — ovládání, co zůstane vidět, ale nezanáší se
   * do záznamu).
   */
  els.popOut.addEventListener('click', async () => {
    if (pipWindow) return;
    try {
      pipWindow = await documentPictureInPicture.requestWindow({ width: 260, height: 108 });
    } catch (err) {
      say('Plovoucí okno se nepodařilo otevřít: ' + err.message, 'warn');
      return;
    }

    pipWindow.document.title = 'Ratatosk — nahrávám';
    copyStylesInto(pipWindow.document);

    const box = pipWindow.document.createElement('div');
    box.className = 'pip-box';
    pipWindow.document.body.appendChild(box);
    box.appendChild(els.timer);
    box.appendChild(els.stop);

    els.popOut.hidden = true;
    els.pipNotice.hidden = false;

    pipWindow.addEventListener('pagehide', closePip, { once: true });
  });

  function closePip() {
    if (!pipWindow) return;
    const controls = document.querySelector('.controls');
    controls.insertBefore(els.timer, els.popOut);
    controls.insertBefore(els.stop, els.popOut);
    pipWindow = null;
    els.pipNotice.hidden = true;
    // Nabídnout vystrčení znovu jen pokud nahrávání ještě běží.
    if (isRecording) els.popOut.hidden = false;
  }

  /** Zkopíruje styly appky do plovoucího okna, ať tlačítko a časovač vypadají stejně. */
  function copyStylesInto(doc) {
    [...document.styleSheets].forEach((sheet) => {
      try {
        const css = [...sheet.cssRules].map((r) => r.cssText).join('');
        const style = doc.createElement('style');
        style.textContent = css;
        doc.head.appendChild(style);
      } catch {
        if (!sheet.href) return;
        const link = doc.createElement('link');
        link.rel = 'stylesheet';
        link.href = sheet.href;
        doc.head.appendChild(link);
      }
    });
  }

  async function finish(mimeType) {
    const durationMs = Date.now() - startedAt;
    liveTracks.forEach((t) => t.stop());
    liveTracks = [];
    els.preview.srcObject = null;

    const blob = new Blob(chunks, { type: mimeType || 'video/webm' });
    chunks = [];

    // MediaRecorder umí vrátit prázdno (zdroj nedodal jediný snímek, zastavení
    // hned po startu). Bez téhle pojistky by se do R2 uložil nulový soubor,
    // uživatel by dostal odkaz a teprve worker by o hodinu později zjistil,
    // že to není video.
    if (blob.size === 0) {
      say('Nahrávka je prázdná — zdroj nedodal žádná data. Zkus to prosím znovu.', 'error');
      els.start.disabled = false;
      els.sources.hidden = true;
      return;
    }

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
      els.start.disabled = false;
    }
  }

  /* --------------------------------------------------------------- upload */

  async function createRecording() {
    const res = await fetch('/api/recordings', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF },
      body: JSON.stringify({ title: els.title.value }),
    });
    if (!res.ok) throw new Error('server odmítl založit záznam (' + res.status + ')');
    return res.json();
  }

  // XHR kvůli progressu — fetch upload progress zatím není všude.
  function uploadBlob(url, blob) {
    return new Promise((resolve, reject) => {
      els.progressWrap.hidden = false;
      const xhr = new XMLHttpRequest();
      xhr.open('PUT', url, true);
      xhr.setRequestHeader('Content-Type', 'video/webm');
      xhr.upload.onprogress = (e) => {
        if (!e.lengthComputable) return;
        const pct = Math.round((e.loaded / e.total) * 100);
        els.progressBar.style.width = pct + '%';
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
    els.progressWrap.hidden = true;
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
    els.state.after(a);
  }

  /* ----------------------------------------------------------------- start */

  if (!navigator.mediaDevices?.getDisplayMedia) {
    els.start.disabled = true;
    say('Tenhle prohlížeč neumí zachytit obrazovku. Zkus Chrome nebo Firefox přes HTTPS.', 'error');
  } else {
    listMics();
    navigator.mediaDevices.addEventListener?.('devicechange', () => listMics(els.micSelect.value));

    // Když už permission jednou padla, seznam se dá naplnit bez ptaní.
    navigator.permissions?.query({ name: 'microphone' })
      .then((p) => {
        if (p.state === 'granted') listMics();
        p.onchange = () => listMics(els.micSelect.value);
      })
      .catch(() => { /* Firefox tenhle dotaz neumí — zůstane tlačítko */ });
  }
})();

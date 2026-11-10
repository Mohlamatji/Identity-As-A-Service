/**
 * Identity Vault browser SDK - drop-in liveness + identity verification.
 *
 *   const result = await IdentityVault.verify({
 *     clientToken,            // short-lived ivt_ token minted by YOUR backend via POST /api/client-token
 *     userId,                 // omit for enrollment
 *     amountCents, payee,     // optional: bind the verification to one payment
 *     video: document.getElementById('video'),
 *     onPrompt: ({label, index, total}) => ...,
 *     enroll: { id_number, full_name, dob, phone },   // only when enrolling
 *   });
 *   // -> { ok, status, verificationToken, liveness, vault_match, userId, message }
 *
 * The SERVER picks the liveness challenge and decides pass/fail. This file
 * only performs the actions and reports measurements. It never sends key
 * contents - behaviour capture records timing numbers only.
 */
(function () {
  const MODEL_URL = 'https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@master/weights';
  const LABELS = {
    blink: 'Blink',
    turn_left: 'Turn your head toward the LEFT edge of the screen',
    turn_right: 'Turn your head toward the RIGHT edge of the screen',
    open_mouth: 'Open your mouth',
  };
  let modelsPromise = null;

  function loadModels() {
    if (modelsPromise) return modelsPromise;
    modelsPromise = (async () => {
      if (typeof faceapi === 'undefined') throw new Error('face-api.js did not load');
      await faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL);
      await faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL);
      await faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL);
    })();
    return modelsPromise;
  }

  async function startCamera(video) {
    const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' } });
    video.srcObject = stream;
    await new Promise((r) => { video.onloadedmetadata = r; });
    await video.play().catch(() => {});
    return stream;
  }

  // ---- landmark measurements ------------------------------------------
  const dist = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
  function ear(eye) { const h = dist(eye[0], eye[3]); return h === 0 ? 1 : (dist(eye[1], eye[5]) + dist(eye[2], eye[4])) / (2 * h); }
  function mar(mouth) { const h = dist(mouth[12], mouth[16]); return h === 0 ? 0 : dist(mouth[14], mouth[18]) / h; }
  function yawRatio(pts) { // 0..1 position of nose tip between jaw edges; ~0.5 when facing camera
    const jaw = pts.getJawOutline(); const nose = pts.positions[30];
    const w = jaw[16].x - jaw[0].x; return w === 0 ? 0.5 : (nose.x - jaw[0].x) / w;
  }
  const median = (a) => { const s = [...a].sort((x, y) => x - y); return s[Math.floor(s.length / 2)]; };

  async function api(base, path, token, body, method = 'POST') {
    const res = await fetch(base + path, {
      method,
      headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token },
      body: method === 'GET' ? undefined : JSON.stringify(body),
    });
    return { status: res.status, data: await res.json().catch(() => ({})) };
  }

  /** Performs the server's challenge; returns evidence + a still frame. */
  async function runChallenge(video, canvas, challenge, onPrompt) {
    const opts = new faceapi.TinyFaceDetectorOptions();
    const t0 = performance.now();
    const actions = [], descriptors = [];
    let frames = 0, still = null;

    const grab = () => {
      canvas.width = video.videoWidth; canvas.height = video.videoHeight;
      canvas.getContext('2d').drawImage(video, 0, 0);
      return canvas.toDataURL('image/jpeg', 0.8).split(',')[1];
    };
    const sample = async () => {
      const r = await faceapi.detectSingleFace(video, opts).withFaceLandmarks().withFaceDescriptor();
      if (r) { descriptors.push(Array.from(r.descriptor)); still = grab(); }
    };

    // Baseline: 6 frames of neutral face so thresholds adapt to this person/camera.
    const base = { ear: [], mar: [], yaw: [] };
    onPrompt && onPrompt({ action: 'baseline', label: 'Look straight at the camera', index: 0, total: challenge.actions.length });
    while (base.ear.length < 6 && performance.now() - t0 < 8000) {
      const r = await faceapi.detectSingleFace(video, opts).withFaceLandmarks();
      if (r) {
        frames++;
        base.ear.push((ear(r.landmarks.getLeftEye()) + ear(r.landmarks.getRightEye())) / 2);
        base.mar.push(mar(r.landmarks.getMouth())); base.yaw.push(yawRatio(r.landmarks));
      }
      await new Promise((r2) => setTimeout(r2, 100));
    }
    if (base.ear.length < 6) throw new Error('No face detected - check lighting and camera position.');
    const b = { ear: median(base.ear), mar: median(base.mar), yaw: median(base.yaw) };
    await sample();

    for (let i = 0; i < challenge.actions.length; i++) {
      const action = challenge.actions[i];
      onPrompt && onPrompt({ action, label: LABELS[action], index: i + 1, total: challenge.actions.length });
      const deadline = performance.now() + 9000;
      let done = false, closed = false;
      while (!done && performance.now() < deadline) {
        const r = await faceapi.detectSingleFace(video, opts).withFaceLandmarks();
        if (r) {
          frames++;
          const L = r.landmarks;
          if (action === 'blink') {
            const e = (ear(L.getLeftEye()) + ear(L.getRightEye())) / 2;
            if (e < b.ear * 0.72) closed = true; else if (closed && e > b.ear * 0.88) done = true;
          } else if (action === 'turn_left') { done = yawRatio(L) < b.yaw - 0.13; }
          else if (action === 'turn_right') { done = yawRatio(L) > b.yaw + 0.13; }
          else if (action === 'open_mouth') { done = mar(L.getMouth()) > Math.max(0.3, b.mar + 0.22); }
        }
        await new Promise((r2) => setTimeout(r2, 80));
      }
      actions.push({ action, completed: done, t_ms: Math.round(performance.now() - t0) });
      if (!done) break;
      await sample();
    }
    return { actions, descriptors, frames_analyzed: frames, duration_ms: Math.round(performance.now() - t0), image_base64: still || grab() };
  }

  // ---- behavioural biometrics (timing only, never key values) -----------
  const behavior = {
    _s: null,
    attach(root = document) {
      const s = this._s = { start: performance.now(), keyTimes: [], pointer: 0, paste: 0, events: 0 };
      root.addEventListener('keydown', () => { s.keyTimes.push(performance.now()); s.events++; }, true);
      root.addEventListener('pointermove', () => { s.pointer++; s.events++; }, true);
      root.addEventListener('pointerdown', () => { s.events++; }, true);
      root.addEventListener('paste', () => { s.paste++; s.events++; }, true);
    },
    snapshot() {
      const s = this._s; if (!s) return null;
      const gaps = []; for (let i = 1; i < s.keyTimes.length; i++) { const g = s.keyTimes[i] - s.keyTimes[i - 1]; if (g < 2000) gaps.push(g); }
      const mean = gaps.length ? gaps.reduce((a, c) => a + c, 0) / gaps.length : 0;
      const std = gaps.length ? Math.sqrt(gaps.reduce((a, c) => a + (c - mean) ** 2, 0) / gaps.length) : 0;
      return { key_count: gaps.length + 1, key_interval_mean_ms: Math.round(mean), key_interval_std_ms: Math.round(std * 10) / 10,
        pointer_events: s.pointer, paste_count: s.paste, interaction_events: s.events, completion_time_ms: Math.round(performance.now() - s.start) };
    },
  };

  function deviceFingerprint() {
    const raw = [navigator.userAgent, navigator.language, screen.width + 'x' + screen.height, screen.colorDepth, Intl.DateTimeFormat().resolvedOptions().timeZone].join('|');
    let h = 0; for (let i = 0; i < raw.length; i++) h = (h * 31 + raw.charCodeAt(i)) | 0;
    return 'dev-' + (h >>> 0).toString(16);
  }

  async function verify(o) {
    const base = o.baseUrl || '';
    const video = o.video, canvas = o.canvas || document.createElement('canvas');
    const ch = await api(base, 'api/verify/challenge', o.clientToken, { user_id: o.userId, amount_cents: o.amountCents, payee: o.payee });
    if (ch.status !== 200) return { ok: false, message: ch.data.message || 'Could not start verification' };

    let evidence;
    try {
      await loadModels();
      if (!video.videoWidth) throw new Error('Camera is not running');
      evidence = await runChallenge(video, canvas, ch.data, o.onPrompt);
    } catch (err) {
      if (!ch.data.simulated_allowed) return { ok: false, message: 'Liveness unavailable: ' + err.message };
      // DEMO FALLBACK (server allowed it): no camera/models. Weak and clearly labelled.
      evidence = { mode: 'simulated', actions: [], descriptors: [], frames_analyzed: 0, duration_ms: 0, image_base64: btoa('demo-frame') };
    }

    const body = Object.assign({}, evidence, o.enroll || {}, {
      challenge_id: ch.data.challenge_id, user_id: o.userId, behavior: behavior.snapshot(), device_fingerprint: deviceFingerprint(),
    });
    const res = await api(base, o.enroll ? 'api/enroll' : 'api/verify/session', o.clientToken, body);
    const d = res.data;
    return {
      ok: d.status === 'approved' || d.status === 'enrolled', status: d.status, message: d.message, userId: d.user_id || o.userId,
      verificationToken: d.verification_token || null, image: evidence.image_base64, liveness: d.liveness, vault_match: d.vault_match, raw: d,
    };
  }

  window.IdentityVault = { verify, startCamera, loadModels, behavior, deviceFingerprint };
})();

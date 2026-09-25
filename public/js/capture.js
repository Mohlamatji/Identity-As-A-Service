// Shared state for the test console
window.__vault = {
  userId: null,
  livenessResult: null,
  vaultMatchResult: null,
  lastTransactionId: null,
  imageBase64: null,
};

// Model weights hosted alongside face-api.js itself - loaded lazily, once,
// the first time a capture is attempted (not on page load, so the console
// still works instantly if the CDN is slow/unreachable and falls back).
const FACE_API_MODEL_URL = 'https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@master/weights';
let modelsReadyPromise = null;

function setStatus(msg) {
  const el = document.getElementById('liveness_status');
  if (el) el.textContent = msg;
}

function loadModels() {
  if (modelsReadyPromise) return modelsReadyPromise;
  modelsReadyPromise = (async () => {
    if (typeof faceapi === 'undefined') {
      throw new Error('face-api.js did not load');
    }
    await faceapi.nets.tinyFaceDetector.loadFromUri(FACE_API_MODEL_URL);
    await faceapi.nets.faceLandmark68Net.loadFromUri(FACE_API_MODEL_URL);
    await faceapi.nets.faceRecognitionNet.loadFromUri(FACE_API_MODEL_URL);
  })();
  return modelsReadyPromise;
}

async function startCamera() {
  const video = document.getElementById('video');
  try {
    const stream = await navigator.mediaDevices.getUserMedia({ video: true });
    video.srcObject = stream;
    await new Promise((resolve) => { video.onloadedmetadata = resolve; });
    setStatus('Camera started. Loading face-landmark model in the background...');
    loadModels().then(() => setStatus('Ready. Click Capture to run a real blink + head-turn check.'))
      .catch(() => setStatus('Face-landmark model failed to load - captures will fall back to a simulated liveness signal.'));
  } catch (err) {
    setStatus('Camera access failed (expected if testing headlessly): ' + err.message);
  }
}

function grabFrameBase64Fallback() {
  const video = document.getElementById('video');
  const canvas = document.getElementById('canvas');
  canvas.width = video.videoWidth || 320;
  canvas.height = video.videoHeight || 240;
  const ctx = canvas.getContext('2d');
  if (video.videoWidth) {
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
    return canvas.toDataURL('image/jpeg').split(',')[1];
  }
  // No camera available in this environment at all.
  return btoa('placeholder-frame-' + Date.now());
}

function eyeAspectRatio(eye) {
  const dist = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
  const vertical = dist(eye[1], eye[5]) + dist(eye[2], eye[4]);
  const horizontal = dist(eye[0], eye[3]);
  return horizontal === 0 ? 1 : vertical / (2 * horizontal);
}

/**
 * Runs a short real-time analysis window over the live video: tracks eye
 * landmarks to detect a blink (eye-aspect-ratio dipping then recovering)
 * and tracks the face box's horizontal position to detect a head turn.
 * Falls back to a simulated result if face-api.js/models aren't available
 * or no face gets detected at all - keeps the console usable offline.
 */
async function runLivenessCapture(durationMs = 3000) {
  const video = document.getElementById('video');
  const videoWrap = document.getElementById('video_wrap');
  if (videoWrap) videoWrap.classList.add('scanning');

  try {
    await loadModels();
  } catch {
    setStatus('No face-landmark model available - using simulated liveness.');
    if (videoWrap) videoWrap.classList.remove('scanning');
    return {
      imageBase64: grabFrameBase64Fallback(),
      livenessMeta: { mode: 'simulated', gesture_completed: true, frame_count: 15, duration_ms: 2200 },
      descriptor: null,
    };
  }

  if (!video.videoWidth) {
    setStatus('No camera feed - using simulated liveness.');
    if (videoWrap) videoWrap.classList.remove('scanning');
    return {
      imageBase64: grabFrameBase64Fallback(),
      livenessMeta: { mode: 'simulated', gesture_completed: true, frame_count: 15, duration_ms: 2200 },
      descriptor: null,
    };
  }

  setStatus('Analyzing... please blink and turn your head slightly.');
  const detectorOptions = new faceapi.TinyFaceDetectorOptions();
  const start = performance.now();
  let framesAnalyzed = 0;
  let wasEyesClosed = false;
  let blinkDetected = false;
  let minCenterX = null;
  let maxCenterX = null;
  let lastImageBase64 = null;
  const canvas = document.getElementById('canvas');

  while (performance.now() - start < durationMs) {
    const result = await faceapi.detectSingleFace(video, detectorOptions).withFaceLandmarks();
    if (result) {
      framesAnalyzed++;
      const ear = (eyeAspectRatio(result.landmarks.getLeftEye()) + eyeAspectRatio(result.landmarks.getRightEye())) / 2;
      if (ear < 0.22) {
        wasEyesClosed = true;
      } else if (wasEyesClosed && ear > 0.25) {
        blinkDetected = true;
        wasEyesClosed = false;
      }

      const box = result.detection.box;
      const centerX = box.x + box.width / 2;
      minCenterX = minCenterX === null ? centerX : Math.min(minCenterX, centerX);
      maxCenterX = maxCenterX === null ? centerX : Math.max(maxCenterX, centerX);

      canvas.width = video.videoWidth;
      canvas.height = video.videoHeight;
      canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
      lastImageBase64 = canvas.toDataURL('image/jpeg').split(',')[1];
    }
    await new Promise((r) => setTimeout(r, 120));
  }

  const durationActual = Math.round(performance.now() - start);

  if (framesAnalyzed === 0 || !lastImageBase64) {
    setStatus('No face detected during capture - using simulated liveness.');
    if (videoWrap) videoWrap.classList.remove('scanning');
    return {
      imageBase64: grabFrameBase64Fallback(),
      livenessMeta: { mode: 'simulated', gesture_completed: true, frame_count: 15, duration_ms: 2200 },
      descriptor: null,
    };
  }

  // Lateral travel of the face box center, in pixels - a real head turn
  // moves this well beyond ordinary hand-shake/frame noise.
  const headTurnDetected = (maxCenterX - minCenterX) > 18;

  // One extra pass to extract the actual face-recognition embedding - kept
  // separate from the per-frame loop above since descriptor extraction is
  // heavier than landmark detection alone, so it's only worth paying for
  // once, on a frame we already know has a good face detection.
  let descriptor = null;
  try {
    const descResult = await faceapi.detectSingleFace(video, detectorOptions).withFaceLandmarks().withFaceDescriptor();
    if (descResult) {
      descriptor = Array.from(descResult.descriptor);
    }
  } catch {
    // Leave descriptor null - server falls back to the hash-stub template.
  }

  setStatus(`Done - blink: ${blinkDetected ? 'yes' : 'no'}, head turn: ${headTurnDetected ? 'yes' : 'no'}, `
    + `descriptor: ${descriptor ? 'captured' : 'unavailable'} (${framesAnalyzed} frames analyzed)`);
  if (videoWrap) videoWrap.classList.remove('scanning');

  return {
    imageBase64: lastImageBase64,
    livenessMeta: {
      mode: 'real',
      blink_detected: blinkDetected,
      head_turn_detected: headTurnDetected,
      frames_analyzed: framesAnalyzed,
      duration_ms: durationActual,
    },
    descriptor,
  };
}

async function enrollUser() {
  setStatus('Starting capture...');
  const { imageBase64, livenessMeta, descriptor } = await runLivenessCapture();
  window.__vault.imageBase64 = imageBase64;

  let data;
  try {
    const res = await fetch('api/enroll', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Api-Key': window.__API_KEY__ || '' },
      body: JSON.stringify({
        id_number: document.getElementById('id_number').value,
        full_name: document.getElementById('full_name').value,
        dob: document.getElementById('dob').value,
        phone: document.getElementById('phone').value,
        image_base64: imageBase64,
        descriptor,
        ...livenessMeta,
      }),
    });
    data = await res.json();
  } catch (err) {
    renderErrorCard('enroll_result', err.message);
    return;
  }

  window.__vault.userId = data.user_id;
  window.__vault.livenessResult = data.liveness;
  window.__vault.vaultMatchResult = data.vault_match;

  if (data.status === 'enrolled') {
    renderResultCard('enroll_result', {
      tone: 'success',
      title: 'User enrolled',
      detail: data.message,
      fields: [
        ['User ID', data.user_id],
        ['Liveness', `${data.liveness.passed ? 'passed' : 'failed'} (${data.liveness.mode})`],
        ['Vault match', `${(data.vault_match.confidence * 100).toFixed(1)}%`],
      ],
      raw: data,
    });
    Wizard.markComplete('enroll');
    Wizard.next('enroll');
  } else {
    renderResultCard('enroll_result', { tone: 'danger', title: 'Enrollment failed', detail: data.message, raw: data });
  }
}

async function capturePhoto() {
  setStatus('Starting capture...');
  const { imageBase64, livenessMeta, descriptor } = await runLivenessCapture();
  window.__vault.imageBase64 = imageBase64;
  window.__vault.pendingLivenessMeta = livenessMeta;
  window.__vault.pendingDescriptor = descriptor;
  renderResultCard('authenticate_result', { tone: 'neutral', title: 'Frame captured', detail: 'Click Authenticate to verify it.' });
}

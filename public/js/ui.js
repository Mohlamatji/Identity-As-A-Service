// Shared UI primitives for the wizard flow: icons, result cards, and step
// state. Kept separate from capture.js/decision-status.js since those own
// the API calls, not the presentation.

const ICONS = {
  check: '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.5"/><path d="M8 12.5l2.5 2.5L16 9.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg>',
  cross: '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.5"/><path d="M9 9l6 6M15 9l-6 6" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/></svg>',
  alert: '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 3.5l9.5 16.5H2.5L12 3.5z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M12 10v4.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/><circle cx="12" cy="17.2" r="0.9" fill="currentColor"/></svg>',
  user: '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="8" r="3.5" stroke="currentColor" stroke-width="1.5"/><path d="M4.5 20c1.4-4 4.2-6 7.5-6s6.1 2 7.5 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
  shield: '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 3l7 3v5.5c0 4.5-3 7.8-7 9.5-4-1.7-7-5-7-9.5V6l7-3z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>',
  face: '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="4" y="4" width="16" height="16" rx="4" stroke="currentColor" stroke-width="1.5"/><path d="M8 10v1.2M16 10v1.2" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/><path d="M8.5 15c1 .9 2.2 1.3 3.5 1.3s2.5-.4 3.5-1.3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
  card: '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3" y="6" width="18" height="12.5" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M3 10.5h18" stroke="currentColor" stroke-width="1.5"/><path d="M6.5 15h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
  radar: '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.5"/><circle cx="12" cy="12" r="5" stroke="currentColor" stroke-width="1.5"/><circle cx="12" cy="12" r="1.2" fill="currentColor"/><path d="M12 3v2.3M21 12h-2.3M12 21v-2.3M3 12h2.3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
  bell: '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M6 10.5a6 6 0 0112 0c0 3.6 1 5 2 6H4c1-1 2-2.4 2-6z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M10 19a2 2 0 004 0" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
};

function icon(name, cls = 'w-5 h-5') {
  return `<span class="${cls} inline-block">${ICONS[name] || ''}</span>`;
}

/**
 * Renders a human-readable outcome card into containerId, with the raw
 * response tucked behind a toggle rather than shown by default.
 * opts: { tone: 'success'|'danger'|'warn'|'neutral', title, detail, fields: [[label,value]], raw }
 */
function renderResultCard(containerId, opts) {
  const el = document.getElementById(containerId);
  if (!el) return;

  const toneMap = {
    success: { icon: 'check', text: 'text-teal', bg: 'bg-teal/10', border: 'border-teal/30' },
    danger: { icon: 'cross', text: 'text-danger', bg: 'bg-danger/10', border: 'border-danger/30' },
    warn: { icon: 'alert', text: 'text-gold', bg: 'bg-gold/10', border: 'border-gold/30' },
    neutral: { icon: 'shield', text: 'text-mute', bg: 'bg-white/5', border: 'border-line' },
  };
  const t = toneMap[opts.tone] || toneMap.neutral;

  const fieldsHtml = (opts.fields || []).map(([label, value]) => `
    <div>
      <div class="text-[11px] text-mute">${label}</div>
      <div class="text-sm text-slate-200 mono">${value}</div>
    </div>
  `).join('');

  const rawId = containerId + '_raw';
  el.innerHTML = `
    <div class="rounded-lg border ${t.border} ${t.bg} p-4">
      <div class="flex items-start gap-3">
        <div class="${t.text} shrink-0 mt-0.5">${icon(t.icon, 'w-6 h-6')}</div>
        <div class="flex-1 min-w-0">
          <div class="text-sm font-medium text-white">${opts.title}</div>
          ${opts.detail ? `<div class="text-sm text-mute mt-0.5">${opts.detail}</div>` : ''}
          ${fieldsHtml ? `<div class="grid grid-cols-2 sm:grid-cols-3 gap-3 mt-3">${fieldsHtml}</div>` : ''}
        </div>
      </div>
      ${opts.raw ? `
        <button onclick="document.getElementById('${rawId}').classList.toggle('hidden')" class="text-xs text-mute hover:text-white mt-3 underline underline-offset-2">Show raw response</button>
        <pre id="${rawId}" class="hidden mt-2 text-xs bg-ink border border-line rounded p-3 overflow-x-auto text-slate-400">${JSON.stringify(opts.raw, null, 2)}</pre>
      ` : ''}
    </div>
  `;
}

function renderErrorCard(containerId, message) {
  renderResultCard(containerId, { tone: 'danger', title: 'Request failed', detail: message });
}

/**
 * Minimal step-wizard controller: tracks which step is expanded, updates
 * the stepper header, and marks steps complete/active/upcoming. Free
 * navigation is intentional - this is still a live API test harness, not
 * a locked consumer flow, so clicking any step jumps to it.
 */
const Wizard = {
  steps: ['enroll', 'consent', 'authenticate', 'approve', 'fraud', 'webhook'],
  completed: new Set(),

  init() {
    this.steps.forEach((id) => {
      const dot = document.getElementById(`stepdot_${id}`);
      if (dot) dot.addEventListener('click', () => this.goTo(id));
    });
    this.goTo('enroll');
  },

  goTo(stepId) {
    this.steps.forEach((id) => {
      const panel = document.getElementById(`panel_${id}`);
      const dot = document.getElementById(`stepdot_${id}`);
      const line = document.getElementById(`stepline_${id}`);
      const isActive = id === stepId;
      const isDone = this.completed.has(id);

      if (panel) panel.classList.toggle('hidden', !isActive);

      if (dot) {
        dot.classList.remove('bg-gold', 'text-ink', 'bg-teal', 'border-line', 'text-mute', 'bg-ink');
        if (isActive) {
          dot.classList.add('bg-gold', 'text-ink');
        } else if (isDone) {
          dot.classList.add('bg-teal', 'text-ink');
        } else {
          dot.classList.add('bg-ink', 'text-mute', 'border-line');
        }
      }
      if (line) line.classList.toggle('bg-teal', this.completed.has(id));
    });
  },

  markComplete(stepId) {
    this.completed.add(stepId);
    this.goTo(stepId);
  },

  next(afterStepId) {
    const idx = this.steps.indexOf(afterStepId);
    if (idx >= 0 && idx < this.steps.length - 1) {
      this.goTo(this.steps[idx + 1]);
    }
  },
};

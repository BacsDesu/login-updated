/* ════════════════════════════════════════════════════════
   SmartPOP Admin — admin.js
   Handles: sidebar, section nav, vetting, analytics charts,
   comm hub, revenue ledger, form builder
════════════════════════════════════════════════════════ */
'use strict';

const $ = (s, ctx = document) => ctx.querySelector(s);
const $$ = (s, ctx = document) => [...ctx.querySelectorAll(s)];

// ══════════════════════════════════════════════════════
//  CLOCK
// ══════════════════════════════════════════════════════
function updateClock() {
    const el = $('#adminClock');
    if (!el) return;
    const now  = new Date();
    const hh   = String(now.getHours()).padStart(2,'0');
    const mm   = String(now.getMinutes()).padStart(2,'0');
    const ss   = String(now.getSeconds()).padStart(2,'0');
    el.textContent = `${hh}:${mm}:${ss}`;
}
setInterval(updateClock, 1000);
updateClock();

// ══════════════════════════════════════════════════════
//  SIDEBAR TOGGLE
// ══════════════════════════════════════════════════════
let sidebarOpen = window.innerWidth > 768;

function toggleSidebar() {
    const sb = $('#adminSidebar');
    sidebarOpen = !sidebarOpen;
    sb.classList.toggle('open', sidebarOpen);
}

document.addEventListener('click', e => {
    if (window.innerWidth > 768) return;
    const sb  = $('#adminSidebar');
    const btn = $('#sidebarToggle');
    if (sidebarOpen && !sb.contains(e.target) && !btn.contains(e.target)) {
        sidebarOpen = false;
        sb.classList.remove('open');
    }
});

// ══════════════════════════════════════════════════════
//  SECTION NAVIGATION
// ══════════════════════════════════════════════════════
const SECTION_TITLES = {
    vetting:     'Vetting Queue',
    analytics:   'Event Analytics',
    comms:       'Communication Hub',
    revenue:     'Revenue & Fees',
    formbuilder: 'Form Builder',
};

function switchSection(name, navEl) {
    // Deactivate all
    $$('.admin-section').forEach(s => s.classList.remove('active'));
    $$('.anav-item').forEach(n => n.classList.remove('active'));

    // Activate target
    $(`#sec-${name}`)?.classList.add('active');
    navEl?.classList.add('active');

    // Update topbar title
    const titleEl = $('#topbarTitle');
    if (titleEl) titleEl.textContent = SECTION_TITLES[name] || name;

    // Trigger section-specific init
    if (name === 'analytics') initAnalytics();
    if (name === 'revenue')   initRevenue();

    // Close sidebar on mobile
    if (window.innerWidth <= 768) {
        sidebarOpen = false;
        $('#adminSidebar')?.classList.remove('open');
    }
}

// ══════════════════════════════════════════════════════
//  TOAST
// ══════════════════════════════════════════════════════
function toast(msg, icon = '⬡', type = 'default') {
    const el = $('#adminToast');
    if (!el) return;
    const colors = { success:'#22d47a', error:'#ff4757', warn:'#f5a623', default:'#e8eaf0' };
    el.innerHTML = `<span style="font-size:16px">${icon}</span><span>${msg}</span>`;
    el.style.borderLeftColor = colors[type] || colors.default;
    el.style.borderLeft = `3px solid ${colors[type] || colors.default}`;
    el.classList.add('show');
    clearTimeout(el._t);
    el._t = setTimeout(() => el.classList.remove('show'), 3400);
}

// ══════════════════════════════════════════════════════
//  VETTING QUEUE
// ══════════════════════════════════════════════════════
let currentVetAction = null;
let currentVetCard   = null;

function vetAction(appId, action, btnEl) {
    currentVetAction = { appId, action };
    currentVetCard   = btnEl.closest('.app-card');

    const modal     = $('#vetModal');
    const vmTitle   = $('#vmTitle');
    const vmDesc    = $('#vmDesc');
    const vmConfirm = $('#vmConfirm');
    const vmNote    = $('#vmNote');

    vmNote.value = '';

    const configs = {
        approve: {
            title:   '✓ Approve Application',
            desc:    `Approve this application? The vendor will be notified and their permit will be issued.`,
            cls:     'confirm-approve',
            btnText: 'Approve',
        },
        reject: {
            title:   '✕ Reject Application',
            desc:    `Reject this application? The vendor will be notified with your reason.`,
            cls:     'confirm-reject',
            btnText: 'Reject',
        },
        info: {
            title:   'ℹ Request More Info',
            desc:    `Request additional information from the vendor. They will receive an email prompt.`,
            cls:     '',
            btnText: 'Send Request',
        },
    };

    const cfg = configs[action];
    vmTitle.textContent   = cfg.title;
    vmDesc.textContent    = cfg.desc;
    vmConfirm.className   = `vm-confirm ${cfg.cls}`;
    vmConfirm.textContent = cfg.btnText;

    vmConfirm.onclick = () => confirmVetAction();
    modal.classList.add('open');
}

function confirmVetAction() {
    if (!currentVetAction || !currentVetCard) return;

    const { appId, action } = currentVetAction;
    const actionLabels = { approve:'Approved', reject:'Rejected', info:'Info Requested' };
    const actionIcons  = { approve:'✅', reject:'❌', info:'📧' };
    const note = $('#vmNote')?.value?.trim() || '';

    // Disable confirm button while saving
    const vmConfirm = $('#vmConfirm');
    if (vmConfirm) { vmConfirm.disabled = true; vmConfirm.textContent = 'Saving…'; }

    // ── POST to process_admin.php ──────────────────────
    const fd = new FormData();
    fd.append('action',   'vet_application');
    fd.append('app_id',   appId);
    fd.append('decision', action);
    fd.append('note',     note);

    fetch('process_admin.php', {
        method:  'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body:    fd,
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Remove card from vetting grid
            currentVetCard.classList.add('resolved');
            setTimeout(() => currentVetCard.remove(), 400);

            // Decrement badge count
            const badge = $('#pendingCount');
            if (badge) {
                const cur = parseInt(badge.textContent) - 1;
                badge.textContent = Math.max(0, cur);
            }

            // Update subtitle
            const sub = $('#vettingSubtitle');
            if (sub) {
                const n = Math.max(0, parseInt(sub.textContent) - 1);
                sub.textContent = `${n} application${n !== 1 ? 's' : ''} awaiting review`;
            }

            closeVetModal();
            toast(actionLabels[action], actionIcons[action],
                  action === 'approve' ? 'success' : action === 'reject' ? 'error' : 'warn');
        } else {
            if (vmConfirm) { vmConfirm.disabled = false; vmConfirm.textContent = 'Try Again'; }
            toast(data.message || 'Action failed. Please try again.', '✕', 'error');
        }
    })
    .catch(() => {
        if (vmConfirm) { vmConfirm.disabled = false; vmConfirm.textContent = 'Try Again'; }
        toast('Network error. Please try again.', '✕', 'error');
    });
}

function closeVetModal(e) {
    if (e && e.target !== $('#vetModal')) return;
    $('#vetModal')?.classList.remove('open');
}

// ── Filter ──
function filterVetting(val) {
    const search = (typeof val === 'string' ? val : $('#vettingSearch')?.value || '').toLowerCase();
    const type   = $('#typeFilter')?.value?.toLowerCase() || '';

    $$('.app-card').forEach(card => {
        const matchSearch = !search ||
            card.dataset.vendor.includes(search) ||
            card.dataset.stall.includes(search)  ||
            card.dataset.event.includes(search);
        const matchType = !type || card.dataset.type.toLowerCase() === type;
        card.style.display = (matchSearch && matchType) ? '' : 'none';
    });
}

// ══════════════════════════════════════════════════════
//  ANALYTICS
// ══════════════════════════════════════════════════════
let analyticsInited = false;

function initAnalytics() {
    if (analyticsInited) return;
    analyticsInited = true;

    // Small delay to allow section to render
    setTimeout(() => {
        drawDonut();
        drawBarChart();
        animateMiniBar();
    }, 100);
}

const PIE_COLORS = {
    Food:     '#fb923c',
    Arts:     '#f472b6',
    Crafts:   '#a78bfa',
    Clothing: '#4a9eff',
    Produce:  '#22d47a',
    Services: '#f5a623',
    Others:   '#8891a4',
};

function drawDonut() {
    const canvas = $('#donutChart');
    if (!canvas) return;
    const data    = ANALYTICS_DATA.product_mix;
    const entries = Object.entries(data);
    const total   = Object.values(data).reduce((a,b) => a+b, 0);

    const dpr = window.devicePixelRatio || 1;
    const W   = 200; const H = 200;
    canvas.width  = W * dpr; canvas.height = H * dpr;
    canvas.style.width  = W + 'px'; canvas.style.height = H + 'px';

    const ctx = canvas.getContext('2d');
    ctx.scale(dpr, dpr);

    const cx = W/2, cy = H/2, r = 80, inner = 52;
    let startAngle = -Math.PI / 2;

    // Animate counter
    animateNum('#donutTotal', 0, total, 1200);

    // Draw segments with animation
    let drawn = 0;
    const segDuration = 800;
    const segStart    = performance.now();

    function drawFrame(ts) {
        const prog = Math.min((ts - segStart) / segDuration, 1);
        const ease = 1 - Math.pow(1 - prog, 3);
        ctx.clearRect(0, 0, W, H);

        let angle = startAngle;
        entries.forEach(([label, val]) => {
            const slice = (val / total) * 2 * Math.PI * ease;
            ctx.beginPath();
            ctx.moveTo(cx, cy);
            ctx.arc(cx, cy, r, angle, angle + slice);
            ctx.closePath();
            ctx.fillStyle = PIE_COLORS[label] || '#8891a4';
            ctx.fill();
            angle += slice;
        });

        // Donut hole
        ctx.beginPath();
        ctx.arc(cx, cy, inner, 0, Math.PI * 2);
        ctx.fillStyle = '#141820';
        ctx.fill();

        if (prog < 1) requestAnimationFrame(drawFrame);
    }
    requestAnimationFrame(drawFrame);

    // Legend
    const legendEl = $('#donutLegend');
    if (legendEl) {
        legendEl.innerHTML = entries.map(([label, val]) => `
            <div class="legend-item">
                <div class="legend-dot" style="background:${PIE_COLORS[label]||'#888'}"></div>
                <span>${label}</span>
                <span class="legend-pct">${val}%</span>
            </div>`).join('');
    }
}

function drawBarChart() {
    const wrap = $('#barChartWrap');
    if (!wrap) return;
    const events = ANALYTICS_DATA.events;

    wrap.innerHTML = events.map(ev => {
        const fillPct = Math.round(ev.applications / ev.capacity * 100);
        const capPct  = 100;
        return `
        <div class="bc-row">
            <div class="bc-label" title="${ev.name}">${ev.name}</div>
            <div class="bc-track">
                <div class="bc-fill" data-val="${fillPct}" style="width:0"></div>
            </div>
            <div class="bc-info">${ev.applications} / ${ev.capacity} stalls</div>
        </div>`;
    }).join('');

    requestAnimationFrame(() => {
        $$('.bc-fill').forEach(el => {
            el.style.width = el.dataset.val + '%';
        });
    });
}

function animateMiniBar() {
    $$('.mbar-fill').forEach(el => {
        setTimeout(() => {
            el.style.width = el.dataset.val + '%';
        }, 200);
    });
}

// ══════════════════════════════════════════════════════
//  COMM HUB
// ══════════════════════════════════════════════════════
let activeChannel   = 'email';
let activeRecipient = 'all';

function setChannel(ch, btn) {
    activeChannel = ch;
    $$('.ch-btn').forEach(b => b.classList.remove('active'));
    btn?.classList.add('active');

    const subjectGroup = $('#subjectGroup');
    if (subjectGroup) {
        subjectGroup.style.display = ch === 'sms' ? 'none' : '';
    }

    // Update char limit indicator
    const counter = $('#charCounter');
    if (counter && ch === 'sms') {
        counter.textContent = (document.getElementById('msgBody')?.value.length || 0) + ' / 160';
    }
}

function toggleChip(chip) {
    $$('.chip').forEach(c => c.classList.remove('chip-active'));
    chip.classList.add('chip-active');
    activeRecipient = chip.dataset.group;
}

function updateCharCount(ta) {
    const counter = $('#charCounter');
    if (!counter) return;
    const len  = ta.value.length;
    const max  = activeChannel === 'sms' ? 160 : 5000;
    counter.textContent = `${len} / ${max}`;
    counter.style.color = len > max * 0.9 ? (len >= max ? '#ff4757' : '#f5a623') : '';
}

const TEMPLATES = {
    event_start: {
        subject: '🚀 Event Starting Now!',
        body:    'Attention vendors! The event is starting in 1 hour. Please make sure your stalls are fully set up and ready for customers. See you there!',
    },
    weather: {
        subject: '⛈ Weather Alert — Action Required',
        body:    '⚠️ Weather alert: High winds and rain expected in the next 2 hours. Please secure all tent structures, signage, and merchandise immediately. Stay safe!',
    },
    payment: {
        subject: '💸 Stall Rental Payment Reminder',
        body:    'This is a reminder that your stall rental fee payment is due. Please settle your outstanding balance to avoid cancellation of your stall slot.',
    },
    reminder: {
        subject: '⏰ Event Reminder — Tomorrow!',
        body:    "Don't forget — the market event is tomorrow! Please arrive at least 1 hour before opening time to set up your stall. Contact admin for any concerns.",
    },
};

function loadTemplate(key) {
    const tpl = TEMPLATES[key];
    if (!tpl) return;
    const subjectEl = $('#msgSubject');
    const bodyEl    = $('#msgBody');
    if (subjectEl) subjectEl.value = tpl.subject;
    if (bodyEl)    { bodyEl.value  = tpl.body; updateCharCount(bodyEl); }
}

function sendBroadcast() {
    const subject = $('#msgSubject')?.value?.trim();
    const body    = $('#msgBody')?.value?.trim();

    if (!body) {
        toast('Please enter a message body.', '⚠', 'warn');
        return;
    }
    if (activeChannel !== 'sms' && !subject) {
        toast('Please enter a subject line.', '⚠', 'warn');
        return;
    }

    const chipEl    = $('.chip.chip-active');
    const recipients = chipEl?.textContent?.trim() || 'All Vendors';
    const now        = new Date();
    const timeStr    = now.toLocaleDateString('en-US', {month:'short',day:'numeric'}) +
                       ' · ' + String(now.getHours()).padStart(2,'0') + ':' +
                       String(now.getMinutes()).padStart(2,'0');

    const chClass = activeChannel === 'email' ? 'email-tag' : activeChannel === 'sms' ? 'sms-tag' : 'both-tag';
    const chLabel = activeChannel.toUpperCase();

    const entry = document.createElement('div');
    entry.className = 'log-entry';
    entry.innerHTML = `
        <div class="log-meta">
            <span class="log-channel ${chClass}">${chLabel}</span>
            <span class="log-time">${timeStr}</span>
            <span class="log-recipients">→ ${recipients}</span>
        </div>
        <div class="log-subject">${escHtml(subject || '(No subject)')}</div>
        <div class="log-preview">${escHtml(body.substring(0,120))}${body.length>120?'…':''}</div>`;

    const log = $('#broadcastLog');
    if (log) log.prepend(entry);

    // Clear form
    if ($('#msgSubject')) $('#msgSubject').value = '';
    if ($('#msgBody'))    $('#msgBody').value = '';
    updateCharCount({ value: '' });

    toast(`Broadcast sent to ${recipients}`, '📡', 'success');
}

// ══════════════════════════════════════════════════════
//  REVENUE & FEE LEDGER
// ══════════════════════════════════════════════════════
let revenueInited = false;
let ledgerSort    = { col: null, dir: 1 };

function initRevenue() {
    if (revenueInited) return;
    revenueInited = true;
    // Animate summary counters
    $$('.rev-val[data-target]').forEach(el => {
        const target = parseInt(el.dataset.target);
        if (!isNaN(target)) {
            animateNum(el, 0, target, 1200, v => `₱${Number(v.toFixed(0)).toLocaleString()}`);
        }
    });
}

function markPaid(id, btn) {
    const row = btn.closest('.ledger-row');
    if (!row) return;

    const amountEl = row.querySelector('.td-mono');
    const balEl    = row.querySelector('.td-owed');
    const paidEl   = row.querySelector('.td-paid');
    const pillEl   = row.querySelector('.status-pill');

    if (paidEl && amountEl) {
        paidEl.textContent = amountEl.textContent;
    }
    if (balEl) { balEl.textContent = '₱0'; balEl.className = 'td-mono td-zero'; }
    if (pillEl) { pillEl.textContent = 'Paid'; pillEl.className = 'status-pill pill-paid'; }
    btn.outerHTML = '<span class="paid-check">✓ Cleared</span>';

    row.dataset.status = 'paid';
    toast('Payment marked as paid', '✓', 'success');
}

function sortLedger(col) {
    const tbody = $('#ledgerBody');
    if (!tbody) return;
    if (ledgerSort.col === col) ledgerSort.dir *= -1;
    else { ledgerSort.col = col; ledgerSort.dir = 1; }

    const rows = $$('tr.ledger-row', tbody);
    rows.sort((a, b) => {
        let aVal, bVal;
        if (col === 'amount') {
            aVal = parseFloat($$('td',a)[3]?.textContent.replace(/[^0-9.]/g,'')) || 0;
            bVal = parseFloat($$('td',b)[3]?.textContent.replace(/[^0-9.]/g,'')) || 0;
        } else if (col === 'vendor') {
            aVal = ($$('td',a)[0]?.textContent || '').toLowerCase();
            bVal = ($$('td',b)[0]?.textContent || '').toLowerCase();
        } else if (col === 'event') {
            aVal = ($$('td',a)[2]?.textContent || '').toLowerCase();
            bVal = ($$('td',b)[2]?.textContent || '').toLowerCase();
        } else if (col === 'status') {
            const order = { paid:0, partial:1, unpaid:2, overdue:3 };
            aVal = order[a.dataset.status] ?? 9;
            bVal = order[b.dataset.status] ?? 9;
        }
        return aVal < bVal ? -ledgerSort.dir : aVal > bVal ? ledgerSort.dir : 0;
    });
    rows.forEach(r => tbody.appendChild(r));
}

function exportCSV() {
    const headers = ['Vendor','Stall','Event','Amount','Paid','Balance','Due','Status'];
    const rows    = $$('.ledger-row');
    const lines   = [headers.join(',')];
    rows.forEach(row => {
        const cells = $$('td', row);
        const line  = [
            cells[0]?.textContent, cells[1]?.textContent,
            cells[2]?.textContent, cells[3]?.textContent,
            cells[4]?.textContent, cells[5]?.textContent,
            cells[6]?.textContent, cells[7]?.textContent,
        ].map(v => `"${(v||'').trim()}"`).join(',');
        lines.push(line);
    });

    const blob = new Blob([lines.join('\n')], { type: 'text/csv' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href = url; a.download = 'fee_ledger.csv';
    a.click(); URL.revokeObjectURL(url);
    toast('CSV exported successfully', '📥', 'success');
}

// ══════════════════════════════════════════════════════
//  FORM BUILDER
// ══════════════════════════════════════════════════════
let fbFields      = [];
let selectedField = null;
let dragType      = null;   // 'palette' or 'field'
let dragFieldId   = null;

function paletteDrag(e) {
    dragType = 'palette';
    e.dataTransfer.setData('text/plain', e.currentTarget.dataset.type);
    e.currentTarget.classList.add('dragging');
    setTimeout(() => e.currentTarget.classList.remove('dragging'), 0);
}

function canvasDragOver(e) {
    e.preventDefault();
    $('#fbCanvas')?.classList.add('drag-over');
}
function canvasDragLeave() {
    $('#fbCanvas')?.classList.remove('drag-over');
}

function canvasDrop(e) {
    e.preventDefault();
    $('#fbCanvas')?.classList.remove('drag-over');

    if (dragType === 'palette') {
        const type = e.dataTransfer.getData('text/plain');
        if (type) addField(type);
    }
    dragType = null;
}

let fieldIdCounter = 0;
const FIELD_DEFAULTS = {
    text:     { label: 'Short Answer',      placeholder: 'Enter your answer…' },
    textarea: { label: 'Detailed Response', placeholder: 'Write here…' },
    email:    { label: 'Email Address',     placeholder: 'you@example.com' },
    phone:    { label: 'Phone Number',      placeholder: '+63 9XX XXX XXXX' },
    select:   { label: 'Choose Option',     options: 'Option 1\nOption 2\nOption 3' },
    radio:    { label: 'Select One',        options: 'Choice A\nChoice B\nChoice C' },
    checkbox: { label: 'I agree to the terms and conditions', placeholder: '' },
    date:     { label: 'Date',              placeholder: '' },
    file:     { label: 'Upload Document',   placeholder: 'PDF, JPG, PNG (max 5MB)' },
    heading:  { label: 'Section Title',     placeholder: '' },
};

function addField(type) {
    const id      = ++fieldIdCounter;
    const def     = FIELD_DEFAULTS[type] || { label: 'Field', placeholder: '' };
    const field   = { id, type, label: def.label, placeholder: def.placeholder, required: false, options: def.options || '' };
    fbFields.push(field);
    renderCanvas();
    selectField(id);
    updateFieldCount();
    toast(`Added: ${def.label}`, '＋', 'success');
}

function renderCanvas() {
    const canvas = $('#fbCanvas');
    const empty  = $('#fbEmpty');
    if (!canvas) return;

    if (fbFields.length === 0) {
        if (empty) empty.style.display = '';
        canvas.querySelectorAll('.fb-field').forEach(el => el.remove());
        return;
    }
    if (empty) empty.style.display = 'none';

    canvas.querySelectorAll('.fb-field').forEach(el => el.remove());

    fbFields.forEach((field, idx) => {
        const el = document.createElement('div');
        el.className = 'fb-field' + (selectedField === field.id ? ' selected' : '');
        el.dataset.id = field.id;
        el.setAttribute('draggable', true);

        el.innerHTML = buildFieldHTML(field);

        el.addEventListener('click', () => selectField(field.id));
        el.addEventListener('dragstart', ev => {
            dragType    = 'field';
            dragFieldId = field.id;
            ev.dataTransfer.setData('text/plain', String(field.id));
        });
        el.addEventListener('dragover', ev => {
            ev.preventDefault();
            if (dragType === 'field') {
                el.style.borderColor = 'var(--amber)';
            }
        });
        el.addEventListener('dragleave', () => {
            el.style.borderColor = '';
        });
        el.addEventListener('drop', ev => {
            ev.preventDefault();
            el.style.borderColor = '';
            if (dragType !== 'field' || dragFieldId === field.id) return;
            const fromIdx = fbFields.findIndex(f => f.id === dragFieldId);
            const toIdx   = fbFields.findIndex(f => f.id === field.id);
            if (fromIdx === -1 || toIdx === -1) return;
            const [moved] = fbFields.splice(fromIdx, 1);
            fbFields.splice(toIdx, 0, moved);
            renderCanvas();
        });

        canvas.appendChild(el);
    });
}

function buildFieldHTML(field) {
    const reqStar = field.required ? '<span class="fb-required-star">*</span>' : '';

    if (field.type === 'heading') {
        return `<div class="fb-field-heading">${escHtml(field.label)}</div><span class="fb-drag-handle">⠿</span>`;
    }
    if (field.type === 'checkbox') {
        return `
            <div class="fb-field-label">
                <input type="checkbox" disabled style="accent-color:var(--amber)">
                ${escHtml(field.label)} ${reqStar}
            </div>
            <span class="fb-drag-handle">⠿</span>`;
    }
    if (field.type === 'select' || field.type === 'radio') {
        const opts = (field.options || '').split('\n').filter(Boolean);
        const preview = field.type === 'select'
            ? `<select class="fb-field-input" disabled><option>${opts[0]||'Option 1'}</option></select>`
            : opts.slice(0,3).map(o => `<label style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-2)"><input type="radio" disabled> ${escHtml(o)}</label>`).join('');
        return `
            <div class="fb-field-label">${escHtml(field.label)} ${reqStar}</div>
            ${preview}
            <span class="fb-drag-handle">⠿</span>`;
    }
    if (field.type === 'textarea') {
        return `
            <div class="fb-field-label">${escHtml(field.label)} ${reqStar}</div>
            <textarea class="fb-field-input" placeholder="${escHtml(field.placeholder||'')}" disabled rows="2"></textarea>
            <span class="fb-drag-handle">⠿</span>`;
    }
    if (field.type === 'file') {
        return `
            <div class="fb-field-label">${escHtml(field.label)} ${reqStar}</div>
            <div class="fb-field-input" style="color:var(--text-3);font-size:12px">📎 ${escHtml(field.placeholder || 'Attach file…')}</div>
            <span class="fb-drag-handle">⠿</span>`;
    }
    return `
        <div class="fb-field-label">${escHtml(field.label)} ${reqStar}</div>
        <input type="${field.type==='email'?'email':field.type==='date'?'date':'text'}"
               class="fb-field-input"
               placeholder="${escHtml(field.placeholder||'')}" disabled>
        <span class="fb-drag-handle">⠿</span>`;
}

function selectField(id) {
    selectedField = id;
    renderCanvas();
    openFieldEditor(id);
}

function openFieldEditor(id) {
    const field = fbFields.find(f => f.id === id);
    if (!field) return;

    $('#editorEmpty').style.display = 'none';
    $('#editorForm').style.display  = '';

    $('#ef_label').value       = field.label || '';
    $('#ef_placeholder').value = field.placeholder || '';
    $('#ef_required').checked  = field.required || false;
    $('#ef_options').value     = field.options || '';

    const optGroup = $('#ef_options_group');
    if (optGroup) {
        optGroup.style.display = (field.type === 'select' || field.type === 'radio') ? '' : 'none';
    }
}

function updateSelectedField() {
    if (!selectedField) return;
    const field = fbFields.find(f => f.id === selectedField);
    if (!field) return;
    field.label       = $('#ef_label')?.value       || '';
    field.placeholder = $('#ef_placeholder')?.value || '';
    field.required    = $('#ef_required')?.checked  || false;
    field.options     = $('#ef_options')?.value     || '';

    // Update canvas title mirror
    const titleEl = $('#fbCanvasTitle');
    // Also sync form title input
    if (titleEl) titleEl.textContent = $('#fbFormTitle')?.value || 'Form';

    renderCanvas();
}

function deleteSelectedField() {
    if (!selectedField) return;
    fbFields = fbFields.filter(f => f.id !== selectedField);
    selectedField = null;
    renderCanvas();
    updateFieldCount();
    $('#editorEmpty').style.display = '';
    $('#editorForm').style.display  = 'none';
    toast('Field removed', '🗑', 'warn');
}

function updateFieldCount() {
    const el = $('#fbFieldCount');
    if (el) el.textContent = fbFields.length === 1 ? '1 field' : `${fbFields.length} fields`;
}

function clearForm() {
    if (!fbFields.length) return;
    if (!confirm('Clear all fields? This cannot be undone.')) return;
    fbFields = [];
    selectedField = null;
    renderCanvas();
    updateFieldCount();
    $('#editorEmpty').style.display = '';
    $('#editorForm').style.display  = 'none';
    toast('Form cleared', '🗑', 'warn');
}

function saveForm() {
    if (!fbFields.length) { toast('Add at least one field first.', '⚠', 'warn'); return; }

    const title    = $('#fbFormTitle')?.value?.trim()  || 'Untitled Form';
    const event    = $('#fbEventTarget')?.value?.trim() || '';
    const deadline = $('#fbDeadline')?.value            || '';

    if (!event) { toast('Please select a target event.', '⚠', 'warn'); return; }

    const btn = document.querySelector('.fb-btn-primary');
    if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }

    const fd = new FormData();
    fd.append('action',   'save_form');
    fd.append('title',    title);
    fd.append('event',    event);
    fd.append('deadline', deadline);
    fd.append('fields',   JSON.stringify(fbFields));

    fetch('process_admin.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd,
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            toast(`Form "${title}" published! (${fbFields.length} fields)`, '🚀', 'success');
            loadPublishedForms();   // refresh the published list
            // Optionally reset builder
            fbFields = [];
            selectedField = null;
            renderCanvas();
            updateFieldCount();
            $('#editorEmpty').style.display = '';
            $('#editorForm').style.display  = 'none';
        } else {
            toast(data.message || 'Save failed.', '✕', 'error');
        }
    })
    .catch(() => toast('Network error. Please try again.', '✕', 'error'))
    .finally(() => {
        if (btn) { btn.disabled = false; btn.innerHTML = `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Save Form`; }
    });
}

function exportFormJSON() {
    if (!fbFields.length) { toast('Nothing to export.', '⚠', 'warn'); return; }
    const title    = $('#fbFormTitle')?.value  || 'Form';
    const event    = $('#fbEventTarget')?.value || '';
    const deadline = $('#fbDeadline')?.value   || '';
    const blob = new Blob([JSON.stringify({ title, event, deadline, fields: fbFields }, null, 2)], { type: 'application/json' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href = url; a.download = `${title.replace(/\s+/g,'_')}.json`;
    a.click(); URL.revokeObjectURL(url);
    toast('JSON exported', '📥', 'success');
}

// ── Load and display published forms in the sidebar list ──
function loadPublishedForms() {
    const list = $('#publishedFormsList');
    if (!list) return;

    fetch('process_admin.php?action=get_forms', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success || !data.data.length) {
            list.innerHTML = '<div class="pf-empty">No forms published yet.</div>';
            return;
        }
        list.innerHTML = data.data.map(f => `
            <div class="pf-item" data-id="${f.id}">
                <div class="pf-item-info">
                    <span class="pf-item-title">${escHtml(f.title)}</span>
                    <span class="pf-item-meta">${escHtml(f.event_name)} · ${f.field_count} fields · ${f.deadline ? 'Due ' + f.deadline : 'No deadline'}</span>
                </div>
                <div class="pf-item-actions">
                    <span class="pf-status ${f.is_active == 1 ? 'pf-active' : 'pf-inactive'}">${f.is_active == 1 ? 'Live' : 'Hidden'}</span>
                    <button class="pf-toggle-btn" onclick="toggleFormActive(${f.id}, ${f.is_active}, this)">${f.is_active == 1 ? 'Hide' : 'Show'}</button>
                    <button class="pf-view-btn" onclick="window.open('apply.php?form=${f.id}','_blank')">View</button>
                    <button class="pf-delete-btn" onclick="deleteForm(${f.id}, this)">Delete</button>
                </div>
            </div>
        `).join('');
    })
    .catch(() => {
        list.innerHTML = '<div class="pf-empty">Could not load forms.</div>';
    });
}

function toggleFormActive(formId, currentState, btn) {
    const fd = new FormData();
    fd.append('action',    'toggle_form');
    fd.append('form_id',   formId);
    fd.append('is_active', currentState == 1 ? 0 : 1);

    fetch('process_admin.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd,
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            loadPublishedForms();
            toast(data.message, '✓', 'success');
        } else {
            toast(data.message || 'Error', '✕', 'error');
        }
    });
}

function deleteForm(formId, btn) {
    if (!confirm('Delete this form? All submissions will also be deleted.')) return;

    const fd = new FormData();
    fd.append('action',  'delete_form');
    fd.append('form_id', formId);

    fetch('process_admin.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd,
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            loadPublishedForms();
            toast('Form deleted', '🗑', 'warn');
        } else {
            toast(data.message || 'Error', '✕', 'error');
        }
    });
}

function viewSubmissions(formId) {
    const panel = $('#submissionsPanel');
    const list  = $('#submissionsList');
    if (!panel || !list) return;

    panel.style.display = '';
    list.innerHTML = '<div class="pf-empty">Loading…</div>';

    fetch(`process_admin.php?action=get_submissions&form_id=${formId}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success || !data.data.length) {
            list.innerHTML = '<div class="pf-empty">No submissions yet.</div>';
            return;
        }
        list.innerHTML = data.data.map(s => {
            const answers = typeof s.answers === 'string' ? JSON.parse(s.answers) : s.answers;
            const preview = Object.values(answers).slice(0,3).join(' · ');
            return `
            <div class="sub-item">
                <div class="sub-meta">
                    <strong>${escHtml(s.full_name)}</strong>
                    <span>${escHtml(s.submitted_at)}</span>
                    <span class="status-pill pill-${s.status.toLowerCase().replace(' ','-')}">${escHtml(s.status)}</span>
                </div>
                <div class="sub-preview">${escHtml(preview)}</div>
                <div class="sub-actions">
                    <button class="act-btn act-approve" onclick="updateSubmissionStatus(${s.id},'Approved',this)">Approve</button>
                    <button class="act-btn act-reject"  onclick="updateSubmissionStatus(${s.id},'Rejected',this)">Reject</button>
                </div>
            </div>`;
        }).join('');
    });
}

function updateSubmissionStatus(subId, status, btn) {
    const fd = new FormData();
    fd.append('action', 'update_submission');
    fd.append('sub_id', subId);
    fd.append('status', status);

    fetch('process_admin.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd,
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            btn.closest('.sub-item').querySelector('.status-pill').textContent = status;
            toast(`Submission ${status}`, status === 'Approved' ? '✅' : '❌', status === 'Approved' ? 'success' : 'error');
        }
    });
}

function previewForm() {
    if (!fbFields.length) { toast('Nothing to preview yet.', '⚠', 'warn'); return; }

    const title   = $('#fbFormTitle')?.value || 'Form Preview';
    const previewModal = $('#previewModal');
    const previewBody  = $('#previewBody');
    const previewTitle = $('#previewTitle');

    previewTitle.textContent = title;

    previewBody.innerHTML = fbFields.map(field => {
        if (field.type === 'heading') {
            return `<div class="preview-heading">${escHtml(field.label)}</div>`;
        }
        const req = field.required ? ' <span style="color:var(--amber)">*</span>' : '';
        const label = `<label class="preview-label">${escHtml(field.label)}${req}</label>`;

        if (field.type === 'textarea') {
            return `<div class="preview-field">${label}<textarea class="preview-input" rows="3" placeholder="${escHtml(field.placeholder||'')}"></textarea></div>`;
        }
        if (field.type === 'select') {
            const opts = (field.options||'').split('\n').filter(Boolean);
            return `<div class="preview-field">${label}<select class="preview-input">${opts.map(o=>`<option>${escHtml(o)}</option>`).join('')}</select></div>`;
        }
        if (field.type === 'radio') {
            const opts = (field.options||'').split('\n').filter(Boolean);
            return `<div class="preview-field">${label}${opts.map(o=>`<label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;font-size:13px;color:var(--text-2)"><input type="radio" name="r_${field.id}"> ${escHtml(o)}</label>`).join('')}</div>`;
        }
        if (field.type === 'checkbox') {
            return `<div class="preview-field" style="display:flex;align-items:center;gap:10px"><input type="checkbox"> <label class="preview-label" style="margin-bottom:0">${escHtml(field.label)}${req}</label></div>`;
        }
        if (field.type === 'file') {
            return `<div class="preview-field">${label}<input type="file" class="preview-input"></div>`;
        }
        return `<div class="preview-field">${label}<input type="${field.type}" class="preview-input" placeholder="${escHtml(field.placeholder||'')}"></div>`;
    }).join('');

    previewModal.classList.add('open');
}

function closePreview(e) {
    if (e && e.target !== $('#previewModal')) return;
    $('#previewModal')?.classList.remove('open');
}

// Sync form title input → canvas header
document.getElementById('fbFormTitle')?.addEventListener('input', function() {
    const t = $('#fbCanvasTitle');
    if (t) t.textContent = this.value || 'Form';
});

// ══════════════════════════════════════════════════════
//  UTILITY
// ══════════════════════════════════════════════════════
function animateNum(selector, from, to, duration, format = v => Math.round(v)) {
    const el = typeof selector === 'string' ? $(selector) : selector;
    if (!el) return;
    let start = null;
    function step(ts) {
        if (!start) start = ts;
        const p = Math.min((ts - start) / duration, 1);
        const e = 1 - Math.pow(1 - p, 4);
        el.textContent = format(from + (to - from) * e);
        if (p < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
}

function escHtml(str) {
    return String(str||'')
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ══════════════════════════════════════════════════════
//  INIT
// ══════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', () => {
    // Stagger nav item animations
    $$('.anav-item').forEach((el, i) => {
        el.style.animationDelay = (0.1 + i * 0.07) + 's';
        el.style.animation = `fadeSlideRight 0.4s ease both`;
        el.style.animationDelay = (0.15 + i * 0.08) + 's';
    });

    // Stagger app cards
    $$('.app-card').forEach((el, i) => {
        el.style.animationDelay = (i * 0.06) + 's';
    });

    // Stagger ledger rows
    $$('.ledger-row').forEach((el, i) => {
        el.style.animationDelay = (i * 0.04) + 's';
    });

    // ESC closes modals
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            $('#vetModal')?.classList.remove('open');
            $('#previewModal')?.classList.remove('open');
        }
    });

    updateFieldCount();
    loadPublishedForms();
});

// Expose globals
window.switchSection   = switchSection;
window.toggleSidebar   = toggleSidebar;
window.vetAction       = vetAction;
window.closeVetModal   = closeVetModal;
window.filterVetting   = filterVetting;
window.setChannel      = setChannel;
window.toggleChip      = toggleChip;
window.updateCharCount = updateCharCount;
window.loadTemplate    = loadTemplate;
window.sendBroadcast   = sendBroadcast;
window.markPaid        = markPaid;
window.sortLedger      = sortLedger;
window.exportCSV       = exportCSV;
window.paletteDrag     = paletteDrag;
window.canvasDragOver  = canvasDragOver;
window.canvasDragLeave = canvasDragLeave;
window.canvasDrop      = canvasDrop;
window.clearForm       = clearForm;
window.saveForm        = saveForm;
window.exportFormJSON  = exportFormJSON;
window.previewForm     = previewForm;
window.closePreview    = closePreview;
window.updateSelectedField  = updateSelectedField;
window.deleteSelectedField  = deleteSelectedField;
window.toggleFormActive     = toggleFormActive;
window.deleteForm           = deleteForm;
window.viewSubmissions      = viewSubmissions;
window.updateSubmissionStatus = updateSubmissionStatus;
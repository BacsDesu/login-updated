/* ═══════════════════════════════════════════════════
   NISU SmartPOP — Dashboard JS
═══════════════════════════════════════════════════ */

'use strict';

// ── Helpers ──────────────────────────────────────────
const $ = (s, ctx = document) => ctx.querySelector(s);
const $$ = (s, ctx = document) => [...ctx.querySelectorAll(s)];
const lerp = (a, b, t) => a + (b - a) * t;

// ── State ─────────────────────────────────────────────
let sidebarOpen = window.innerWidth > 768;
let rightOpen   = false;

// ══════════════════════════════════════════════════════
//  SIDEBAR TOGGLE
// ══════════════════════════════════════════════════════
function toggleSidebar() {
    const sidebar = $('#sidebar');
    sidebarOpen = !sidebarOpen;
    sidebar.classList.toggle('open', sidebarOpen);
}

// Close sidebar on outside click (mobile)
document.addEventListener('click', e => {
    if (window.innerWidth > 768) return;
    const sidebar = $('#sidebar');
    const hamburger = $('#hamburger');
    if (sidebarOpen && !sidebar.contains(e.target) && !hamburger.contains(e.target)) {
        sidebarOpen = false;
        sidebar.classList.remove('open');
    }
});

// ══════════════════════════════════════════════════════
//  PROFILE DROPDOWN
// ══════════════════════════════════════════════════════
const profileCard   = $('#profileCard');
const profileToggle = $('#profileToggle');
const dropdown      = $('#profileDropdown');

if (profileCard && dropdown) {
    profileCard.addEventListener('click', e => {
        const isOpen = dropdown.classList.toggle('open');
        profileToggle.classList.toggle('open', isOpen);
        e.stopPropagation();
    });

    document.addEventListener('click', () => {
        dropdown.classList.remove('open');
        profileToggle?.classList.remove('open');
    });
}

// ══════════════════════════════════════════════════════
//  TAB NAVIGATION
// ══════════════════════════════════════════════════════
function setTab(navEl, tabId) {
    // Deactivate all nav items
    $$('.nav-item').forEach(el => el.classList.remove('active'));
    // Deactivate all panes
    $$('.tab-pane').forEach(pane => {
        pane.classList.remove('active');
        // Reset animation
        pane.style.animation = 'none';
        pane.offsetHeight; // reflow
        pane.style.animation = '';
    });
    // Activate selected
    navEl.classList.add('active');
    const pane = $(`#tab-${tabId}`);
    if (pane) pane.classList.add('active');

    // Close sidebar on mobile after tap
    if (window.innerWidth <= 768) {
        sidebarOpen = false;
        $('#sidebar')?.classList.remove('open');
    }
}

// ══════════════════════════════════════════════════════
//  NOTIFICATION BUTTON (demo)
// ══════════════════════════════════════════════════════
$('#notifBtn')?.addEventListener('click', () => {
    const pip = $('#notifBtn .notif-pip');
    if (pip) pip.style.display = 'none';
    showToast('You\'re all caught up!', '✅');
});

// ══════════════════════════════════════════════════════
//  TOAST NOTIFICATION
// ══════════════════════════════════════════════════════
function showToast(msg, icon = '📢') {
    let toast = $('#dashToast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'dashToast';
        Object.assign(toast.style, {
            position:      'fixed',
            bottom:        '28px',
            right:         '28px',
            background:    'rgba(20,15,12,0.96)',
            border:        '1px solid rgba(198,124,150,0.3)',
            borderRadius:  '12px',
            padding:       '12px 18px',
            display:       'flex',
            alignItems:    'center',
            gap:           '10px',
            fontSize:      '13px',
            color:         '#f0ece4',
            zIndex:        '9999',
            backdropFilter:'blur(20px)',
            boxShadow:     '0 8px 32px rgba(0,0,0,0.5)',
            transform:     'translateY(20px)',
            opacity:       '0',
            transition:    'all 0.35s cubic-bezier(0.4,0,0.2,1)',
            fontFamily:    "'DM Sans', sans-serif",
            pointerEvents: 'none',
        });
        document.body.appendChild(toast);
    }
    toast.innerHTML = `<span style="font-size:18px">${icon}</span><span>${msg}</span>`;
    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            toast.style.transform = 'translateY(0)';
            toast.style.opacity   = '1';
        });
    });
    clearTimeout(toast._t);
    toast._t = setTimeout(() => {
        toast.style.transform = 'translateY(12px)';
        toast.style.opacity   = '0';
    }, 3500);
}

// ══════════════════════════════════════════════════════
//  REVENUE CHART (vanilla canvas)
// ══════════════════════════════════════════════════════
function drawRevenueChart() {
    const canvas = $('#revenueChart');
    if (!canvas || typeof REVENUE_DATA === 'undefined') return;

    const data   = REVENUE_DATA;
    const months = REVENUE_MONTHS;
    const dpr    = window.devicePixelRatio || 1;
    const W      = canvas.offsetWidth  || 264;
    const H      = 150;

    canvas.width  = W * dpr;
    canvas.height = H * dpr;
    canvas.style.width  = W + 'px';
    canvas.style.height = H + 'px';

    const ctx = canvas.getContext('2d');
    ctx.scale(dpr, dpr);

    const PAD_L = 8, PAD_R = 8, PAD_T = 12, PAD_B = 20;
    const cw = W - PAD_L - PAD_R;
    const ch = H - PAD_T - PAD_B;

    const maxVal = Math.max(...data) * 1.1;
    const step   = cw / (data.length - 1);

    // Map data to coords
    const pts = data.map((v, i) => ({
        x: PAD_L + i * step,
        y: PAD_T + ch - (v / maxVal) * ch,
    }));

    // Animate counter
    const total = data.reduce((a, b) => a + b, 0);
    animateCounter('#totalRevenue', 0, total, 1800, v => `₱${(v/1000).toFixed(1)}K`);

    // Render labels
    const labelsEl = $('#chartLabels');
    if (labelsEl) {
        labelsEl.innerHTML = months.map(m => `<span>${m}</span>`).join('');
    }

    // ── Animated draw ──
    let progress = 0;
    const duration = 1600;
    let startTime = null;

    function draw(ts) {
        if (!startTime) startTime = ts;
        progress = Math.min((ts - startTime) / duration, 1);
        const ease = 1 - Math.pow(1 - progress, 3); // cubic ease out
        const reveal = Math.floor(ease * (data.length - 1));

        ctx.clearRect(0, 0, W, H);

        if (reveal < 1) { requestAnimationFrame(draw); return; }

        const visiblePts = pts.slice(0, reveal + 1);

        // ── Gradient fill under curve ──
        const grad = ctx.createLinearGradient(0, PAD_T, 0, H);
        grad.addColorStop(0,   'rgba(198,124,150,0.35)');
        grad.addColorStop(0.6, 'rgba(198,124,150,0.08)');
        grad.addColorStop(1,   'rgba(198,124,150,0.00)');

        ctx.beginPath();
        ctx.moveTo(visiblePts[0].x, H - PAD_B);
        visiblePts.forEach(p => ctx.lineTo(p.x, p.y));
        ctx.lineTo(visiblePts[visiblePts.length - 1].x, H - PAD_B);
        ctx.closePath();
        ctx.fillStyle = grad;
        ctx.fill();

        // ── Smooth line ──
        ctx.beginPath();
        ctx.moveTo(visiblePts[0].x, visiblePts[0].y);
        for (let i = 1; i < visiblePts.length - 1; i++) {
            const mx = (visiblePts[i].x + visiblePts[i + 1].x) / 2;
            const my = (visiblePts[i].y + visiblePts[i + 1].y) / 2;
            ctx.quadraticCurveTo(visiblePts[i].x, visiblePts[i].y, mx, my);
        }
        const last = visiblePts[visiblePts.length - 1];
        ctx.lineTo(last.x, last.y);

        ctx.strokeStyle = 'rgba(198,124,150,0.9)';
        ctx.lineWidth = 2;
        ctx.lineJoin  = 'round';
        ctx.lineCap   = 'round';
        ctx.stroke();

        // ── Dots ──
        visiblePts.forEach((p, i) => {
            if (i % 2 !== 0 && i !== visiblePts.length - 1) return;
            ctx.beginPath();
            ctx.arc(p.x, p.y, 3, 0, Math.PI * 2);
            ctx.fillStyle = '#d4a574';
            ctx.fill();
        });

        // ── Highlight last dot ──
        ctx.beginPath();
        ctx.arc(last.x, last.y, 5, 0, Math.PI * 2);
        ctx.fillStyle = '#c67c96';
        ctx.fill();
        ctx.beginPath();
        ctx.arc(last.x, last.y, 8, 0, Math.PI * 2);
        ctx.strokeStyle = 'rgba(198,124,150,0.25)';
        ctx.lineWidth = 2;
        ctx.stroke();

        if (progress < 1) requestAnimationFrame(draw);
    }

    requestAnimationFrame(draw);

    // ── Hover tooltip ──
    let hoveredIdx = -1;
    canvas.addEventListener('mousemove', e => {
        const rect = canvas.getBoundingClientRect();
        const mx   = e.clientX - rect.left;
        let nearest = -1, minDist = Infinity;
        pts.forEach((p, i) => {
            const d = Math.abs(p.x - mx);
            if (d < minDist) { minDist = d; nearest = i; }
        });
        if (nearest !== hoveredIdx && minDist < step * 0.6) {
            hoveredIdx = nearest;
            const month = months[nearest];
            const val   = data[nearest];
            canvas.title = `${month}: ₱${val.toLocaleString()}`;
        } else if (minDist >= step * 0.6) {
            hoveredIdx = -1;
            canvas.title = '';
        }
    });
}

// ══════════════════════════════════════════════════════
//  RATING BARS (IntersectionObserver animated)
// ══════════════════════════════════════════════════════
function initRatingBars() {
    const bars = $$('.rbar-fill');
    if (!bars.length) return;

    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const fill = entry.target;
                const val  = parseFloat(fill.dataset.val) || 0;
                setTimeout(() => {
                    fill.style.width = val + '%';
                }, 200);
                observer.unobserve(fill);
            }
        });
    }, { threshold: 0.2 });

    bars.forEach(bar => observer.observe(bar));
}

// ══════════════════════════════════════════════════════
//  ANIMATE COUNTER
// ══════════════════════════════════════════════════════
function animateCounter(selector, from, to, duration, format = v => Math.round(v)) {
    const el = $(selector);
    if (!el) return;
    let startTime = null;
    function step(ts) {
        if (!startTime) startTime = ts;
        const progress = Math.min((ts - startTime) / duration, 1);
        const ease     = 1 - Math.pow(1 - progress, 4);
        el.textContent = format(lerp(from, to, ease));
        if (progress < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
}

// ══════════════════════════════════════════════════════
//  CARD STAGGER ANIMATION (IntersectionObserver)
// ══════════════════════════════════════════════════════
function initCardAnimations() {
    const items = $$('.ann-item, .event-card, .app-card, .qs-item');
    const obs = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.animationPlayState = 'running';
                obs.unobserve(entry.target);
            }
        });
    }, { threshold: 0.15 });
    items.forEach(el => {
        el.style.animationPlayState = 'paused';
        obs.observe(el);
    });
}

// ══════════════════════════════════════════════════════
//  DONUT ARC ANIMATION
// ══════════════════════════════════════════════════════
function animateDonut() {
    const arc = $('.donut-arc');
    if (!arc) return;
    // Already set via CSS dash offset, just trigger a reflow
    setTimeout(() => {
        arc.style.strokeDasharray = '127 145';
    }, 600);
}

// ══════════════════════════════════════════════════════
//  RESIZE HANDLER
// ══════════════════════════════════════════════════════
let resizeTimer;
window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
        drawRevenueChart();
        // Auto-show sidebar on desktop
        if (window.innerWidth > 768) {
            sidebarOpen = true;
            $('#sidebar')?.classList.remove('open');
        }
    }, 200);
});

// ══════════════════════════════════════════════════════
//  KEYBOARD SHORTCUT: Esc closes dropdowns
// ══════════════════════════════════════════════════════
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        dropdown?.classList.remove('open');
        profileToggle?.classList.remove('open');
        if (window.innerWidth <= 768) {
            sidebarOpen = false;
            $('#sidebar')?.classList.remove('open');
        }
    }
});

// ══════════════════════════════════════════════════════
//  INIT
// ══════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', () => {
    // Stagger sidebar nav items
    $$('.nav-item').forEach((el, i) => {
        el.style.animationDelay = (0.15 + i * 0.1) + 's';
    });

    // Widgets stagger
    $$('.widget').forEach((el, i) => {
        el.style.animationDelay = (0.3 + i * 0.15) + 's';
    });

    // Run all initializers
    initCardAnimations();
    initRatingBars();
    animateDonut();

    // Wait a tick for layout to settle before drawing chart
    setTimeout(drawRevenueChart, 400);

    // Greeting chips counter effect
    setTimeout(() => {
        animateCounter('.chip:nth-child(3) .chip-val', 0, 4200, 1600, v => `₱${(v/1000).toFixed(1)}K`);
    }, 800);
});
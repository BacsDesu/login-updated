/* ═══════════════════════════════════════════════════
   profile_edit.js
   Handles: modal open/close, avatar preview + drag-drop,
   bio char count, AJAX form submit, DOM live-updates
═══════════════════════════════════════════════════ */

'use strict';

// ── Refs ──────────────────────────────────────────────
const modal        = document.getElementById('profileModal');
const backdrop     = document.getElementById('profileModalBackdrop');
const profileForm  = document.getElementById('profileForm');
const avatarInput  = document.getElementById('avatarInput');
const pmAvatarImg  = document.getElementById('pmAvatarImg');
const pmInitials   = document.getElementById('pmAvatarInitials');
const pmAvatarWrap = document.getElementById('pmAvatarWrap');
const pmRemoveBtn  = document.getElementById('pmRemoveAvatar');
const bioField     = document.getElementById('pm_bio');
const bioCount     = document.getElementById('bioCharCount');
const pmFeedback   = document.getElementById('pmFeedback');
const pmSaveBtn    = document.getElementById('pmSaveBtn');
const pmBtnText    = pmSaveBtn?.querySelector('.pm-btn-text');
const pmBtnSpinner = pmSaveBtn?.querySelector('.pm-btn-spinner');

// Track whether avatar was explicitly removed
let avatarRemoved = false;
let isOpen        = false;

// ══════════════════════════════════════════════════════
//  OPEN / CLOSE
// ══════════════════════════════════════════════════════
function openProfileEdit() {
    if (isOpen) return;
    isOpen = true;

    // Close the dropdown first
    document.getElementById('profileDropdown')?.classList.remove('open');
    document.getElementById('profileToggle')?.classList.remove('open');

    // Reset form to current DB values
    resetForm();

    // Show modal
    modal.classList.add('open');
    backdrop.classList.add('active');
    document.body.style.overflow = 'hidden';

    // Focus first field after animation
    setTimeout(() => {
        document.getElementById('pm_full_name')?.focus();
    }, 350);

    // Prevent page scroll on mobile
    document.addEventListener('keydown', handleModalKey);
}

function closeProfileEdit() {
    if (!isOpen) return;
    isOpen = false;

    modal.classList.remove('open');
    backdrop.classList.remove('active');
    document.body.style.overflow = '';
    document.removeEventListener('keydown', handleModalKey);

    // Clear feedback after close
    setTimeout(clearFeedback, 400);
}

function handleModalKey(e) {
    if (e.key === 'Escape') closeProfileEdit();
    // Trap tab focus inside modal
    if (e.key === 'Tab') {
        const focusable = modal.querySelectorAll(
            'button:not([disabled]), input:not([readonly]):not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        );
        const first = focusable[0];
        const last  = focusable[focusable.length - 1];
        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault(); last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault(); first.focus();
        }
    }
}

// ══════════════════════════════════════════════════════
//  RESET FORM
// ══════════════════════════════════════════════════════
function resetForm() {
    if (!profileForm || typeof PROFILE_DATA === 'undefined') return;

    avatarRemoved = false;

    // Repopulate fields
    document.getElementById('pm_full_name').value  = PROFILE_DATA.full_name  || '';
    document.getElementById('pm_stall_name').value = PROFILE_DATA.stall_name || '';
    if (bioField) {
        bioField.value = PROFILE_DATA.bio || '';
        updateBioCount();
    }

    // Reset avatar preview
    resetAvatarPreview();

    // Clear validation states
    modal.querySelectorAll('.pm-input-wrap').forEach(w => {
        w.classList.remove('error', 'success');
    });

    clearFeedback();

    // Re-enable save button
    setSaving(false);
}

function resetAvatarPreview() {
    avatarInput.value = '';
    if (PROFILE_DATA.avatar_path) {
        pmAvatarImg.src     = PROFILE_DATA.avatar_path;
        pmAvatarImg.style.display = 'block';
        if (pmInitials) pmInitials.style.display = 'none';
        if (pmRemoveBtn) pmRemoveBtn.style.display = '';
    } else {
        pmAvatarImg.src     = '';
        pmAvatarImg.style.display = 'none';
        if (pmInitials) pmInitials.style.display = '';
        if (pmRemoveBtn) pmRemoveBtn.style.display = 'none';
    }
}

// ══════════════════════════════════════════════════════
//  AVATAR — FILE INPUT & DRAG-DROP
// ══════════════════════════════════════════════════════
if (avatarInput) {
    avatarInput.addEventListener('change', e => {
        const file = e.target.files[0];
        if (file) handleAvatarFile(file);
    });
}

// Drag-and-drop directly onto avatar
if (pmAvatarWrap) {
    pmAvatarWrap.addEventListener('dragover', e => {
        e.preventDefault();
        pmAvatarWrap.classList.add('dragover');
    });
    pmAvatarWrap.addEventListener('dragleave', () => {
        pmAvatarWrap.classList.remove('dragover');
    });
    pmAvatarWrap.addEventListener('drop', e => {
        e.preventDefault();
        pmAvatarWrap.classList.remove('dragover');
        const file = e.dataTransfer?.files[0];
        if (file && file.type.startsWith('image/')) {
            // Inject into the file input so it submits with the form
            const dt = new DataTransfer();
            dt.items.add(file);
            avatarInput.files = dt.files;
            handleAvatarFile(file);
        } else {
            showFeedback('Please drop an image file.', 'error');
        }
    });
}

function handleAvatarFile(file) {
    const maxSize = 3 * 1024 * 1024;
    const allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    if (!allowed.includes(file.type)) {
        showFeedback('Only JPG, PNG, WebP or GIF images allowed.', 'error');
        avatarInput.value = '';
        return;
    }
    if (file.size > maxSize) {
        showFeedback('Image must be under 3MB.', 'error');
        avatarInput.value = '';
        return;
    }

    clearFeedback();
    avatarRemoved = false;

    const reader = new FileReader();
    reader.onload = e => {
        pmAvatarImg.src             = e.target.result;
        pmAvatarImg.style.display   = 'block';
        if (pmInitials) pmInitials.style.display = 'none';
        if (pmRemoveBtn) pmRemoveBtn.style.display = '';
    };
    reader.readAsDataURL(file);
}

function removeAvatarPreview() {
    avatarRemoved    = true;
    avatarInput.value = '';
    pmAvatarImg.src   = '';
    pmAvatarImg.style.display   = 'none';
    if (pmInitials) {
        pmInitials.textContent   = PROFILE_DATA.initials || '?';
        pmInitials.style.display = '';
    }
    if (pmRemoveBtn) pmRemoveBtn.style.display = 'none';
}

// ══════════════════════════════════════════════════════
//  BIO CHAR COUNT
// ══════════════════════════════════════════════════════
function updateBioCount() {
    if (!bioField || !bioCount) return;
    const len  = bioField.value.length;
    const max  = 500;
    bioCount.textContent = `${len}/${max}`;
    bioCount.style.color = len > max * 0.9
        ? (len >= max ? '#f87171' : '#f6a623')
        : 'var(--text-muted)';
}

if (bioField) {
    bioField.addEventListener('input', updateBioCount);
}

// ══════════════════════════════════════════════════════
//  FEEDBACK HELPERS
// ══════════════════════════════════════════════════════
function showFeedback(msg, type = 'error') {
    if (!pmFeedback) return;
    pmFeedback.textContent = type === 'success' ? '✓ ' + msg : '⚠ ' + msg;
    pmFeedback.className   = 'pm-feedback ' + type;
}
function clearFeedback() {
    if (!pmFeedback) return;
    pmFeedback.textContent = '';
    pmFeedback.className   = 'pm-feedback';
}

function setSaving(saving) {
    if (!pmSaveBtn) return;
    pmSaveBtn.disabled = saving;
    if (pmBtnText)    pmBtnText.style.display    = saving ? 'none' : '';
    if (pmBtnSpinner) pmBtnSpinner.style.display = saving ? '' : 'none';
}

// ══════════════════════════════════════════════════════
//  FORM SUBMISSION
// ══════════════════════════════════════════════════════
if (profileForm) {
    profileForm.addEventListener('submit', async e => {
        e.preventDefault();
        clearFeedback();

        // ── Client-side validation ──
        const nameField  = document.getElementById('pm_full_name');
        const stallField = document.getElementById('pm_stall_name');
        const nameWrap   = nameField?.closest('.pm-input-wrap');
        let valid = true;

        if (!nameField.value.trim()) {
            nameWrap?.classList.add('error');
            showFeedback('Full name is required.', 'error');
            nameField.focus();
            valid = false;
        } else if (nameField.value.trim().length < 2) {
            nameWrap?.classList.add('error');
            showFeedback('Name must be at least 2 characters.', 'error');
            nameField.focus();
            valid = false;
        } else {
            nameWrap?.classList.remove('error');
            nameWrap?.classList.add('success');
        }

        if (!valid) return;

        setSaving(true);

        // ── Build FormData ──
        const fd = new FormData(profileForm);
        // Include avatar file if selected
        if (avatarInput?.files[0]) {
            fd.set('avatar', avatarInput.files[0]);
        }
        // Signal avatar removal
        if (avatarRemoved) {
            fd.set('remove_avatar', '1');
        }

        try {
            const res  = await fetch('process_profile.php', {
                method:  'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body:    fd,
            });
            const data = await res.json();

            if (data.success) {
                showFeedback(data.message || 'Saved!', 'success');

                // ── Update PROFILE_DATA in memory ──
                PROFILE_DATA.full_name  = data.full_name;
                PROFILE_DATA.stall_name = data.stall_name  || '';
                PROFILE_DATA.bio        = document.getElementById('pm_bio').value;
                if (data.avatar_path) PROFILE_DATA.avatar_path = data.avatar_path;
                if (avatarRemoved)    PROFILE_DATA.avatar_path = '';

                // ── Update initials ──
                const parts = data.full_name.split(' ');
                PROFILE_DATA.initials = (
                    (parts[0]?.[0] || '') + (parts[1]?.[0] || '')
                ).toUpperCase();

                // ── Live-update the DOM ──
                updateDashboardDOM(data);

                // Close after short delay
                setTimeout(closeProfileEdit, 1200);

            } else {
                showFeedback(data.message || 'Something went wrong.', 'error');
                setSaving(false);
            }
        } catch (err) {
            console.error('Profile save error:', err);
            showFeedback('Network error. Please try again.', 'error');
            setSaving(false);
        }
    });
}

// ══════════════════════════════════════════════════════
//  LIVE DOM UPDATE — sidebar + greeting
// ══════════════════════════════════════════════════════
function updateDashboardDOM(data) {
    // ── Sidebar name ──
    const sidebarName = document.getElementById('sidebarName');
    if (sidebarName) sidebarName.textContent = data.full_name;

    // ── Sidebar stall / email ──
    const sidebarStall = document.getElementById('sidebarStall');
    if (sidebarStall) {
        if (data.stall_name) {
            sidebarStall.textContent = '🏪 ' + data.stall_name;
            sidebarStall.className   = 'profile-stall';
        } else {
            sidebarStall.textContent = PROFILE_DATA.email;
            sidebarStall.className   = 'profile-email';
        }
    }

    // ── Sidebar avatar ──
    const sidebarAvatar = document.getElementById('sidebarAvatar');
    if (sidebarAvatar) {
        if (data.avatar_path) {
            sidebarAvatar.innerHTML = `<img src="${escHtml(data.avatar_path)}" alt="Avatar" class="avatar-img">`;
        } else if (avatarRemoved) {
            sidebarAvatar.innerHTML = escHtml(PROFILE_DATA.initials);
        }
    }

    // ── Greeting name (first name only) ──
    const greetingName = document.querySelector('.greeting-name');
    if (greetingName) {
        const firstName = data.full_name.split(' ')[0];
        greetingName.innerHTML = escHtml(firstName) + ' <span class="wave">👋</span>';
    }

    // ── Avatar success ring ──
    const pmAvatarEl = document.querySelector('.pm-avatar');
    if (pmAvatarEl) {
        pmAvatarEl.classList.add('saved');
        setTimeout(() => pmAvatarEl.classList.remove('saved'), 900);
    }

    // ── Toast notification ──
    if (typeof showToast === 'function') {
        showToast('Profile updated!', '✅');
    }
}

function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// ══════════════════════════════════════════════════════
//  WIRE Edit Profile link in sidebar dropdown
// ══════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', () => {
    // The onclick="openProfileEdit()" in PHP handles the call,
    // but also wire it as a safety fallback:
    document.getElementById('openProfileModal')?.addEventListener('click', e => {
        e.preventDefault();
        openProfileEdit();
    });
});

// Expose globally (called from onclick in PHP HTML)
window.openProfileEdit  = openProfileEdit;
window.closeProfileEdit = closeProfileEdit;
window.removeAvatarPreview = removeAvatarPreview;

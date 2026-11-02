/**
 * TCH Event Raffle v2.1 — Countdown Timer, Auto-Lock, Winner Result Transition & Ticket Lookup
 */
document.addEventListener('DOMContentLoaded', function () {
    if (typeof tchRaffleData === 'undefined') return;

    const form            = document.getElementById('tch-raffle-form');
    const flipScene       = document.getElementById('tch-flip-scene');
    const flipCard        = document.getElementById('tch-flip-card');
    const countdownBox    = document.getElementById('tch-countdown-box');
    const finalWinnerCard = document.getElementById('tch-final-winner-card');
    const fwWinnersList   = document.getElementById('tch-fw-winners-list');
    const submitBtn       = document.getElementById('tch-submit-btn');
    const feedbackBox     = document.getElementById('tch-form-feedback');
    const closedOverlay   = document.getElementById('tch-closed-overlay');

    let hasWinner         = Boolean(tchRaffleData.hasWinner);

    // Countdown Elements
    const elDays  = document.getElementById('tch-cd-days');
    const elHours = document.getElementById('tch-cd-hours');
    const elMins  = document.getElementById('tch-cd-mins');
    const elSecs  = document.getElementById('tch-cd-secs');

    const clientLoadTime = Math.floor(Date.now() / 1000);
    const serverOffset   = parseInt(tchRaffleData.nowTs, 10) - clientLoadTime;
    const closeTs        = parseInt(tchRaffleData.closeTs, 10);

    function pad(num) {
        return String(num).padStart(2, '0');
    }

    function updateCountdown() {
        if (hasWinner || !elDays) return false;

        const currentServerTime = Math.floor(Date.now() / 1000) + serverOffset;
        const remaining         = closeTs - currentServerTime;

        if (remaining <= 0) {
            elDays.textContent  = '00';
            elHours.textContent = '00';
            elMins.textContent  = '00';
            elSecs.textContent  = '00';
            if (closedOverlay) closedOverlay.style.display = 'flex';
            if (submitBtn) submitBtn.disabled = true;
            return false;
        }

        const days  = Math.floor(remaining / 86400);
        const hours = Math.floor((remaining % 86400) / 3600);
        const mins  = Math.floor((remaining % 3600) / 60);
        const secs  = remaining % 60;

        elDays.textContent  = pad(days);
        elHours.textContent = pad(hours);
        elMins.textContent  = pad(mins);
        elSecs.textContent  = pad(secs);
        return true;
    }

    if (!hasWinner && updateCountdown()) {
        const timerInterval = setInterval(function () {
            if (!updateCountdown()) {
                clearInterval(timerInterval);
            }
        }, 1000);
    }

    // Background Poll: If Winner is Confirmed on Stage, Immediately Hide Form & Live Links and Show Winner Result
    function checkIfWinnerConfirmed() {
        if (hasWinner) return;

        const fd = new FormData();
        fd.append('action', 'tch_stage_action');
        fd.append('nonce', tchRaffleData.stageNonce);
        fd.append('sub_action', 'get_pool');

        fetch(tchRaffleData.ajaxUrl, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.success && res.data.winners && res.data.winners.length > 0) {
                    hasWinner = true;
                    if (flipScene) flipScene.style.display = 'none';
                    if (countdownBox) countdownBox.style.display = 'none';
                    if (finalWinnerCard && fwWinnersList) {
                        fwWinnersList.innerHTML = res.data.winners.map(w => `
                            <div class="tch-fw-ticket-box">
                                <span class="tch-fw-ref-label">WINNING TICKET REFERENCE</span>
                                <div class="tch-fw-ref-code">${escapeHtml(w.ref_code)}</div>
                                <div class="tch-fw-winner-name">🎉 ${escapeHtml(w.full_name)}</div>
                            </div>
                        `).join('');
                        finalWinnerCard.style.display = 'block';
                    }
                }
            })
            .catch(() => {});
    }

    if (!hasWinner) {
        setInterval(checkIfWinnerConfirmed, 5000);
    }

    // Highlight social step boxes when ticked
    document.querySelectorAll('.tch-step-box input[type="checkbox"]').forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            const box = this.closest('.tch-step-box');
            if (this.checked) {
                box.classList.add('is-checked');
            } else {
                box.classList.remove('is-checked');
            }
        });
    });

    // Terms & Conditions Modal Toggles
    const modal       = document.getElementById('tch-terms-modal');
    const openTerms   = document.getElementById('tch-open-terms');
    const closeTerms  = document.getElementById('tch-close-terms');
    const acceptTerms = document.getElementById('tch-accept-terms');

    function toggleModal(show) {
        if (!modal) return;
        if (show) {
            modal.classList.add('is-open');
        } else {
            modal.classList.remove('is-open');
        }
    }

    if (openTerms) openTerms.addEventListener('click', () => toggleModal(true));
    if (closeTerms) closeTerms.addEventListener('click', () => toggleModal(false));
    if (acceptTerms) acceptTerms.addEventListener('click', () => toggleModal(false));
    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) toggleModal(false);
        });
    }

    // AJAX Form Submission & 3D Ticket Flip
    if (form && submitBtn) {
        const btnText   = submitBtn.querySelector('.tch-btn-text');
        const btnLoader = submitBtn.querySelector('.tch-btn-loader');

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            feedbackBox.style.display = 'none';

            const fullName = document.getElementById('tch_full_name').value.trim();
            const email    = document.getElementById('tch_email').value.trim();
            const phone    = document.getElementById('tch_phone').value.trim();
            const igCheck  = document.getElementById('tch_ig_check').checked;
            const ytCheck  = document.getElementById('tch_yt_check').checked;

            if (!fullName || !email || !phone) {
                showError('Please fill in your Full Name, Email, and Mobile/WhatsApp number.');
                return;
            }

            if (!igCheck || !ytCheck) {
                showError('Please complete both Instagram & YouTube steps and tick the checkboxes.');
                return;
            }

            submitBtn.disabled      = true;
            btnText.style.display   = 'none';
            btnLoader.style.display = 'inline';

            const formData = new FormData(form);
            formData.append('action', 'tch_submit_raffle');
            formData.append('nonce', tchRaffleData.nonce);

            fetch(tchRaffleData.ajaxUrl, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(response => {
                if (response.success) {
                    document.getElementById('tch-ticket-ref').textContent  = response.data.ref_code;
                    document.getElementById('tch-ticket-name').textContent = response.data.full_name;
                    flipCard.classList.add('is-flipped');
                    window.scrollTo({ top: flipCard.offsetTop - 20, behavior: 'smooth' });
                } else {
                    showError(response.data && response.data.message ? response.data.message : 'Something went wrong. Please try again.');
                    resetButton(btnText, btnLoader);
                }
            })
            .catch(() => {
                showError('Network error. Please check your connection and try again.');
                resetButton(btnText, btnLoader);
            });
        });
    }

    // Participant Ticket Status & Result Lookup Handler
    const lookupForm   = document.getElementById('tch-lookup-form');
    const lookupInput  = document.getElementById('tch-lookup-input');
    const lookupResult = document.getElementById('tch-lookup-result');

    if (lookupForm) {
        lookupForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const q = lookupInput.value.trim();
            if (!q) return;

            lookupResult.style.display = 'block';
            lookupResult.innerHTML     = '<span style="color:#94a3b8;">Checking ticket...</span>';

            const fd = new FormData();
            fd.append('action', 'tch_check_ticket_status');
            fd.append('nonce', tchRaffleData.nonce);
            fd.append('lookup_query', q);

            fetch(tchRaffleData.ajaxUrl, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        const d = res.data;
                        lookupResult.innerHTML = `
                            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">
                                <div>
                                    <strong style="color:#fff;">${escapeHtml(d.full_name)}</strong>
                                    <code style="background:#1e293b;color:#facc15;padding:2px 6px;border-radius:4px;margin-left:6px;font-weight:800;">${escapeHtml(d.ref_code)}</code>
                                </div>
                                <span style="font-weight:700;color:#86efac;">${escapeHtml(d.status_label)}</span>
                            </div>
                        `;
                    } else {
                        lookupResult.innerHTML = `<span style="color:#fca5a5;">${escapeHtml(res.data.message || 'Ticket not found.')}</span>`;
                    }
                })
                .catch(() => {
                    lookupResult.innerHTML = '<span style="color:#fca5a5;">Could not check ticket right now.</span>';
                });
        });
    }

    function showError(msg) {
        if (!feedbackBox) return;
        feedbackBox.textContent   = msg;
        feedbackBox.className     = 'tch-form-feedback is-error';
        feedbackBox.style.display = 'block';
    }

    function resetButton(btnText, btnLoader) {
        if (!submitBtn) return;
        submitBtn.disabled      = false;
        btnText.style.display   = 'inline';
        btnLoader.style.display = 'none';
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
});

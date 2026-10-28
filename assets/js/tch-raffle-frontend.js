/**
 * TCH Event Raffle — Countdown Timer, Auto-Lock, Modal & 3D Ticket Flip
 */
document.addEventListener('DOMContentLoaded', function () {
    if (typeof tchRaffleData === 'undefined') return;

    const form          = document.getElementById('tch-raffle-form');
    const flipCard      = document.getElementById('tch-flip-card');
    const submitBtn     = document.getElementById('tch-submit-btn');
    const btnText       = submitBtn.querySelector('.tch-btn-text');
    const btnLoader     = submitBtn.querySelector('.tch-btn-loader');
    const feedbackBox   = document.getElementById('tch-form-feedback');
    const closedOverlay = document.getElementById('tch-closed-overlay');

    // Countdown Elements
    const elDays  = document.getElementById('tch-cd-days');
    const elHours = document.getElementById('tch-cd-hours');
    const elMins  = document.getElementById('tch-cd-mins');
    const elSecs  = document.getElementById('tch-cd-secs');

    // Sync client clock offset with WordPress server timestamp (GST)
    const clientLoadTime = Math.floor(Date.now() / 1000);
    const serverOffset   = parseInt(tchRaffleData.nowTs, 10) - clientLoadTime;
    const closeTs        = parseInt(tchRaffleData.closeTs, 10);

    function pad(num) {
        return String(num).padStart(2, '0');
    }

    function updateCountdown() {
        const currentServerTime = Math.floor(Date.now() / 1000) + serverOffset;
        const remaining         = closeTs - currentServerTime;

        if (remaining <= 0) {
            elDays.textContent  = '00';
            elHours.textContent = '00';
            elMins.textContent  = '00';
            elSecs.textContent  = '00';
            closedOverlay.style.display = 'flex';
            submitBtn.disabled = true;
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

    if (updateCountdown()) {
        const timerInterval = setInterval(function () {
            if (!updateCountdown()) {
                clearInterval(timerInterval);
            }
        }, 1000);
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
    if (form) {
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
                    window.scrollTo({ top: flipCard.offsetTop - 40, behavior: 'smooth' });
                } else {
                    showError(response.data && response.data.message ? response.data.message : 'Something went wrong. Please try again.');
                    resetButton();
                }
            })
            .catch(() => {
                showError('Network error. Please check your connection and try again.');
                resetButton();
            });
        });
    }

    function showError(msg) {
        feedbackBox.textContent   = msg;
        feedbackBox.className     = 'tch-form-feedback is-error';
        feedbackBox.style.display = 'block';
    }

    function resetButton() {
        submitBtn.disabled      = false;
        btnText.style.display   = 'inline';
        btnLoader.style.display = 'none';
    }
});

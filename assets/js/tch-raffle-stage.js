/**
 * TCH Event Raffle — Live Stage Slot Engine, Web Audio Synth & Confetti
 */
document.addEventListener('DOMContentLoaded', function () {
    if (typeof window.tchStageConfig === 'undefined') return;

    const cfg             = window.tchStageConfig;
    const statValid       = document.getElementById('tch-stat-valid');
    const machineCard     = document.getElementById('tch-machine-card');
    const machineStatus   = document.getElementById('tch-machine-status');
    const slotWindow      = document.getElementById('tch-slot-window');
    const slotCode        = document.getElementById('tch-slot-code');
    const candidatePanel  = document.getElementById('tch-candidate-panel');
    const candidateName   = document.getElementById('tch-candidate-name');
    const candidateMeta   = document.getElementById('tch-candidate-meta');
    const verifyControls  = document.getElementById('tch-verify-controls');
    const spinControls    = document.getElementById('tch-spin-controls');
    const btnSpin         = document.getElementById('tch-btn-spin');
    const btnConfirm      = document.getElementById('tch-btn-confirm');
    const btnDisqualify   = document.getElementById('tch-btn-disqualify');
    const btnFullscreen   = document.getElementById('tch-btn-fullscreen');
    const winnersBox      = document.getElementById('tch-winners-box');
    const winnersList     = document.getElementById('tch-winners-list');

    let poolList         = [];
    let currentCandidate = null;
    let audioCtx         = null;

    // Initialize Web Audio API Synthesizer (No external MP3 dependencies)
    function initAudio() {
        if (!audioCtx) {
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            if (AudioContext) audioCtx = new AudioContext();
        }
        if (audioCtx && audioCtx.state === 'suspended') {
            audioCtx.resume();
        }
    }

    function playTick(freq = 520) {
        if (!audioCtx) return;
        const osc  = audioCtx.createOscillator();
        const gain = audioCtx.createGain();
        osc.type = 'triangle';
        osc.frequency.setValueAtTime(freq, audioCtx.currentTime);
        gain.gain.setValueAtTime(0.12, audioCtx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.045);
        osc.connect(gain);
        gain.connect(audioCtx.destination);
        osc.start();
        osc.stop(audioCtx.currentTime + 0.05);
    }

    function playLockChime() {
        if (!audioCtx) return;
        const notes = [523.25, 659.25, 783.99, 1046.50]; // C5, E5, G5, C6
        notes.forEach((freq, idx) => {
            const osc  = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(freq, audioCtx.currentTime + idx * 0.09);
            gain.gain.setValueAtTime(0.18, audioCtx.currentTime + idx * 0.09);
            gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + idx * 0.09 + 0.45);
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.start(audioCtx.currentTime + idx * 0.09);
            osc.stop(audioCtx.currentTime + idx * 0.09 + 0.46);
        });
    }

    // Fire Gold & Green Confetti Cannon
    function fireCelebrationConfetti() {
        if (typeof confetti !== 'function') return;
        const duration = 4000;
        const end      = Date.now() + duration;

        (function frame() {
            confetti({
                particleCount: 6,
                angle: 60,
                spread: 55,
                origin: { x: 0, y: 0.65 },
                colors: ['#facc15', '#22c55e', '#ffffff']
            });
            confetti({
                particleCount: 6,
                angle: 120,
                spread: 55,
                origin: { x: 1, y: 0.65 },
                colors: ['#facc15', '#22c55e', '#ffffff']
            });
            if (Date.now() < end) {
                requestAnimationFrame(frame);
            }
        })();
    }

    // Helper for Stage AJAX Requests
    function stageRequest(subAction, extraData = {}) {
        const formData = new FormData();
        formData.append('action', 'tch_stage_action');
        formData.append('nonce', cfg.nonce);
        formData.append('sub_action', subAction);
        for (const key in extraData) {
            formData.append(key, extraData[key]);
        }
        return fetch(cfg.ajaxUrl, { method: 'POST', body: formData }).then(r => r.json());
    }

    // Load Eligible Pool & Confirmed Winners
    function refreshStagePool() {
        stageRequest('get_pool').then(res => {
            if (!res.success) return;
            poolList              = res.data.pool || [];
            statValid.textContent = res.data.valid_count;

            if (res.data.winners && res.data.winners.length > 0) {
                winnersBox.style.display = 'block';
                winnersList.innerHTML    = res.data.winners.map(w => `
                    <div class="tch-winner-row">
                        <strong>${escapeHtml(w.full_name)}</strong>
                        <code>${escapeHtml(w.ref_code)}</code>
                    </div>
                `).join('');
            } else {
                winnersBox.style.display = 'none';
            }
        });
    }

    // Decelerating Slot Reel Animation
    function animateSlotToWinner(candidate) {
        slotWindow.classList.add('is-spinning');
        machineCard.classList.remove('is-winner');
        candidatePanel.style.display = 'none';
        spinControls.style.display   = 'none';
        machineStatus.textContent    = 'SPINNING TICKET POOL...';

        const totalSteps = 48;
        let step         = 0;

        function nextTick() {
            step++;
            if (step < totalSteps) {
                // Pick random code from pool or generate random scramble
                if (poolList.length > 1) {
                    const randItem = poolList[Math.floor(Math.random() * poolList.length)];
                    slotCode.textContent = randItem.ref_code;
                } else {
                    slotCode.textContent = cfg.prefix + Math.random().toString(36).substring(2, 7).toUpperCase();
                }

                playTick(380 + (step * 6));

                // Easing curve: starts fast (45ms) and slows down dramatically at the end
                const progress = step / totalSteps;
                const delay    = Math.floor(45 + Math.pow(progress, 3) * 320);
                setTimeout(nextTick, delay);
            } else {
                // Lock onto the winning candidate
                slotWindow.classList.remove('is-spinning');
                slotCode.textContent         = candidate.ref_code;
                machineStatus.textContent    = 'TICKET SELECTED — AWAITING VERIFICATION';
                candidateName.textContent    = candidate.full_name;
                candidateMeta.textContent    = `WhatsApp: ${candidate.masked_phone} • Ticket: ${candidate.ref_code}`;
                verifyControls.style.display = 'block';
                candidatePanel.style.display = 'block';
                playLockChime();
            }
        }

        nextTick();
    }

    // Spin Button Click
    if (btnSpin) {
        btnSpin.addEventListener('click', function () {
            initAudio();
            btnSpin.disabled = true;

            stageRequest('draw_candidate').then(res => {
                btnSpin.disabled = false;
                if (!res.success) {
                    alert(res.data && res.data.message ? res.data.message : 'No eligible tickets remaining.');
                    return;
                }
                currentCandidate = res.data.candidate;
                animateSlotToWinner(currentCandidate);
            });
        });
    }

    // Confirm Official Winner Click
    if (btnConfirm) {
        btnConfirm.addEventListener('click', function () {
            if (!currentCandidate) return;
            initAudio();

            stageRequest('confirm_winner', { entry_id: currentCandidate.id }).then(res => {
                if (res.success) {
                    machineCard.classList.add('is-winner');
                    machineStatus.textContent    = '🎉 OFFICIAL GRAND PRIZE WINNER CONFIRMED! 🎉';
                    verifyControls.style.display = 'none';
                    spinControls.style.display   = 'block';
                    btnSpin.textContent          = '🎰 DRAW ANOTHER WINNER';
                    fireCelebrationConfetti();
                    playLockChime();
                    refreshStagePool();
                }
            });
        });
    }

    // Disqualify & Redraw Click
    if (btnDisqualify) {
        btnDisqualify.addEventListener('click', function () {
            if (!currentCandidate) return;
            if (!confirm(`Disqualify ${currentCandidate.full_name} (${currentCandidate.ref_code}) and spin again?`)) {
                return;
            }

            stageRequest('disqualify_entry', { entry_id: currentCandidate.id }).then(res => {
                if (res.success) {
                    currentCandidate             = null;
                    candidatePanel.style.display = 'none';
                    spinControls.style.display   = 'block';
                    slotCode.textContent         = cfg.prefix + '•••••';
                    machineStatus.textContent    = 'ENTRY DISQUALIFIED — READY FOR REDRAW';
                    refreshStagePool();
                }
            });
        });
    }

    // Fullscreen Toggle
    if (btnFullscreen) {
        btnFullscreen.addEventListener('click', function () {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen().catch(() => {});
            } else {
                document.exitFullscreen();
            }
        });
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    refreshStagePool();
});

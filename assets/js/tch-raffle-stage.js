/**
 * TCH Event Raffle v2.0 — 5-Engine Broadcast Controller & Real-Time Spectator Sync
 */
document.addEventListener('DOMContentLoaded', function () {
    if (typeof window.tchStageConfig === 'undefined') return;

    const cfg            = window.tchStageConfig;
    let isOperator       = Boolean(cfg.isOperator);
    let operatorPin      = sessionStorage.getItem('tch_operator_pin') || '';
    let activeEngine     = cfg.defaultEngine || 'slot';
    let poolList         = [];
    let currentCandidate = null;
    let lastSeenSpinId   = 0;
    let lastSeenStatus   = 'idle';
    let isAnimating      = false;
    let audioCtx         = null;

    // DOM Elements
    const statValid       = document.getElementById('tch-stat-valid');
    const machineCard     = document.getElementById('tch-machine-card');
    const machineStatus   = document.getElementById('tch-machine-status');
    const engineSelect    = document.getElementById('tch-engine-select');
    const operatorToolbar = document.getElementById('tch-operator-toolbar');
    const btnPinLogin     = document.getElementById('tch-btn-pin-login');
    const pinModal        = document.getElementById('tch-pin-modal');
    const pinInput        = document.getElementById('tch-pin-input');
    const pinSubmit       = document.getElementById('tch-pin-submit');
    const pinCancel       = document.getElementById('tch-pin-cancel');

    const viewSlot        = document.getElementById('tch-engine-slot');
    const viewDrum        = document.getElementById('tch-engine-drum');
    const viewWheel       = document.getElementById('tch-engine-wheel');
    const viewLotto       = document.getElementById('tch-engine-lotto');

    const slotCode        = document.getElementById('tch-slot-code');
    const drumBarrel      = document.getElementById('tch-drum-barrel');
    const wheelWrapper    = document.getElementById('tch-wheel-wrapper');
    const wheelCanvas     = document.getElementById('tch-wheel-canvas');
    const lottoCanvas     = document.getElementById('tch-lotto-canvas');
    const lottoRack       = document.getElementById('tch-lotto-rack');

    const candidatePanel  = document.getElementById('tch-candidate-panel');
    const candidateBadge  = document.getElementById('tch-candidate-ref-badge');
    const candidateName   = document.getElementById('tch-candidate-name');
    const candidateMeta   = document.getElementById('tch-candidate-meta');
    const verifyControls  = document.getElementById('tch-verify-controls');
    const spinControls    = document.getElementById('tch-spin-controls');
    const spectatorBanner = document.getElementById('tch-spectator-banner');
    const btnSpin         = document.getElementById('tch-btn-spin');
    const btnConfirm      = document.getElementById('tch-btn-confirm');
    const btnDisqualify   = document.getElementById('tch-btn-disqualify');
    const btnFullscreen   = document.getElementById('tch-btn-fullscreen');
    const winnersBox      = document.getElementById('tch-winners-box');
    const winnersList     = document.getElementById('tch-winners-list');

    // Web Audio Synthesizer
    function initAudio() {
        if (!audioCtx) {
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            if (AudioContext) audioCtx = new AudioContext();
        }
        if (audioCtx && audioCtx.state === 'suspended') {
            audioCtx.resume();
        }
    }

    function playTick(freq = 500) {
        if (!audioCtx) return;
        const osc  = audioCtx.createOscillator();
        const gain = audioCtx.createGain();
        osc.type = 'triangle';
        osc.frequency.setValueAtTime(freq, audioCtx.currentTime);
        gain.gain.setValueAtTime(0.11, audioCtx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.04);
        osc.connect(gain);
        gain.connect(audioCtx.destination);
        osc.start();
        osc.stop(audioCtx.currentTime + 0.045);
    }

    function playFanfare() {
        if (!audioCtx) return;
        [523.25, 659.25, 783.99, 1046.5].forEach((freq, idx) => {
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

    function fireConfetti() {
        if (typeof confetti !== 'function') return;
        const end = Date.now() + 3800;
        (function frame() {
            confetti({ particleCount: 6, angle: 60, spread: 55, origin: { x: 0, y: 0.65 }, colors: ['#facc15', '#22c55e', '#38bdf8'] });
            confetti({ particleCount: 6, angle: 120, spread: 55, origin: { x: 1, y: 0.65 }, colors: ['#facc15', '#22c55e', '#38bdf8'] });
            if (Date.now() < end) requestAnimationFrame(frame);
        })();
    }

    // AJAX Helper
    function stageRequest(subAction, extra = {}) {
        const fd = new FormData();
        fd.append('action', 'tch_stage_action');
        fd.append('nonce', cfg.nonce);
        fd.append('sub_action', subAction);
        if (operatorPin) fd.append('operator_pin', operatorPin);
        for (const k in extra) fd.append(k, extra[k]);
        return fetch(cfg.ajaxUrl, { method: 'POST', body: fd }).then(r => r.json());
    }

    // Unlock UI for Operator
    function enableOperatorUI() {
        isOperator                    = true;
        operatorToolbar.style.display = 'flex';
        btnPinLogin.style.display     = 'none';
        spectatorBanner.style.display = 'none';
        if (!isAnimating) {
            spinControls.style.display = 'block';
        }
        if (currentCandidate && lastSeenStatus !== 'winner_confirmed') {
            verifyControls.style.display = 'block';
        }
    }

    // Engine Visibility Switcher
    function setEngineView(engine) {
        activeEngine = engine;
        if (engineSelect) engineSelect.value = engine;

        viewSlot.style.display  = engine === 'slot' ? 'block' : 'none';
        viewDrum.style.display  = engine === 'drum' ? 'block' : 'none';
        viewWheel.style.display = (engine === 'cyber_wheel' || engine === 'carnival_wheel') ? 'block' : 'none';
        viewLotto.style.display = engine === 'lotto_cage' ? 'block' : 'none';

        if (engine === 'drum') renderDrumBars(['?', '?', '?', '?', '? READY ?', '?', '?', '?', '?']);
        if (engine === 'cyber_wheel' || engine === 'carnival_wheel') {
            wheelWrapper.className = 'tch-wheel-stage ' + (engine === 'cyber_wheel' ? 'is-cyber' : 'is-carnival');
            drawWheelCanvas(0, buildWheelSlices(null));
        }
        if (engine === 'lotto_cage') {
            startIdleLottoCage();
        }
    }

    // =========================================================================
    // ENGINE 2: 3D VERTICAL COLOR DRUM RENDERER
    // =========================================================================
    function renderDrumBars(items) {
        drumBarrel.innerHTML = items.slice(0, 9).map(txt => `<div class="tch-drum-bar">${escapeHtml(txt)}</div>`).join('');
    }

    // =========================================================================
    // ENGINE 3 & 4: NEON CYBER WHEEL & CARNIVAL WHEEL CANVAS ENGINE
    // =========================================================================
    function buildWheelSlices(candidate) {
        const slices = [];
        const source = (candidate && candidate.decoys && candidate.decoys.length) ? candidate.decoys : poolList;
        for (let i = 0; i < 15; i++) {
            if (source.length > 0) {
                const item = source[i % source.length];
                slices.push(item.ref_code);
            } else {
                slices.push(cfg.prefix + (1000 + i));
            }
        }
        // Index 0 is the winning wedge at top pointer
        slices.unshift(candidate ? candidate.ref_code : (cfg.prefix + 'WIN'));
        return slices;
    }

    function drawWheelCanvas(rotationDeg, slices) {
        if (!wheelCanvas) return;
        const ctx    = wheelCanvas.getContext('2d');
        const cx     = wheelCanvas.width / 2;
        const cy     = wheelCanvas.height / 2;
        const radius = cx - 18;
        const count  = slices.length;
        const arc    = (2 * Math.PI) / count;
        const rotRad = (rotationDeg * Math.PI) / 180;

        ctx.clearRect(0, 0, wheelCanvas.width, wheelCanvas.height);

        // Outer Rim
        ctx.beginPath();
        ctx.arc(cx, cy, radius + 12, 0, 2 * Math.PI);
        ctx.fillStyle = activeEngine === 'cyber_wheel' ? '#082f49' : '#b91c1c';
        ctx.fill();
        ctx.lineWidth = 4;
        ctx.strokeStyle = activeEngine === 'cyber_wheel' ? '#38bdf8' : '#facc15';
        ctx.stroke();

        // Draw Slices (Centered so slice 0 aligns with top -90deg pointer when rotationDeg=0)
        for (let i = 0; i < count; i++) {
            const startAngle = rotRad + (i * arc) - (Math.PI / 2) - (arc / 2);
            const endAngle   = startAngle + arc;

            ctx.beginPath();
            ctx.moveTo(cx, cy);
            ctx.arc(cx, cy, radius, startAngle, endAngle);
            ctx.closePath();

            if (activeEngine === 'cyber_wheel') {
                ctx.fillStyle   = i % 2 === 0 ? '#0c4a6e' : '#1e293b';
                ctx.strokeStyle = '#38bdf8';
            } else {
                ctx.fillStyle   = i % 2 === 0 ? '#dc2626' : '#fef08a';
                ctx.strokeStyle = '#eab308';
            }
            ctx.fill();
            ctx.lineWidth = 1.5;
            ctx.stroke();

            // Slice Label
            ctx.save();
            ctx.translate(cx, cy);
            ctx.rotate(startAngle + arc / 2);
            ctx.textAlign = 'right';
            ctx.fillStyle = (activeEngine === 'carnival_wheel' && i % 2 !== 0) ? '#7f1d1d' : '#ffffff';
            ctx.font      = 'bold 13px monospace';
            ctx.fillText(slices[i], radius - 16, 4);
            ctx.restore();
        }

        // Rim Bulbs / Cyber Ticks
        const bulbCount = 32;
        for (let b = 0; b < bulbCount; b++) {
            const bAngle = (b * 2 * Math.PI) / bulbCount + rotRad;
            const bx     = cx + Math.cos(bAngle) * (radius + 6);
            const by     = cy + Math.sin(bAngle) * (radius + 6);
            ctx.beginPath();
            ctx.arc(bx, by, activeEngine === 'cyber_wheel' ? 2.2 : 4, 0, 2 * Math.PI);
            ctx.fillStyle = activeEngine === 'cyber_wheel' ? '#7dd3fc' : (b % 2 === 0 ? '#fef08a' : '#ffffff');
            ctx.fill();
        }
    }

    // =========================================================================
    // ENGINE 5: LOTTO BALL CAGE PHYSICS SIMULATOR
    // =========================================================================
    const lottoBalls = [];
    const ballColors = ['#ef4444', '#22c55e', '#3b82f6', '#eab308', '#a855f7', '#ec4899', '#f97316'];
    let lottoAnimId  = null;
    let paddleAngle  = 0;
    let cageSpeed    = 0.03;

    function initLottoBalls() {
        if (lottoBalls.length > 0) return;
        for (let i = 0; i < 28; i++) {
            lottoBalls.push({
                x: 160 + (Math.random() - 0.5) * 140,
                y: 160 + (Math.random() - 0.5) * 140,
                vx: (Math.random() - 0.5) * 5,
                vy: (Math.random() - 0.5) * 5,
                r: 17,
                color: ballColors[i % ballColors.length],
                label: String((i % 90) + 10)
            });
        }
    }

    function startIdleLottoCage() {
        initLottoBalls();
        if (lottoAnimId) cancelAnimationFrame(lottoAnimId);
        const ctx = lottoCanvas.getContext('2d');

        function stepLotto() {
            if (activeEngine !== 'lotto_cage') return;
            ctx.clearRect(0, 0, 320, 320);
            paddleAngle += cageSpeed;

            // Update & Draw Balls
            lottoBalls.forEach(b => {
                b.vy += 0.18; // Gravity
                b.x  += b.vx;
                b.y  += b.vy;

                // Circular Dome Collision
                const dx   = b.x - 160;
                const dy   = b.y - 160;
                const dist = Math.sqrt(dx * dx + dy * dy);
                const maxR = 140 - b.r;

                if (dist > maxR) {
                    const nx = dx / dist;
                    const ny = dy / dist;
                    b.x = 160 + nx * maxR;
                    b.y = 160 + ny * maxR;
                    // Bounce + Paddle Kick
                    const dot = b.vx * nx + b.vy * ny;
                    b.vx = (b.vx - 2 * dot * nx) * 0.85 + (-ny * cageSpeed * 95);
                    b.vy = (b.vy - 2 * dot * ny) * 0.85 + (nx * cageSpeed * 95);
                }

                // Draw Sphere
                ctx.beginPath();
                ctx.arc(b.x, b.y, b.r, 0, 2 * Math.PI);
                ctx.fillStyle = b.color;
                ctx.fill();
                ctx.lineWidth = 1.5;
                ctx.strokeStyle = 'rgba(255,255,255,0.65)';
                ctx.stroke();

                // Number Circle
                ctx.beginPath();
                ctx.arc(b.x, b.y, 9, 0, 2 * Math.PI);
                ctx.fillStyle = '#ffffff';
                ctx.fill();

                ctx.fillStyle = '#0f172a';
                ctx.font      = 'bold 10px sans-serif';
                ctx.textAlign = 'center';
                ctx.fillText(b.label, b.x, b.y + 3.5);
            });

            // Draw Rotating 4-Blade Metallic Paddle (Matching Attachment 4)
            ctx.save();
            ctx.translate(160, 160);
            ctx.rotate(paddleAngle);
            ctx.strokeStyle = 'rgba(203, 213, 225, 0.78)';
            ctx.lineWidth   = 10;
            ctx.lineCap     = 'round';
            for (let arm = 0; arm < 4; arm++) {
                ctx.beginPath();
                ctx.moveTo(0, 0);
                ctx.lineTo(132, 0);
                ctx.stroke();
                ctx.rotate(Math.PI / 2);
            }
            ctx.beginPath();
            ctx.arc(0, 0, 18, 0, 2 * Math.PI);
            ctx.fillStyle = '#94a3b8';
            ctx.fill();
            ctx.restore();

            lottoAnimId = requestAnimationFrame(stepLotto);
        }
        stepLotto();
    }

    // =========================================================================
    // MASTER ANIMATION RUNNER FOR ALL 5 ENGINES
    // =========================================================================
    function runStageAnimation(candidate) {
        isAnimating                  = true;
        currentCandidate             = candidate;
        machineCard.classList.remove('is-winner');
        candidatePanel.style.display = 'none';
        spinControls.style.display   = 'none';
        machineStatus.textContent    = 'SPINNING LIVE DRAW POOL...';

        // 1. SLOT ENGINE
        if (activeEngine === 'slot') {
            const totalSteps = 44;
            let step         = 0;
            (function tickSlot() {
                step++;
                if (step < totalSteps) {
                    const randCode = poolList.length > 0
                        ? poolList[Math.floor(Math.random() * poolList.length)].ref_code
                        : cfg.prefix + Math.random().toString(36).substring(2, 7).toUpperCase();
                    slotCode.textContent = randCode;
                    playTick(400 + step * 6);
                    setTimeout(tickSlot, Math.floor(45 + Math.pow(step / totalSteps, 3) * 280));
                } else {
                    slotCode.textContent = candidate.ref_code;
                    finishCandidateReveal(candidate);
                }
            })();
            return;
        }

        // 2. 3D VERTICAL COLOR DRUM ENGINE
        if (activeEngine === 'drum') {
            const totalSteps = 40;
            let step         = 0;
            const rolling    = [];
            for (let i = 0; i < 9; i++) {
                rolling.push(poolList.length > 0 ? poolList[i % poolList.length].ref_code : (cfg.prefix + (1000 + i)));
            }
            (function tickDrum() {
                step++;
                if (step < totalSteps) {
                    rolling.pop();
                    const nextCode = poolList.length > 0
                        ? poolList[Math.floor(Math.random() * poolList.length)].ref_code
                        : cfg.prefix + Math.random().toString(36).substring(2, 7).toUpperCase();
                    rolling.unshift(nextCode);
                    if (step === totalSteps - 1) {
                        rolling[4] = candidate.ref_code; // Lock center green bar
                    }
                    renderDrumBars(rolling);
                    playTick(360 + step * 7);
                    setTimeout(tickDrum, Math.floor(50 + Math.pow(step / totalSteps, 3) * 290));
                } else {
                    rolling[4] = '★ ' + candidate.ref_code + ' ★';
                    renderDrumBars(rolling);
                    finishCandidateReveal(candidate);
                }
            })();
            return;
        }

        // 3 & 4. NEON CYBER WHEEL & CARNIVAL SPIN WHEEL
        if (activeEngine === 'cyber_wheel' || activeEngine === 'carnival_wheel') {
            const slices     = buildWheelSlices(candidate);
            const totalDeg   = 360 * 6; // 6 full rotations landing cleanly on Slice 0 at top pointer
            const duration   = 4600;
            const startTime  = performance.now();
            let lastPegIndex = -1;

            function animateWheel(now) {
                const elapsed  = now - startTime;
                const progress = Math.min(elapsed / duration, 1);
                // Cubic ease-out
                const eased    = 1 - Math.pow(1 - progress, 3);
                const curDeg   = eased * totalDeg;

                drawWheelCanvas(curDeg, slices);

                const pegIndex = Math.floor(curDeg / (360 / slices.length));
                if (pegIndex !== lastPegIndex && progress < 0.99) {
                    lastPegIndex = pegIndex;
                    playTick(activeEngine === 'cyber_wheel' ? 680 : 520);
                }

                if (progress < 1) {
                    requestAnimationFrame(animateWheel);
                } else {
                    finishCandidateReveal(candidate);
                }
            }
            requestAnimationFrame(animateWheel);
            return;
        }

        // 5. LOTTO BALL CAGE & CHUTE
        if (activeEngine === 'lotto_cage') {
            cageSpeed = 0.14; // High-speed tumble
            const cleanChars = candidate.ref_code.replace(cfg.prefix, '').padEnd(5, '•').slice(0, 5).split('');
            lottoRack.innerHTML = `<div class="tch-rack-ball">?</div>`.repeat(5);
            const rackEls = lottoRack.querySelectorAll('.tch-rack-ball');

            cleanChars.forEach((ch, idx) => {
                setTimeout(() => {
                    rackEls[idx].textContent      = ch;
                    rackEls[idx].style.background = `radial-gradient(circle at 30% 30%, #fff 0%, ${ballColors[idx % ballColors.length]} 70%)`;
                    rackEls[idx].style.color      = '#070b14';
                    rackEls[idx].classList.add('is-dropped');
                    playTick(520 + idx * 90);

                    if (idx === cleanChars.length - 1) {
                        cageSpeed = 0.025;
                        setTimeout(() => finishCandidateReveal(candidate), 500);
                    }
                }, 800 + idx * 750);
            });
        }
    }

    function finishCandidateReveal(candidate) {
        isAnimating                  = false;
        machineStatus.textContent    = 'TICKET SELECTED — VERIFYING SOCIAL ACTIONS';
        candidateBadge.textContent   = candidate.ref_code;
        candidateName.textContent    = candidate.full_name;
        candidateMeta.textContent    = `WhatsApp: ${candidate.masked_phone}`;
        candidatePanel.style.display = 'block';
        verifyControls.style.display = isOperator ? 'block' : 'none';
        playFanfare();
    }

    // =========================================================================
    // REAL-TIME POOL & SPECTATOR HEARTBEAT SYNC (EVERY 2.5 SECONDS)
    // =========================================================================
    function syncStageState() {
        if (isAnimating) return;

        stageRequest('get_pool').then(res => {
            if (!res.success) return;
            poolList              = res.data.pool || [];
            statValid.textContent = res.data.valid_count;

            // Update Winners Podium
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

            const ls = res.data.live_state;
            if (!ls) return;

            // Sync Engine Selection if changed by Operator
            if (ls.engine && ls.engine !== activeEngine) {
                setEngineView(ls.engine);
            }

            // If Operator triggered a new Spin, animate on Spectator screen!
            if (ls.spin_id && ls.spin_id !== lastSeenSpinId && ls.candidate) {
                lastSeenSpinId = ls.spin_id;
                lastSeenStatus = ls.status;
                runStageAnimation(ls.candidate);
                return;
            }

            // If Winner Confirmed by Operator
            if (ls.status === 'winner_confirmed' && lastSeenStatus !== 'winner_confirmed') {
                lastSeenStatus               = 'winner_confirmed';
                machineCard.classList.add('is-winner');
                machineStatus.textContent    = '🎉 OFFICIAL GRAND PRIZE WINNER CONFIRMED! 🎉';
                verifyControls.style.display = 'none';
                if (isOperator) {
                    spinControls.style.display = 'block';
                    btnSpin.textContent        = '🎰 DRAW ANOTHER WINNER';
                }
                fireConfetti();
                playFanfare();
            }

            // If Disqualified by Operator
            if (ls.status === 'disqualified' && lastSeenStatus !== 'disqualified') {
                lastSeenStatus               = 'disqualified';
                candidatePanel.style.display = 'none';
                machineStatus.textContent    = 'TICKET DISQUALIFIED — READY FOR REDRAW';
                if (isOperator) spinControls.style.display = 'block';
            }
        }).catch(() => {});
    }

    // Operator Controls Events
    if (engineSelect) {
        engineSelect.addEventListener('change', function () {
            setEngineView(this.value);
            stageRequest('switch_engine', { engine: this.value });
        });
    }

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
                lastSeenSpinId = res.data.live_state.spin_id;
                lastSeenStatus = 'spinning';
                runStageAnimation(res.data.candidate);
            });
        });
    }

    if (btnConfirm) {
        btnConfirm.addEventListener('click', function () {
            if (!currentCandidate) return;
            initAudio();
            stageRequest('confirm_winner', { entry_id: currentCandidate.id }).then(res => {
                if (res.success) {
                    lastSeenStatus               = 'winner_confirmed';
                    machineCard.classList.add('is-winner');
                    machineStatus.textContent    = '🎉 OFFICIAL GRAND PRIZE WINNER CONFIRMED! 🎉';
                    verifyControls.style.display = 'none';
                    spinControls.style.display   = 'block';
                    btnSpin.textContent          = '🎰 DRAW ANOTHER WINNER';
                    fireConfetti();
                    playFanfare();
                    syncStageState();
                }
            });
        });
    }

    if (btnDisqualify) {
        btnDisqualify.addEventListener('click', function () {
            if (!currentCandidate) return;
            if (!confirm(`Disqualify ${currentCandidate.full_name} (${currentCandidate.ref_code}) and spin again?`)) return;
            stageRequest('disqualify_entry', { entry_id: currentCandidate.id }).then(res => {
                if (res.success) {
                    lastSeenStatus               = 'disqualified';
                    currentCandidate             = null;
                    candidatePanel.style.display = 'none';
                    spinControls.style.display   = 'block';
                    machineStatus.textContent    = 'ENTRY DISQUALIFIED — READY FOR REDRAW';
                    syncStageState();
                }
            });
        });
    }

    // Team Operator PIN Login Modal
    if (btnPinLogin) {
        btnPinLogin.addEventListener('click', () => {
            pinModal.style.display = 'flex';
            pinInput.focus();
        });
    }
    if (pinCancel) {
        pinCancel.addEventListener('click', () => {
            pinModal.style.display = 'none';
        });
    }
    if (pinSubmit) {
        pinSubmit.addEventListener('click', function () {
            const val = pinInput.value.trim();
            if (!val) return;
            operatorPin = val;
            stageRequest('verify_pin').then(res => {
                if (res.success) {
                    sessionStorage.setItem('tch_operator_pin', val);
                    pinModal.style.display = 'none';
                    enableOperatorUI();
                } else {
                    alert('Invalid Operator PIN. Please check TCH Raffle → Settings.');
                }
            });
        });
    }

    // Fullscreen Button
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

    // Initialize Default Engine View & Start 2.5s Spectator Sync Heartbeat
    setEngineView(activeEngine);
    if (operatorPin && !isOperator) {
        stageRequest('verify_pin').then(r => { if (r.success) enableOperatorUI(); });
    }
    syncStageState();
    setInterval(syncStageState, 2500);
});

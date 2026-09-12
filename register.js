// ================================================
// register.js – Register Page Scripts
// ================================================

document.addEventListener('DOMContentLoaded', function () {
    const text = window.registerText || {};

    // ── Toggle Password Visibility ─────────────────
    const EYE_OPEN   = `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>`;
    const EYE_CLOSED = `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>`;

    document.querySelectorAll('.pw-toggle').forEach(btn => {
        btn.addEventListener('click', function () {
            const targetId = this.getAttribute('data-target');
            const input    = document.getElementById(targetId);
            const icon     = this.querySelector('svg');
            if (!input || !icon) return;
            const isHidden = input.type === 'password';
            input.type     = isHidden ? 'text' : 'password';
            icon.innerHTML = isHidden ? EYE_CLOSED : EYE_OPEN;
        });
    });

    // ── Password Strength Meter ────────────────────
    const pwInput       = document.getElementById('password');
    const strengthFill  = document.getElementById('strengthFill');
    const strengthLabel = document.getElementById('strengthLabel');

    const strengthLabels = text.strength || ['ضعيف جداً', 'ضعيف', 'متوسط', 'جيد', 'قوي'];
    const levels = [
        { label: strengthLabels[0], color: '#E53E3E', pct: 20 },
        { label: strengthLabels[1], color: '#E53E3E', pct: 40 },
        { label: strengthLabels[2], color: '#D97706', pct: 60 },
        { label: strengthLabels[3], color: '#65A30D', pct: 80 },
        { label: strengthLabels[4], color: '#16A34A', pct: 100 },
    ];

    function getStrength(pw) {
        let score = 0;
        if (pw.length >= 8)       score++;
        if (/[A-Z]/.test(pw))     score++;
        if (/[a-z]/.test(pw))     score++;
        if (/[0-9]/.test(pw))     score++;
        return score; // 0-4; strong requires every criterion
    }

    function isStrongPassword(pw) {
        return pw.length >= 8 &&
            /[A-Z]/.test(pw) &&
            /[a-z]/.test(pw) &&
            /[0-9]/.test(pw);
    }

    if (pwInput && strengthFill && strengthLabel) {
        pwInput.addEventListener('input', function () {
            const val = this.value;
            if (!val) {
                strengthFill.style.width      = '0%';
                strengthFill.style.background = '';
                strengthLabel.textContent     = '';
                strengthLabel.style.color     = '';
                return;
            }
            const idx   = getStrength(val);
            const level = levels[idx];
            strengthFill.style.width      = level.pct + '%';
            strengthFill.style.background = level.color;
            strengthLabel.textContent     = level.label;
            strengthLabel.style.color     = level.color;
        });
    }

    // ── Password Match Check ───────────────────────
    const pwConfirm  = document.getElementById('password_confirm');
    const matchLabel = document.getElementById('matchLabel');

    function checkMatch() {
        if (!pwConfirm || !matchLabel || !pwInput) return;
        const val = pwConfirm.value;
        if (!val) { matchLabel.textContent = ''; return; }

        if (val === pwInput.value) {
            matchLabel.textContent = text.passwordMatch || '✓ كلمتا المرور متطابقتان';
            matchLabel.style.color = '#16A34A';
        } else {
            matchLabel.textContent = text.passwordMismatch || '✗ كلمتا المرور غير متطابقتين';
            matchLabel.style.color = '#E53E3E';
        }
    }

    if (pwConfirm) pwConfirm.addEventListener('input', checkMatch);
    if (pwInput)   pwInput.addEventListener('input', checkMatch);

    // ── Family/Class Selection ──────────────────────
    const familySelect = document.getElementById('churchFamily');
    const classSection = document.getElementById('classSection');
    const classSelect = document.getElementById('class_name');
    const roleInputs = document.querySelectorAll('input[name="role"]');

    function selectedRole() {
        const checked = document.querySelector('input[name="role"]:checked');
        return checked ? checked.value : 'خادم';
    }

    function fillClassOptions(keepValue = '') {
        if (!familySelect || !classSelect) return;
        const classes = (window.familyClasses && window.familyClasses[familySelect.value]) || [];
        const currentValue = keepValue || classSelect.value;
        classSelect.innerHTML = '';
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.disabled = true;
        placeholder.selected = true;
        placeholder.textContent = text.classPlaceholder || 'اختر الفصل...';
        classSelect.appendChild(placeholder);

        classes.forEach(cls => {
            const option = document.createElement('option');
            option.value = cls;
            option.textContent = cls;
            if (cls === currentValue) option.selected = true;
            classSelect.appendChild(option);
        });
    }

    function toggleClassSection() {
        if (!classSection || !classSelect) return;
        const isServant = selectedRole() === 'خادم';
        classSection.style.display = isServant ? '' : 'none';
        classSelect.required = isServant;
        if (!isServant) classSelect.value = '';
    }

    if (familySelect && classSelect) {
        fillClassOptions(classSelect.value);
        familySelect.addEventListener('change', () => fillClassOptions());
    }

    roleInputs.forEach(input => input.addEventListener('change', toggleClassSection));
    toggleClassSection();

    // ── Disable Submit on Send ─────────────────────
    const regForm   = document.getElementById('regForm');
    const submitBtn = document.getElementById('submitBtn');

    if (regForm && submitBtn) {
        regForm.addEventListener('submit', function (e) {
            const pw = pwInput ? pwInput.value : '';
            if (!isStrongPassword(pw)) {
                e.preventDefault();
                // Show inline error under the strength bar
                let errEl = document.getElementById('strengthError');
                if (!errEl) {
                    errEl = document.createElement('span');
                    errEl.id = 'strengthError';
                    errEl.style.cssText = 'color:#E53E3E;font-size:0.85rem;display:block;margin-top:4px;';
                    strengthLabel.parentNode.insertBefore(errEl, strengthLabel.nextSibling);
                }
                errEl.textContent = text.weakPasswordError ||
                    'كلمة المرور يجب أن تكون قوية (حروف كبيرة + صغيرة + أرقام)';
                pwInput.focus();
                return;
            }
            // Clear any previous error
            const errEl = document.getElementById('strengthError');
            if (errEl) errEl.textContent = '';

            submitBtn.disabled    = true;
            submitBtn.textContent = text.creating || 'جاري الإنشاء...';
        });
    }

});

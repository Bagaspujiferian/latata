import Alpine from 'alpinejs';

window.Alpine = Alpine;

window.formatRupiah = (value) => {
    const num = Math.round(Number(value) || 0);
    return 'Rp ' + num.toLocaleString('id-ID');
};

window.nexaToast = (type, title, message) => {
    window.dispatchEvent(new CustomEvent('nexa-toast', {
        detail: { type, title, message },
    }));
};

/* ============================================================================
   GLOBAL TOP NAVIGATION PROGRESS BAR
   ============================================================================ */
Alpine.data('navProgressBar', () => ({
    visible: false,
    progress: 0,
    trickleTimer: null,

    init() {
        document.addEventListener('click', (event) => {
            const link = event.target.closest('a[href]');
            if (!link) return;
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
            if (link.hasAttribute('download') || link.target === '_blank' || link.dataset.noProgress !== undefined) return;

            const href = link.getAttribute('href') || '';
            if (href === '' || href.startsWith('#') || href.startsWith('javascript:') || href.startsWith('mailto:')) return;

            try {
                const url = new URL(link.href, window.location.origin);
                if (url.origin !== window.location.origin) return;
                if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash) return;
            } catch {
                return;
            }

            this.start();
        }, true);

        document.addEventListener('submit', (event) => {
            const form = event.target;
            if (!form || form.dataset.noProgress !== undefined) return;
            if (event.defaultPrevented) return;
            this.start();
        }, true);

        window.addEventListener('pageshow', () => {
            this.finish();
        });

        window.addEventListener('nexa-progress-start', () => this.start());
        window.addEventListener('nexa-progress-done', () => this.finish());
    },

    start() {
        this.clearTimer();
        this.visible = true;
        this.progress = 0.22;

        this.trickleTimer = setInterval(() => {
            if (this.progress < 0.88) {
                const step = (0.92 - this.progress) * 0.24;
                this.progress = Math.min(0.92, this.progress + Math.max(0.02, step));
            }
        }, 180);
    },

    finish() {
        this.clearTimer();
        if (!this.visible) return;
        this.progress = 1;
        setTimeout(() => {
            this.visible = false;
            this.progress = 0;
        }, 200);
    },

    clearTimer() {
        if (this.trickleTimer) {
            clearInterval(this.trickleTimer);
            this.trickleTimer = null;
        }
    },
}));

/* ============================================================================
   FLOATING TOAST NOTIFICATION MANAGER (CRISP LIGHT MODE)
   ============================================================================ */
Alpine.data('toastManager', (initialToasts = []) => ({
    toasts: [],
    nextId: 1,

    init() {
        (initialToasts || []).forEach((t, idx) => {
            setTimeout(() => {
                this.push(t.type || 'success', t.title || 'Informasi', t.message || '', t.items || []);
            }, idx * 80);
        });

        window.addEventListener('nexa-toast', (event) => {
            const d = event.detail || {};
            this.push(d.type || 'success', d.title || 'Informasi', d.message || '', d.items || []);
        });
    },

    push(type, title, message, items = []) {
        const id = this.nextId++;
        const duration = type === 'error' ? 7500 : 5500;
        const toast = {
            id,
            type,
            title,
            message,
            items,
            duration,
            paused: false,
            visible: true,
            remainingMs: duration,
            startedAt: Date.now(),
            timer: null,
        };

        this.toasts.push(toast);
        this.scheduleDismiss(toast);
    },

    scheduleDismiss(toast) {
        if (toast.timer) clearTimeout(toast.timer);
        toast.startedAt = Date.now();
        toast.timer = setTimeout(() => {
            this.dismiss(toast.id);
        }, toast.remainingMs);
    },

    pause(toast) {
        if (toast.paused) return;
        toast.paused = true;
        if (toast.timer) clearTimeout(toast.timer);
        const elapsed = Date.now() - toast.startedAt;
        toast.remainingMs = Math.max(600, toast.remainingMs - elapsed);
    },

    resume(toast) {
        if (!toast.paused) return;
        toast.paused = false;
        this.scheduleDismiss(toast);
    },

    dismiss(id) {
        const toast = this.toasts.find((t) => t.id === id);
        if (!toast) return;
        if (toast.timer) clearTimeout(toast.timer);
        toast.visible = false;
        setTimeout(() => {
            this.toasts = this.toasts.filter((t) => t.id !== id);
        }, 220);
    },
}));

/* ============================================================================
   LIVE SLA & AUTO-COMPLETE COUNTDOWN ENGINE
   ============================================================================ */
Alpine.data('liveCountdown', (isoDeadline) => ({
    deadlineMs: isoDeadline ? new Date(isoDeadline).getTime() : 0,
    remainingSec: 0,
    expired: false,
    intervalId: null,

    init() {
        this.tick();
        this.intervalId = setInterval(() => this.tick(), 1000);
    },

    destroy() {
        if (this.intervalId) {
            clearInterval(this.intervalId);
        }
    },

    tick() {
        if (!this.deadlineMs) {
            this.expired = true;
            this.remainingSec = 0;
            return;
        }
        const diff = Math.floor((this.deadlineMs - Date.now()) / 1000);
        if (diff <= 0) {
            this.remainingSec = 0;
            this.expired = true;
        } else {
            this.remainingSec = diff;
            this.expired = false;
        }
    },

    get isUrgent() {
        return !this.expired && this.remainingSec > 0 && this.remainingSec <= 180;
    },

    get formatted() {
        if (this.expired) {
            return '00:00:00';
        }
        const h = String(Math.floor(this.remainingSec / 3600)).padStart(2, '0');
        const m = String(Math.floor((this.remainingSec % 3600) / 60)).padStart(2, '0');
        const s = String(this.remainingSec % 60).padStart(2, '0');
        return `${h}:${m}:${s}`;
    },
}));

Alpine.start();

/**
 * Global Form Double-Submit & Action Button Loading Protection
 * Mencegah klik ganda / redundansi pada form dan memberikan animasi loading spinner.
 */
function initPreventDoubleSubmit() {
    // 1. Cegah double-submit pada seluruh form secara global
    document.addEventListener('submit', function (e) {
        const form = e.target;
        if (!form || form.tagName !== 'FORM') return;

        // Abaikan jika form dikecualikan secara eksplisit
        if (form.hasAttribute('data-no-loading') || form.hasAttribute('data-no-prevent-double-submit')) {
            return;
        }

        // Abaikan jika aksi dibatalkan (misal: user menekan 'Cancel' pada confirm dialog)
        if (e.defaultPrevented) {
            return;
        }

        // Cek validasi HTML5 form
        if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
            return;
        }

        // Cegah pengiriman jika form sedang dalam proses pengiriman
        if (form.dataset.submitting === 'true') {
            e.preventDefault();
            e.stopPropagation();
            return false;
        }

        // Cari tombol submit pemicu
        let submitBtn = e.submitter;
        if (!submitBtn) {
            submitBtn = form.querySelector('button[type="submit"]:not([data-no-loading]), input[type="submit"]:not([data-no-loading])');
        }
        if (!submitBtn && form.id) {
            submitBtn = document.querySelector(`button[type="submit"][form="${form.id}"]:not([data-no-loading])`);
        }

        form.dataset.submitting = 'true';

        if (submitBtn && !submitBtn.hasAttribute('data-no-loading')) {
            if (!submitBtn.dataset.originalHtml) {
                submitBtn.dataset.originalHtml = submitBtn.innerHTML;
            }

            // Pertahankan lebar tombol agar layout tidak bergeser (jumping)
            const currentWidth = submitBtn.offsetWidth;
            if (currentWidth > 0 && !submitBtn.style.minWidth) {
                submitBtn.style.minWidth = `${currentWidth}px`;
            }

            const customText = submitBtn.getAttribute('data-loading-text') 
                || form.getAttribute('data-loading-text') 
                || 'Memproses...';

            if (submitBtn.tagName === 'BUTTON') {
                submitBtn.innerHTML = `<span class="inline-flex items-center justify-center gap-1.5"><i class="fas fa-circle-notch fa-spin text-xs"></i><span>${customText}</span></span>`;
            } else {
                submitBtn.value = customText;
            }

            submitBtn.classList.add('opacity-75', 'cursor-not-allowed', 'pointer-events-none');

            // Beri sedikit jeda sebelum disabled=true agar browser sempat mengirimkan form data
            setTimeout(() => {
                submitBtn.disabled = true;
            }, 20);
        }

        // Nonaktifkan link batal terdekat agar pengguna tidak membatalkan di tengah proses
        const cancelLinks = form.querySelectorAll('a[href]:not([target="_blank"])');
        cancelLinks.forEach(link => {
            link.classList.add('pointer-events-none', 'opacity-50');
        });
    });

    // 2. Pulihkan tombol jika pengguna kembali menggunakan navigasi browser (bfcache)
    window.addEventListener('pageshow', function () {
        document.querySelectorAll('form[data-submitting="true"]').forEach(form => {
            form.dataset.submitting = 'false';
            
            const submitBtns = form.querySelectorAll('button[type="submit"], input[type="submit"]');
            submitBtns.forEach(btn => {
                btn.disabled = false;
                btn.classList.remove('opacity-75', 'cursor-not-allowed', 'pointer-events-none');
                if (btn.dataset.originalHtml) {
                    btn.innerHTML = btn.dataset.originalHtml;
                }
            });

            if (form.id) {
                const extBtns = document.querySelectorAll(`button[type="submit"][form="${form.id}"]`);
                extBtns.forEach(btn => {
                    btn.disabled = false;
                    btn.classList.remove('opacity-75', 'cursor-not-allowed', 'pointer-events-none');
                    if (btn.dataset.originalHtml) {
                        btn.innerHTML = btn.dataset.originalHtml;
                    }
                });
            }

            const cancelLinks = form.querySelectorAll('a[href]:not([target="_blank"])');
            cancelLinks.forEach(link => {
                link.classList.remove('pointer-events-none', 'opacity-50');
            });
        });
    });
}

// Inisialisasi otomatis saat script dimuat
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPreventDoubleSubmit);
} else {
    initPreventDoubleSubmit();
}

export default initPreventDoubleSubmit;

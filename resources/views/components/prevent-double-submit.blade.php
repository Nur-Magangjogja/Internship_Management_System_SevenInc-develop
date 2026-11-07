{{-- Script Global Pencegahan Double Submit Form & Animasi Loading --}}
<script>
(function() {
    if (typeof window !== 'undefined') {
        if (window.__preventDoubleSubmitLoaded) return;
        window.__preventDoubleSubmitLoaded = true;
    }

    function resetFormState(form) {
        if (!form) return;
        form.dataset.submitting = 'false';
        form.dataset.preventDoubleSubmitActive = 'false';

        const submitBtns = form.querySelectorAll('button[type="submit"], input[type="submit"]');
        submitBtns.forEach(function(btn) {
            btn.disabled = false;
            btn.classList.remove('opacity-75', 'cursor-not-allowed', 'pointer-events-none');
            if (btn.dataset.originalHtml) {
                btn.innerHTML = btn.dataset.originalHtml;
            }
        });

        if (form.id) {
            const extBtns = document.querySelectorAll('button[type="submit"][form="' + form.id + '"]');
            extBtns.forEach(function(btn) {
                btn.disabled = false;
                btn.classList.remove('opacity-75', 'cursor-not-allowed', 'pointer-events-none');
                if (btn.dataset.originalHtml) {
                    btn.innerHTML = btn.dataset.originalHtml;
                }
            });
        }

        const cancelLinks = form.querySelectorAll('a[href]:not([target="_blank"])');
        cancelLinks.forEach(function(link) {
            link.classList.remove('pointer-events-none', 'opacity-50');
        });
    }

    document.addEventListener('submit', function (e) {
        const form = e.target;
        if (!form || form.tagName !== 'FORM') return;

        if (form.hasAttribute('data-no-loading') || form.hasAttribute('data-no-prevent-double-submit')) {
            return;
        }

        if (e.defaultPrevented) return;

        if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
            return;
        }

        // Cegah pengiriman ganda jika form sedang diproses oleh script ini
        if (form.dataset.preventDoubleSubmitActive === 'true') {
            e.preventDefault();
            e.stopPropagation();
            return false;
        }

        let submitBtn = e.submitter;
        if (!submitBtn) {
            submitBtn = form.querySelector('button[type="submit"]:not([data-no-loading]), input[type="submit"]:not([data-no-loading])');
        }
        if (!submitBtn && form.id) {
            submitBtn = document.querySelector('button[type="submit"][form="' + form.id + '"]:not([data-no-loading])');
        }

        form.dataset.submitting = 'true';
        form.dataset.preventDoubleSubmitActive = 'true';

        if (submitBtn && !submitBtn.hasAttribute('data-no-loading')) {
            if (!submitBtn.dataset.originalHtml) {
                submitBtn.dataset.originalHtml = submitBtn.innerHTML;
            }

            const currentWidth = submitBtn.offsetWidth;
            if (currentWidth > 0 && !submitBtn.style.minWidth) {
                submitBtn.style.minWidth = currentWidth + 'px';
            }

            const customText = submitBtn.getAttribute('data-loading-text') 
                || form.getAttribute('data-loading-text') 
                || 'Memproses...';

            if (submitBtn.tagName === 'BUTTON') {
                submitBtn.innerHTML = '<span class="inline-flex items-center justify-center gap-1.5"><i class="fas fa-circle-notch fa-spin text-xs"></i><span>' + customText + '</span></span>';
            } else {
                submitBtn.value = customText;
            }

            submitBtn.classList.add('opacity-75', 'cursor-not-allowed', 'pointer-events-none');

            setTimeout(function() {
                submitBtn.disabled = true;
            }, 20);
        }

        const cancelLinks = form.querySelectorAll('a[href]:not([target="_blank"])');
        cancelLinks.forEach(function(link) {
            link.classList.add('pointer-events-none', 'opacity-50');
        });

        setTimeout(function() {
            if (form.dataset.preventDoubleSubmitActive === 'true' || form.dataset.submitting === 'true') {
                resetFormState(form);
            }
        }, 15000);
    });

    window.addEventListener('pageshow', function () {
        document.querySelectorAll('form[data-submitting="true"], form[data-prevent-double-submit-active="true"]').forEach(function(form) {
            resetFormState(form);
        });
    });
})();
</script>

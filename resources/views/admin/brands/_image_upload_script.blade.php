<script>
function showBrandClientError(fieldName, msg) {
    const errBox = document.getElementById('client_error_' + fieldName);
    const errMsg = document.getElementById('client_error_msg_' + fieldName);
    const cardWrap = document.getElementById('card_wrap_' + fieldName);

    if (errMsg) errMsg.textContent = msg;
    if (errBox) errBox.classList.remove('hidden');
    if (cardWrap) {
        cardWrap.classList.add('border-red-400', 'ring-1', 'ring-red-400');
        cardWrap.classList.remove('border-[#DCE7E1]');
    }
}

function clearBrandClientError(fieldName) {
    const errBox = document.getElementById('client_error_' + fieldName);
    const cardWrap = document.getElementById('card_wrap_' + fieldName);

    if (errBox) errBox.classList.add('hidden');
    if (cardWrap) {
        cardWrap.classList.remove('border-red-400', 'ring-1', 'ring-red-400');
        cardWrap.classList.add('border-[#DCE7E1]');
    }
}

function triggerBrandFileInput(fieldName) {
    clearBrandClientError(fieldName);
    const input = document.getElementById('file_input_' + fieldName);
    if (input) input.click();
}

function cancelBrandFileInput(fieldName, hasCurrent) {
    clearBrandClientError(fieldName);
    const input = document.getElementById('file_input_' + fieldName);
    if (input) input.value = '';

    const previewBox = document.getElementById('preview_box_' + fieldName);
    if (previewBox) previewBox.classList.add('hidden');

    const currentBox = document.getElementById('current_box_' + fieldName);
    const dropzone = document.getElementById('dropzone_' + fieldName);

    if (hasCurrent && currentBox) {
        currentBox.classList.remove('hidden');
        if (dropzone) dropzone.classList.add('hidden');
    } else if (dropzone) {
        dropzone.classList.remove('hidden');
    }
}

function formatBytes(bytes, decimals = 1) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const dm = decimals < 0 ? 0 : decimals;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
}

document.addEventListener('DOMContentLoaded', function () {
    const fileInputs = document.querySelectorAll('.brand-file-input');

    fileInputs.forEach(input => {
        const fieldName = input.getAttribute('data-field');
        const dropzone = document.getElementById('dropzone_' + fieldName);
        const previewBox = document.getElementById('preview_box_' + fieldName);
        const currentBox = document.getElementById('current_box_' + fieldName);
        const imgPreview = document.getElementById('img_preview_' + fieldName);
        const filenameEl = document.getElementById('filename_' + fieldName);
        const filesizeEl = document.getElementById('filesize_' + fieldName);

        function handleFile(file) {
            clearBrandClientError(fieldName);
            if (!file) return;

            // Validasi tipe berkas gambar
            if (!file.type.match('image.*')) {
                showBrandClientError(fieldName, 'Berkas harus berupa gambar yang valid (JPG, PNG, atau WEBP).');
                input.value = '';
                return;
            }

            // Batas ukuran (default 2MB, membercard 3MB)
            const maxMb = fieldName === 'membercard_bg' ? 3 : 2;
            const maxBytes = maxMb * 1024 * 1024;
            if (file.size > maxBytes) {
                showBrandClientError(fieldName, `Ukuran berkas "${file.name}" (${formatBytes(file.size)}) melebihi batas maksimal ${maxMb}MB. Silakan pilih berkas lain.`);
                input.value = '';
                return;
            }

            const reader = new FileReader();
            reader.onload = function (e) {
                if (imgPreview) imgPreview.src = e.target.result;
                if (filenameEl) filenameEl.textContent = file.name;
                if (filesizeEl) filesizeEl.textContent = formatBytes(file.size);

                if (dropzone) dropzone.classList.add('hidden');
                if (currentBox) currentBox.classList.add('hidden');
                if (previewBox) previewBox.classList.remove('hidden');
            };
            reader.readAsDataURL(file);
        }

        input.addEventListener('change', function () {
            if (this.files && this.files[0]) {
                handleFile(this.files[0]);
            }
        });

        // Drag & Drop handlers pada dropzone
        if (dropzone) {
            ['dragenter', 'dragover'].forEach(eventName => {
                dropzone.addEventListener(eventName, function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dropzone.classList.add('border-[#2D8659]', 'bg-[#F0F7F3]', 'scale-[1.01]');
                }, false);
            });

            ['dragleave', 'drop'].forEach(eventName => {
                dropzone.addEventListener(eventName, function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dropzone.classList.remove('border-[#2D8659]', 'bg-[#F0F7F3]', 'scale-[1.01]');
                }, false);
            });

            dropzone.addEventListener('drop', function (e) {
                const dt = e.dataTransfer;
                if (dt && dt.files && dt.files.length > 0) {
                    input.files = dt.files;
                    handleFile(dt.files[0]);
                }
            }, false);
        }
    });
});
</script>

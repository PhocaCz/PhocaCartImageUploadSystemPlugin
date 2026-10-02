document.addEventListener('DOMContentLoaded', () => {
    const config = Joomla.getOptions('phocacartImageUploadConfig');
    const lang = Joomla.getOptions('phocacartImageUploadLang') || {};
    const existingImages = Joomla.getOptions('phocacartImageUploadExistingImages') || [];

    if (!config) {
        console.log('PhocaCart Image Uploader: No configuration found');
        return;
    }

    const uploaderEl = document.querySelector('#phocacart-imageupload-uploader');
    if (!uploaderEl) {
        console.log('PhocaCart Image Uploader: Uploader element not found');
        return;
    }

    const uploader = new PhocacartImageUploader(uploaderEl, config, lang, existingImages);
    uploader.init();
});

class PhocacartImageUploader {
    constructor(wrapper, config, lang, existingImages) {
        this.wrapper = wrapper;
        this.config = config;
        this.lang = lang;
        this.images = existingImages.map((img, idx) => ({
            ...img,
            order: idx + 1
        }));
        this.sortableInstance = null;
    }

    init() {
        this.cacheElements();
        this.initDragAndDrop();
        this.initSelectButton();
        this.initSortable();
        this.initDeleteButtons();
        this.initDeleteAllButton();
        this.commitState();
    }

    cacheElements() {
        this.dropzone = this.wrapper.querySelector('.phocacart-imageupload-dropzone');
        this.fileInput = this.wrapper.querySelector('.phocacart-imageupload-file-input');
        this.selectBtn = this.wrapper.querySelector('.phocacart-imageupload-select-btn');
        this.progressBar = this.wrapper.querySelector('.phocacart-imageupload-progress');
        this.progressBarFill = this.wrapper.querySelector('.phocacart-imageupload-progress-bar-fill');
        this.errorContainer = this.wrapper.querySelector('.phocacart-imageupload-error');
        this.gallery = this.wrapper.querySelector('.phocacart-imageupload-gallery');
        this.deleteAllBtn = this.wrapper.querySelector('.phocacart-imageupload-delete-all-btn');

        // Find the hidden input field
        this.dataInput = document.querySelector('[name="jform[phocacartimageupload_images_data]"]');
    }

    initDragAndDrop() {
        if (!this.dropzone) return;

        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            this.dropzone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
            }, false);
        });

        ['dragenter', 'dragover'].forEach(eventName => {
            this.dropzone.addEventListener(eventName, () => {
                this.dropzone.classList.add('highlight');
            }, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            this.dropzone.addEventListener(eventName, () => {
                this.dropzone.classList.remove('highlight');
            }, false);
        });

        this.dropzone.addEventListener('drop', (e) => {
            const files = e.dataTransfer.files;
            this.handleFiles(files);
        }, false);
    }

    initSelectButton() {
        if (!this.selectBtn || !this.fileInput) return;

        this.selectBtn.addEventListener('click', (e) => {
            e.preventDefault();
            this.fileInput.click();
        });

        this.fileInput.addEventListener('change', () => {
            this.handleFiles(this.fileInput.files);
            this.fileInput.value = '';
        });
    }

    initSortable() {
        if (!this.gallery) return;

        if (typeof Sortable === 'undefined') {
            console.error('SortableJS is not loaded');
            return;
        }

        this.sortableInstance = new Sortable(this.gallery, {
            animation: 150,
            ghostClass: 'sortable-ghost',
            chosenClass: 'sortable-chosen',
            onEnd: () => {
                this.updateStateFromDOM();
            }
        });
    }

    initDeleteButtons() {
        if (!this.gallery) return;

        this.gallery.addEventListener('click', (e) => {
            if (e.target.closest('.phocacart-imageupload-delete-btn')) {
                e.preventDefault();
                const item = e.target.closest('.phocacart-imageupload-item');
                this.deleteImage(item);
            }
        });
    }

    initDeleteAllButton() {
        if (!this.deleteAllBtn) return;

        this.deleteAllBtn.addEventListener('click', (e) => {
            e.preventDefault();
            this.deleteAllImages();
        });
    }

    /*handleFiles(files) {
        if (!files.length) return;

        const validFiles = [];
        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            const ext = file.name.split('.').pop().toLowerCase();

            if (!this.config.allowedExtensions.includes(ext)) {
                this.showError(this.lang['PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_INVALID_FILE_TYPE'] || 'Invalid file type');
                continue;
            }

            if (file.size > this.config.maxFileSize) {
                this.showError(this.lang['PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_FILE_TOO_LARGE'] || 'File is too large');
                continue;
            }

            validFiles.push(file);
        }

        if (validFiles.length > 0) {
            this.uploadFiles(validFiles);
        }
    }*/

    handleFiles(files) {
        if (!files.length) return;

        const validFiles = [];
        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            const ext = file.name.split('.').pop().toLowerCase();

            if (!this.config.allowedExtensions.includes(ext)) {
                this.showError(this.lang['PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_INVALID_FILE_TYPE'] || 'Invalid file type');
                continue;
            }

            if (file.size > this.config.maxFileSize) {
                this.showError(this.lang['PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_FILE_TOO_LARGE'] || 'File is too large');
                continue;
            }

            validFiles.push(file);
        }

        if (!validFiles.length) return;

        // Check max images limit
        const maxImages = this.config.maxImages || 0;
        if (maxImages > 0 && (this.images.length + validFiles.length) > maxImages) {
            this.showError(
                (this.lang['PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_MAX_IMAGES_EXCEEDED'] || 'Maximum of %s images allowed.').replace('%s', maxImages)
            );
            return;
        }

        this.uploadFilesSequentially(validFiles);
    }

    async uploadFilesSequentially(files) {
        const BATCH_SIZE = this.config.uploadBatchSize || 5;

        this.progressBar.style.display = 'block';
        this.progressBarFill.style.width = '0%';
        this.hideError();

        const total = files.length;
        let done = 0;

        for (let i = 0; i < files.length; i += BATCH_SIZE) {
            const batch = files.slice(i, i + BATCH_SIZE);
            try {
                await this.uploadBatch(batch);
            } catch (e) {
                this.showError(this.lang['PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_UPLOAD_FAILED'] || 'Upload failed');
            }
            done += batch.length;
            this.progressBarFill.style.width = ((done / total) * 100) + '%';
        }

        this.progressBar.style.display = 'none';
    }

    uploadBatch(files) {
        return new Promise((resolve, reject) => {
            const formData = new FormData();
            formData.append(this.config.csrfToken, 1);
            formData.append('product_id', this.config.productId);
            formData.append('temp_token', this.config.tempToken);
            formData.append('existing_count', this.images.length);  // for PHP enforcement

            files.forEach(file => formData.append('phocacart_images[]', file));

            this.images.forEach(img => formData.append('existing_filenames[]', img.filename));

            const xhr = new XMLHttpRequest();
            xhr.open('POST', this.config.uploadUrl, true);

            xhr.onload = () => {
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        let result = Array.isArray(response.data) ? response.data[0] : (response.data || response);

                        if (result.success && result.files) {
                            result.files.forEach(file => {
                                this.images.push({
                                    filename: file.filename,
                                    fullPath: result.folder + '/' + file.filename,
                                    order: this.images.length + 1,
                                    thumbnails: file.thumbnails
                                });
                                this.addImageToGallery(file, result.folder);
                            });
                            this.updateMainBadge();
                            this.updateDeleteAllVisibility();
                            this.commitState();
                            this.initTooltips();
                        } else {
                            this.showError(result.message || this.lang['PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_UPLOAD_FAILED'] || 'Upload failed');
                        }
                    } catch (e) {
                        this.showError(this.lang['PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_UPLOAD_FAILED'] || 'Upload failed');
                    }
                } else {
                    this.showError(this.lang['PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_UPLOAD_FAILED'] || 'Upload failed');
                }
                resolve();
            };

            xhr.onerror = () => reject(new Error('Network error'));
            xhr.send(formData);
        });
    }

    /*
    uploadFiles(files) {
        const formData = new FormData();
        formData.append(this.config.csrfToken, 1);
        formData.append('product_id', this.config.productId);
        formData.append('temp_token', this.config.tempToken);

        files.forEach(file => {
            formData.append('phocacart_images[]', file);
        });

        // Send existing filenames to handle collisions across different folders
        this.images.forEach(img => {
            formData.append('existing_filenames[]', img.filename);
        });

        this.progressBar.style.display = 'block';
        this.progressBarFill.style.width = '0%';
        this.hideError();

        const xhr = new XMLHttpRequest();
        xhr.open('POST', this.config.uploadUrl, true);

        xhr.upload.onprogress = (e) => {
            if (e.lengthComputable) {
                const percentComplete = (e.loaded / e.total) * 100;
                this.progressBarFill.style.width = percentComplete + '%';
            }
        };

        xhr.onload = () => {
            this.progressBar.style.display = 'none';

            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);

                    let result = response;
                    if (Array.isArray(response.data) && response.data.length > 0) {
                        result = response.data[0];
                    } else if (response.data) {
                        result = response.data;
                    }

                    if (result.success && result.files) {
                        result.files.forEach(file => {
                            this.images.push({
                                filename: file.filename,
                                fullPath: result.folder + '/' + file.filename,
                                order: this.images.length + 1,
                                thumbnails: file.thumbnails
                            });
                            this.addImageToGallery(file, result.folder);
                        });
                        this.updateMainBadge();
                        this.updateDeleteAllVisibility();
                        this.commitState();
                        this.initTooltips();
                    } else {
                        this.showError(result.message || this.lang['PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_UPLOAD_FAILED'] || 'Upload failed');
                    }
                } catch (e) {
                    console.error('Parse error:', e);
                    this.showError(this.lang['PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_UPLOAD_FAILED'] || 'Upload failed');
                }
            } else {
                this.showError(this.lang['PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_UPLOAD_FAILED'] || 'Upload failed');
            }
        };

        xhr.onerror = () => {
            this.progressBar.style.display = 'none';
            this.showError(this.lang['PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_UPLOAD_FAILED'] || 'Upload failed');
        };

        xhr.send(formData);
    }
*/
    addImageToGallery(file, folder) {
        const item = document.createElement('div');
        item.className = 'phocacart-imageupload-item';
        item.dataset.filename = file.filename;
        item.dataset.fullpath = folder + '/' + file.filename;

        let thumbUrl = this.config.baseImagePath;
        if (file.thumbnails && file.thumbnails.medium) {
            thumbUrl += file.thumbnails.medium;
        } else {
            thumbUrl += folder + '/thumbs/phoca_thumb_m_' + file.filename;
        }

        item.innerHTML = `
            <div class="phocacart-imageupload-thumb">
                <img src="${thumbUrl}" alt="${file.filename}" loading="lazy">
            </div>
            <div class="phocacart-imageupload-info">
                <span class="phocacart-imageupload-filename">${file.filename}</span>
            </div>
            <div class="phocacart-imageupload-actions">
                <span class="phocacart-imageupload-info-icon icon-info text-primary hasTooltip" title="${folder}/${file.filename}" aria-hidden="true"></span>
                <button type="button" class="phocacart-imageupload-delete-btn hasTooltip" title="${this.lang['PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_DELETE'] || 'Delete'}">
                    <span class="icon-trash" aria-hidden="true"></span>
                </button>
            </div>
        `;

        this.gallery.appendChild(item);
    }

    deleteImage(item) {
        const confirmMsg = this.lang['PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_DELETE_IMAGE_CONFIRM'] || 'Are you sure you want to delete this image?';
        if (!confirm(confirmMsg)) return;

        const filename = item.dataset.filename;
        const fullpath = item.dataset.fullpath || '';

        const formData = new FormData();
        formData.append(this.config.csrfToken, 1);
        formData.append('product_id', this.config.productId);
        formData.append('temp_token', this.config.tempToken);
        formData.append('filename', filename);
        formData.append('fullpath', fullpath);

        fetch(this.config.deleteUrl, {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(data => {
                const result = data.data || data;
                if (result.success) {
                    this.images = this.images.filter(img => img.filename !== filename);
                    item.remove();
                    this.updateMainBadge();
                    this.updateDeleteAllVisibility();
                    this.commitState();
                } else {
                    this.showError(result.message || this.lang['PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_DELETE_FAILED'] || 'Delete failed');
                }
            })
            .catch(error => {
                console.error('Delete error:', error);
                this.showError(this.lang['PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_DELETE_FAILED'] || 'Delete failed');
            });
    }

    async deleteAllImages() {
        const confirmMsg = this.lang['PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_DELETE_ALL_IMAGES_CONFIRM'] || 'Are you sure you want to delete all images?';
        if (!confirm(confirmMsg)) return;

        const items = Array.from(this.gallery.querySelectorAll('.phocacart-imageupload-item'));

        for (const item of items) {
            const filename = item.dataset.filename;
            const fullpath = item.dataset.fullpath || '';

            const formData = new FormData();
            formData.append(this.config.csrfToken, 1);
            formData.append('product_id', this.config.productId);
            formData.append('temp_token', this.config.tempToken);
            formData.append('filename', filename);
            formData.append('fullpath', fullpath);

            try {
                const response = await fetch(this.config.deleteUrl, {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                const result = data.data || data;

                if (result.success) {
                    this.images = this.images.filter(img => img.filename !== filename);
                    item.remove();
                }
            } catch (error) {
                console.error('Delete error for ' + filename, error);
            }
        }

        this.updateDeleteAllVisibility();
        this.commitState();
    }

    updateStateFromDOM() {
        const items = this.gallery.querySelectorAll('.phocacart-imageupload-item');
        const newImages = [];

        items.forEach((item, index) => {
            const filename = item.dataset.filename;
            const existing = this.images.find(img => img.filename === filename);
            if (existing) {
                newImages.push({
                    ...existing,
                    order: index + 1
                });
            }
        });

        this.images = newImages;
        this.updateMainBadge();
        this.commitState();
    }

    updateMainBadge() {
        const items = this.gallery.querySelectorAll('.phocacart-imageupload-item');
        items.forEach((item, index) => {
            const existingBadge = item.querySelector('.phocacart-imageupload-main-badge');
            if (index === 0 && !existingBadge) {
                const badge = document.createElement('span');
                badge.className = 'phocacart-imageupload-main-badge';
                badge.textContent = this.lang['PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_MAIN_IMAGE_LABEL'] || 'Main Image';
                item.insertBefore(badge, item.firstChild);
            } else if (index !== 0 && existingBadge) {
                existingBadge.remove();
            }
        });
    }

    updateDeleteAllVisibility() {
        if (this.deleteAllBtn) {
            this.deleteAllBtn.style.display = this.images.length > 0 ? 'inline-flex' : 'none';
        }
    }

    commitState() {
        if (!this.dataInput) return;

        const data = this.images.map((img, index) => ({
            filename: img.filename,
            fullPath: img.fullPath,
            order: index + 1
        }));
        this.dataInput.value = JSON.stringify(data);
    }

    initTooltips() {
        if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
            const tooltipTriggerList = [].slice.call(this.gallery.querySelectorAll('.hasTooltip'));
            tooltipTriggerList.map(function (tooltipTriggerEl) {
                return bootstrap.Tooltip.getOrCreateInstance(tooltipTriggerEl);
            });
        }
    }

    showError(msg) {
        if (!this.errorContainer) return;
        this.errorContainer.textContent = msg;
        this.errorContainer.style.display = 'block';
        setTimeout(() => {
            this.errorContainer.style.display = 'none';
        }, 8000);
    }

    hideError() {
        if (this.errorContainer) {
            this.errorContainer.style.display = 'none';
        }
    }
}

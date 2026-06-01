/**
 * Phoca MD — admin editor lock & warning behaviour
 *
 * LOCK:  When the markdown textarea contains text, the TinyMCE editor
 *        is natively set to 'readonly' mode, dimmed, and a large red
 *        warning banner is injected directly above it.
 */document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var textarea = document.querySelector('textarea.phocamd-field');
    if (!textarea) {
        return;
    }

    textarea.dataset.phocamdField = '1';

    function findEditorWrapper() {
        if (typeof tinymce !== 'undefined') {
            var editor = tinymce.get('jform_articletext');
            if (editor && editor.initialized && typeof editor.getContainer === 'function') {
                var container = editor.getContainer();
                if (container) {
                    return container.parentNode;
                }
            }
        }

        return document.querySelector('joomla-editor-tinymce')
            || document.querySelector('.tox-tinymce')
            || document.querySelector('.com-content-article__editor')
            || document.getElementById('jform_articletext_parent')
            || (document.getElementById('jform_articletext')
                    ? document.getElementById('jform_articletext').closest('.control-group') || document.getElementById('jform_articletext').parentNode
                    : null);
    }

    function setTinyMCEMode(editor, hasContent) {
        try {
            if (editor.mode && typeof editor.mode.set === 'function') {
                editor.mode.set(hasContent ? 'readonly' : 'design');
            } else if (typeof editor.setMode === 'function') {
                editor.setMode(hasContent ? 'readonly' : 'design');
            }
        } catch (e) {
        }
    }

    function updateLock() {
        var hasContent = textarea.value.trim() !== '';
        var wrapper    = findEditorWrapper();

        if (wrapper) {
            if (hasContent) {
                wrapper.style.opacity = '0.4';

                if (!document.getElementById('phocamd-lock-info')) {
                    var alert       = document.createElement('div');
                    alert.id        = 'phocamd-lock-info';
                    alert.className = 'alert alert-info ph-md-warning';
                    alert.innerHTML = Joomla.Text._('PLG_FIELDS_PHOCAMD_EDITOR_LOCKED');
                    wrapper.parentNode.insertBefore(alert, wrapper);
                }
            } else {
                wrapper.style.opacity = '';

                var lockInfo = document.getElementById('phocamd-lock-info');
                if (lockInfo) {
                    lockInfo.remove();
                }
            }
        }

        if (typeof tinymce !== 'undefined') {
            var editor = tinymce.get('jform_articletext');
            if (editor && editor.initialized) {
                setTinyMCEMode(editor, hasContent);
            }
        }
    }

    updateLock();
    textarea.addEventListener('input', updateLock);

    var attempts = 0;
    var interval = setInterval(function () {
        attempts++;
        if (typeof tinymce !== 'undefined') {
            var editor = tinymce.get('jform_articletext');
            if (editor && editor.initialized) {
                updateLock();

                editor.on('focus', updateLock);

                clearInterval(interval);
                return;
            }
        }

        if (attempts >= 40) {
            clearInterval(interval);
        }
    }, 500);
});

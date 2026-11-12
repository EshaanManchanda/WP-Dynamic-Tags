/**
 * WP Dynamic Tags admin screens: copy buttons, placeholder insertion, preview, import/export and the database tools.
 *
 * Everything shown from a server response or an uploaded file is put in with textContent, never as HTML. The preview is
 * rendered in an iframe with an empty sandbox attribute, so scripts in a tag's content do not run in the admin.
 */
(function ($) {
    'use strict';

    var cfg = window.dtAdmin || {};
    var t = cfg.strings || {};

    function el(tag, text, attrs) {
        var node = document.createElement(tag);
        if (text !== undefined && text !== null) {
            node.textContent = text;
        }
        if (attrs) {
            Object.keys(attrs).forEach(function (k) { node.setAttribute(k, attrs[k]); });
        }
        return node;
    }

    function errorText(response) {
        return response && typeof response.data === 'string' ? response.data : '';
    }

    // ------------------------------------------------------------------ copy and insert

    $(document).on('click', '.dt-copy', function () {
        var button = this;
        var value = button.getAttribute('data-copy') || '';
        var original = button.textContent;
        function done(ok) {
            button.textContent = ok ? t.copied : t.copyFailed;
            setTimeout(function () { button.textContent = original; }, 1500);
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value).then(function () { done(true); }, function () { done(false); });
            return;
        }
        var probe = el('textarea', value, { 'aria-hidden': 'true' });
        document.body.appendChild(probe);
        probe.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        document.body.removeChild(probe);
        done(ok);
    });

    $(document).on('click', '.dt-insert', function () {
        var area = document.getElementById('dt-content');
        if (!area) { return; }
        var text = this.getAttribute('data-insert') || '';
        var start = area.selectionStart || 0;
        var end = area.selectionEnd || 0;
        area.value = area.value.slice(0, start) + text + area.value.slice(end);
        area.focus();
        area.selectionStart = area.selectionEnd = start + text.length;
    });

    // ------------------------------------------------------------------ preview

    $('#dt-preview-button').on('click', function () {
        var $button = $(this);
        var box = document.getElementById('dt-preview');
        var original = $button.text();
        $button.text(t.previewing).prop('disabled', true);
        $.post(cfg.ajaxUrl, {
            action: 'dt_preview_tag',
            nonce: cfg.previewNonce,
            content: $('#dt-content').val()
        }).done(function (response) {
            box.textContent = '';
            if (response && response.success) {
                var frame = el('iframe', null, { sandbox: '', title: 'Preview', 'class': 'dt-preview-frame' });
                frame.srcdoc = response.data.html;
                box.appendChild(frame);
            } else {
                box.appendChild(el('p', errorText(response) || t.previewFailed));
            }
            box.hidden = false;
        }).fail(function () {
            box.textContent = t.previewFailed;
            box.hidden = false;
        }).always(function () {
            $button.text(original).prop('disabled', false);
        });
    });

    // ------------------------------------------------------------------ import / export

    function extension(name) {
        return String(name).split('.').pop().toLowerCase();
    }

    function results() {
        var box = document.getElementById('dt-import-results');
        box.textContent = '';
        box.hidden = false;
        return box;
    }

    function list(parent, items) {
        var ul = el('ul');
        items.forEach(function (item) { ul.appendChild(el('li', item)); });
        parent.appendChild(ul);
    }

    $('#dt-export-button').on('click', function () {
        var $button = $(this);
        var original = $button.text();
        $button.text(t.exporting).prop('disabled', true);
        $.post(cfg.ajaxUrl, {
            action: 'dt_table_export',
            nonce: cfg.nonce,
            format: $('#export-format').val(),
            filters: { status: $('#export-status').val(), category_id: $('#export-category').val() }
        }).done(function (response) {
            if (response && response.success && response.data && response.data.download_url) {
                window.location.href = response.data.download_url;
            } else {
                window.alert(errorText(response) || t.exportFailed);
            }
        }).fail(function () {
            window.alert(t.exportFailed);
        }).always(function () {
            $button.text(original).prop('disabled', false);
        });
    });

    function upload(action, extra, label) {
        var input = document.getElementById('import-file');
        if (!input || !input.files.length) {
            window.alert(t.chooseFile);
            return $.Deferred().reject().promise();
        }
        var data = new FormData();
        data.append('action', action);
        data.append('nonce', cfg.nonce);
        data.append('import_file', input.files[0]);
        data.append('format', extension(input.files[0].name));
        Object.keys(extra).forEach(function (k) { data.append(k, extra[k]); });
        return $.ajax({ url: cfg.ajaxUrl, type: 'POST', data: data, processData: false, contentType: false });
    }

    $('#dt-validate-import-button').on('click', function () {
        var $button = $(this);
        var original = $button.text();
        var request = upload('dt_table_validate_import', {});
        $button.text(t.validating).prop('disabled', true);
        request.done(function (response) {
            var box = results();
            if (!response || !response.success) {
                box.appendChild(el('p', errorText(response) || t.importFailed));
                return;
            }
            var validation = response.data.validation || {};
            box.appendChild(el('p', t.totalRecords + ': ' + response.data.total_records));
            if (validation.valid) {
                box.appendChild(el('p', t.valid));
            } else {
                box.appendChild(el('p', t.invalid));
                var problems = [];
                Object.keys(validation.errors || {}).forEach(function (key) {
                    problems.push(key + ': ' + [].concat(validation.errors[key]).join(', '));
                });
                list(box, problems);
            }
            if (validation.warnings && validation.warnings.length) {
                list(box, validation.warnings);
            }
        }).fail(function () {
            results().appendChild(el('p', t.requestFailed));
        }).always(function () {
            $button.text(original).prop('disabled', false);
        });
    });

    $('#dt-import-button').on('click', function () {
        var input = document.getElementById('import-file');
        if (!input || !input.files.length) {
            window.alert(t.chooseFile);
            return;
        }
        if (!window.confirm(t.confirmImport)) {
            return;
        }
        var $button = $(this);
        var original = $button.text();
        var request = upload('dt_table_import', {
            'options[update_existing]': $('#update-existing').is(':checked') ? '1' : '0',
            'options[skip_duplicates]': $('#skip-duplicates').is(':checked') ? '1' : '0'
        });
        $button.text(t.importing).prop('disabled', true);
        request.done(function (response) {
            var box = results();
            if (!response || !response.success) {
                box.appendChild(el('p', errorText(response) || t.importFailed));
                return;
            }
            var stats = response.data.stats || {};
            box.appendChild(el('p', [
                t.totalRecords + ': ' + stats.total_records,
                t.created + ': ' + stats.created,
                t.updated + ': ' + stats.updated,
                t.skipped + ': ' + stats.skipped,
                t.errors + ': ' + stats.errors
            ].join(' · ')));
            if (stats.error_details && stats.error_details.length) {
                list(box, stats.error_details.map(function (d) { return t.row + ' ' + d.row + ': ' + d.error; }));
            }
        }).fail(function () {
            results().appendChild(el('p', t.requestFailed));
        }).always(function () {
            $button.text(original).prop('disabled', false);
        });
    });

    // ------------------------------------------------------------------ database tools

    function tool(selector, action) {
        $(selector).on('click', function () {
            var $button = $(this);
            var out = document.getElementById('dt-health-result');
            $button.prop('disabled', true);
            $.post(cfg.ajaxUrl, { action: action, nonce: cfg.nonce }).done(function (response) {
                out.textContent = JSON.stringify(response && response.data !== undefined ? response.data : response, null, 2);
            }).fail(function () {
                out.textContent = t.requestFailed;
            }).always(function () {
                out.hidden = false;
                $button.prop('disabled', false);
            });
        });
    }
    tool('#dt-check-health', 'dt_check_table_health');
    tool('#dt-create-table', 'dt_create_table');
})(jQuery);

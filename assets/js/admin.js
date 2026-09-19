/**
 * WP Dynamic Tags - Admin JavaScript
 */

(function($) {
    'use strict';
    
    var DynamicTagsAdmin = {
        
        /**
         * Initialize admin functionality
         */
        init: function() {
            this.initBulkManager();
            this.initImportExport();
            this.initHelpTabs();
            this.initPlaceholderHelp();
            this.initShortcodeCopy();
            this.initFormValidation();
            this.initGroupManager();
            this.initBulkGroups();
        },
        
        /**
         * Initialize bulk manager functionality
         */
        initBulkManager: function() {
            // Select all functionality
            $('#cb-select-all').on('change', function() {
                $('.tag-checkbox').prop('checked', this.checked);
                DynamicTagsAdmin.toggleBulkButtons();
            });
            
            // Individual checkbox changes
            $(document).on('change', '.tag-checkbox', function() {
                DynamicTagsAdmin.toggleBulkButtons();
                var allChecked = $('.tag-checkbox:checked').length === $('.tag-checkbox').length;
                $('#cb-select-all').prop('checked', allChecked);
            });
            
            // Inline editing
            $(document).on('click', '.inline-edit-btn', function(e) {
                e.preventDefault();
                DynamicTagsAdmin.startInlineEdit($(this).closest('tr'));
            });
            
            $(document).on('click', '.save-inline', function(e) {
                e.preventDefault();
                DynamicTagsAdmin.saveInlineEdit($(this).closest('tr'));
            });
            
            $(document).on('click', '.cancel-inline', function(e) {
                e.preventDefault();
                DynamicTagsAdmin.cancelInlineEdit($(this).closest('tr'));
            });
            
            // Bulk actions
            $('#dt-bulk-delete-btn').on('click', function() {
                DynamicTagsAdmin.handleBulkDelete();
            });
            
            // Duplicate functionality
            $(document).on('click', '.duplicate-tag', function(e) {
                e.preventDefault();
                DynamicTagsAdmin.duplicateTag($(this).data('tag-id'));
            });
        },
        
        /**
         * Toggle bulk action buttons based on selection
         */
        toggleBulkButtons: function() {
            var hasChecked = $('.tag-checkbox:checked').length > 0;
            $('#dt-bulk-edit-btn, #dt-bulk-groups-btn, #dt-bulk-delete-btn').prop('disabled', !hasChecked);
        },
        
        /**
         * Start inline editing
         */
        startInlineEdit: function($row) {
            $row.find('.tag-value-display, .category-display, .actions-display').hide();
            $row.find('.tag-value-edit, .category-input, .actions-edit').show();
            $row.addClass('inline-editing');
            
            // Focus on textarea
            $row.find('.tag-value-input').focus();
        },
        
        /**
         * Save inline edit
         */
        saveInlineEdit: function($row) {
            var tagId = $row.data('tag-id');
            var tagValue = $row.find('.tag-value-input').val();
            var category = $row.find('.category-input').val();
            
            // Show loading state
            $row.addClass('dt-loading');
            
            $.ajax({
                url: dtAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'dt_inline_edit',
                    tag_id: tagId,
                    tag_value: tagValue,
                    category: category,
                    nonce: dtAdmin.nonces.inline_edit
                },
                success: function(response) {
                    $row.removeClass('dt-loading');
                    
                    if (response.success) {
                        $row.find('.tag-value-display').html(response.data.value_preview);
                        $row.find('.category-display').html(response.data.category_display);
                        DynamicTagsAdmin.endInlineEdit($row);
                        DynamicTagsAdmin.showNotice('success', dtAdmin.strings.saved);
                    } else {
                        DynamicTagsAdmin.showNotice('error', response.data || dtAdmin.strings.error);
                    }
                },
                error: function() {
                    $row.removeClass('dt-loading');
                    DynamicTagsAdmin.showNotice('error', dtAdmin.strings.error);
                }
            });
        },
        
        /**
         * Cancel inline edit
         */
        cancelInlineEdit: function($row) {
            // Reset form values
            var originalValue = $row.find('.tag-value-display').text();
            var originalCategory = $row.find('.category-display').text().toLowerCase();
            
            $row.find('.tag-value-input').val(originalValue);
            $row.find('.category-input').val(originalCategory);
            
            this.endInlineEdit($row);
        },
        
        /**
         * End inline editing
         */
        endInlineEdit: function($row) {
            $row.find('.tag-value-edit, .category-input, .actions-edit').hide();
            $row.find('.tag-value-display, .category-display, .actions-display').show();
            $row.removeClass('inline-editing');
        },
        
        /**
         * Handle bulk delete
         */
        handleBulkDelete: function() {
            var selectedIds = $('.tag-checkbox:checked').map(function() {
                return this.value;
            }).get();
            
            if (selectedIds.length === 0) return;
            
            if (!confirm(dtAdmin.strings.confirmBulkDelete)) {
                return;
            }
            
            $.ajax({
                url: dtAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'dt_bulk_delete',
                    tag_ids: selectedIds,
                    nonce: dtAdmin.nonces.bulk_delete
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        DynamicTagsAdmin.showNotice('error', response.data || dtAdmin.strings.error);
                    }
                },
                error: function() {
                    DynamicTagsAdmin.showNotice('error', dtAdmin.strings.error);
                }
            });
        },
        
        /**
         * Duplicate a tag
         */
        duplicateTag: function(tagId) {
            $.ajax({
                url: dtAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'dt_duplicate_tag',
                    tag_id: tagId,
                    nonce: dtAdmin.nonces.duplicate
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        DynamicTagsAdmin.showNotice('error', response.data || dtAdmin.strings.error);
                    }
                },
                error: function() {
                    DynamicTagsAdmin.showNotice('error', dtAdmin.strings.error);
                }
            });
        },
        
        /**
         * Initialize import/export functionality
         */
        initImportExport: function() {
            // Export buttons
            $('#export-json-btn').on('click', function() {
                var includeMetadata = $('#include-metadata').is(':checked');
                var includeUsage = $('#include-usage-stats').is(':checked');
                
                window.location.href = dtAdmin.ajaxUrl + 
                    '?action=dt_export_json' +
                    '&include_metadata=' + includeMetadata +
                    '&include_usage=' + includeUsage +
                    '&nonce=' + dtAdmin.nonces.export;
            });
            
            $('#export-csv-btn').on('click', function() {
                var includeMetadata = $('#include-metadata').is(':checked');
                
                window.location.href = dtAdmin.ajaxUrl + 
                    '?action=dt_export_csv' +
                    '&include_metadata=' + includeMetadata +
                    '&nonce=' + dtAdmin.nonces.export;
            });
            
            // File upload validation
            $('#import-file').on('change', function() {
                var file = this.files[0];
                if (file) {
                    var extension = file.name.split('.').pop().toLowerCase();
                    if (extension !== 'json' && extension !== 'csv') {
                        alert(dtAdmin.strings.invalidFileFormat);
                        this.value = '';
                    } else {
                        DynamicTagsAdmin.validateImportFile(file);
                    }
                }
            });
            
            // Drag and drop support
            this.initDragDrop();
        },
        
        /**
         * Initialize drag and drop for import
         */
        initDragDrop: function() {
            var $importZone = $('.dt-import-zone');
            if ($importZone.length === 0) return;
            
            $importZone.on('dragover dragenter', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).addClass('dragover');
            });
            
            $importZone.on('dragleave', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).removeClass('dragover');
            });
            
            $importZone.on('drop', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).removeClass('dragover');
                
                var files = e.originalEvent.dataTransfer.files;
                if (files.length > 0) {
                    $('#import-file')[0].files = files;
                    $('#import-file').trigger('change');
                }
            });
        },
        
        /**
         * Validate import file
         */
        validateImportFile: function(file) {
            var maxSize = 5 * 1024 * 1024; // 5MB
            
            if (file.size > maxSize) {
                alert(dtAdmin.strings.fileTooLarge);
                $('#import-file').val('');
                return false;
            }
            
            // Show file info
            var fileInfo = file.name + ' (' + DynamicTagsAdmin.formatFileSize(file.size) + ')';
            $('.dt-file-info').remove();
            $('#import-file').after('<p class="dt-file-info"><strong>' + dtAdmin.strings.selectedFile + ':</strong> ' + fileInfo + '</p>');
            
            return true;
        },
        
        /**
         * Format file size for display
         */
        formatFileSize: function(bytes) {
            if (bytes === 0) return '0 Bytes';
            
            var k = 1024;
            var sizes = ['Bytes', 'KB', 'MB', 'GB'];
            var i = Math.floor(Math.log(bytes) / Math.log(k));
            
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        },
        
        /**
         * Initialize help tabs
         */
        initHelpTabs: function() {
            $(document).on('click', '.dt-help-tab', function(e) {
                e.preventDefault();
                
                var target = $(this).data('tab');
                
                // Update active tab
                $('.dt-help-tab').removeClass('active');
                $(this).addClass('active');
                
                // Show target content
                $('.dt-help-content').removeClass('active').hide();
                $('#' + target).addClass('active').show();
            });
        },
        
        /**
         * Initialize placeholder help functionality
         */
        initPlaceholderHelp: function() {
            // Copy placeholder on click
            $(document).on('click', '.dt-placeholder-key', function(e) {
                e.preventDefault();
                var placeholder = '{' + $(this).text() + '}';
                DynamicTagsAdmin.copyToClipboard(placeholder);
                DynamicTagsAdmin.showNotice('success', dtAdmin.strings.placeholderCopied);
            });
            
            // Search placeholders
            $('#placeholder-search').on('input', function() {
                var searchTerm = $(this).val().toLowerCase();
                
                $('.dt-placeholder-item').each(function() {
                    var $item = $(this);
                    var key = $item.find('.dt-placeholder-key').text().toLowerCase();
                    var desc = $item.find('.dt-placeholder-desc').text().toLowerCase();
                    
                    if (key.includes(searchTerm) || desc.includes(searchTerm)) {
                        $item.show();
                    } else {
                        $item.hide();
                    }
                });
            });
        },
        
        /**
         * Initialize shortcode copy functionality
         */
        initShortcodeCopy: function() {
            $(document).on('click', '.copy-shortcode, .dt-copy-btn', function(e) {
                e.preventDefault();
                
                var shortcode = $(this).data('shortcode') || $(this).prev('code').text();
                DynamicTagsAdmin.copyToClipboard(shortcode);
                
                // Show feedback
                var $btn = $(this);
                var originalText = $btn.text();
                $btn.text(dtAdmin.strings.copied);
                setTimeout(function() {
                    $btn.text(originalText);
                }, 1500);
            });
        },
        
        /**
         * Copy text to clipboard with enhanced error handling
         */
        copyToClipboard: function(text) {
            if (!text) {
                console.error('DT: No text to copy');
                return false;
            }

            // Check if dtAdmin strings are loaded
            if (typeof dtAdmin === 'undefined' || !dtAdmin.strings) {
                console.warn('DT: dtAdmin object not loaded, using fallback copy method');
            }

            // Try modern clipboard API first
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(function() {
                    console.log('DT: Copied successfully using Clipboard API: ' + text);
                }).catch(function(err) {
                    console.error('DT: Clipboard API failed, trying fallback: ', err);
                    DynamicTagsAdmin.fallbackCopy(text);
                });
            } else {
                // Use fallback for older browsers or non-secure contexts
                console.log('DT: Using fallback copy method (no Clipboard API or non-secure context)');
                DynamicTagsAdmin.fallbackCopy(text);
            }
        },

        /**
         * Fallback copy method using execCommand
         */
        fallbackCopy: function(text) {
            var textArea = document.createElement('textarea');
            textArea.value = text;
            textArea.style.position = 'fixed';
            textArea.style.left = '-999999px';
            textArea.style.top = '-999999px';
            textArea.setAttribute('readonly', '');
            document.body.appendChild(textArea);

            try {
                textArea.focus();
                textArea.select();
                textArea.setSelectionRange(0, 99999); // For mobile devices

                var successful = document.execCommand('copy');
                if (successful) {
                    console.log('DT: Copied successfully using execCommand: ' + text);
                } else {
                    console.error('DT: execCommand copy failed');
                    if (typeof dtAdmin !== 'undefined' && dtAdmin.strings && dtAdmin.strings.copyFailed) {
                        alert(dtAdmin.strings.copyFailed + '\n\n' + text);
                    } else {
                        alert('Failed to copy. Please copy manually:\n\n' + text);
                    }
                }
            } catch (err) {
                console.error('DT: Fallback copy failed: ', err);
                if (typeof dtAdmin !== 'undefined' && dtAdmin.strings && dtAdmin.strings.copyFailed) {
                    alert(dtAdmin.strings.copyFailed + '\n\n' + text);
                } else {
                    alert('Failed to copy. Please copy manually:\n\n' + text);
                }
            } finally {
                document.body.removeChild(textArea);
            }
        },
        
        /**
         * Initialize form validation
         */
        initFormValidation: function() {
            // Tag key validation
            $(document).on('input', 'input[name="tag_key"]', function() {
                var $input = $(this);
                var value = $input.val();
                var sanitized = value.toLowerCase()
                    .replace(/[^a-z0-9_-]/g, '_')
                    .replace(/[_-]+/g, '_')
                    .replace(/^[_-]+|[_-]+$/g, '');
                
                if (value !== sanitized) {
                    $input.val(sanitized);
                    DynamicTagsAdmin.showNotice('warning', dtAdmin.strings.keySanitized, 3000);
                }
            });
            
            // Form submission validation
            $(document).on('submit', '#dt-quick-create-form', function(e) {
                var tagKey = $('input[name="tag_key"]').val().trim();
                var tagValue = $('textarea[name="tag_value"]').val().trim();
                
                if (!tagKey || !tagValue) {
                    e.preventDefault();
                    DynamicTagsAdmin.showNotice('error', dtAdmin.strings.fillRequired);
                    return false;
                }
                
                // Check for duplicate keys
                if (dtAdmin.existingKeys && dtAdmin.existingKeys.includes(tagKey)) {
                    if (!confirm(dtAdmin.strings.confirmDuplicate)) {
                        e.preventDefault();
                        return false;
                    }
                }
            });
        },
        
        /**
         * Show admin notice
         */
        showNotice: function(type, message, duration) {
            duration = duration || 5000;
            
            var $notice = $('<div class="dt-notice dt-notice-' + type + '">' + message + '</div>');
            
            // Remove existing notices of same type
            $('.dt-notice-' + type).remove();
            
            // Add new notice
            if ($('.wrap h1').length) {
                $('.wrap h1').after($notice);
            } else {
                $('.wrap').prepend($notice);
            }
            
            // Auto-remove after duration
            setTimeout(function() {
                $notice.fadeOut(function() {
                    $(this).remove();
                });
            }, duration);
        },
        
        /**
         * Initialize tooltips
         */
        initTooltips: function() {
            $(document).on('mouseenter', '[data-tooltip]', function() {
                var $element = $(this);
                var tooltip = $element.data('tooltip');
                
                if (!tooltip) return;
                
                var $tooltip = $('<div class="dt-tooltip">' + tooltip + '</div>');
                $('body').append($tooltip);
                
                var position = $element.offset();
                $tooltip.css({
                    top: position.top - $tooltip.height() - 10,
                    left: position.left + ($element.width() / 2) - ($tooltip.width() / 2)
                });
                
                $element.data('tooltip-element', $tooltip);
            });
            
            $(document).on('mouseleave', '[data-tooltip]', function() {
                var $tooltip = $(this).data('tooltip-element');
                if ($tooltip) {
                    $tooltip.remove();
                    $(this).removeData('tooltip-element');
                }
            });
        },

        /**
         * Initialize group manager functionality
         */
        initGroupManager: function() {
            // Group tree interaction
            $(document).on('click', '.dt-group-item', function(e) {
                e.preventDefault();
                $('.dt-group-item').removeClass('selected');
                $(this).addClass('selected');

                var groupId = $(this).data('group-id');
                DynamicTagsAdmin.loadGroupDetails(groupId);
            });

            // Expand/Collapse tree nodes
            $(document).on('click', '.dt-toggle', function(e) {
                e.stopPropagation();
                var $children = $(this).closest('.dt-group-wrapper').find('> .dt-group-children');
                $children.toggle();
                $(this).toggleClass('dashicons-arrow-right dashicons-arrow-down');
            });

            // Expand/Collapse all
            $('#dt-expand-all').on('click', function() {
                $('.dt-group-children').show();
                $('.dt-toggle').removeClass('dashicons-arrow-right').addClass('dashicons-arrow-down');
            });

            $('#dt-collapse-all').on('click', function() {
                $('.dt-group-children').hide();
                $('.dt-toggle').removeClass('dashicons-arrow-down').addClass('dashicons-arrow-right');
            });

            // Group actions
            $(document).on('click', '.dt-add-child', function(e) {
                e.stopPropagation();
                var parentId = $(this).closest('.dt-group-item').data('group-id');
                DynamicTagsAdmin.showGroupForm(0, parentId);
            });

            $(document).on('click', '.dt-edit-group', function(e) {
                e.stopPropagation();
                var groupId = $(this).closest('.dt-group-item').data('group-id');
                DynamicTagsAdmin.showGroupForm(groupId, 0);
            });

            // Color picker functionality
            $(document).on('change', '#group-color', function() {
                var color = $(this).val();
                $(this).css('background-color', color);
            });
        },

        /**
         * Initialize bulk groups functionality
         */
        initBulkGroups: function() {
            var currentGroupAction = 'add';

            // Show bulk groups modal
            $('#dt-bulk-groups-btn').on('click', function() {
                var selectedIds = $('.tag-checkbox:checked').map(function() {
                    return this.value;
                }).get();

                if (selectedIds.length === 0) return;

                $('#dt-bulk-groups-modal').show();
            });

            // Group action tabs
            $(document).on('click', '.group-action-tab', function() {
                $('.group-action-tab').removeClass('active');
                $(this).addClass('active');
                currentGroupAction = $(this).data('action');
            });

            // Cancel bulk groups
            $('#dt-bulk-groups-cancel').on('click', function() {
                $('#dt-bulk-groups-modal').hide();
                $('input[name="bulk_groups[]"]').prop('checked', false);
            });

            // Apply bulk groups changes
            $('#dt-bulk-groups-apply').on('click', function() {
                var selectedTagIds = $('.tag-checkbox:checked').map(function() {
                    return this.value;
                }).get();

                var selectedGroupIds = $('input[name="bulk_groups[]"]:checked').map(function() {
                    return this.value;
                }).get();

                if (selectedTagIds.length === 0 || selectedGroupIds.length === 0) {
                    DynamicTagsAdmin.showNotice('error', 'Please select both tags and groups.');
                    return;
                }

                DynamicTagsAdmin.applyBulkGroups(selectedTagIds, selectedGroupIds, currentGroupAction);
            });

            // Close modal when clicking outside
            $('#dt-bulk-groups-modal').on('click', function(e) {
                if (e.target === this) {
                    $(this).hide();
                }
            });

            // Escape key to close modal
            $(document).on('keydown', function(e) {
                if (e.keyCode === 27 && $('#dt-bulk-groups-modal').is(':visible')) {
                    $('#dt-bulk-groups-modal').hide();
                }
            });
        },

        /**
         * Load group details
         */
        loadGroupDetails: function(groupId) {
            $.ajax({
                url: dtAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'dt_get_group_details',
                    group_id: groupId,
                    nonce: dtAdmin.nonces.group_details || 'nonce-placeholder'
                },
                success: function(response) {
                    if (response.success) {
                        DynamicTagsAdmin.displayGroupDetails(response.data);
                    } else {
                        DynamicTagsAdmin.showNotice('error', response.data || dtAdmin.strings.error);
                    }
                },
                error: function() {
                    DynamicTagsAdmin.showNotice('error', dtAdmin.strings.error);
                }
            });
        },

        /**
         * Display group details
         */
        displayGroupDetails: function(data) {
            $('#dt-welcome-message').hide();
            $('#dt-group-info').hide();
            $('#dt-group-tags').show();

            $('#dt-details-title').text(data.name);
            $('#dt-tags-list').html(data.tags_html);
        },

        /**
         * Show group form for creating/editing
         */
        showGroupForm: function(groupId, parentId) {
            var isEditing = groupId > 0;

            $('#dt-welcome-message').hide();
            $('#dt-group-tags').hide();
            $('#dt-group-info').show();

            $('#dt-details-title').text(isEditing ? 'Edit Group' : 'New Group');
            $('#dt-delete-group').toggle(isEditing);

            if (isEditing) {
                this.loadGroupForEdit(groupId);
            } else {
                $('#group-name').val('');
                $('#group-description').val('');
                $('#group-color').val('#2271b1');
                $('#group-parent').val(parentId);
            }
        },

        /**
         * Load group data for editing
         */
        loadGroupForEdit: function(groupId) {
            $.ajax({
                url: dtAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'dt_get_group_edit_data',
                    group_id: groupId,
                    nonce: dtAdmin.nonces.group_edit || 'nonce-placeholder'
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        $('#group-name').val(data.name);
                        $('#group-description').val(data.description);
                        $('#group-color').val(data.color);
                        $('#group-parent').val(data.parent);
                    }
                },
                error: function() {
                    DynamicTagsAdmin.showNotice('error', dtAdmin.strings.error);
                }
            });
        },

        /**
         * Apply bulk groups changes
         */
        applyBulkGroups: function(tagIds, groupIds, action) {
            $('#dt-bulk-groups-apply').prop('disabled', true).text('Processing...');

            $.ajax({
                url: dtAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'dt_assign_groups',
                    tag_ids: tagIds,
                    group_ids: groupIds,
                    assign_action: action,
                    nonce: dtAdmin.nonces.assign_groups || 'nonce-placeholder'
                },
                success: function(response) {
                    $('#dt-bulk-groups-apply').prop('disabled', false).text('Apply Changes');

                    if (response.success) {
                        $('#dt-bulk-groups-modal').hide();
                        DynamicTagsAdmin.showNotice('success', response.data.message);

                        // Reload page to show updated groups
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        DynamicTagsAdmin.showNotice('error', response.data || dtAdmin.strings.error);
                    }
                },
                error: function() {
                    $('#dt-bulk-groups-apply').prop('disabled', false).text('Apply Changes');
                    DynamicTagsAdmin.showNotice('error', dtAdmin.strings.error);
                }
            });
        },

        /**
         * Enhanced group badge functionality
         */
        initGroupBadges: function() {
            // Click to filter by group
            $(document).on('click', '.dt-group-badge', function(e) {
                e.preventDefault();
                var groupName = $(this).text().trim();

                // If on bulk manager page, filter by this group
                if ($('select[name="category"]').length) {
                    $('select[name="category"]').val(groupName.toLowerCase()).trigger('change');
                }
            });

            // Hover effects for group badges
            $(document).on('mouseenter', '.dt-group-badge', function() {
                $(this).css('transform', 'scale(1.05)');
            });

            $(document).on('mouseleave', '.dt-group-badge', function() {
                $(this).css('transform', 'scale(1)');
            });
        },

        /**
         * Real-time search for groups
         */
        initGroupSearch: function() {
            var searchTimeout;

            $(document).on('input', '#group-search', function() {
                var searchTerm = $(this).val().toLowerCase();

                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    $('.dt-group-item').each(function() {
                        var $item = $(this);
                        var groupName = $item.find('.dt-group-name').text().toLowerCase();

                        if (groupName.includes(searchTerm) || searchTerm === '') {
                            $item.closest('.dt-group-wrapper').show();
                        } else {
                            $item.closest('.dt-group-wrapper').hide();
                        }
                    });
                }, 300);
            });
        },

        /**
         * Group statistics animations
         */
        initGroupStats: function() {
            // Animate numbers on load
            $('.dt-stat-number').each(function() {
                var $this = $(this);
                var finalValue = parseInt($this.text());
                var duration = 1000;
                var increment = finalValue / (duration / 16);
                var currentValue = 0;

                var timer = setInterval(function() {
                    currentValue += increment;
                    if (currentValue >= finalValue) {
                        currentValue = finalValue;
                        clearInterval(timer);
                    }
                    $this.text(Math.floor(currentValue));
                }, 16);
            });
        }
    };
    
    // Initialize when document is ready
    $(document).ready(function() {
        DynamicTagsAdmin.init();
    });
    
    // Expose to global scope for external use
    window.DynamicTagsAdmin = DynamicTagsAdmin;

})(jQuery);

/**
 * Enhanced Shortcode Copy Functionality
 */
function copyShortcode(button) {
    var codeElement = button.previousElementSibling;
    var shortcode = codeElement ? codeElement.getAttribute('data-shortcode') : null;

    // Fallback: try to get shortcode from button's data attribute
    if (!shortcode) {
        shortcode = button.getAttribute('data-shortcode');
    }

    // Fallback: try to get text from code element
    if (!shortcode && codeElement) {
        shortcode = codeElement.textContent || codeElement.innerText;
    }

    if (!shortcode) {
        console.error('DT: No shortcode found to copy');
        var errorMsg = (typeof dtAdmin !== 'undefined' && dtAdmin.strings && dtAdmin.strings.copyFailed)
            ? dtAdmin.strings.copyFailed
            : 'Failed to copy shortcode';
        showCopyNotification(errorMsg, 'error');
        return;
    }

    console.log('DT: Attempting to copy shortcode: ' + shortcode);

    // Try to copy to clipboard
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(shortcode).then(function() {
            console.log('DT: Shortcode copied successfully');
            var successMsg = (typeof dtAdmin !== 'undefined' && dtAdmin.strings && dtAdmin.strings.shortcodeCopied)
                ? dtAdmin.strings.shortcodeCopied
                : 'Shortcode copied!';
            showCopyNotification(successMsg);
            animateButton(button);
        }).catch(function(err) {
            console.error('DT: Clipboard API failed: ', err);
            fallbackCopy(shortcode, button);
        });
    } else {
        console.log('DT: Using fallback copy for shortcode');
        fallbackCopy(shortcode, button);
    }
}

function fallbackCopy(text, button) {
    // Create temporary textarea
    var textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    textarea.style.left = '-999999px';
    textarea.style.top = '-999999px';
    textarea.setAttribute('readonly', '');
    document.body.appendChild(textarea);

    try {
        textarea.focus();
        textarea.select();
        textarea.setSelectionRange(0, 99999); // For mobile devices
        var successful = document.execCommand('copy');

        if (successful) {
            console.log('DT: Fallback copy successful');
            var successMsg = (typeof dtAdmin !== 'undefined' && dtAdmin.strings && dtAdmin.strings.shortcodeCopied)
                ? dtAdmin.strings.shortcodeCopied
                : 'Shortcode copied!';
            showCopyNotification(successMsg);
            if (button) {
                animateButton(button);
            }
        } else {
            console.error('DT: execCommand copy returned false');
            var errorMsg = (typeof dtAdmin !== 'undefined' && dtAdmin.strings && dtAdmin.strings.copyFailed)
                ? dtAdmin.strings.copyFailed
                : 'Failed to copy shortcode';
            showCopyNotification(errorMsg, 'error');
        }
    } catch (err) {
        console.error('DT: Fallback copy exception: ', err);
        var errorMsg = (typeof dtAdmin !== 'undefined' && dtAdmin.strings && dtAdmin.strings.copyFailed)
            ? dtAdmin.strings.copyFailed
            : 'Failed to copy shortcode';
        showCopyNotification(errorMsg, 'error');
    } finally {
        if (textarea && textarea.parentNode) {
            document.body.removeChild(textarea);
        }
    }
}

function showCopyNotification(message, type) {
    type = type || 'success';

    // Remove existing notification
    var existing = document.querySelector('.dt-copy-notification');
    if (existing) {
        existing.remove();
    }

    // Create new notification
    var notification = document.createElement('div');
    notification.className = 'dt-copy-notification';
    notification.textContent = message;

    if (type === 'error') {
        notification.style.background = '#dc3545';
    }

    document.body.appendChild(notification);

    // Show notification
    setTimeout(function() {
        notification.classList.add('show');
    }, 10);

    // Hide and remove notification
    setTimeout(function() {
        notification.classList.remove('show');
        setTimeout(function() {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 300);
    }, 2000);
}

function animateButton(button) {
    var originalText = button.textContent;
    button.textContent = '✓';
    button.style.background = '#00a32a';
    button.style.color = 'white';
    button.style.borderColor = '#00a32a';

    setTimeout(function() {
        button.textContent = originalText;
        button.style.background = '';
        button.style.color = '';
        button.style.borderColor = '';
    }, 1000);
}
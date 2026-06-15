(function ($, wp, settings) {
    'use strict';

    if (!settings || !settings.requestKey) {
        return;
    }

    var requestKey = settings.requestKey;
    var state = {
        currentFolder: settings.currentFolder || 'all',
        frame: null
    };

    function allFolders() {
        var items = [];

        function walk(folders, depth) {
            folders = folders || [];

            folders.forEach(function (folder) {
                items.push({
                    id: String(folder.id),
                    name: folder.name,
                    path: folder.path,
                    count: folder.count,
                    depth: depth
                });

                walk(folder.children || [], depth + 1);
            });
        }

        walk(settings.folders || [], 0);

        return items;
    }

    function folderOptionHtml(value, label, selected, depth) {
        var prefix = '';
        var index;

        for (index = 0; index < depth; index++) {
            prefix += '— ';
        }

        return '<option value="' + escapeHtml(value) + '"' + (selected ? ' selected' : '') + '>' + escapeHtml(prefix + label) + '</option>';
    }

    function folderSelectHtml(selected) {
        var html = '';

        html += folderOptionHtml('all', settings.labels.all, selected === 'all', 0);
        html += folderOptionHtml('unassigned', settings.labels.unassigned, selected === 'unassigned', 0);

        allFolders().forEach(function (folder) {
            html += folderOptionHtml(folder.id, folder.name, selected === folder.id, folder.depth);
        });

        return html;
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function selectedFolderForUpload() {
        return /^\d+$/.test(String(state.currentFolder)) ? state.currentFolder : '';
    }

    function updateUploaderDefaults() {
        if (!wp.Uploader || !wp.Uploader.defaults) {
            return;
        }

        wp.Uploader.defaults.multipart_params = wp.Uploader.defaults.multipart_params || {};
        wp.Uploader.defaults.multipart_params[requestKey] = selectedFolderForUpload();
    }

    function patchUploader() {
        if (!wp.Uploader || !wp.Uploader.prototype || wp.Uploader.prototype.purepressMediaFoldersPatched) {
            return;
        }

        var originalInit = wp.Uploader.prototype.init;

        wp.Uploader.prototype.init = function () {
            var uploaderInstance = this;
            var result;

            if (typeof originalInit === 'function') {
                result = originalInit.apply(this, arguments);
            }

            if (typeof uploaderInstance.param === 'function') {
                uploaderInstance.param(requestKey, selectedFolderForUpload());
            }

            if (uploaderInstance.uploader && uploaderInstance.uploader.bind && !uploaderInstance.purepressMediaFoldersBound) {
                uploaderInstance.uploader.bind('BeforeUpload', function (uploader) {
                    if (typeof uploaderInstance.param === 'function') {
                        uploaderInstance.param(requestKey, selectedFolderForUpload());
                        return;
                    }

                    uploader.settings.multipart_params = uploader.settings.multipart_params || {};
                    uploader.settings.multipart_params[requestKey] = selectedFolderForUpload();
                });

                uploaderInstance.purepressMediaFoldersBound = true;
            }

            return result;
        };

        wp.Uploader.prototype.purepressMediaFoldersPatched = true;
        updateUploaderDefaults();
    }

    function currentLibrary() {
        var frame = state.frame || (wp.media && wp.media.frame);
        var library;

        if (!frame || !frame.state) {
            return null;
        }

        try {
            library = frame.state().get('library');
        } catch (error) {
            library = null;
        }

        return library && library.props ? library : null;
    }

    function applyFolderToLibrary(value) {
        var library = currentLibrary();
        var props = {};

        if (!library) {
            return;
        }

        props[requestKey] = value === 'all' ? null : value;
        library.props.set(props);
    }

    function refreshLibrary() {
        var library = currentLibrary();

        if (!library) {
            return;
        }

        if (typeof library._requery === 'function') {
            library._requery(true);
            return;
        }

        library.props.trigger('change', library.props);
    }

    function updateBrowserUrl(value) {
        var url;

        if (!window.history || !$('body').hasClass('upload-php')) {
            return;
        }

        url = new URL(window.location.href);

        if (value === 'all') {
            url.searchParams.delete(requestKey);
        } else {
            url.searchParams.set(requestKey, value);
        }

        window.history.replaceState({}, '', url.toString());
    }

    function selectFolder(value, options) {
        options = options || {};
        state.currentFolder = value || 'all';
        updateUploaderDefaults();
        markActiveFolder();

        if (!options.skipLibrary) {
            applyFolderToLibrary(state.currentFolder);
        }

        if (!options.skipUrl) {
            updateBrowserUrl(state.currentFolder);
        }

        $('.purepress-media-folder-select').val(state.currentFolder);
    }

    function renderFolderButtons(folders, depth) {
        var html = '';

        folders = folders || [];

        folders.forEach(function (folder) {
            html += '<button type="button" class="purepress-media-folders__item" data-folder="' + escapeHtml(folder.id) + '" style="--purepress-folder-depth:' + depth + '">';
            html += '<span>' + escapeHtml(folder.name) + '</span>';
            html += '<span class="purepress-media-folders__count">' + escapeHtml(folder.count || 0) + '</span>';
            html += '</button>';
            html += renderFolderButtons(folder.children || [], depth + 1);
        });

        return html;
    }

    function renderPanel() {
        var $anchor = $('.wp-filter').first();
        var html;

        if (!$('body').hasClass('upload-php') || !$anchor.length) {
            return;
        }

        html = '<section class="purepress-media-folders" aria-label="' + escapeHtml(settings.labels.folderFilter) + '">';
        html += '<div class="purepress-media-folders__header">';
        html += '<h2>' + escapeHtml(settings.labels.folderFilter) + '</h2>';
        html += '<div class="purepress-media-folders__actions">';
        html += '<button type="button" class="button button-small" data-purepress-folder-action="create">' + escapeHtml(settings.labels.create) + '</button>';
        html += '<button type="button" class="button button-small" data-purepress-folder-action="rename">' + escapeHtml(settings.labels.rename) + '</button>';
        html += '<button type="button" class="button button-small" data-purepress-folder-action="delete">' + escapeHtml(settings.labels.delete) + '</button>';
        html += '<button type="button" class="button button-small" data-purepress-folder-action="move">' + escapeHtml(settings.labels.move) + '</button>';
        html += '<button type="button" class="button button-small" data-purepress-folder-action="unassign">' + escapeHtml(settings.labels.unassign) + '</button>';
        html += '</div>';
        html += '</div>';
        html += '<div class="purepress-media-folders__tree">';
        html += '<button type="button" class="purepress-media-folders__item" data-folder="all" style="--purepress-folder-depth:0"><span>' + escapeHtml(settings.labels.all) + '</span></button>';
        html += '<button type="button" class="purepress-media-folders__item" data-folder="unassigned" style="--purepress-folder-depth:0"><span>' + escapeHtml(settings.labels.unassigned) + '</span></button>';
        html += renderFolderButtons(settings.folders || [], 0);
        html += '</div>';
        html += '</section>';

        $('.purepress-media-folders').remove();
        $anchor.before(html);
        markActiveFolder();
    }

    function markActiveFolder() {
        $('.purepress-media-folders__item').removeClass('is-active');
        $('.purepress-media-folders__item[data-folder="' + state.currentFolder + '"]').addClass('is-active');
    }

    function selectedAttachmentIds() {
        var ids = [];
        var seen = {};
        var frame = state.frame || (wp.media && wp.media.frame);
        var library = currentLibrary();
        var selection;

        if (library && frame && frame.state) {
            try {
                selection = frame.state().get('selection');
            } catch (error) {
                selection = null;
            }

            if (selection && selection.each) {
                selection.each(function (attachment) {
                    ids.push(attachment.id);
                });
            }
        }

        $('input[name="media[]"]:checked').each(function () {
            ids.push($(this).val());
        });

        return ids.filter(function (id) {
            id = parseInt(id, 10);

            if (!id || seen[id]) {
                return false;
            }

            seen[id] = true;

            return true;
        });
    }

    function request(action, data) {
        data = data || {};
        data.action = action;
        data.nonce = settings.nonce;

        return $.post(settings.ajaxUrl, data);
    }

    function refreshFolders(folders) {
        settings.folders = folders || [];
        renderPanel();
        $(document).trigger('purepress:mediaFoldersUpdated');
    }

    function activeNumericFolder() {
        return /^\d+$/.test(String(state.currentFolder)) ? parseInt(state.currentFolder, 10) : 0;
    }

    function handleCreateFolder() {
        var name = window.prompt(settings.labels.newFolderPrompt);
        var parent = activeNumericFolder();

        if (!name) {
            return;
        }

        request('purepress_media_folders_create', {
            name: name,
            parent: parent
        }).done(function (response) {
            if (response.success) {
                refreshFolders(response.data.folders);
                return;
            }

            window.alert(response.data && response.data.message ? response.data.message : settings.labels.requestFailed);
        }).fail(function () {
            window.alert(settings.labels.requestFailed);
        });
    }

    function handleRenameFolder() {
        var folder = activeNumericFolder();
        var name;

        if (!folder) {
            window.alert(settings.labels.selectFolderFirst);
            return;
        }

        name = window.prompt(settings.labels.renamePrompt);

        if (!name) {
            return;
        }

        request('purepress_media_folders_rename', {
            folder: folder,
            name: name
        }).done(function (response) {
            if (response.success) {
                refreshFolders(response.data.folders);
                return;
            }

            window.alert(response.data && response.data.message ? response.data.message : settings.labels.requestFailed);
        }).fail(function () {
            window.alert(settings.labels.requestFailed);
        });
    }

    function handleDeleteFolder() {
        var folder = activeNumericFolder();

        if (!folder) {
            window.alert(settings.labels.selectFolderFirst);
            return;
        }

        if (!window.confirm(settings.labels.deleteConfirm)) {
            return;
        }

        request('purepress_media_folders_delete', {
            folder: folder
        }).done(function (response) {
            if (response.success) {
                state.currentFolder = 'all';
                refreshFolders(response.data.folders);
                selectFolder('all');
                return;
            }

            window.alert(response.data && response.data.message ? response.data.message : settings.labels.requestFailed);
        }).fail(function () {
            window.alert(settings.labels.requestFailed);
        });
    }

    function handleMoveAttachment(targetFolder) {
        var attachments = selectedAttachmentIds();

        if (!attachments.length) {
            window.alert(settings.labels.selectAttachmentFirst);
            return;
        }

        request('purepress_media_folders_move_attachment', {
            folder: targetFolder,
            attachments: attachments
        }).done(function (response) {
            if (response.success) {
                refreshFolders(response.data.folders);
                refreshLibrary();
                return;
            }

            window.alert(response.data && response.data.message ? response.data.message : settings.labels.requestFailed);
        }).fail(function () {
            window.alert(settings.labels.requestFailed);
        });
    }

    function bindPanel() {
        $(document).on('click', '.purepress-media-folders__item', function () {
            selectFolder(String($(this).data('folder')));
        });

        $(document).on('click', '[data-purepress-folder-action]', function () {
            var action = $(this).data('purepress-folder-action');

            if (action === 'create') {
                handleCreateFolder();
            } else if (action === 'rename') {
                handleRenameFolder();
            } else if (action === 'delete') {
                handleDeleteFolder();
            } else if (action === 'move') {
                if (!activeNumericFolder()) {
                    window.alert(settings.labels.selectFolderFirst);
                    return;
                }

                handleMoveAttachment(activeNumericFolder());
            } else if (action === 'unassign') {
                handleMoveAttachment(0);
            }
        });
    }

    function patchAttachmentsBrowser() {
        if (!wp.media || !wp.media.view || !wp.media.view.AttachmentsBrowser || wp.media.view.AttachmentsBrowser.prototype.purepressMediaFoldersPatched) {
            return;
        }

        var originalCreateToolbar = wp.media.view.AttachmentsBrowser.prototype.createToolbar;
        var FolderFilter = wp.media.View.extend({
            tagName: 'select',
            className: 'attachment-filters purepress-media-folder-select',
            events: {
                change: 'change'
            },
            initialize: function () {
                this.listenTo(this.model, 'change:' + requestKey, this.sync);
                $(document).on('purepress:mediaFoldersUpdated', this.render.bind(this));
            },
            render: function () {
                this.$el.html(folderSelectHtml(state.currentFolder));
                this.sync();
                return this;
            },
            change: function () {
                var value = this.el.value || 'all';
                var props = {};

                props[requestKey] = value === 'all' ? null : value;
                state.currentFolder = value;
                updateUploaderDefaults();
                markActiveFolder();
                updateBrowserUrl(value);
                this.model.set(props);
            },
            sync: function () {
                var value = this.model.get(requestKey) || 'all';

                this.$el.val(value);
            }
        });

        wp.media.view.AttachmentsBrowser.prototype.createToolbar = function () {
            originalCreateToolbar.apply(this, arguments);

            if (!this.toolbar || !this.collection || !this.collection.props) {
                return;
            }

            this.toolbar.set('purepressMediaFolderFilter', new FolderFilter({
                controller: this.controller,
                model: this.collection.props,
                priority: -75
            }).render());
        };

        wp.media.view.AttachmentsBrowser.prototype.purepressMediaFoldersPatched = true;
    }

    function bindMediaGrid() {
        $('#wp-media-grid').on('wp-media-grid-ready', function (event, frame) {
            state.frame = frame;
            selectFolder(state.currentFolder, {
                skipUrl: true
            });
        });
    }

    patchUploader();
    patchAttachmentsBrowser();

    $(function () {
        patchUploader();
        patchAttachmentsBrowser();
        renderPanel();
        bindPanel();
        bindMediaGrid();
        updateUploaderDefaults();
    });
})(jQuery, window.wp || {}, window.PurePressMediaFolders || {});

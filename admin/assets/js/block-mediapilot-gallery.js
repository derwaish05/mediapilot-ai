/* global wp */
/**
 * MediaPilot Gallery block — editor registration.
 *
 * Plain ES2015 (no build step required). Uses the wp.* globals provided by
 * the Gutenberg runtime so this file can be enqueued as a regular script with
 * the appropriate wp-* script dependencies.
 *
 * Block name : mediapilot/gallery
 * Render     : server-side via GalleryBlock::renderBlock() (PHP)
 * save()     : returns null (dynamic block)
 */
(function () {
    'use strict';

    var blocks        = wp.blocks;
    var el            = wp.element.createElement;
    var Fragment      = wp.element.Fragment;
    var useState      = wp.element.useState;
    var useEffect     = wp.element.useEffect;
    var __            = wp.i18n.__;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var useBlockProps = wp.blockEditor.useBlockProps;
    var PanelBody     = wp.components.PanelBody;
    var SelectControl = wp.components.SelectControl;
    var RangeControl  = wp.components.RangeControl;
    var ToggleControl = wp.components.ToggleControl;
    var Spinner       = wp.components.Spinner;
    var apiFetch      = wp.apiFetch;

    // -------------------------------------------------------------------------
    // Helper — flatten the nested MediaPilotFolder tree into SelectControl options
    // -------------------------------------------------------------------------

    function flattenTree(folders, depth, options) {
        folders.forEach(function (folder) {
            options.push({
                label: '\u00a0'.repeat(depth * 3) + folder.name +
                       (folder.count > 0 ? ' (' + folder.count + ')' : ''),
                value: folder.id,
            });
            if (folder.children && folder.children.length > 0) {
                flattenTree(folder.children, depth + 1, options);
            }
        });
        return options;
    }

    // -------------------------------------------------------------------------
    // Edit component
    // -------------------------------------------------------------------------

    function MediaPilotGalleryEdit(props) {
        var attributes    = props.attributes;
        var setAttributes = props.setAttributes;

        var folderId   = attributes.folderId;
        var folderName = attributes.folderName;
        var layout     = attributes.layout;
        var columns    = attributes.columns;
        var gap        = attributes.gap;
        var lightbox   = attributes.lightbox;
        var imageSize  = attributes.imageSize;

        // Folder list options populated from REST API.
        var folderState   = useState([{ label: __('Loading…', 'mediapilot-ai'), value: 0 }]);
        var folderOptions = folderState[0];
        var setFolderOptions = folderState[1];

        var loadingState = useState(true);
        var loading      = loadingState[0];
        var setLoading   = loadingState[1];

        // Fetch folder tree once on mount.
        useEffect(function () {
            apiFetch({ path: '/mediapilot/v1/folders' })
                .then(function (response) {
                    // Envelope: { success: true, data: { tree: [], total: N } }
                    var tree    = (response && response.data && response.data.tree) ? response.data.tree : [];
                    var options = [{ label: __('— Select a folder —', 'mediapilot-ai'), value: 0 }];
                    flattenTree(tree, 0, options);
                    setFolderOptions(options);
                    setLoading(false);
                })
                .catch(function () {
                    setFolderOptions([{ label: __('Failed to load folders', 'mediapilot-ai'), value: 0 }]);
                    setLoading(false);
                });
        }, []);

        // Block wrapper — shown in the editor canvas.
        var blockProps = useBlockProps({
            className: 'mediapilot-gallery-editor-preview',
        });

        // Inspector sidebar controls.
        var inspector = el(
            InspectorControls,
            null,
            // --- Folder panel ---
            el(
                PanelBody,
                { title: __('Folder', 'mediapilot-ai'), initialOpen: true },
                loading
                    ? el(Spinner, null)
                    : el(SelectControl, {
                        label: __('Source folder', 'mediapilot-ai'),
                        value: folderId,
                        options: folderOptions,
                        onChange: function (val) {
                            var id  = parseInt(val, 10);
                            var opt = folderOptions.find(function (o) { return o.value === id; });
                            setAttributes({
                                folderId:   id,
                                folderName: opt ? opt.label.trim() : '',
                            });
                        },
                        __nextHasNoMarginBottom: true,
                    })
            ),
            // --- Layout panel ---
            el(
                PanelBody,
                { title: __('Layout', 'mediapilot-ai'), initialOpen: false },
                el(SelectControl, {
                    label: __('Gallery layout', 'mediapilot-ai'),
                    value: layout,
                    options: [
                        { label: __('Grid',    'mediapilot-ai'), value: 'grid' },
                        { label: __('Masonry', 'mediapilot-ai'), value: 'masonry' },
                        { label: __('Flex',    'mediapilot-ai'), value: 'flex' },
                    ],
                    onChange: function (val) { setAttributes({ layout: val }); },
                    __nextHasNoMarginBottom: true,
                }),
                el(RangeControl, {
                    label: __('Columns', 'mediapilot-ai'),
                    value: columns,
                    min: 1,
                    max: 8,
                    onChange: function (val) { setAttributes({ columns: val }); },
                    __nextHasNoMarginBottom: true,
                }),
                el(RangeControl, {
                    label: __('Gap (px)', 'mediapilot-ai'),
                    value: gap,
                    min: 0,
                    max: 64,
                    onChange: function (val) { setAttributes({ gap: val }); },
                    __nextHasNoMarginBottom: true,
                }),
                el(SelectControl, {
                    label: __('Image size', 'mediapilot-ai'),
                    value: imageSize,
                    options: [
                        { label: __('Thumbnail', 'mediapilot-ai'), value: 'thumbnail' },
                        { label: __('Medium',    'mediapilot-ai'), value: 'medium' },
                        { label: __('Large',     'mediapilot-ai'), value: 'large' },
                        { label: __('Full size', 'mediapilot-ai'), value: 'full' },
                    ],
                    onChange: function (val) { setAttributes({ imageSize: val }); },
                    __nextHasNoMarginBottom: true,
                })
            ),
            // --- Options panel ---
            el(
                PanelBody,
                { title: __('Options', 'mediapilot-ai'), initialOpen: false },
                el(ToggleControl, {
                    label:   __('Enable lightbox', 'mediapilot-ai'),
                    help:    __('Wraps each image in a full-size link for lightbox plugins.', 'mediapilot-ai'),
                    checked: lightbox,
                    onChange: function (val) { setAttributes({ lightbox: val }); },
                    __nextHasNoMarginBottom: true,
                })
            )
        );

        // Editor canvas placeholder.
        var preview = el(
            'div',
            blockProps,
            el(
                'p',
                { className: 'mediapilot-gallery-editor-preview__label' },
                folderId > 0
                    ? __('MediaPilot Gallery', 'mediapilot-ai') + ' — ' + folderName +
                      ' · ' + layout + ' · ' + columns + ' col'
                    : __('MediaPilot Gallery — select a folder in the block settings →', 'mediapilot-ai')
            )
        );

        return el(Fragment, null, inspector, preview);
    }

    // -------------------------------------------------------------------------
    // Block registration
    // -------------------------------------------------------------------------

    blocks.registerBlockType('mediapilot/gallery', {
        apiVersion: 3,

        title:       __('MediaPilot Gallery', 'mediapilot-ai'),
        description: __('Display images from a MediaPilot AI folder.', 'mediapilot-ai'),
        category:    'media',
        icon:        'format-gallery',
        keywords:    [__('gallery', 'mediapilot-ai'), __('folder', 'mediapilot-ai'), __('images', 'mediapilot-ai')],

        supports: {
            html:             false,
            align:            ['wide', 'full'],
            spacing:          { margin: true, padding: true },
            color:            { background: false, text: false },
        },

        attributes: {
            folderId:   { type: 'integer', default: 0 },
            folderName: { type: 'string',  default: '' },
            layout:     { type: 'string',  default: 'grid' },
            columns:    { type: 'integer', default: 3 },
            gap:        { type: 'integer', default: 16 },
            lightbox:   { type: 'boolean', default: true },
            imageSize:  { type: 'string',  default: 'medium' },
        },

        edit: MediaPilotGalleryEdit,

        // Dynamic block — server renders the output; save() must return null.
        save: function () { return null; },
    });
}());

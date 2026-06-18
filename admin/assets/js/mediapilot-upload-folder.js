/* MediaPilot AI — bind the upload folder <select> into every plupload upload
   so the chosen folder id is posted with each file. No localized data. */
(function ($) {
    'use strict';
    $(function () {
        if (typeof wp !== 'undefined' && wp.Uploader) {
            var origInit = wp.Uploader.prototype.init;
            wp.Uploader.prototype.init = function () {
                origInit.apply(this, arguments);
                this.uploader.bind('BeforeUpload', function (up, file) {
                    var folderSelect = document.getElementById('mediapilot-upload-folder');
                    if (folderSelect) {
                        up.settings.multipart_params = up.settings.multipart_params || {};
                        up.settings.multipart_params.mdpai_upload_folder_id = folderSelect.value;
                    }
                });
            };
        }
    });
})(jQuery);

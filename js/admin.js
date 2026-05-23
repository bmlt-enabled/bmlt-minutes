/* global wp, jQuery, BMLT_MINUTES_ADMIN */
(function ($) {
	'use strict';

	$(function () {
		var $idInput  = $('#bmlt_minutes_attachment_id');
		var $preview  = $('#bmlt_minutes_attachment_preview');
		var $pickBtn  = $('#bmlt_minutes_pick_file');
		var $clearBtn = $('#bmlt_minutes_clear_file');
		var frame;

		if (!$pickBtn.length) {
			return;
		}

		$pickBtn.on('click', function (e) {
			e.preventDefault();

			if (frame) {
				frame.open();
				return;
			}

			frame = wp.media({
				title: BMLT_MINUTES_ADMIN.pickTitle,
				button: { text: BMLT_MINUTES_ADMIN.pickButton },
				library: { type: ['application', 'text'] },
				multiple: false
			});

			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				if (BMLT_MINUTES_ADMIN.maxUpload && attachment.filesizeInBytes && attachment.filesizeInBytes > BMLT_MINUTES_ADMIN.maxUpload) {
					window.alert(BMLT_MINUTES_ADMIN.tooLargeMsg);
					return;
				}
				$idInput.val(attachment.id);
				$preview.html(
					'<span class="dashicons dashicons-media-document" style="vertical-align:middle;"></span> ' +
					'<a href="' + attachment.url + '" target="_blank" rel="noopener">' + attachment.filename + '</a>'
				);
				$clearBtn.prop('disabled', false);
			});

			frame.open();
		});

		$clearBtn.on('click', function (e) {
			e.preventDefault();
			$idInput.val('');
			$preview.html('<em>' + BMLT_MINUTES_ADMIN.noFileLabel + '</em>');
			$clearBtn.prop('disabled', true);
		});
	});
})(jQuery);

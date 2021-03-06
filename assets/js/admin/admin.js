/* eslint-disable no-use-before-define */
const { epla } = window;
const { __ } = wp.i18n;

jQuery(document).ready(function elasticPressLabsFeature($) {
	$('.right .ep-feature-meta_key_pattern').css('display', 'block');
	$('.left .ep-feature-search_algorithm').appendTo('.right');

	function removeWhitespace(index, value) {
		return value.trim();
	}

	$('#feature_meta_key_allow_pattern_setting').val(removeWhitespace);
	$('#feature_meta_key_deny_pattern_setting').val(removeWhitespace);

	function getElasticPressLabsSettings() {
		return $('[data-feature="elasticpress_labs"] input[value="1"]')
			.map(function isChecked() {
				return $(this).is(':checked');
			})
			.get();
	}

	const currentSettings = getElasticPressLabsSettings();

	function reloadPageIfSettingsChange(event, xhr, settings) {
		if (settings?.data?.includes('action=ep_save_feature&feature=elasticpress_labs')) {
			const updatedSettings = getElasticPressLabsSettings();

			if (JSON.stringify(currentSettings) !== JSON.stringify(updatedSettings)) {
				window.location.reload();
			}
		}
	}

	function getMetaKeyPatternSettings() {
		return {
			allow_pattern: $('#feature_meta_key_allow_pattern_setting').val(),
			deny_pattern: $('#feature_meta_key_deny_pattern_setting').val(),
		};
	}

	const metaKeyPatternSettings = getMetaKeyPatternSettings();

	function afterSaveSettingsMetaKeyPatternFeature(event, xhr, settings) {
		if (settings?.data?.includes('action=ep_save_feature&feature=meta_key_pattern')) {
			const isEnabled = $('#feature_active_meta_key_pattern_enabled').is(':checked');

			if (isEnabled) {
				const updatedMetaKeyPatternSettings = getMetaKeyPatternSettings();

				if (
					JSON.stringify(metaKeyPatternSettings) !==
					JSON.stringify(updatedMetaKeyPatternSettings)
				) {
					$.ajax({
						method: 'post',
						url: epla.ajax_url,
						data: {
							action: 'epl_meta_key_pattern_after_save',
							nonce: epla.nonce,
						},
					});

					const hasNotice = !!$(
						'.ep-feature-meta_key_pattern .settings.inside .requirements-status-notice',
					).length;

					if (!hasNotice) {
						$('.ep-feature-meta_key_pattern')
							.removeClass('feature-requirements-status-0')
							.addClass('feature-requirements-status-1');
						$('.ep-feature-meta_key_pattern .settings.inside').prepend(
							`<div class="requirements-status-notice">${epla.sync_notice}</div>`,
						);
					}
				}
			}
		}
	}

	function getSearchAlgorithmSettings() {
		return {
			isEnable: $('#feature_active_search_algorithm_enabled').is(':checked'),
			version: $('input[name="feature_search_algorithm_version_setting"]:checked').val(),
		};
	}

	function showNoticeSearchAlgorithm() {
		const hasNotice = !!$(
			'.ep-feature-search_algorithm .settings.inside .requirements-status-notice',
		).length;

		if (!hasNotice) {
			$('.ep-feature-search_algorithm').addClass('feature-requirements-status-0');
			$('.ep-feature-search_algorithm .settings.inside').prepend(
				`<div class="requirements-status-notice">${__(
					'This change will be reflected on the next page reload or expiration of any front-end caches.',
					'elasticpress-labs',
				)}</div>`,
			);
		}
	}

	let searchAlgorithmSettings = getSearchAlgorithmSettings();

	function afterSaveSettingsSearchAlgorithmVersionFeature(event, xhr, settings) {
		if (settings?.data?.includes('action=ep_save_feature&feature=search_algorithm')) {
			const updatedSearchAlgorithmSettings = getSearchAlgorithmSettings();

			if (
				searchAlgorithmSettings?.isEnable !== updatedSearchAlgorithmSettings?.isEnable &&
				updatedSearchAlgorithmSettings?.version === '3.4'
			) {
				showNoticeSearchAlgorithm();
			}

			if (
				updatedSearchAlgorithmSettings?.isEnable &&
				searchAlgorithmSettings?.version !== updatedSearchAlgorithmSettings.version
			) {
				showNoticeSearchAlgorithm();
			}

			searchAlgorithmSettings = updatedSearchAlgorithmSettings;
		}
	}

	$(document)
		.ajaxSuccess(reloadPageIfSettingsChange)
		.ajaxSuccess(afterSaveSettingsMetaKeyPatternFeature)
		.ajaxSuccess(afterSaveSettingsSearchAlgorithmVersionFeature);
});

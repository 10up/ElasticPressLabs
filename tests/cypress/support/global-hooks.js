window.indexNames = null;
window.isEpIo = false;
window.wpVersion = '';

before(() => {
	cy.wpCliEval(
		`
		// Clear any stuck sync process.
		\\ElasticPress\\IndexHelper::factory()->clear_index_meta();

		$is_epio = (int) \\ElasticPress\\Utils\\is_epio();

		$index_names = \\ElasticPress\\Elasticsearch::factory()->get_index_names( 'active' );
		echo wp_json_encode(
			[
				'indexNames' => $index_names,
				'isEpIo'     => $is_epio,
				'wpVersion'  => get_bloginfo( 'version' ),
			]
		);
		`,
	).then((wpCliResponse) => {
		const wpCliRespObj = JSON.parse(wpCliResponse.stdout);
		window.indexNames = wpCliRespObj.indexNames;
		window.isEpIo = wpCliRespObj.isEpIo === 1;
		window.wpVersion = wpCliRespObj.wpVersion;
	});
});

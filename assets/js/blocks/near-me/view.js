

document.addEventListener("DOMContentLoaded", () => {



	const onClickAction = (e) => {
		e.preventDefault();

		const form = e.target.closest("form");

		if (navigator.geolocation) {
			navigator.geolocation.getCurrentPosition(
				(position) => {
					const { latitude, longitude } = position.coords;

						// get form field with name ep_lat
					 form.querySelector("input[name=ep_lat]").value = latitude;
					 // get form field with name ep_long
					 form.querySelector("input[name=ep_lon]").value = longitude;

					form.submit();


					console.log("Latitude:", latitude, "Longitude:", longitude);
				},
				(error) => {
					console.error("Error getting location:", error.message);
				},
			);
		} else {
			console.log("Geolocation is not supported by this browser.");
		}


	};




	const buttons = document.querySelectorAll(".ep-near-me-block__submit-button");

	 if ( ! buttons ) {
		return;
	}

	buttons.forEach((button) => {
		button.addEventListener("click", onClickAction);
	});



});

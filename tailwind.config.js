module.exports = {
	content: [
		'./assets/src/**/*.{js,jsx,ts,tsx}',
		'./inc/**/*.php',
		'./templates/**/*.php', // if using templates
		'./*.php', // root files like index.php
		'./assets/**/*.html', // if using HTML templates
	],
	theme: {
		extend: {
			aspectRatio: {
				'4/3': '4 / 3',
				'3/2': '3 / 2',
				golden: '1.618 / 1',
			},
		},
	},
};

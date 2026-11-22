// resources/js/lib/latest-read.ts
function latestReads() {
	let latest = 0;
	return {
		start: () => {
			const mine = ++latest;
			return () => mine === latest;
		},
		drop: () => {
			latest++;
		}
	};
}
export { latestReads as t };

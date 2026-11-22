import { useEffect, useRef, useState } from "react";
// resources/js/lib/live-read.ts
/** A value that follows another after a pause - a search typed, a dialog's fields for its note. */
function useDebounced(value, delay = 300) {
	const [settled, setSettled] = useState(value);
	useEffect(() => {
		const timer = setTimeout(() => setSettled(value), delay);
		return () => clearTimeout(timer);
	}, [value, delay]);
	return settled;
}
/** A read the page asks as it is typed: JSON, in the panel's session; null when it is refused or fails. */
async function readJson(path, params, signal) {
	const query = new URLSearchParams();
	for (const [key, value] of Object.entries(params)) if (Array.isArray(value)) value.forEach((each) => query.append(`${key}[]`, each));
	else if (typeof value === "boolean") {
		if (value) query.set(key, "1");
	} else if (value !== null && value !== void 0 && value !== "") query.set(key, value);
	try {
		const response = await fetch(`${path}?${query.toString()}`, {
			headers: {
				Accept: "application/json",
				"X-Requested-With": "XMLHttpRequest"
			},
			credentials: "same-origin",
			signal
		});
		if (response.status === 401) window.location.reload();
		return response.ok ? await response.json() : null;
	} catch {
		return null;
	}
}
/**
* A read that follows what is typed (a dialog's note or preview, a picker's search): it keeps its last
* answer while the next one is on its way (`loading`), and a later answer always wins over an earlier one.
*
* @param params  null while there is nothing to ask yet
*/
function useLiveRead(path, params, delay = 300) {
	const settled = useDebounced(params === null ? null : JSON.stringify(params), delay);
	const [data, setData] = useState(null);
	const [loading, setLoading] = useState(false);
	const [failed, setFailed] = useState(false);
	const [attempt, setAttempt] = useState(0);
	const latest = useRef(0);
	useEffect(() => {
		const mine = ++latest.current;
		if (settled === null) {
			setData(null);
			setLoading(false);
			setFailed(false);
			return;
		}
		const controller = new AbortController();
		setLoading(true);
		setFailed(false);
		readJson(path, JSON.parse(settled), controller.signal).then((answer) => {
			if (controller.signal.aborted || mine !== latest.current) return;
			setLoading(false);
			setFailed(answer === null);
			setData(answer);
		});
		return () => controller.abort();
	}, [
		path,
		settled,
		attempt
	]);
	return {
		data,
		loading,
		failed,
		retry: () => setAttempt((count) => count + 1)
	};
}
export { useDebounced as n, useLiveRead as r, readJson as t };

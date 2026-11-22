import { useRef } from "react";
// resources/js/lib/use-return-focus.ts
/**
* Focus goes back to whatever opened a dialog (Geist's Modal: "return focus to the trigger after
* close"; frontend.md §6). Radix does that through its own Trigger; a dialog opened from a ⋯ menu,
* or opened by the page, has none, and focus fell to the page's start (found in the Geist move,
* 2026-10-02).
*
* The opener is read while the opening is being rendered - before a field inside takes focus with
* autoFocus. When it is gone by then - a menu item, whose menu closed as it was chosen - `fallback`
* is used instead: the menu's own ⋯ button. Reading `document` here is safe on the server too: a
* dialog renders closed there.
*
* Give the result to the dialog content's `onCloseAutoFocus`.
*/
function useReturnFocus(open, fallback) {
	const opener = useRef(null);
	const wasOpen = useRef(false);
	if (open && !wasOpen.current && typeof document !== "undefined") opener.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
	wasOpen.current = open;
	return (event) => {
		const target = opener.current !== null && opener.current.isConnected && opener.current !== document.body ? opener.current : fallback?.current ?? null;
		if (target !== null && target.isConnected) {
			event.preventDefault();
			target.focus();
		}
	};
}
export { useReturnFocus as t };

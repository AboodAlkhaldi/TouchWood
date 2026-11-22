/*
| The addresses the pages draw from a YouTube video's id (flags-2026-11.md S4.4): YouTube's own thumbnail, an image
| (Q3), and the player without cookies until played, loaded only once Play is pressed. Kept apart from reading a pasted
| link (youtube-id.ts), which only the panel needs: the shop's pages pay for these three alone.
*/

/** The link a kept video is sent back as, when its list is saved again: the server reads the id out of it again. */
export const youTubeLink = (id: string): string => `https://www.youtube.com/watch?v=${id}`;

/** YouTube's own thumbnail for the video (480 x 360, given for every video): an image from Google, no cookie. */
export const youTubeThumbnail = (id: string): string => `https://i.ytimg.com/vi/${encodeURIComponent(id)}/hqdefault.jpg`;

/** The player without cookies until played, starting at once: it is loaded only by a press of Play. */
export const youTubePlayer = (id: string): string => `https://www.youtube-nocookie.com/embed/${encodeURIComponent(id)}?autoplay=1&rel=0`;

import { n as useTranslator } from "./t-Da8CTZFi.js";
import { t as Button } from "./button-DoWvyMdE.js";
import { i as DialogDescription, n as DialogClose, r as DialogContent, s as DialogTitle, t as Dialog } from "./dialog-C7NRbZ1B.js";
import { L as ShopPhoto, M as VideoTile } from "../ssr.js";
import { usePage } from "@inertiajs/react";
import { useState } from "react";
import { ChevronLeft, ChevronRight, X } from "lucide-react";
import { jsx, jsxs } from "react/jsx-runtime";
// resources/js/components/shop/PhotoViewer.tsx
function PhotoViewer({ items, index, onIndex, title, play = false }) {
	const t = useTranslator();
	const { direction } = usePage().props;
	const [auto, setAuto] = useState(play);
	const open = index !== null && items[index] !== void 0;
	const last = items.length - 1;
	const go = (step) => {
		setAuto(false);
		onIndex(index === null ? null : (index + step + items.length) % items.length);
	};
	const item = index === null ? void 0 : items[index];
	return /* @__PURE__ */ jsx(Dialog, {
		open,
		onOpenChange: (next) => next ? void 0 : onIndex(null),
		children: /* @__PURE__ */ jsxs(DialogContent, {
			className: "max-w-4xl gap-3 p-3 sm:max-w-4xl",
			showCloseButton: false,
			"data-test": "photo-viewer",
			onKeyDown: (event) => {
				if (event.key === "ArrowRight" || event.key === "ArrowLeft") {
					event.preventDefault();
					go(event.key === "ArrowRight" !== (direction === "rtl") ? 1 : -1);
				}
			},
			children: [
				/* @__PURE__ */ jsx(DialogTitle, {
					className: "sr-only",
					children: title
				}),
				/* @__PURE__ */ jsx(DialogDescription, {
					className: "sr-only",
					children: t("content::shop.project.viewer_count", {
						number: (index ?? 0) + 1,
						total: items.length
					})
				}),
				/* @__PURE__ */ jsx("div", {
					className: "flex justify-end",
					children: /* @__PURE__ */ jsx(DialogClose, {
						asChild: true,
						children: /* @__PURE__ */ jsx(Button, {
							type: "button",
							variant: "ghost",
							size: "icon",
							"aria-label": t("ui.close"),
							"data-test": "viewer-close",
							children: /* @__PURE__ */ jsx(X, { "aria-hidden": "true" })
						})
					})
				}),
				item === void 0 || index === null ? null : item.video !== void 0 ? /* @__PURE__ */ jsx(VideoTile, {
					video: item.video,
					label: t("content::shop.project.video_play"),
					autoPlay: auto,
					className: "relative aspect-video max-h-[75vh] w-full overflow-hidden rounded-md",
					test: "viewer-video"
				}, item.video.id) : /* @__PURE__ */ jsx(ShopPhoto, {
					photo: item.photo,
					size: "zoom",
					alt: "",
					eager: true,
					className: "max-h-[75vh] w-full rounded-md object-contain"
				}),
				last > 0 && index !== null ? /* @__PURE__ */ jsxs("div", {
					className: "flex items-center justify-between gap-3",
					children: [
						/* @__PURE__ */ jsx(Button, {
							type: "button",
							variant: "outline",
							size: "icon",
							"aria-label": t("content::shop.project.viewer_previous"),
							onClick: () => go(-1),
							"data-test": "viewer-previous",
							children: /* @__PURE__ */ jsx(ChevronLeft, {
								"aria-hidden": "true",
								className: "rtl:rotate-180"
							})
						}),
						/* @__PURE__ */ jsx("span", {
							className: "tw-figure text-label-13 text-ink-muted",
							"data-test": "viewer-count",
							children: t("content::shop.project.viewer_count", {
								number: index + 1,
								total: items.length
							})
						}),
						/* @__PURE__ */ jsx(Button, {
							type: "button",
							variant: "outline",
							size: "icon",
							"aria-label": t("content::shop.project.viewer_next"),
							onClick: () => go(1),
							"data-test": "viewer-next",
							children: /* @__PURE__ */ jsx(ChevronRight, {
								"aria-hidden": "true",
								className: "rtl:rotate-180"
							})
						})
					]
				}) : null
			]
		})
	});
}
export { PhotoViewer };

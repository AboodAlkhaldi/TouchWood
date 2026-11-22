import { t as cn } from "./cn-C2NdifnB.js";
import { n as useTranslator } from "./t-Da8CTZFi.js";
import "./button-DoWvyMdE.js";
import { t as figure } from "./digits-D5IxcRsf.js";
import { N as BannerSlide } from "../ssr.js";
import { usePage } from "@inertiajs/react";
import * as React from "react";
import { useEffect, useState } from "react";
import { ChevronLeft, ChevronRight } from "lucide-react";
import { Fragment as Fragment$1, jsx, jsxs } from "react/jsx-runtime";
import useEmblaCarousel from "embla-carousel-react";
// resources/js/components/ui/carousel.tsx
var CarouselContext = React.createContext(null);
function useCarousel() {
	const context = React.useContext(CarouselContext);
	if (!context) throw new Error("useCarousel must be used within a <Carousel />");
	return context;
}
function Carousel({ orientation = "horizontal", opts, setApi, plugins, className, children, ...props }) {
	const [carouselRef, api] = useEmblaCarousel({
		...opts,
		axis: orientation === "horizontal" ? "x" : "y"
	}, plugins);
	const [canScrollPrev, setCanScrollPrev] = React.useState(false);
	const [canScrollNext, setCanScrollNext] = React.useState(false);
	const onSelect = React.useCallback((api) => {
		if (!api) return;
		setCanScrollPrev(api.canScrollPrev());
		setCanScrollNext(api.canScrollNext());
	}, []);
	const scrollPrev = React.useCallback(() => {
		api?.scrollPrev();
	}, [api]);
	const scrollNext = React.useCallback(() => {
		api?.scrollNext();
	}, [api]);
	const handleKeyDown = React.useCallback((event) => {
		if (event.key === "ArrowLeft") {
			event.preventDefault();
			scrollPrev();
		} else if (event.key === "ArrowRight") {
			event.preventDefault();
			scrollNext();
		}
	}, [scrollPrev, scrollNext]);
	React.useEffect(() => {
		if (!api || !setApi) return;
		setApi(api);
	}, [api, setApi]);
	React.useEffect(() => {
		if (!api) return;
		onSelect(api);
		api.on("reInit", onSelect);
		api.on("select", onSelect);
		return () => {
			api?.off("select", onSelect);
		};
	}, [api, onSelect]);
	return /* @__PURE__ */ jsx(CarouselContext.Provider, {
		value: {
			carouselRef,
			api,
			opts,
			orientation: orientation || (opts?.axis === "y" ? "vertical" : "horizontal"),
			scrollPrev,
			scrollNext,
			canScrollPrev,
			canScrollNext
		},
		children: /* @__PURE__ */ jsx("div", {
			onKeyDownCapture: handleKeyDown,
			className: cn("relative", className),
			role: "region",
			"aria-roledescription": "carousel",
			"data-slot": "carousel",
			...props,
			children
		})
	});
}
function CarouselContent({ className, ...props }) {
	const { carouselRef, orientation } = useCarousel();
	return /* @__PURE__ */ jsx("div", {
		ref: carouselRef,
		className: "overflow-hidden",
		"data-slot": "carousel-content",
		children: /* @__PURE__ */ jsx("div", {
			className: cn("flex", orientation === "horizontal" ? "-ms-4" : "-mt-4 flex-col", className),
			...props
		})
	});
}
function CarouselItem({ className, ...props }) {
	const { orientation } = useCarousel();
	return /* @__PURE__ */ jsx("div", {
		role: "group",
		"aria-roledescription": "slide",
		"data-slot": "carousel-item",
		className: cn("min-w-0 shrink-0 grow-0 basis-full", orientation === "horizontal" ? "ps-4" : "pt-4", className),
		...props
	});
}
// resources/js/components/content/HomeBanners.tsx
/** How long a banner stays before the next (§3.1: 5 seconds, the owner's yes). */
var TURN_MS = 5e3;
function HomeBanners({ banners, store, locale }) {
	const t = useTranslator();
	const { direction } = usePage().props;
	const [api, setApi] = useState();
	const [shown, setShown] = useState(0);
	const [hovered, setHovered] = useState(false);
	const [focused, setFocused] = useState(false);
	const [touched, setTouched] = useState(false);
	const [still, setStill] = useState(false);
	const held = hovered || focused || touched || still;
	const many = banners.length > 1;
	const rtl = direction === "rtl";
	useEffect(() => {
		const query = window.matchMedia("(prefers-reduced-motion: reduce)");
		const read = () => setStill(query.matches);
		read();
		query.addEventListener("change", read);
		return () => query.removeEventListener("change", read);
	}, []);
	useEffect(() => {
		if (!api) return;
		const select = () => setShown(api.selectedScrollSnap());
		select();
		api.on("select", select);
		return () => {
			api.off("select", select);
		};
	}, [api]);
	useEffect(() => {
		if (!api || !many || held) return;
		const turn = window.setInterval(() => {
			if (!document.hidden) api.scrollNext();
		}, TURN_MS);
		return () => window.clearInterval(turn);
	}, [
		api,
		many,
		held
	]);
	const arrow = "absolute top-1/2 z-10 hidden size-10 -translate-y-1/2 place-items-center rounded-full bg-surface/90 text-ink shadow-sm hover:bg-surface sm:grid";
	const of = (index) => t("content::shop.home.banner_of", {
		number: figure(locale, index + 1),
		count: figure(locale, banners.length)
	});
	return /* @__PURE__ */ jsxs("div", {
		className: "shop-box relative max-sm:px-0!",
		onMouseEnter: () => setHovered(true),
		onMouseLeave: () => setHovered(false),
		onFocusCapture: () => setFocused(true),
		onBlurCapture: (event) => setFocused(event.currentTarget.contains(event.relatedTarget)),
		onPointerDown: (event) => event.pointerType !== "mouse" && setTouched(true),
		onPointerUp: () => setTouched(false),
		onPointerCancel: () => setTouched(false),
		onKeyDownCapture: (event) => {
			if (rtl && (event.key === "ArrowLeft" || event.key === "ArrowRight")) {
				event.preventDefault();
				event.stopPropagation();
				event.key === "ArrowLeft" ? api?.scrollNext() : api?.scrollPrev();
			}
		},
		"data-test": "home-banners",
		"data-turning": "true",
		children: [/* @__PURE__ */ jsx(Carousel, {
			setApi,
			opts: {
				loop: many,
				direction: rtl ? "rtl" : "ltr"
			},
			"aria-label": t("content::shop.home.banners"),
			children: /* @__PURE__ */ jsx(CarouselContent, {
				className: "ms-0",
				children: banners.map((banner, index) => /* @__PURE__ */ jsx(CarouselItem, {
					className: "ps-0",
					"aria-label": of(index),
					"data-test": "home-banner",
					children: /* @__PURE__ */ jsx(BannerSlide, {
						banner,
						first: index === 0,
						store,
						locale
					})
				}, banner.id))
			})
		}), many ? /* @__PURE__ */ jsxs(Fragment$1, { children: [
			/* @__PURE__ */ jsx("button", {
				type: "button",
				className: `${arrow} start-14`,
				onClick: () => api?.scrollPrev(),
				"aria-label": t("content::shop.home.banner_previous"),
				"data-test": "banner-previous",
				children: /* @__PURE__ */ jsx(ChevronLeft, {
					"aria-hidden": true,
					className: "size-5 rtl:rotate-180"
				})
			}),
			/* @__PURE__ */ jsx("button", {
				type: "button",
				className: `${arrow} end-14`,
				onClick: () => api?.scrollNext(),
				"aria-label": t("content::shop.home.banner_next"),
				"data-test": "banner-next",
				children: /* @__PURE__ */ jsx(ChevronRight, {
					"aria-hidden": true,
					className: "size-5 rtl:rotate-180"
				})
			}),
			/* @__PURE__ */ jsx("div", {
				className: "mt-3 flex justify-center gap-1.5",
				"data-test": "banner-dots",
				children: banners.map((banner, index) => /* @__PURE__ */ jsx("button", {
					type: "button",
					onClick: () => api?.scrollTo(index),
					"aria-label": of(index),
					"aria-current": index === shown ? "true" : void 0,
					className: `h-1.5 rounded-full transition-all ${index === shown ? "w-6 bg-brand" : "w-1.5 bg-line-strong"}`
				}, banner.id))
			})
		] }) : null]
	});
}
export { HomeBanners as default };

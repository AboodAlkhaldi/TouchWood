import { n as useTranslator } from "./t-Da8CTZFi.js";
import { t as Note } from "./Note-BdXDWF9_.js";
import { H as useLink, a as payAddresses, c as useClock, i as minutesAndSeconds, l as useCsrfToken, n as P, o as postJson, s as untilOf, t as CHANNEL, u as useServerNow } from "../ssr.js";
import { router } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Fragment as Fragment$1, jsx, jsxs } from "react/jsx-runtime";
// resources/js/components/sales/payment/PaymentInTheWay.tsx
var EVERY = 15e3;
function PaymentInTheWay({ payment, number, onClosed }) {
	const t = useTranslator();
	const link = useLink();
	const token = useCsrfToken();
	const clock = useClock();
	const now = useServerNow(payment.serverNow);
	const [otherWindow, setOtherWindow] = useState(false);
	const until = untilOf(payment);
	const addresses = payAddresses(link, number);
	useEffect(() => {
		if (typeof BroadcastChannel === "undefined") return;
		const channel = new BroadcastChannel(CHANNEL);
		channel.onmessage = (event) => {
			if (event.data === "here") setOtherWindow(true);
		};
		channel.postMessage("ping");
		return () => channel.close();
	}, []);
	useEffect(() => {
		let gone = false;
		let timer;
		const ask = async () => {
			const answer = await postJson(addresses.follow(payment.tryId), token);
			if (gone) return;
			if (answer.ok && answer.data.state === "LIVE") timer = setTimeout(() => void ask(), EVERY);
			else if (answer.ok && answer.data.mainStoreUrl !== null) onClosed(answer.data);
			else if (answer.ok && answer.data.orderNumber !== null) router.visit(addresses.done(answer.data.orderNumber));
			else if (answer.ok || answer.status === 404 || answer.status === 401 || answer.status === 419) {
				router.reload();
				timer = setTimeout(() => void ask(), EVERY);
			} else timer = setTimeout(() => void ask(), EVERY);
		};
		ask();
		return () => {
			gone = true;
			clearTimeout(timer);
		};
	}, [payment.tryId]);
	return /* @__PURE__ */ jsx(Note, {
		"data-test": "payment-in-the-way",
		children: otherWindow ? t(`${P}.other_window`) : /* @__PURE__ */ jsxs(Fragment$1, { children: [until === null ? t(`${P}.pay_later`) : t(number === void 0 ? `${P}.in_the_way` : `${P}.request.in_the_way`, { time: clock(until) }), until === null ? null : /* @__PURE__ */ jsx("span", {
			className: "tw-figure ms-2 text-label-14",
			dir: "ltr",
			"data-test": "payment-time-left",
			children: minutesAndSeconds(new Date(until).getTime() - now)
		})] })
	});
}
export { PaymentInTheWay as default };

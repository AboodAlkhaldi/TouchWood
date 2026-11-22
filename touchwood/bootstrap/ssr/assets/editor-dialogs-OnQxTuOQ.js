import { n as useTranslator } from "./t-Da8CTZFi.js";
import { t as ActionButton } from "./ActionButton-CnzjbvkB.js";
import { r as TextField } from "./Fields-BdHuDKNr.js";
import { t as PanelDialog } from "./PanelDialog-BPuxGGA-.js";
import { I as ImageBox } from "../ssr.js";
import { useState } from "react";
import { jsx, jsxs } from "react/jsx-runtime";
// resources/js/components/content/editor-dialogs.tsx
function ImageDialog({ kind, store, language, onInsert, onClose }) {
	const t = useTranslator();
	const [image, setImage] = useState(null);
	const [description, setDescription] = useState("");
	const reason = image === null ? t("content::admin_documents.editor.image_first") : description.trim() === "" ? t("content::admin_documents.editor.description_first") : void 0;
	return /* @__PURE__ */ jsx(PanelDialog, {
		open: true,
		onOpenChange: (open) => open ? void 0 : onClose(),
		title: t("content::admin_documents.editor.image_title"),
		description: t("content::admin_documents.editor.image_body"),
		busy: false,
		confirm: /* @__PURE__ */ jsx(ActionButton, {
			type: "button",
			disabledReason: reason,
			onClick: () => image !== null && onInsert(image, description.trim()),
			"data-test": "insert-image",
			children: t("content::admin_documents.editor.insert")
		}),
		children: /* @__PURE__ */ jsxs("div", {
			className: "grid gap-4",
			children: [/* @__PURE__ */ jsx(ImageBox, {
				id: "editor-image",
				label: t("content::admin_documents.editor.image"),
				kind,
				store,
				image,
				onChange: setImage
			}), /* @__PURE__ */ jsx(TextField, {
				id: "editor-image-description",
				dir: language === "ar" ? "rtl" : "ltr",
				lang: language,
				label: t("content::admin_documents.editor.description"),
				helper: t("content::admin_documents.editor.description_helper"),
				value: description,
				onChange: (event) => setDescription(event.target.value),
				"data-test": "image-description"
			})]
		})
	});
}
export { ImageDialog };

import { createCn } from "cn/config";
// resources/js/lib/cn.ts
var TYPE_SCALE = /^(heading|label|copy|button)-\d+(-mono)?$/;
var cn = createCn({ extend: {
	classGroups: {
		"font-size": [{ text: [(value) => TYPE_SCALE.test(value)] }],
		material: [{ material: [
			"base",
			"small",
			"medium",
			"large",
			"tooltip",
			"menu",
			"modal",
			"fullscreen"
		] }]
	},
	conflictingClassGroups: { material: [
		"rounded",
		"shadow",
		"bg-color"
	] }
} });
export { cn as t };

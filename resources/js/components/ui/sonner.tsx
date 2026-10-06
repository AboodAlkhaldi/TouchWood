import {
  CircleCheckIcon,
  InfoIcon,
  Loader2Icon,
  OctagonXIcon,
  TriangleAlertIcon,
} from "lucide-react"
import { usePage } from "@inertiajs/react"
import { Toaster as Sonner, type ToasterProps } from "sonner"
import type { SharedProps } from "@/types/page"

// Ours, edit 2 of frontend.md §1.11 (owner, 2026-10-02): upstream reads the theme from next-themes;
// ours is chosen on the server and sent with every page, so the toasts read it from there - the
// person's choice, which Sonner understands as it is: light, dark or system.
// Checked by tests/Architecture/ShadcnEditsTest.
const Toaster = ({ ...props }: ToasterProps) => {
  const { theme } = usePage<SharedProps>().props

  return (
    <Sonner
      theme={theme.choice}
      className="toaster group"
      icons={{
        success: <CircleCheckIcon className="size-4" />,
        info: <InfoIcon className="size-4" />,
        warning: <TriangleAlertIcon className="size-4" />,
        error: <OctagonXIcon className="size-4" />,
        loading: <Loader2Icon className="size-4 animate-spin" />,
      }}
      style={
        {
          "--normal-bg": "var(--popover)",
          "--normal-text": "var(--popover-foreground)",
          "--normal-border": "var(--border)",
          "--border-radius": "var(--radius)",
        } as React.CSSProperties
      }
      {...props}
    />
  )
}

export { Toaster }

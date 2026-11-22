import { cn } from "cn"

// Ours, edit 1 of frontend.md §1.11: shadcn paints hover, focus and open states with `accent`,
// which is TouchWood's copper; those backgrounds read `muted` here. Checked by
// tests/Architecture/ShadcnEditsTest.

function Skeleton({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="skeleton"
      className={cn("animate-pulse rounded-md bg-muted", className)}
      {...props}
    />
  )
}

export { Skeleton }

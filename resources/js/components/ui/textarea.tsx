import * as React from "react"

import { cn } from "@/lib/utils"

type TextareaProps = React.ComponentProps<"textarea"> & {
  size?: "default" | "lg"
}

function Textarea({ className, size = "default", ...props }: TextareaProps) {
  return (
    <textarea
      data-slot="textarea"
      className={cn(
        "border-input placeholder:text-muted-foreground flex field-sizing-content min-h-16 w-full rounded-md border bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm",
        "focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]",
        "aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive",
        size === "lg" &&
          "min-h-40 bg-field px-[18px] py-4 text-[17px] leading-[1.6] shadow-none md:text-[17px] focus-visible:border-input focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background aria-invalid:border-2 aria-invalid:focus-visible:border-destructive",
        className
      )}
      {...props}
    />
  )
}

export { Textarea }
